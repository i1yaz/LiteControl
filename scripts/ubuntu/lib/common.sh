#!/usr/bin/env bash
# ==============================================================================
# LiteControl - Shared Ubuntu Bash Library
# Provides logging, OS detection, apt lock handling, secret management,
# and OpenLiteSpeed atomic config validation + auto-rollback.
# ==============================================================================

set -o pipefail

# Core Paths
export LC_BASE_DIR="${LC_BASE_DIR:-/usr/local/litecontrol}"
export LC_PANEL_DIR="${LC_PANEL_DIR:-${LC_BASE_DIR}/panel}"
export LC_PMA_DIR="${LC_PMA_DIR:-${LC_BASE_DIR}/phpmyadmin}"
export LC_CONF_DIR="${LC_CONF_DIR:-/etc/litecontrol}"
export LC_SECRETS_DIR="${LC_SECRETS_DIR:-${LC_CONF_DIR}/secrets}"
export LC_SSL_DIR="${LC_SSL_DIR:-${LC_CONF_DIR}/ssl}"
export LC_MODULES_DIR="${LC_MODULES_DIR:-${LC_CONF_DIR}/modules}"
export LC_LOG_DIR="${LC_LOG_DIR:-/var/log/litecontrol}"
export LC_INSTALL_LOG="${LC_INSTALL_LOG:-${LC_LOG_DIR}/install.log}"
export LC_HOME_ROOT="${LC_HOME_ROOT:-/home}"

# Core Version Resolver (Single Source of Truth)
get_lc_version() {
    local default_v="0.1.0-alpha.1"
    if [[ -s "${LC_BASE_DIR}/VERSION" ]]; then
        tr -d ' \t\r\n' < "${LC_BASE_DIR}/VERSION"
    elif [[ -s "${LC_CONF_DIR}/version" ]]; then
        tr -d ' \t\r\n' < "${LC_CONF_DIR}/version"
    elif [[ -n "${REPO_ROOT:-}" && -s "${REPO_ROOT}/VERSION" ]]; then
        tr -d ' \t\r\n' < "${REPO_ROOT}/VERSION"
    else
        printf "%s" "${default_v}"
    fi
}
export LC_VERSION="${LC_VERSION:-$(get_lc_version 2>/dev/null || echo "0.1.0-alpha.1")}"

# Panel Dedicated System User & PHP Version
export LC_USER="litecontrol"
export LC_GROUP="litecontrol"
export LC_PHP_VER="${LC_PHP_VER:-83}"
export LC_DEFAULT_PORT="${LC_DEFAULT_PORT:-8090}"

# OpenLiteSpeed Paths (overridable for unit test sandboxing)
export OLS_DIR="${OLS_DIR:-/usr/local/lsws}"
export OLS_CONF="${OLS_CONF:-${OLS_DIR}/conf/httpd_config.conf}"
export OLS_VHOSTS_DIR="${OLS_VHOSTS_DIR:-${OLS_DIR}/conf/vhosts}"
export OLS_CTRL="${OLS_CTRL:-${OLS_DIR}/bin/lswsctrl}"
export OLS_BIN="${OLS_BIN:-${OLS_DIR}/bin/lshttpd}"

# Panel Nginx & PHP-FPM Paths (overridable for unit test sandboxing)
export PANEL_NGINX_DIR="${PANEL_NGINX_DIR:-${LC_CONF_DIR}/nginx}"
export PANEL_NGINX_CONF="${PANEL_NGINX_CONF:-${PANEL_NGINX_DIR}/nginx.conf}"
export PANEL_FPM_DIR="${PANEL_FPM_DIR:-${LC_CONF_DIR}/php-fpm}"
export PANEL_FPM_CONF="${PANEL_FPM_CONF:-${PANEL_FPM_DIR}/php-fpm.conf}"
export NGINX_BIN="${NGINX_BIN:-/usr/sbin/nginx}"
export PANEL_SYSTEMD_SERVICE="${PANEL_SYSTEMD_SERVICE:-litecontrol-panel}"
export PANEL_FPM_SYSTEMD_SERVICE="${PANEL_FPM_SYSTEMD_SERVICE:-litecontrol-php}"

# Terminal Colors (disabled automatically when not a TTY or in JSON mode)
if [[ -t 1 && "${LC_JSON_OUTPUT:-0}" != "1" ]]; then
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

# ------------------------------------------------------------------------------
# Logging Helpers
# ------------------------------------------------------------------------------
_log_write() {
    local level="$1"
    local color="$2"
    shift 2
    local msg="$*"
    local ts
    ts=$(date '+%Y-%m-%d %H:%M:%S')

    if [[ -d "${LC_LOG_DIR}" ]]; then
        printf "[%s] [%s] %s\n" "${ts}" "${level}" "${msg}" >> "${LC_INSTALL_LOG}" 2>/dev/null || true
    fi

    if [[ "${LC_JSON_OUTPUT:-0}" != "1" ]]; then
        printf "%s[%s]%s %s\n" "${color}" "${level}" "${C_RESET}" "${msg}"
    fi
}

log_info()  { _log_write "INFO"  "${C_BLUE}"   "$@"; }
log_ok()    { _log_write "OK"    "${C_GREEN}"  "$@"; }
log_warn()  { _log_write "WARN"  "${C_YELLOW}" "$@"; }
log_error() { _log_write "ERROR" "${C_RED}"    "$@" >&2; }

log_step() {
    local step_title="$*"
    if [[ "${LC_JSON_OUTPUT:-0}" != "1" ]]; then
        printf "\n%s==> %s%s\n" "${C_BOLD}${C_BLUE}" "${step_title}" "${C_RESET}"
    fi
    _log_write "STEP" "${C_BLUE}" "${step_title}"
}

# JSON output helper for `lc-cli --json`
json_escape() {
    local str="$1"
    str="${str//\\/\\\\}"
    str="${str//\"/\\\"}"
    str="${str//$'\n'/\\n}"
    str="${str//$'\r'/\\r}"
    str="${str//$'\t'/\\t}"
    printf "%s" "${str}"
}

json_response() {
    local status="$1"
    local message
    message=$(json_escape "$2")
    local data_json="${3:-null}"
    printf '{"status":"%s","message":"%s","data":%s}\n' "${status}" "${message}" "${data_json}"
}

# ------------------------------------------------------------------------------
# Privilege & OS Detection
# ------------------------------------------------------------------------------
require_root() {
    if [[ "${LC_TEST_MODE:-0}" == "1" ]]; then
        return 0
    fi
    if [[ "$(id -u)" -ne 0 ]]; then
        if [[ "${LC_JSON_OUTPUT:-0}" == "1" ]]; then
            json_response "error" "Root privileges are required to run this command."
        else
            log_error "This script must be run as root (use sudo)."
        fi
        exit 1
    fi
}

detect_ubuntu_os() {
    if [[ ! -f /etc/os-release ]]; then
        log_error "Cannot detect operating system (/etc/os-release missing)."
        return 1
    fi

    # shellcheck disable=SC1091
    . /etc/os-release

    export OS_ID="${ID,,}"
    export OS_VERSION_FULL="${VERSION_ID}"
    export OS_VERSION_MAJOR="${VERSION_ID%%.*}"
    export OS_CODENAME="${VERSION_CODENAME:-}"
    export ARCH
    ARCH=$(uname -m)

    if [[ "${OS_ID}" != "ubuntu" ]]; then
        log_error "Unsupported OS: '${OS_ID}'. LiteControl only supports Ubuntu LTS (20.04, 22.04, 24.04)."
        return 1
    fi

    case "${OS_VERSION_MAJOR}" in
        20|22|24)
            ;;
        *)
            log_error "Unsupported Ubuntu version: ${OS_VERSION_FULL:-unknown} (${OS_CODENAME:-unknown})."
            log_error "LiteControl strictly supports Ubuntu LTS releases: 20.04 (focal), 22.04 (jammy), and 24.04 (noble)."
            log_error "Upstream packages such as OpenLiteSpeed and LSPHP do not have pre-built repositories for this version."
            return 1
            ;;
    esac

    # OpenLiteSpeed systemd service name differs on Ubuntu 24.04+
    if [[ "${OS_VERSION_MAJOR}" -ge 24 ]]; then
        export OLS_SYSTEMD_SERVICE="lshttpd"
    else
        export OLS_SYSTEMD_SERVICE="lsws"
    fi

    return 0
}

# ------------------------------------------------------------------------------
# Apt Lock & Non-Interactive Package Helpers
# ------------------------------------------------------------------------------
wait_for_apt_lock() {
    local max_wait=300
    local waited=0
    while fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 \
       || fuser /var/lib/dpkg/lock >/dev/null 2>&1 \
       || fuser /var/lib/apt/lists/lock >/dev/null 2>&1; do
        if [[ "${waited}" -eq 0 ]]; then
            log_info "Waiting for another apt/dpkg process to release lock..."
        fi
        sleep 3
        waited=$((waited + 3))
        if [[ "${waited}" -ge "${max_wait}" ]]; then
            log_error "Timed out waiting for apt lock after ${max_wait}s."
            return 1
        fi
    done
    return 0
}

apt_update() {
    wait_for_apt_lock || return 1
    DEBIAN_FRONTEND=noninteractive apt-get update -qq
}

apt_install() {
    wait_for_apt_lock || return 1
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
        -o Dpkg::Options::="--force-confdef" \
        -o Dpkg::Options::="--force-confold" \
        "$@"
}

# ------------------------------------------------------------------------------
# Secret, Password & Network Helpers
# ------------------------------------------------------------------------------
init_litecontrol_dirs() {
    mkdir -p "${LC_BASE_DIR}/bin" \
             "${LC_BASE_DIR}/scripts" \
             "${LC_BASE_DIR}/templates" \
             "${LC_PANEL_DIR}" \
             "${LC_PMA_DIR}" \
             "${LC_CONF_DIR}" \
             "${LC_SECRETS_DIR}" \
             "${LC_SSL_DIR}" \
             "${LC_MODULES_DIR}" \
             "${LC_LOG_DIR}"
    chmod 700 "${LC_SECRETS_DIR}"
    chmod 750 "${LC_CONF_DIR}" "${LC_LOG_DIR}"
}

generate_password() {
    local length="${1:-20}"
    head -c 2048 /dev/urandom | LC_ALL=C tr -dc 'A-Za-z0-9' | head -c "${length}" || true
}

save_secret() {
    local name="$1"
    local value="$2"
    mkdir -p "${LC_SECRETS_DIR}"
    chmod 700 "${LC_SECRETS_DIR}"
    printf "%s" "${value}" > "${LC_SECRETS_DIR}/${name}"
    chmod 600 "${LC_SECRETS_DIR}/${name}"
    chown root:root "${LC_SECRETS_DIR}/${name}"
}

get_secret() {
    local name="$1"
    local file="${LC_SECRETS_DIR}/${name}"
    if [[ ! -f "${file}" ]]; then
        return 1
    fi
    cat "${file}"
}

get_or_create_secret() {
    local name="$1"
    local length="${2:-20}"
    if [[ -s "${LC_SECRETS_DIR}/${name}" ]]; then
        cat "${LC_SECRETS_DIR}/${name}"
    else
        local pass
        pass=$(generate_password "${length}")
        save_secret "${name}" "${pass}"
        printf "%s" "${pass}"
    fi
}

get_panel_port() {
    local port="${LC_DEFAULT_PORT:-8090}"
    if [[ -s "${LC_CONF_DIR}/port" ]]; then
        port=$(tr -d ' \t\r\n' < "${LC_CONF_DIR}/port")
    fi
    printf "%s" "${port}"
}

get_server_ip() {
    local ip
    ip=$(hostname -I 2>/dev/null | awk '{print $1}')
    if [[ -z "${ip}" || "${ip}" == 10.* || "${ip}" == 172.* || "${ip}" == 192.168.* || "${ip}" == 127.* ]]; then
        local pub_ip
        pub_ip=$(curl -4 -fsSL --max-time 5 https://ifconfig.me 2>/dev/null || curl -4 -fsSL --max-time 5 https://api.ipify.org 2>/dev/null || true)
        if [[ -n "${pub_ip}" ]]; then
            ip="${pub_ip}"
        fi
    fi
    printf "%s" "${ip:-127.0.0.1}"
}

mark_module_installed() {
    local module_name="$1"
    mkdir -p "${LC_MODULES_DIR}"
    date -Iseconds > "${LC_MODULES_DIR}/${module_name}.installed"
}

mark_module_removed() {
    local module_name="$1"
    rm -f "${LC_MODULES_DIR}/${module_name}.installed"
}

is_module_installed() {
    local module_name="$1"
    [[ -f "${LC_MODULES_DIR}/${module_name}.installed" ]]
}

# ------------------------------------------------------------------------------
# OpenLiteSpeed Atomic Config Validation & Auto-Rollback Guard
# Prevents panel self-lockout when editing httpd_config.conf or vhost configs.
# ------------------------------------------------------------------------------
ols_backup_config() {
    if [[ -f "${OLS_CONF}" ]]; then
        cp -a "${OLS_CONF}" "${OLS_CONF}.last_good"
    fi
}

ols_restore_config() {
    if [[ -f "${OLS_CONF}.last_good" ]]; then
        log_warn "Restoring last known good OpenLiteSpeed configuration..."
        cp -a "${OLS_CONF}.last_good" "${OLS_CONF}"
    fi
}

ols_test_config() {
    if [[ ! -x "${OLS_BIN}" ]]; then
        log_error "OpenLiteSpeed binary not found at ${OLS_BIN}"
        return 1
    fi
    local test_output
    if ! test_output=$("${OLS_BIN}" -t 2>&1); then
        log_error "OpenLiteSpeed configuration test failed: ${test_output}"
        return 1
    fi
    return 0
}

ols_safe_reload() {
    if ! ols_test_config; then
        ols_restore_config
        return 1
    fi

    if "${OLS_CTRL}" status >/dev/null 2>&1; then
        "${OLS_CTRL}" reload >/dev/null 2>&1 || "${OLS_CTRL}" restart >/dev/null 2>&1
    else
        "${OLS_CTRL}" start >/dev/null 2>&1
    fi

    sleep 1
    if ! "${OLS_CTRL}" status >/dev/null 2>&1; then
        log_error "OpenLiteSpeed failed to stay running after reload. Triggering auto-rollback..."
        ols_restore_config
        "${OLS_CTRL}" restart >/dev/null 2>&1
        return 1
    fi

    # Update last_good snapshot now that config is verified healthy
    ols_backup_config
    return 0
}

# ------------------------------------------------------------------------------
# Out-of-Band Panel Nginx Atomic Config Validation & Auto-Rollback Guard
# Prevents panel lockout when updating panel port, SSL, or server blocks.
# ------------------------------------------------------------------------------
panel_backup_config() {
    if [[ -f "${PANEL_NGINX_CONF}" ]]; then
        cp -a "${PANEL_NGINX_CONF}" "${PANEL_NGINX_CONF}.last_good"
    fi
}

panel_restore_config() {
    if [[ -f "${PANEL_NGINX_CONF}.last_good" ]]; then
        log_warn "Restoring last known good Panel Nginx configuration..."
        cp -a "${PANEL_NGINX_CONF}.last_good" "${PANEL_NGINX_CONF}"
    fi
}

panel_test_config() {
    if [[ ! -x "${NGINX_BIN}" ]]; then
        log_error "Nginx binary not found at ${NGINX_BIN}"
        return 1
    fi
    local test_output
    if ! test_output=$("${NGINX_BIN}" -t -q -c "${PANEL_NGINX_CONF}" 2>&1); then
        log_error "Panel Nginx configuration test failed: ${test_output}"
        return 1
    fi
    return 0
}

panel_safe_reload() {
    if ! panel_test_config; then
        panel_restore_config
        return 1
    fi

    if [[ "${LC_TEST_MODE:-0}" == "1" ]]; then
        panel_backup_config
        return 0
    fi

    if systemctl is-active --quiet "${PANEL_SYSTEMD_SERVICE}"; then
        systemctl reload-or-restart "${PANEL_SYSTEMD_SERVICE}" >/dev/null 2>&1 || systemctl restart "${PANEL_SYSTEMD_SERVICE}" >/dev/null 2>&1
    else
        systemctl start "${PANEL_SYSTEMD_SERVICE}" >/dev/null 2>&1
    fi

    sleep 1
    if ! systemctl is-active --quiet "${PANEL_SYSTEMD_SERVICE}"; then
        log_error "Panel Nginx service failed to stay running after reload. Triggering auto-rollback..."
        panel_restore_config
        systemctl restart "${PANEL_SYSTEMD_SERVICE}" >/dev/null 2>&1
        return 1
    fi

    # Update last_good snapshot now that config is verified healthy
    panel_backup_config
    return 0
}


