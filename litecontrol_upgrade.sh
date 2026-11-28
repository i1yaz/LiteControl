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
GITHUB_REPO_SLUG="${GITHUB_REPO_SLUG:-$(printf '%s' "${GITHUB_REPO_URL}" | sed -E 's#^https?://github\.com/##; s#\.git$##')}"
UPGRADE_SRC_DIR="${UPGRADE_SRC_DIR:-}"
UPGRADE_SRC_IS_TEMP=0
LC_BASE_DIR="${LC_BASE_DIR:-/usr/local/litecontrol}"
LC_CONF_DIR="${LC_CONF_DIR:-/etc/litecontrol}"
LC_BACKUP_ROOT="${LC_BACKUP_ROOT:-/var/backups/litecontrol}"

TARGET_REF=""
TARGET_IS_TAG=0
NON_INTERACTIVE=0
BUILD_FRONTEND=1

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
      --build-frontend   Compile Vue 3/Vite frontend assets on host machine (default)
      --skip-frontend    Skip npm frontend build
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
    # Query latest release tag from GitHub Releases API (no git required), falling back to git ls-remote then 'main'
    local latest_tag=""
    if command -v curl >/dev/null 2>&1; then
        latest_tag=$(curl -fsSL "https://api.github.com/repos/${GITHUB_REPO_SLUG}/releases/latest" 2>/dev/null \
            | sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n 1 || true)
    fi
    if [[ -z "${latest_tag}" ]] && command -v git >/dev/null 2>&1; then
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

cleanup_upgrade_src() {
    if [[ "${UPGRADE_SRC_IS_TEMP}" -eq 1 && -n "${UPGRADE_SRC_DIR}" && -d "${UPGRADE_SRC_DIR}" ]]; then
        rm -rf "${UPGRADE_SRC_DIR}"
    fi
}

fetch_target_source() {
    printf "\n%s==> Fetching LiteControl source (%s)...%s\n" "${C_BOLD}${C_BLUE}" "${TARGET_REF}" "${C_RESET}"

    if ! command -v curl >/dev/null 2>&1 || ! command -v rsync >/dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get update -qq
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq curl rsync tar
    fi

    if [[ -z "${UPGRADE_SRC_DIR}" ]]; then
        UPGRADE_SRC_DIR="$(mktemp -d /tmp/litecontrol-upgrade.XXXXXX)"
        UPGRADE_SRC_IS_TEMP=1
        trap cleanup_upgrade_src EXIT
    else
        mkdir -p "${UPGRADE_SRC_DIR}"
    fi

    local archive_url=""
    local downloaded=0

    # 1. Try tag archive first (production default — no .git history on disk)
    for tag_candidate in "${TARGET_REF}" "v${TARGET_REF#v}"; do
        archive_url="https://github.com/${GITHUB_REPO_SLUG}/archive/refs/tags/${tag_candidate}.tar.gz"
        if curl -fsSL "${archive_url}" | tar -xz --strip-components=1 -C "${UPGRADE_SRC_DIR}" 2>/dev/null; then
            TARGET_REF="${tag_candidate}"
            TARGET_IS_TAG=1
            downloaded=1
            break
        fi
        rm -rf "${UPGRADE_SRC_DIR:?}"/*
    done

    # 2. Try branch archive (dev/testing — still no .git history on disk)
    if [[ "${downloaded}" -eq 0 ]]; then
        archive_url="https://github.com/${GITHUB_REPO_SLUG}/archive/refs/heads/${TARGET_REF}.tar.gz"
        if curl -fsSL "${archive_url}" | tar -xz --strip-components=1 -C "${UPGRADE_SRC_DIR}" 2>/dev/null; then
            TARGET_IS_TAG=0
            downloaded=1
        else
            rm -rf "${UPGRADE_SRC_DIR:?}"/*
        fi
    fi

    # 3. Fallback to shallow git clone (e.g., private repo or commit SHA)
    if [[ "${downloaded}" -eq 0 ]]; then
        if ! command -v git >/dev/null 2>&1; then
            DEBIAN_FRONTEND=noninteractive apt-get update -qq
            DEBIAN_FRONTEND=noninteractive apt-get install -y -qq git
        fi
        if git clone --depth 1 --branch "${TARGET_REF}" --quiet "${GITHUB_REPO_URL}" "${UPGRADE_SRC_DIR}" 2>/dev/null; then
            rm -rf "${UPGRADE_SRC_DIR}/.git"
            downloaded=1
        fi
    fi

    if [[ "${downloaded}" -eq 0 ]]; then
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

    # 1. Ensure panel nginx and php-fpm packages are installed and stock port-80 services are disabled
    bash "${UPGRADE_SRC_DIR}/scripts/ubuntu/core/05-php.sh" --panel-only

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

    rsync -a --delete --exclude='lc-cli-go' "${UPGRADE_SRC_DIR}/bin/" "${LC_BASE_DIR}/bin/"
    rsync -a --delete "${UPGRADE_SRC_DIR}/scripts/" "${LC_BASE_DIR}/scripts/"
    rsync -a --delete "${UPGRADE_SRC_DIR}/templates/" "${LC_BASE_DIR}/templates/"
    if [[ -d "${UPGRADE_SRC_DIR}/cli" ]]; then
        rsync -a --delete "${UPGRADE_SRC_DIR}/cli/" "${LC_BASE_DIR}/cli/"
    fi
    if [[ -f "${UPGRADE_SRC_DIR}/VERSION" ]]; then
        cp "${UPGRADE_SRC_DIR}/VERSION" "${LC_BASE_DIR}/VERSION"
        sed -i 's/\r$//' "${LC_BASE_DIR}/VERSION"
    fi
    cp "${UPGRADE_SRC_DIR}/litecontrol_upgrade.sh" "${LC_BASE_DIR}/litecontrol_upgrade.sh"
    chmod 750 "${LC_BASE_DIR}/litecontrol_upgrade.sh"

    chown -R root:root "${LC_BASE_DIR}/bin" "${LC_BASE_DIR}/scripts" "${LC_BASE_DIR}/templates"
    if [[ -d "${LC_BASE_DIR}/cli" ]]; then
        chown -R root:root "${LC_BASE_DIR}/cli"
    fi
    chmod 750 "${LC_BASE_DIR}/bin/lc-cli"
    find "${LC_BASE_DIR}/scripts" -type f -name "*.sh" -exec chmod 750 {} +
    ln -sf "${LC_BASE_DIR}/bin/lc-cli" /usr/local/bin/lc-cli

    # Build/refresh Go CLI binary (`lc-cli-go`); install golang-go if missing and cli/ is present
    local go_bin=""
    go_bin=$(command -v go 2>/dev/null || true)
    if [[ -z "${go_bin}" && -x /usr/local/go/bin/go ]]; then
        go_bin="/usr/local/go/bin/go"
    fi
    if [[ -z "${go_bin}" && -d "${LC_BASE_DIR}/cli" ]] && command -v apt-get >/dev/null 2>&1; then
        log_info "Installing golang-go to build LiteControl Go CLI binary (lc-cli-go)..."
        DEBIAN_FRONTEND=noninteractive apt-get update -qq >/dev/null 2>&1 || true
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq golang-go >/dev/null 2>&1 || true
        go_bin=$(command -v go 2>/dev/null || true)
    fi
    if [[ -n "${go_bin}" && -d "${LC_BASE_DIR}/cli" ]]; then
        mkdir -p /var/log/litecontrol 2>/dev/null || true
        if (cd "${LC_BASE_DIR}/cli" && CGO_ENABLED=0 "${go_bin}" build -ldflags="-s -w" -o "${LC_BASE_DIR}/bin/lc-cli-go.tmp" .) >> /var/log/litecontrol/panel-error.log 2>&1; then
            chmod 750 "${LC_BASE_DIR}/bin/lc-cli-go.tmp"
            mv -f "${LC_BASE_DIR}/bin/lc-cli-go.tmp" "${LC_BASE_DIR}/bin/lc-cli-go"
        else
            log_warn "Failed to build lc-cli-go; see /var/log/litecontrol/panel-error.log"
            rm -f "${LC_BASE_DIR}/bin/lc-cli-go.tmp" 2>/dev/null || true
        fi
    fi

    # Install per-user PHP CLI setter and Go php-shim (prebuilt release binary preferred, source build fallback)
    install -d -m 0755 -o root -g root /usr/local/lib/lc-panel
    if [[ -f "${LC_BASE_DIR}/scripts/set-user-php-cli.sh" ]]; then
        install -m 0750 -o root -g root "${LC_BASE_DIR}/scripts/set-user-php-cli.sh" /usr/local/lib/lc-panel/set-user-php-cli.sh
    fi
    if [[ -f "${LC_BASE_DIR}/scripts/bootstrap-lc-php-shim.sh" ]]; then
        local shim_arch=""
        case "$(uname -m)" in
            x86_64|amd64) shim_arch="amd64" ;;
            aarch64|arm64) shim_arch="arm64" ;;
        esac

        local prebuilt_shim=""
        if [[ "${TARGET_IS_TAG}" -eq 1 && -n "${shim_arch}" ]]; then
            local asset_name="php-shim-linux-${shim_arch}"
            local release_base="https://github.com/${GITHUB_REPO_SLUG}/releases/download/${TARGET_REF}"
            local tmp_bin="${UPGRADE_SRC_DIR}/${asset_name}"
            local tmp_sums="${UPGRADE_SRC_DIR}/checksums.txt"

            if curl -fsSL "${release_base}/${asset_name}" -o "${tmp_bin}" 2>/dev/null \
                && curl -fsSL "${release_base}/checksums.txt" -o "${tmp_sums}" 2>/dev/null; then
                if (cd "${UPGRADE_SRC_DIR}" && grep -E " ${asset_name}$" checksums.txt | sha256sum -c - >/dev/null 2>&1); then
                    prebuilt_shim="${tmp_bin}"
                    log_info "Verified pre-built ${asset_name} from release ${TARGET_REF}."
                else
                    log_warn "Checksum verification failed for ${asset_name}; skipping pre-built binary."
                    rm -f "${tmp_bin}" "${tmp_sums}"
                fi
            fi
        fi

        if [[ -n "${prebuilt_shim}" ]]; then
            SHIM_PREBUILT_BIN="${prebuilt_shim}" bash "${LC_BASE_DIR}/scripts/bootstrap-lc-php-shim.sh"
        elif command -v go >/dev/null 2>&1 && [[ -f "${UPGRADE_SRC_DIR}/shim/go.mod" ]]; then
            SHIM_SRC_DIR="${UPGRADE_SRC_DIR}/shim" bash "${LC_BASE_DIR}/scripts/bootstrap-lc-php-shim.sh"
        fi
    fi

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

    # Fallback shell PHP CLI shim only if the Go php-shim binary is not installed
    if [[ ! -x /usr/local/lib/lc-panel/php-shim && -f "${LC_BASE_DIR}/templates/system/php-cli-shim.sh" ]]; then
        rm -f /usr/local/bin/php
        cp "${LC_BASE_DIR}/templates/system/php-cli-shim.sh" /usr/local/bin/php
        sed -i 's/\r$//' /usr/local/bin/php
        chmod 0755 /usr/local/bin/php
    fi

    log_ok "CLI engine, scripts, and templates updated."
}

ensure_panel_composer() {
    local composer_bin="${LC_BASE_DIR}/bin/composer.phar"
    local php_bin
    php_bin=$(get_panel_php_bin)

    local system_composer
    system_composer="$(command -v composer 2>/dev/null || true)"
    if [[ -n "${system_composer}" ]] && COMPOSER_ALLOW_SUPERUSER=1 "${php_bin}" "${system_composer}" --version --no-interaction </dev/null >/dev/null 2>&1; then
        log_info "Using system Composer at ${system_composer}."
        return 0
    fi

    if [[ -x "${composer_bin}" && -f "${composer_bin}" ]]; then
        if COMPOSER_ALLOW_SUPERUSER=1 "${php_bin}" "${composer_bin}" --version --no-interaction </dev/null >/dev/null 2>&1; then
            log_info "Reusing isolated Composer at ${composer_bin}."
            return 0
        fi
        log_warn "Cached composer.phar failed verification; re-downloading official release..."
        rm -f "${composer_bin}"
    fi

    log_info "Fetching verified Composer official phar..."
    mkdir -p "${LC_BASE_DIR}/bin"
    if curl -fsSL --connect-timeout 15 --max-time 30 -o "${composer_bin}" https://getcomposer.org/download/latest-stable/composer.phar; then
        chmod 755 "${composer_bin}"
        if COMPOSER_ALLOW_SUPERUSER=1 "${php_bin}" "${composer_bin}" --version --no-interaction </dev/null >/dev/null 2>&1; then
            log_ok "Verified Composer phar installed at ${composer_bin}."
            return 0
        fi
    fi

    # Fallback if download failed or curl unavailable
    if [[ -n "${system_composer}" ]]; then
        log_warn "Using system composer fallback."
        return 0
    fi
}

upgrade_laravel_panel() {
    log_step "04. Updating Laravel Panel Application, Dependencies & Database Migrations"

    if [[ ! -d "${UPGRADE_SRC_DIR}/panel" ]]; then
        log_warn "No panel/ directory found in source; skipping Laravel upgrade."
        return 0
    fi

    local php_bin
    php_bin=$(get_panel_php_bin)

    if [[ -f "${LC_PANEL_DIR}/artisan" ]]; then
        su -s /bin/bash "${LC_USER}" -c "cd '${LC_PANEL_DIR}' && ${php_bin} artisan down --retry=15" 2>/dev/null || true
    fi

    # Sync panel code while preserving .env and storage/
    rsync -a --delete \
        --exclude='.env' \
        --exclude='storage/' \
        --exclude='node_modules/' \
        --exclude='vendor/' \
        --exclude='.node-bin/' \
        "${UPGRADE_SRC_DIR}/panel/" "${LC_PANEL_DIR}/"

    # Restore .env if missing
    if [[ ! -f "${LC_PANEL_DIR}/.env" && -f "${SNAPSHOT_DIR:-}/panel.env.bak" ]]; then
        cp -a "${SNAPSHOT_DIR}/panel.env.bak" "${LC_PANEL_DIR}/.env"
    fi

    mkdir -p "${LC_BASE_DIR}/.config/composer" "${LC_BASE_DIR}/.cache/composer"
    chown -R "${LC_USER}:${LC_GROUP}" "${LC_BASE_DIR}/.config" "${LC_BASE_DIR}/.cache" "${LC_PANEL_DIR}"

    if [[ -f "${LC_PANEL_DIR}/artisan" ]]; then
        ensure_panel_composer
        log_info "Installing Composer dependencies..."
        local composer_bin=""
        local system_composer
        system_composer="$(command -v composer 2>/dev/null || true)"
        if [[ -n "${system_composer}" ]] && COMPOSER_ALLOW_SUPERUSER=1 "${php_bin}" "${system_composer}" --version --no-interaction </dev/null >/dev/null 2>&1; then
            composer_bin="${php_bin} -d memory_limit=-1 ${system_composer}"
        elif [[ -f "${LC_BASE_DIR}/bin/composer.phar" ]]; then
            composer_bin="${php_bin} -d memory_limit=-1 ${LC_BASE_DIR}/bin/composer.phar"
        else
            composer_bin="/usr/local/bin/composer"
        fi
        local composer_env="export HOME='${LC_BASE_DIR}' COMPOSER_HOME='${LC_BASE_DIR}/.config/composer' COMPOSER_CACHE_DIR='${LC_BASE_DIR}/.cache/composer' COMPOSER_MEMORY_LIMIT=-1 GIT_TERMINAL_PROMPT=0;"
        su -s /bin/bash "${LC_USER}" -c "${composer_env} cd '${LC_PANEL_DIR}' && ${composer_bin} install --no-dev -o --no-interaction --no-progress"

        mkdir -p "${LC_BASE_DIR}/.config/composer" "${LC_BASE_DIR}/.cache/composer" "${LC_BASE_DIR}/.npm" "${LC_BASE_DIR}/.node-bin"
        chown -R "${LC_USER}:${LC_GROUP}" "${LC_BASE_DIR}/.config" "${LC_BASE_DIR}/.cache" "${LC_BASE_DIR}/.npm" "${LC_BASE_DIR}/.node-bin" 2>/dev/null || true

        # Build frontend assets if package.json exists
        if [[ -f "${LC_PANEL_DIR}/package.json" ]]; then
            if [[ "${BUILD_FRONTEND}" -eq 1 || ! -f "${LC_PANEL_DIR}/public/build/manifest.json" ]]; then
                log_info "Building Vue 3 + Vite frontend assets using isolated Node 24 runtime..."
                rm -rf "${LC_PANEL_DIR}/public/build"
                su -s /bin/bash "${LC_USER}" -c "cd '${LC_PANEL_DIR}' && ${php_bin} scripts/isolated-build.php"
                rm -rf "${LC_PANEL_DIR}/node_modules"
            else
                log_info "Frontend build skipped (--skip-frontend specified)."
            fi
        fi

        if [[ -s "${LC_CONF_DIR}/panel_domain" && -f "${LC_PANEL_DIR}/.env" ]]; then
            local saved_domain saved_port
            saved_domain=$(tr -d ' \t\r\n' < "${LC_CONF_DIR}/panel_domain")
            saved_port=$(get_panel_port)
            if [[ -n "${saved_domain}" ]]; then
                if grep -qE '^APP_URL=' "${LC_PANEL_DIR}/.env"; then
                    sed -i -E "s|^APP_URL=.*|APP_URL=https://${saved_domain}:${saved_port}|" "${LC_PANEL_DIR}/.env"
                else
                    printf "\nAPP_URL=https://%s:%s\n" "${saved_domain}" "${saved_port}" >> "${LC_PANEL_DIR}/.env"
                fi
            fi
        fi

        log_info "Running database migrations and refreshing caches..."
        su -s /bin/bash "${LC_USER}" -c "cd '${LC_PANEL_DIR}' && ${php_bin} artisan migrate --force"
        su -s /bin/bash "${LC_USER}" -c "cd '${LC_PANEL_DIR}' && ${php_bin} artisan optimize:clear"
        su -s /bin/bash "${LC_USER}" -c "cd '${LC_PANEL_DIR}' && ${php_bin} artisan config:cache"
        su -s /bin/bash "${LC_USER}" -c "cd '${LC_PANEL_DIR}' && ${php_bin} artisan route:cache"
        su -s /bin/bash "${LC_USER}" -c "cd '${LC_PANEL_DIR}' && ${php_bin} artisan view:cache"
        su -s /bin/bash "${LC_USER}" -c "cd '${LC_PANEL_DIR}' && ${php_bin} artisan queue:restart" 2>/dev/null || true
        su -s /bin/bash "${LC_USER}" -c "cd '${LC_PANEL_DIR}' && ${php_bin} artisan up" 2>/dev/null || true
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

    if [[ -d "${LC_CONF_DIR}" ]]; then
        chgrp "${LC_GROUP}" "${LC_CONF_DIR}" 2>/dev/null || true
        chmod 750 "${LC_CONF_DIR}" 2>/dev/null || true
    fi
    if [[ -d "${LC_CONF_DIR}/ssl" ]]; then
        chgrp "${LC_GROUP}" "${LC_CONF_DIR}/ssl" 2>/dev/null || true
        chmod 750 "${LC_CONF_DIR}/ssl" 2>/dev/null || true
        [[ -f "${LC_CONF_DIR}/ssl/fullchain.pem" ]] && chmod 644 "${LC_CONF_DIR}/ssl/fullchain.pem" 2>/dev/null || true
        [[ -f "${LC_CONF_DIR}/ssl/privkey.pem" ]] && chmod 600 "${LC_CONF_DIR}/ssl/privkey.pem" 2>/dev/null || true
    fi

    cp "${LC_BASE_DIR}/templates/panel/fastcgi_params.tpl" "${PANEL_NGINX_DIR}/fastcgi_params"
    sed "s/__PANEL_PORT__/${panel_port}/g" "${LC_BASE_DIR}/templates/panel/nginx.conf.tpl" > "${PANEL_NGINX_CONF}"
    apply_panel_ip_access_config "$(get_panel_ip_access)"
    panel_backup_config

    cp "${LC_BASE_DIR}/templates/panel/php-fpm.conf.tpl" "${PANEL_FPM_CONF}"

    local panel_ver="${LC_PANEL_PHP_VER:-86}"
    local dotted_panel_ver="${panel_ver:0:1}.${panel_ver:1:1}"
    local php_fpm_bin
    php_fpm_bin=$(command -v php-fpm"${dotted_panel_ver}" 2>/dev/null || command -v php-fpm"${LC_PHP_VER:0:1}.${LC_PHP_VER:1:1}" 2>/dev/null || command -v php-fpm 2>/dev/null || echo "/usr/sbin/php-fpm")

    sed "s|__PHP_FPM_BIN__|${php_fpm_bin}|g" "${LC_BASE_DIR}/templates/system/litecontrol-php.service" > /etc/systemd/system/litecontrol-php.service
    cp "${LC_BASE_DIR}/templates/system/litecontrol-panel.service" /etc/systemd/system/litecontrol-panel.service

    if [[ -f "${LC_BASE_DIR}/templates/system/litecontrol-worker.service" ]]; then
        local panel_php_bin
        panel_php_bin=$(get_panel_php_bin)
        sed "s|__PANEL_PHP_BIN__|${panel_php_bin}|g" "${LC_BASE_DIR}/templates/system/litecontrol-worker.service" > /etc/systemd/system/litecontrol-worker.service
    fi

    systemctl daemon-reload
    systemctl enable litecontrol-php.service litecontrol-panel.service litecontrol-worker.service >/dev/null 2>&1 || true
    systemctl restart litecontrol-php.service || true
    systemctl restart litecontrol-worker.service || true

    if ! panel_safe_reload; then
        log_error "Panel Nginx failed to reload during upgrade; rolled back."
        return 1
    fi

    # Ensure permissions for all existing user home and vhost directories so panel worker can manage files
    # (Top-level doorways only - NEVER touches or overwrites inner customer files)
    if command -v setfacl >/dev/null 2>&1; then
        for u_dir in "${LC_HOME_ROOT}"/*; do
            if [[ -d "${u_dir}" ]]; then
                chmod 711 "${u_dir}" 2>/dev/null || true
                setfacl -m u:nobody:x "${u_dir}" 2>/dev/null || true
                setfacl -m u:"${LC_USER}":r-x "${u_dir}" 2>/dev/null || true
                setfacl -d -m u:"${LC_USER}":r-x "${u_dir}" 2>/dev/null || true

                # Check inside each domain directory
                for vh_dir in "${u_dir}"/*; do
                    if [[ -d "${vh_dir}" && "$(basename "${vh_dir}")" != "logs" && "$(basename "${vh_dir}")" != "backups" && "$(basename "${vh_dir}")" != "tmp" ]]; then
                        chmod 755 "${vh_dir}" 2>/dev/null || true
                        setfacl -m u:nobody:x "${vh_dir}" 2>/dev/null || true
                        setfacl -m u:"${LC_USER}":r-x "${vh_dir}" 2>/dev/null || true
                        setfacl -d -m u:"${LC_USER}":r-x "${vh_dir}" 2>/dev/null || true

                        if [[ -d "${vh_dir}/public_html" ]]; then
                            chmod 755 "${vh_dir}/public_html" 2>/dev/null || true
                            setfacl -m u:nobody:r-x "${vh_dir}/public_html" 2>/dev/null || true
                            setfacl -m u:"${LC_USER}":rwX "${vh_dir}/public_html" 2>/dev/null || true
                            setfacl -d -m u:"${LC_USER}":rwX "${vh_dir}/public_html" 2>/dev/null || true
                            setfacl -m m::r-x,d:m::r-x "${vh_dir}/public_html" 2>/dev/null || true
                        fi
                    fi
                done
            fi
        done
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

    old_ver="unknown"
    if [[ -f "${LC_CONF_DIR}/version" ]]; then
        old_ver=$(tr -d ' \t\r\n' < "${LC_CONF_DIR}/version")
    fi

    log_info "Starting LiteControl upgrade (Current: ${old_ver} -> Target: ${TARGET_REF})..."

    create_pre_upgrade_backup
    migrate_ols_panel_to_nginx_if_needed
    sync_core_files_and_sudoers
    upgrade_laravel_panel
    reconcile_panel_services
    verify_openlitespeed_health

    panel_port=$(get_panel_port)
    srv_ip=$(get_server_ip)
    final_ver=$(cat "${LC_CONF_DIR}/version" 2>/dev/null || echo "${TARGET_REF}")

    printf "\n%s======================================================================%s\n" "${C_GREEN}${C_BOLD}" "${C_RESET}"
    printf "%s  LiteControl Upgrade Completed Successfully!%s\n" "${C_GREEN}${C_BOLD}" "${C_RESET}"
    printf "%s======================================================================%s\n" "${C_GREEN}${C_BOLD}" "${C_RESET}"
    printf "  %-22s : %s (%s)\n" "Upgraded Version" "${final_ver}" "${TARGET_REF}"
    printf "  %-22s : https://%s:%s\n" "Panel URL" "${srv_ip}" "${panel_port}"
    printf "  %-22s : %s\n" "Backup Snapshot" "${SNAPSHOT_DIR}"
    printf "%s======================================================================%s\n" "${C_GREEN}${C_BOLD}" "${C_RESET}"
}

{
    if [[ "${BASH_SOURCE[0]:-$0}" == "${0}" ]]; then
        main "$@"
    fi
    exit $?
}

