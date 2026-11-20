#!/usr/bin/env bash
# ==============================================================================
# LiteControl Module: ImunifyAV Antivirus & Malware Scanner (`imunifyav`)
# Supports Ubuntu/Debian (common-auth) and RHEL/Alma/Rocky/CentOS Stream (system-auth)
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

ACTION="${1:-install}"

IMUNIFY_SYSCONFIG_DIR="${IMUNIFY_SYSCONFIG_DIR:-/etc/sysconfig/imunify360}"
IMUNIFY_CONF_PATH="${IMUNIFY_CONF_PATH:-${IMUNIFY_SYSCONFIG_DIR}/integration.conf}"
IMUNIFY_UI_DIR="${IMUNIFY_UI_DIR:-${LC_BASE_DIR}/imunifyav}"
IMUNIFY_SCRIPTS_DIR="${IMUNIFY_SCRIPTS_DIR:-${LC_BASE_DIR}/scripts/ubuntu/imunify}"
IMUNIFY_DEPLOY_DIR="${IMUNIFY_DEPLOY_DIR:-/var/lib/litecontrol/tmp/imunify-deploy}"
IMUNIFY_INSTALL_LOG="${IMUNIFY_INSTALL_LOG:-${LC_LOG_DIR}/imunifyav-install.log}"
IMUNIFY_DEPLOY_URL="https://repo.imunify360.cloudlinux.com/defence360/imav-deploy.sh"

detect_pam_service() {
    if [[ -f /etc/pam.d/system-auth ]]; then
        # RHEL, AlmaLinux, Rocky Linux, CentOS Stream, CloudLinux
        echo "system-auth"
    elif [[ -f /etc/pam.d/common-auth ]]; then
        # Ubuntu, Debian
        echo "common-auth"
    else
        echo "login"
    fi
}

ensure_install_log() {
    local log_dir
    log_dir="$(dirname "${IMUNIFY_INSTALL_LOG}")"
    mkdir -p "${log_dir}"
    chmod 0755 "${log_dir}" 2>/dev/null || true
    : > "${IMUNIFY_INSTALL_LOG}"
    chmod 0644 "${IMUNIFY_INSTALL_LOG}" 2>/dev/null || true
    if id "${LC_USER}" >/dev/null 2>&1; then
        chown "${LC_USER}:${LC_GROUP}" "${IMUNIFY_INSTALL_LOG}" 2>/dev/null || true
    fi
}

append_install_log() {
    local msg="$1"
    printf "[%s] %s\n" "$(date '+%Y-%m-%d %H:%M:%S')" "${msg}" >> "${IMUNIFY_INSTALL_LOG}" 2>/dev/null || true
}

write_integration_conf() {
    local pam_service
    pam_service="$(detect_pam_service)"

    mkdir -p "${IMUNIFY_SYSCONFIG_DIR}/generic" "${IMUNIFY_SYSCONFIG_DIR}/imunify360.config.d"
    touch "${IMUNIFY_SYSCONFIG_DIR}/generic/modsec.conf"
    mkdir -p "${IMUNIFY_UI_DIR}"

    if id "${LC_USER}" >/dev/null 2>&1; then
        chown -R "${LC_USER}:${LC_GROUP}" "${IMUNIFY_UI_DIR}" 2>/dev/null || true
    fi

    local tmp_conf="${IMUNIFY_SYSCONFIG_DIR}/.integration.conf.tmp.$$"
    cat > "${tmp_conf}" <<EOF
[paths]
ui_path = ${IMUNIFY_UI_DIR}
ui_path_owner = ${LC_USER}:${LC_GROUP}

[pam]
service_name = ${pam_service}

[integration_scripts]
admins = ${IMUNIFY_SCRIPTS_DIR}/admins.sh
users = ${IMUNIFY_SCRIPTS_DIR}/users.sh
domains = ${IMUNIFY_SCRIPTS_DIR}/domains.sh
packages = ${IMUNIFY_SCRIPTS_DIR}/packages.sh
resellers = ${IMUNIFY_SCRIPTS_DIR}/resellers.sh
db_info = ${IMUNIFY_SCRIPTS_DIR}/db_info.sh
panel_info = ${IMUNIFY_SCRIPTS_DIR}/panel_info.sh

[malware]
basedir = ${LC_HOME_ROOT}
pattern_to_watch = ^${LC_HOME_ROOT}/.+?/(public_html|domains/.+?/public_html)(/.*)?$

[web_server]
server_type = litespeed
graceful_restart_script = ${OLS_CTRL} restart
modsec_audit_log = ${OLS_DIR}/logs/auditmodsec.log
modsec_audit_logdir = ${OLS_DIR}/logs/
EOF

    chmod 0644 "${tmp_conf}"
    mv -f "${tmp_conf}" "${IMUNIFY_CONF_PATH}"
}

ensure_resolver_scripts_executable() {
    local scripts=("admins.sh" "users.sh" "domains.sh" "packages.sh" "resellers.sh" "db_info.sh" "panel_info.sh")
    local src_dir
    src_dir="$(cd "${SCRIPT_DIR}/../imunify" 2>/dev/null && pwd || echo "${SCRIPT_DIR}/../imunify")"

    mkdir -p "${IMUNIFY_SCRIPTS_DIR}"
    for s in "${scripts[@]}"; do
        if [[ -f "${src_dir}/${s}" && ! "${src_dir}/${s}" -ef "${IMUNIFY_SCRIPTS_DIR}/${s}" ]]; then
            cp -f "${src_dir}/${s}" "${IMUNIFY_SCRIPTS_DIR}/${s}"
        fi
        if [[ -f "${IMUNIFY_SCRIPTS_DIR}/${s}" ]]; then
            chmod 0755 "${IMUNIFY_SCRIPTS_DIR}/${s}"
        fi
    done
}

fix_ui_permissions_and_selinux() {
    if [[ -d "${IMUNIFY_UI_DIR}" ]]; then
        if id "${LC_USER}" >/dev/null 2>&1; then
            chown -R "${LC_USER}:${LC_GROUP}" "${IMUNIFY_UI_DIR}" 2>/dev/null || true
        fi
        find "${IMUNIFY_UI_DIR}" -name "execute.py" -exec chmod 0755 {} + 2>/dev/null || true

        # RHEL / Rocky / AlmaLinux / CentOS Stream SELinux contexts
        if command -v selinuxenabled >/dev/null 2>&1 && selinuxenabled; then
            chcon -R -t httpd_sys_content_t "${IMUNIFY_UI_DIR}" 2>/dev/null || true
            find "${IMUNIFY_UI_DIR}" -name "execute.py" -exec chcon -t httpd_sys_script_exec_t {} + 2>/dev/null || true
        fi
    fi
}

ensure_panel_nginx_imunify_route() {
    if [[ -f "${PANEL_NGINX_CONF}" ]] && ! grep -q "location \^~ /imunifyav/" "${PANEL_NGINX_CONF}" 2>/dev/null; then
        local tpl="${LC_BASE_DIR}/templates/panel/nginx.conf.tpl"
        if [[ -f "${tpl}" ]]; then
            local port
            port=$(grep -oE 'listen[[:space:]]+[0-9]+[[:space:]]+ssl' "${PANEL_NGINX_CONF}" | head -n1 | awk '{print $2}')
            port="${port:-${LC_DEFAULT_PORT}}"
            panel_nginx_backup_config 2>/dev/null || true
            sed "s/__PANEL_PORT__/${port}/g" "${tpl}" > "${PANEL_NGINX_CONF}"
            if panel_nginx_test_config 2>/dev/null; then
                systemctl reload "${PANEL_SYSTEMD_SERVICE}" 2>/dev/null || true
            else
                panel_nginx_restore_config 2>/dev/null || true
            fi
        fi
    fi
}

do_install() {
    log_info "Installing ImunifyAV Antivirus..."
    ensure_install_log
    append_install_log "Starting ImunifyAV installation..."

    append_install_log "Writing integration contract to ${IMUNIFY_CONF_PATH}..."
    write_integration_conf
    ensure_resolver_scripts_executable
    ensure_panel_nginx_imunify_route

    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        # Ensure EPEL on RHEL / AlmaLinux / Rocky Linux / CentOS Stream if dnf/yum is present
        if command -v dnf >/dev/null 2>&1 && [[ -f /etc/redhat-release ]]; then
            append_install_log "RHEL-family OS detected; ensuring epel-release is installed..."
            dnf install -y epel-release >> "${IMUNIFY_INSTALL_LOG}" 2>&1 || true
        fi

        mkdir -p "${IMUNIFY_DEPLOY_DIR}"
        chmod 0700 "${IMUNIFY_DEPLOY_DIR}"
        local deploy_script="${IMUNIFY_DEPLOY_DIR}/imav-deploy.sh"

        # 1. Stop any hung deploy process (self-excluding pattern)
        pkill -f '[i]mav-deploy.sh' >/dev/null 2>&1 || true

        # 2. Download official installer from CloudLinux
        append_install_log "Downloading official ImunifyAV installer from ${IMUNIFY_DEPLOY_URL}..."
        if command -v wget >/dev/null 2>&1; then
            if ! wget -qO "${deploy_script}" "${IMUNIFY_DEPLOY_URL}" >> "${IMUNIFY_INSTALL_LOG}" 2>&1; then
                append_install_log "ERROR: ImunifyAV installer download failed."
                log_error "ImunifyAV installer download failed."
                exit 1
            fi
        else
            if ! curl -fsSL "${IMUNIFY_DEPLOY_URL}" -o "${deploy_script}" >> "${IMUNIFY_INSTALL_LOG}" 2>&1; then
                append_install_log "ERROR: ImunifyAV installer download failed."
                log_error "ImunifyAV installer download failed."
                exit 1
            fi
        fi

        # 3. Clean previous broken/partial installation (tolerated if not yet installed)
        append_install_log "Preparing clean install state (--uninstall --yes)..."
        bash "${deploy_script}" --uninstall --yes >> "${IMUNIFY_INSTALL_LOG}" 2>&1 || true

        # Re-ensure contract & UI dir after --uninstall
        write_integration_conf
        ensure_resolver_scripts_executable

        # 4. Run full installation (strict failure gate — do not swallow non-zero exit)
        append_install_log "Running ImunifyAV installer (imav-deploy.sh --yes)..."
        if ! bash "${deploy_script}" --yes >> "${IMUNIFY_INSTALL_LOG}" 2>&1; then
            append_install_log "ERROR: ImunifyAV installer exited with a non-zero status. Check ${IMUNIFY_INSTALL_LOG}."
            log_error "ImunifyAV installation failed. Check ${IMUNIFY_INSTALL_LOG} for details."
            exit 1
        fi

        # 5. Fix execute.py permissions & SELinux contexts
        fix_ui_permissions_and_selinux

        # 6. Enable & start systemd daemon
        systemctl enable imunify-antivirus >> "${IMUNIFY_INSTALL_LOG}" 2>&1 || true
        systemctl start imunify-antivirus >> "${IMUNIFY_INSTALL_LOG}" 2>&1 || true
    fi

    append_install_log "ImunifyAV Antivirus installed successfully."
    log_ok "ImunifyAV Antivirus installed successfully."
}

do_remove() {
    log_info "Removing ImunifyAV Antivirus..."
    ensure_install_log
    append_install_log "Removing ImunifyAV Antivirus..."

    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        local deploy_script="${IMUNIFY_DEPLOY_DIR}/imav-deploy.sh"
        if [[ -f "${deploy_script}" ]]; then
            bash "${deploy_script}" --uninstall --yes >> "${IMUNIFY_INSTALL_LOG}" 2>&1 || true
        elif command -v dnf >/dev/null 2>&1; then
            dnf remove -y imunify-antivirus >> "${IMUNIFY_INSTALL_LOG}" 2>&1 || true
        else
            apt_remove imunify-antivirus 2>/dev/null || true
        fi
        systemctl stop imunify-antivirus >/dev/null 2>&1 || true
    fi

    rm -f "${IMUNIFY_CONF_PATH}"
    append_install_log "ImunifyAV Antivirus removed."
    log_ok "ImunifyAV Antivirus removed."
}

case "${ACTION}" in
    install)
        do_install
        ;;
    remove)
        do_remove
        ;;
    *)
        log_error "Usage: $0 <install|remove>"
        exit 1
        ;;
esac
