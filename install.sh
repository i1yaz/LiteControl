#!/usr/bin/env bash
# ==============================================================================
# LiteControl - Modular OpenLiteSpeed Control Panel Installer (Ubuntu LTS)
#
# Core Stack Installed by Default:
#   - OpenLiteSpeed Web Server (hosted websites) + Default Website LSPHP (lsphp83)
#   - Isolated Panel Nginx & PHP-FPM (out-of-band panel daemon) + Composer
#   - MariaDB Server (hardened)
#   - Redis Server (local cache/queue/session store)
#   - phpMyAdmin (served under Panel listener /phpmyadmin/)
#   - acme.sh (Let's Encrypt SSL) & UFW Firewall
#
# Optional Modules (NOT installed unless requested via flags or Panel UI):
#   --with-ftp | --with-dns | --with-mail | --with-csf | --with-modsec | --with-imunifyav
# ==============================================================================

set -euo pipefail

# Resolve repository root: support running from local checkout or curl/wget pipe
GITHUB_REPO_URL="${GITHUB_REPO_URL:-https://github.com/i1yaz/LiteControl.git}"
GITHUB_REPO_SLUG="${GITHUB_REPO_SLUG:-$(printf '%s' "${GITHUB_REPO_URL}" | sed -E 's#^https?://github\.com/##; s#\.git$##')}"
INSTALL_REF="${INSTALL_REF:-}"
INSTALL_SRC_IS_TEMP=0

cleanup_install_src() {
    if [[ "${INSTALL_SRC_IS_TEMP}" -eq 1 && -n "${REPO_DIR:-}" && -d "${REPO_DIR}" ]]; then
        rm -rf "${REPO_DIR}"
    fi
}

if [[ -n "${BASH_SOURCE[0]:-}" && -f "${BASH_SOURCE[0]}" ]]; then
    REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
else
    # Executed via curl/wget pipe or bash -c string — use ephemeral temp dir (no .git left on disk)
    echo "==> Downloading LiteControl installer and scripts..."
    if ! command -v curl >/dev/null 2>&1 || ! command -v tar >/dev/null 2>&1; then
        apt-get update -qq && apt-get install -y -qq curl tar
    fi
    REPO_DIR="$(mktemp -d /tmp/litecontrol-install.XXXXXX)"
    INSTALL_SRC_IS_TEMP=1
    trap cleanup_install_src EXIT

    if [[ -z "${INSTALL_REF}" ]]; then
        INSTALL_REF="$(curl -fsSL "https://api.github.com/repos/${GITHUB_REPO_SLUG}/releases/latest" 2>/dev/null \
            | sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n 1 || true)"
    fi

    downloaded=0
    if [[ -n "${INSTALL_REF}" ]]; then
        if curl -fsSL "https://github.com/${GITHUB_REPO_SLUG}/archive/refs/tags/${INSTALL_REF}.tar.gz" \
            | tar -xz --strip-components=1 -C "${REPO_DIR}" 2>/dev/null; then
            downloaded=1
        else
            rm -rf "${REPO_DIR:?}"/*
        fi
    fi

    if [[ "${downloaded}" -eq 0 ]]; then
        branch_ref="${INSTALL_REF:-main}"
        if curl -fsSL "https://github.com/${GITHUB_REPO_SLUG}/archive/refs/heads/${branch_ref}.tar.gz" \
            | tar -xz --strip-components=1 -C "${REPO_DIR}" 2>/dev/null; then
            downloaded=1
        else
            rm -rf "${REPO_DIR:?}"/*
        fi
    fi

    if [[ "${downloaded}" -eq 0 ]]; then
        if ! command -v git >/dev/null 2>&1; then
            apt-get update -qq && apt-get install -y -qq git
        fi
        git clone --depth 1 "${GITHUB_REPO_URL}" "${REPO_DIR}"
        rm -rf "${REPO_DIR}/.git"
    fi
fi

# Strip CRLF in case repository was cloned/copied from Windows with CRLF
find "${REPO_DIR}/scripts" "${REPO_DIR}/bin" "${REPO_DIR}/templates" -type f -exec sed -i 's/\r$//' {} + 2>/dev/null || true

# shellcheck source=scripts/ubuntu/lib/common.sh
source "${REPO_DIR}/scripts/ubuntu/lib/common.sh"

PANEL_PORT="${LC_DEFAULT_PORT}"
RANDOM_PORT=0
OPTIONAL_MODULES=()

usage() {
    cat <<EOF
Usage: sudo bash install.sh [OPTIONS]

Core Options:
  --port <1024-65535>    Set custom LiteControl panel port (default: 8090)
  --random-port          Generate a random panel port between 2000 and 9999
  --php <74|80|81|82|83|84|85|86> Set primary LSPHP version (default: 83)

Optional Module Flags (omitted by default to keep server lean):
  --with-ftp             Install Pure-FTPd server module
  --with-dns             Install PowerDNS server module
  --with-mail            Install Postfix + Dovecot + OpenDKIM mail module
  --with-csf             Install ConfigServer Firewall (CSF) module
  --with-modsec          Install OpenLiteSpeed ModSecurity OWASP module
  --with-imunifyav       Install ImunifyAV malware scanner module
  -h, --help             Show this help message
EOF
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --port)
                PANEL_PORT="$2"
                shift 2
                ;;
            --random-port)
                RANDOM_PORT=1
                shift
                ;;
            --php)
                export LC_PHP_VER="$2"
                shift 2
                ;;
            --with-ftp)
                OPTIONAL_MODULES+=("ftp")
                shift
                ;;
            --with-dns)
                OPTIONAL_MODULES+=("dns")
                shift
                ;;
            --with-mail)
                OPTIONAL_MODULES+=("mail")
                shift
                ;;
            --with-csf)
                OPTIONAL_MODULES+=("csf")
                shift
                ;;
            --with-modsec)
                OPTIONAL_MODULES+=("modsecurity")
                shift
                ;;
            --with-imunifyav)
                OPTIONAL_MODULES+=("imunifyav")
                shift
                ;;
            -h|--help)
                usage
                exit 0
                ;;
            *)
                log_error "Unknown option: $1"
                usage
                exit 1
                ;;
        esac
    done

    if [[ "${RANDOM_PORT}" -eq 1 ]]; then
        PANEL_PORT=$(shuf -i 2000-9999 -n 1)
    fi

    if ! [[ "${PANEL_PORT}" =~ ^[0-9]+$ ]] || [[ "${PANEL_PORT}" -lt 1024 || "${PANEL_PORT}" -gt 65535 ]]; then
        log_error "Invalid panel port '${PANEL_PORT}'. Must be between 1024 and 65535."
        exit 1
    fi
}

print_banner() {
    printf "%s\n" "${C_BLUE}${C_BOLD}"
    cat <<'EOF'
  _      _ _        _____            _             _ 
 | |    (_) |      / ____|          | |           | |
 | |     _| |_ ___| |     ___  _ __ | |_ _ __ ___ | |
 | |    | | __/ _ \ |    / _ \| '_ \| __| '__/ _ \| |
 | |____| | ||  __/ |___| (_) | | | | |_| | | (_) | |
 |______|_|\__\___|\_____\___/|_| |_|\__|_|  \___/|_|
EOF
    printf "%s\n" "${C_RESET}"
    log_warn "NOTICE: LiteControl is currently in EXPERIMENTAL phase and NOT production ready."
    log_info "Starting LiteControl modular installation on Ubuntu..."
}

display_completion_summary() {
    local srv_ip
    srv_ip=$(get_server_ip)
    local admin_pass
    admin_pass=$(get_secret "panel_admin_password" || echo "See /etc/litecontrol/secrets")
    local db_root_pass
    db_root_pass=$(get_secret "mysql_root_password" || echo "See /etc/litecontrol/secrets")
    local ols_pass
    ols_pass=$(get_secret "ols_admin_password" || echo "See /etc/litecontrol/secrets")

    printf "\n%s======================================================================%s\n" "${C_GREEN}${C_BOLD}" "${C_RESET}"
    printf "%s  LiteControl Core Installation Completed Successfully!%s\n" "${C_GREEN}${C_BOLD}" "${C_RESET}"
    printf "%s======================================================================%s\n\n" "${C_GREEN}${C_BOLD}" "${C_RESET}"
    printf "  %-22s : %shttps://%s:%s%s\n" "Panel URL" "${C_BOLD}" "${srv_ip}" "${PANEL_PORT}" "${C_RESET}"
    printf "  %-22s : https://%s:%s/phpmyadmin/\n" "phpMyAdmin URL" "${srv_ip}" "${PANEL_PORT}"
    printf "  %-22s : admin\n" "Panel Username"
    printf "  %-22s : %s%s%s\n\n" "Panel Password" "${C_YELLOW}" "${admin_pass}" "${C_RESET}"
    printf "  %-22s : %s\n" "MariaDB Root Password" "${db_root_pass}"
    printf "  %-22s : %s\n" "OLS WebAdmin Password" "${ols_pass}"
    printf "  %-22s : %s\n" "Secrets Directory" "${LC_SECRETS_DIR}"
    printf "  %-22s : %s\n" "Install Log" "${LC_INSTALL_LOG}"
    printf "  %-22s : lc-cli status\n" "CLI Management"
    printf "\n%s======================================================================%s\n" "${C_GREEN}${C_BOLD}" "${C_RESET}"
}

main() {
    require_root
    detect_ubuntu_os
    parse_args "$@"
    init_litecontrol_dirs
    # Persist chosen panel port so subsequent scripts and helper functions know it
    printf "%s" "${PANEL_PORT}" > "${LC_CONF_DIR}/port"
    chmod 644 "${LC_CONF_DIR}/port"
    print_banner

    bash "${REPO_DIR}/scripts/ubuntu/core/01-system.sh"
    bash "${REPO_DIR}/scripts/ubuntu/core/02-mariadb.sh"
    bash "${REPO_DIR}/scripts/ubuntu/core/03-redis.sh"
    bash "${REPO_DIR}/scripts/ubuntu/core/04-openlitespeed.sh"
    bash "${REPO_DIR}/scripts/ubuntu/core/05-php.sh"
    bash "${REPO_DIR}/scripts/ubuntu/core/06-phpmyadmin.sh"
    bash "${REPO_DIR}/scripts/ubuntu/core/07-ssl-firewall.sh" "${PANEL_PORT}"
    bash "${REPO_DIR}/scripts/ubuntu/core/08-panel-setup.sh" "${PANEL_PORT}"

    # Install any requested optional modules
    if [[ "${#OPTIONAL_MODULES[@]}" -gt 0 ]]; then
        for mod in "${OPTIONAL_MODULES[@]}"; do
            if [[ -x "${LC_BASE_DIR}/bin/lc-cli" ]]; then
                log_step "Installing Optional Module: ${mod}"
                "${LC_BASE_DIR}/bin/lc-cli" module install "${mod}" || log_warn "Module '${mod}' installer not yet available or returned warning."
            fi
        done
    fi

    verify_openlitespeed_health

    display_completion_summary
}

main "$@"

