#!/usr/bin/env bash
# ==============================================================================
# LiteControl - Automated Upgrade & Migration Utility (`litecontrol_upgrade.sh`)
#
# CyberPanel-style one-line upgrader supporting Git tags, branches, automatic
# pre-upgrade backups, non-destructive OpenLiteSpeed -> Nginx panel migration,
# Composer/Vite builds, and zero-data-loss Laravel migrations.
#
# Usage:
#   Interactive / Default:
#     sudo bash litecontrol_upgrade.sh
#   Specific Version Tag or Branch:
#     sudo bash litecontrol_upgrade.sh --version v1.1.0
#     sudo bash litecontrol_upgrade.sh --branch feat-nginx-panel-server
#   One-Liner via curl/wget:
#     sudo bash -c "$(curl -fsSL https://raw.githubusercontent.com/i1yaz/LiteControl/main/litecontrol_upgrade.sh)"
# ==============================================================================

set -euo pipefail

GITHUB_REPO_URL="${GITHUB_REPO_URL:-https://github.com/i1yaz/LiteControl.git}"
UPGRADE_SRC_DIR="${UPGRADE_SRC_DIR:-/usr/local/src/litecontrol}"
LC_BASE_DIR="${LC_BASE_DIR:-/usr/local/litecontrol}"
LC_CONF_DIR="${LC_CONF_DIR:-/etc/litecontrol}"
LC_BACKUP_ROOT="${LC_BACKUP_ROOT:-/var/backups/litecontrol}"

TARGET_REF=""
NON_INTERACTIVE=0
BUILD_FRONTEND=0

# Terminal Colors
if [[ -t 1 ]]; then
    C_RESET=$'\033[0m'
    C_BLUE=$'\033[38;5;39m'
    C_GREEN=$'\033[38;5;83m'
    C_YELLOW=$'\033[38;5;220m'
    C_RED=$'\033[38;5;196m'
    C_BOLD=$'\033[1m'
else
    C_RESET=""
    C_BLUE=""
    C_GREEN=""
    C_YELLOW=""
    C_RED=""
    C_BOLD=""
fi

usage() {
    cat <<EOF
Usage: sudo bash litecontrol_upgrade.sh [OPTIONS]

Options:
  -v, --version <tag>    Upgrade to a specific Git release tag (e.g. v1.0.0, v1.1.0)
  -b, --branch <branch>  Upgrade to a specific Git branch (e.g. main, feat-nginx-panel-server)
  -y, --yes              Run non-interactively using latest release tag (or main)
      --build-frontend   Compile Vue 3/Vite frontend assets on host machine (default: disabled)
      --skip-frontend    Skip npm frontend build (default)
  -h, --help             Show this help message
EOF
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            -v|--version|-b|--branch)
                TARGET_REF="${2:-}"
                shift 2
                ;;
            -y|--yes)
                NON_INTERACTIVE=1
                shift
                ;;
            --build-frontend)
                BUILD_FRONTEND=1
                shift
                ;;
            --skip-frontend)
                BUILD_FRONTEND=0
                shift
                ;;
            -h|--help)
                usage
                exit 0
                ;;
            *)
                # Allow positional version/branch argument (e.g. `lc-cli upgrade v1.1.0`)
                if [[ -z "${TARGET_REF}" && "$1" != -* ]]; then
                    TARGET_REF="$1"
                    shift
                else
                    printf "%s[ERROR]%s Unknown option: %s\n" "${C_RED}" "${C_RESET}" "$1" >&2
                    usage
                    exit 1
                fi
                ;;
        esac
    done
}

resolve_target_ref() {
    # Query latest release tag from GitHub remote, falling back to 'main'
    local latest_tag=""
    if command -v git >/dev/null 2>&1; then
        latest_tag=$(git ls-remote --tags --refs --sort="-v:refname" "${GITHUB_REPO_URL}" 2>/dev/null | head -n 1 | awk -F'/' '{print $NF}' || true)
    fi
    local default_ref="${latest_tag:-main}"

    if [[ "${TARGET_REF:-}" == "latest" || "${TARGET_REF:-}" == "current" ]]; then
        TARGET_REF="${default_ref}"
        return 0
    fi

    if [[ -n "${TARGET_REF:-}" ]]; then
        return 0
    fi

    if [[ "${NON_INTERACTIVE}" -eq 0 && -t 0 ]]; then
        printf "\n%sLatest available version/branch:%s %s\n" "${C_BOLD}" "${C_RESET}" "${default_ref}"
        read -r -p "Enter version tag or branch to upgrade to [Press Enter for ${default_ref}]: " user_input
        TARGET_REF="${user_input:-${default_ref}}"
    else
        TARGET_REF="${default_ref}"
    fi
}

fetch_target_source() {
    printf "\n%s==> Fetching LiteControl source (%s)...%s\n" "${C_BOLD}${C_BLUE}" "${TARGET_REF}" "${C_RESET}"

    if ! command -v git >/dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get update -qq
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq git curl rsync
    fi

    mkdir -p "$(dirname "${UPGRADE_SRC_DIR}")"

    if [[ ! -d "${UPGRADE_SRC_DIR}/.git" ]]; then
        rm -rf "${UPGRADE_SRC_DIR}"
        git clone --quiet "${GITHUB_REPO_URL}" "${UPGRADE_SRC_DIR}"
    fi

    git -C "${UPGRADE_SRC_DIR}" fetch --all --tags --prune --quiet

    # Checkout matching tag, remote branch, or commit
    if git -C "${UPGRADE_SRC_DIR}" rev-parse -q --verify "refs/tags/${TARGET_REF}" >/dev/null 2>&1; then
        git -C "${UPGRADE_SRC_DIR}" checkout -f "tags/${TARGET_REF}" --quiet
    elif git -C "${UPGRADE_SRC_DIR}" rev-parse -q --verify "refs/tags/v${TARGET_REF}" >/dev/null 2>&1; then
        TARGET_REF="v${TARGET_REF}"
        git -C "${UPGRADE_SRC_DIR}" checkout -f "tags/${TARGET_REF}" --quiet
    elif git -C "${UPGRADE_SRC_DIR}" rev-parse -q --verify "refs/remotes/origin/${TARGET_REF}" >/dev/null 2>&1; then
        git -C "${UPGRADE_SRC_DIR}" checkout -B "${TARGET_REF}" "origin/${TARGET_REF}" --quiet
    else
        printf "%s[ERROR]%s Version tag or branch '%s' not found in repository %s\n" "${C_RED}" "${C_RESET}" "${TARGET_REF}" "${GITHUB_REPO_URL}" >&2
        exit 1
    fi

    # Strip CRLF line endings from all Linux scripts and templates (excluding binaries like .phar)
    find "${UPGRADE_SRC_DIR}/scripts" "${UPGRADE_SRC_DIR}/bin" "${UPGRADE_SRC_DIR}/templates" \
        -type f ! -name "*.phar" -exec sed -i 's/\r$//' {} + 2>/dev/null || true
    sed -i 's/\r$//' "${UPGRADE_SRC_DIR}/install.sh" "${UPGRADE_SRC_DIR}/litecontrol_upgrade.sh" 2>/dev/null || true
}

create_pre_upgrade_backup() {
    local ts
    ts=$(date '+%Y%m%d_%H%M%S')
    export SNAPSHOT_DIR="${LC_BACKUP_ROOT}/pre-upgrade-${ts}"
    log_step "01. Creating Pre-Upgrade Safety Backup at ${SNAPSHOT_DIR}"

    mkdir -p "${SNAPSHOT_DIR}"
    chmod 700 "${SNAPSHOT_DIR}"

    if [[ -d "${LC_CONF_DIR}" ]]; then
        cp -a "${LC_CONF_DIR}" "${SNAPSHOT_DIR}/etc-litecontrol"
    fi
    if [[ -f "${OLS_CONF}" ]]; then
        cp -a "${OLS_CONF}" "${SNAPSHOT_DIR}/httpd_config.conf.bak"
    fi
    if [[ -f "${LC_PANEL_DIR}/.env" ]]; then
        cp -a "${LC_PANEL_DIR}/.env" "${SNAPSHOT_DIR}/panel.env.bak"
    fi

    # Backup LiteControl panel MariaDB database if running
    if command -v mysqldump >/dev/null 2>&1 && systemctl is-active --quiet mariadb 2>/dev/null; then
        mysqldump --single-transaction --quick litecontrol > "${SNAPSHOT_DIR}/litecontrol_panel_db.sql" 2>/dev/null || true
    fi

    log_ok "Pre-upgrade backup saved to ${SNAPSHOT_DIR}"
}

migrate_ols_panel_to_nginx_if_needed() {
    log_step "02. Verifying Dedicated Nginx + PHP-FPM Out-of-Band Panel Architecture"

    # 1. Ensure nginx and php-fpm packages are installed and stock port-80 services are disabled
    bash "${UPGRADE_SRC_DIR}/scripts/ubuntu/core/05-php.sh"

    # 2. Non-destructively strip legacy panel blocks from OpenLiteSpeed httpd_config.conf
    #    while preserving all customer vhosts, maps, and tuning blocks!
    if [[ -f "${OLS_CONF}" ]] && grep -qE 'listener panel|virtualhost litecontrol|extprocessor litecontrol_php' "${OLS_CONF}"; then
        log_info "Detected legacy OpenLiteSpeed panel listener in ${OLS_CONF}. Migrating without touching customer vhosts..."
        ols_backup_config

        awk '
            /^[[:space:]]*(extprocessor[[:space:]]+litecontrol_php|virtualhost[[:space:]]+litecontrol|listener[[:space:]]+panel)[[:space:]]*\{/ {
                skip = 1
                depth = 1
                next
            }
            skip {
                if ($0 ~ /\{/) depth++
                if ($0 ~ /\}/) {
                    depth--
                    if (depth <= 0) skip = 0
                }
                next
            }
            /^# Isolated Panel PHP Pool/ || /^# LiteControl Panel VirtualHost/ || /^# LiteControl Panel HTTPS Listener/ {
                next
            }
            { print }
        ' "${OLS_CONF}" > "${OLS_CONF}.tmp" && mv "${OLS_CONF}.tmp" "${OLS_CONF}"

        rm -rf "${OLS_VHOSTS_DIR}/litecontrol"

        if ! ols_safe_reload; then
            log_error "Failed to reload OpenLiteSpeed during panel decoupling; rolled back."
            return 1
        fi
        log_ok "OpenLiteSpeed decoupled from panel port; all customer virtual hosts preserved."
    fi
}

sync_core_files_and_sudoers() {
    log_step "03. Updating LiteControl CLI, Scripts, Templates & Sudoers"

    rsync -a --delete "${UPGRADE_SRC_DIR}/bin/" "${LC_BASE_DIR}/bin/"
    rsync -a --delete "${UPGRADE_SRC_DIR}/scripts/" "${LC_BASE_DIR}/scripts/"
    rsync -a --delete "${UPGRADE_SRC_DIR}/templates/" "${LC_BASE_DIR}/templates/"
    if [[ -f "${UPGRADE_SRC_DIR}/VERSION" ]]; then
        cp "${UPGRADE_SRC_DIR}/VERSION" "${LC_BASE_DIR}/VERSION"
        sed -i 's/\r$//' "${LC_BASE_DIR}/VERSION"
    fi
    cp "${UPGRADE_SRC_DIR}/litecontrol_upgrade.sh" "${LC_BASE_DIR}/litecontrol_upgrade.sh"
    chmod 750 "${LC_BASE_DIR}/litecontrol_upgrade.sh"

    find "${LC_BASE_DIR}/bin" "${LC_BASE_DIR}/scripts" "${LC_BASE_DIR}/templates" \
        -type f ! -name "*.phar" -exec sed -i 's/\r$//' {} + 2>/dev/null || true

    chown -R root:root "${LC_BASE_DIR}/bin" "${LC_BASE_DIR}/scripts" "${LC_BASE_DIR}/templates"
    chmod 750 "${LC_BASE_DIR}/bin/lc-cli"
    find "${LC_BASE_DIR}/scripts" -type f -name "*.sh" -exec chmod 750 {} +
    ln -sf "${LC_BASE_DIR}/bin/lc-cli" /usr/local/bin/lc-cli

    # Update sudoers rule safely
    local sudoers_tpl="${LC_BASE_DIR}/templates/system/sudoers.litecontrol"
    local sudoers_dest="/etc/sudoers.d/litecontrol"
    cp "${sudoers_tpl}" "${sudoers_dest}.tmp"
    sed -i 's/\r$//' "${sudoers_dest}.tmp"
    chmod 440 "${sudoers_dest}.tmp"
    chown root:root "${sudoers_dest}.tmp"
    if visudo -cf "${sudoers_dest}.tmp" >/dev/null 2>&1; then
        mv "${sudoers_dest}.tmp" "${sudoers_dest}"
    else
        rm -f "${sudoers_dest}.tmp"
        log_error "Invalid sudoers template syntax; kept existing /etc/sudoers.d/litecontrol."
        return 1
    fi

    # Update MOTD
    if [[ -f "${LC_BASE_DIR}/templates/system/motd-litecontrol.sh" ]]; then
        cp "${LC_BASE_DIR}/templates/system/motd-litecontrol.sh" /etc/profile.d/litecontrol.sh
        sed -i 's/\r$//' /etc/profile.d/litecontrol.sh
        chmod 755 /etc/profile.d/litecontrol.sh
    fi

    log_ok "CLI engine, scripts, and templates updated."
}

ensure_nodejs_for_frontend_build() {
    if command -v npm >/dev/null 2>&1 && command -v node >/dev/null 2>&1; then
        return 0
    fi
    log_info "Node.js / npm not found. Installing Node.js 20.x LTS for Vite frontend compilation..."
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash - >/dev/null 2>&1 || true
    apt_install nodejs
}

upgrade_laravel_panel() {
    log_step "04. Updating Laravel Panel Application, Dependencies & Database Migrations"

    if [[ ! -d "${UPGRADE_SRC_DIR}/panel" ]]; then
        log_warn "No panel/ directory found in source; skipping Laravel upgrade."
        return 0
    fi

    if [[ -f "${LC_PANEL_DIR}/artisan" ]]; then
        su -s /bin/bash "${LC_USER}" -c "cd '${LC_PANEL_DIR}' && /usr/local/bin/php artisan down --retry=15" 2>/dev/null || true
    fi

    # Sync panel code while preserving .env and storage/
    rsync -a --delete \
        --exclude='.env' \
        --exclude='storage/' \
        --exclude='node_modules/' \
        --exclude='vendor/' \
        "${UPGRADE_SRC_DIR}/panel/" "${LC_PANEL_DIR}/"

    # Restore .env if missing
    if [[ ! -f "${LC_PANEL_DIR}/.env" && -f "${SNAPSHOT_DIR:-}/panel.env.bak" ]]; then
        cp -a "${SNAPSHOT_DIR}/panel.env.bak" "${LC_PANEL_DIR}/.env"
    fi

    mkdir -p "${LC_BASE_DIR}/.config" "${LC_BASE_DIR}/.cache"
    chown -R "${LC_USER}:${LC_GROUP}" "${LC_BASE_DIR}/.config" "${LC_BASE_DIR}/.cache" "${LC_PANEL_DIR}"

    if [[ -f "${LC_PANEL_DIR}/artisan" ]]; then
        log_info "Installing Composer dependencies..."
        local composer_bin="/usr/local/bin/composer"
        if [[ -f "${LC_BASE_DIR}/bin/composer.phar" ]]; then
            composer_bin="/usr/local/bin/php ${LC_BASE_DIR}/bin/composer.phar"
        fi
        su -s /bin/bash "${LC_USER}" -c "cd '${LC_PANEL_DIR}' && ${composer_bin} install --no-dev -o --no-interaction"

        # Build frontend assets if package.json exists and build is explicitly requested or prebuilt assets are missing
        if [[ -f "${LC_PANEL_DIR}/package.json" ]]; then
            if [[ "${BUILD_FRONTEND}" -eq 1 || ! -f "${LC_PANEL_DIR}/public/build/manifest.json" ]]; then
                ensure_nodejs_for_frontend_build
                if command -v npm >/dev/null 2>&1; then
                    log_info "Building Vue 3 + Vite frontend assets..."
                    su -s /bin/bash "${LC_USER}" -c "cd '${LC_PANEL_DIR}' && npm install --no-audit --no-fund && npm run build"
                    rm -rf "${LC_PANEL_DIR}/node_modules"
                fi
            else
                log_info "Pre-built frontend assets detected; skipping on-server npm build."
            fi
        fi

        log_info "Running database migrations and refreshing caches..."
        su -s /bin/bash "${LC_USER}" -c "cd '${LC_PANEL_DIR}' && /usr/local/bin/php artisan migrate --force"
        su -s /bin/bash "${LC_USER}" -c "cd '${LC_PANEL_DIR}' && /usr/local/bin/php artisan optimize:clear"
        su -s /bin/bash "${LC_USER}" -c "cd '${LC_PANEL_DIR}' && /usr/local/bin/php artisan config:cache"
        su -s /bin/bash "${LC_USER}" -c "cd '${LC_PANEL_DIR}' && /usr/local/bin/php artisan route:cache"
        su -s /bin/bash "${LC_USER}" -c "cd '${LC_PANEL_DIR}' && /usr/local/bin/php artisan view:cache"
        su -s /bin/bash "${LC_USER}" -c "cd '${LC_PANEL_DIR}' && /usr/local/bin/php artisan queue:restart" 2>/dev/null || true
        su -s /bin/bash "${LC_USER}" -c "cd '${LC_PANEL_DIR}' && /usr/local/bin/php artisan up" 2>/dev/null || true
    fi

    chown -R "${LC_USER}:${LC_GROUP}" "${LC_PANEL_DIR}"
    chmod -R 755 "${LC_PANEL_DIR}"
    if [[ -d "${LC_PANEL_DIR}/storage" ]]; then
        chmod -R 775 "${LC_PANEL_DIR}/storage" "${LC_PANEL_DIR}/bootstrap/cache"
    fi

    log_ok "Laravel panel application updated and migrated."
}

reconcile_panel_services() {
    local panel_port
    panel_port=$(get_panel_port)
    log_step "05. Reconciling Panel Nginx, PHP-FPM & Worker Services (Port ${panel_port})"

    mkdir -p "${PANEL_NGINX_DIR}" "${PANEL_FPM_DIR}"
    mkdir -p /tmp/litecontrol-nginx-body /tmp/litecontrol-nginx-proxy /tmp/litecontrol-nginx-fastcgi /tmp/litecontrol-nginx-uwsgi /tmp/litecontrol-nginx-scgi
    chown -R "${LC_USER}:${LC_GROUP}" /tmp/litecontrol-nginx-* 2>/dev/null || true

    cp "${LC_BASE_DIR}/templates/panel/fastcgi_params.tpl" "${PANEL_NGINX_DIR}/fastcgi_params"
    sed "s/__PANEL_PORT__/${panel_port}/g" "${LC_BASE_DIR}/templates/panel/nginx.conf.tpl" > "${PANEL_NGINX_CONF}"
    panel_backup_config

    cp "${LC_BASE_DIR}/templates/panel/php-fpm.conf.tpl" "${PANEL_FPM_CONF}"

    local php_fpm_bin
    php_fpm_bin=$(command -v php-fpm"${LC_PHP_VER:0:1}.${LC_PHP_VER:1:1}" 2>/dev/null || command -v php-fpm 2>/dev/null || echo "/usr/sbin/php-fpm")

    sed "s|__PHP_FPM_BIN__|${php_fpm_bin}|g" "${LC_BASE_DIR}/templates/system/litecontrol-php.service" > /etc/systemd/system/litecontrol-php.service
    cp "${LC_BASE_DIR}/templates/system/litecontrol-panel.service" /etc/systemd/system/litecontrol-panel.service

    if [[ -f "${LC_BASE_DIR}/templates/system/litecontrol-worker.service" ]]; then
        sed "s/__PHP_VER__/${LC_PHP_VER}/g" "${LC_BASE_DIR}/templates/system/litecontrol-worker.service" > /etc/systemd/system/litecontrol-worker.service
    fi

    systemctl daemon-reload
    systemctl enable litecontrol-php.service litecontrol-panel.service litecontrol-worker.service >/dev/null 2>&1 || true
    systemctl restart litecontrol-php.service || true
    systemctl restart litecontrol-worker.service || true

    if ! panel_safe_reload; then
        log_error "Panel Nginx failed to reload during upgrade; rolled back."
        return 1
    fi

    # Record installed version
    local new_version="${TARGET_REF#v}"
    if [[ -f "${LC_BASE_DIR}/VERSION" ]]; then
        new_version=$(tr -d ' \t\r\n' < "${LC_BASE_DIR}/VERSION")
    fi
    printf "%s" "${new_version}" > "${LC_CONF_DIR}/version"
    printf "%s" "${TARGET_REF}" > "${LC_CONF_DIR}/git_ref"
    chmod 644 "${LC_CONF_DIR}/version" "${LC_CONF_DIR}/git_ref"

    log_ok "LiteControl upgraded to ${new_version} (${TARGET_REF}) and all services are active."
}

main() {
    parse_args "$@"

    if [[ "$(id -u)" -ne 0 && "${LC_TEST_MODE:-0}" != "1" ]]; then
        printf "%s[ERROR]%s Root privileges are required. Run with sudo.\n" "${C_RED}" "${C_RESET}" >&2
        exit 1
    fi

    resolve_target_ref
    fetch_target_source

    # Source newly fetched common.sh library
    # shellcheck source=scripts/ubuntu/lib/common.sh
    source "${UPGRADE_SRC_DIR}/scripts/ubuntu/lib/common.sh"

    detect_ubuntu_os
    init_litecontrol_dirs

    local old_ver="unknown"
    if [[ -f "${LC_CONF_DIR}/version" ]]; then
        old_ver=$(tr -d ' \t\r\n' < "${LC_CONF_DIR}/version")
    fi

    log_info "Starting LiteControl upgrade (Current: ${old_ver} -> Target: ${TARGET_REF})..."

    create_pre_upgrade_backup
    migrate_ols_panel_to_nginx_if_needed
    sync_core_files_and_sudoers
    upgrade_laravel_panel
    reconcile_panel_services

    local panel_port
    panel_port=$(get_panel_port)
    local srv_ip
    srv_ip=$(get_server_ip)
    local final_ver
    final_ver=$(cat "${LC_CONF_DIR}/version" 2>/dev/null || echo "${TARGET_REF}")

    printf "\n%s======================================================================%s\n" "${C_GREEN}${C_BOLD}" "${C_RESET}"
    printf "%s  LiteControl Upgrade Completed Successfully!%s\n" "${C_GREEN}${C_BOLD}" "${C_RESET}"
    printf "%s======================================================================%s\n" "${C_GREEN}${C_BOLD}" "${C_RESET}"
    printf "  %-22s : %s (%s)\n" "Upgraded Version" "${final_ver}" "${TARGET_REF}"
    printf "  %-22s : https://%s:%s\n" "Panel URL" "${srv_ip}" "${panel_port}"
    printf "  %-22s : %s\n" "Backup Snapshot" "${SNAPSHOT_DIR}"
    printf "%s======================================================================%s\n" "${C_GREEN}${C_BOLD}" "${C_RESET}"
}

if [[ "${BASH_SOURCE[0]:-$0}" == "${0}" ]]; then
    main "$@"
fi

