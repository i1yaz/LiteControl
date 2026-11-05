#!/usr/bin/env bash
# ==============================================================================
# LiteControl Module: Pure-FTPd with MySQL Backend (`ftp`)
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_DIR="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

ACTION="${1:-install}"
PUREFTPD_CONF_DIR="${PUREFTPD_CONF_DIR:-/etc/pure-ftpd}"

do_install() {
    log_info "Configuring Pure-FTPd module..."

    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        apt_install pure-ftpd-mysql openssl
    fi

    local db_user="litecontrol"
    local db_name="litecontrol"
    local db_pass
    db_pass=$(get_secret "mysql_panel_password" 2>/dev/null || echo "secret")

    mkdir -p "${PUREFTPD_CONF_DIR}/conf" "${PUREFTPD_CONF_DIR}/db"

    # Configure MySQL backend
    local tpl="${BASE_DIR}/templates/modules/pure-ftpd/pure-ftpd-mysql.conf.tpl"
    if [[ -f "${tpl}" ]]; then
        sed -e "s|{{DB_USER}}|${db_user}|g" \
            -e "s|{{DB_PASS}}|${db_pass}|g" \
            -e "s|{{DB_NAME}}|${db_name}|g" \
            "${tpl}" > "${PUREFTPD_CONF_DIR}/db/mysql.conf"
    fi

    # Configure Pure-FTPd runtime parameters
    echo "40110 40210" > "${PUREFTPD_CONF_DIR}/conf/PassivePortRange"
    echo "yes" > "${PUREFTPD_CONF_DIR}/conf/ChrootEveryone"
    echo "yes" > "${PUREFTPD_CONF_DIR}/conf/NoAnonymous"
    echo "yes" > "${PUREFTPD_CONF_DIR}/conf/DontResolve"
    echo "yes" > "${PUREFTPD_CONF_DIR}/conf/CustomerProof"
    echo "1" > "${PUREFTPD_CONF_DIR}/conf/TLS" 2>/dev/null || true

    # Create self-signed TLS cert if needed
    local cert_file="/etc/ssl/private/pure-ftpd.pem"
    if [[ "${LC_TEST_MODE:-0}" != "1" && ! -f "${cert_file}" ]]; then
        mkdir -p /etc/ssl/private
        openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
            -keyout "${cert_file}" \
            -out "${cert_file}" \
            -subj "/CN=ftp.litecontrol" >/dev/null 2>&1 || true
        chmod 600 "${cert_file}" 2>/dev/null || true
    fi

    # Open firewall ports
    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        ufw allow 21/tcp comment "FTP" >/dev/null 2>&1 || true
        ufw allow 40110:40210/tcp comment "FTP Passive Ports" >/dev/null 2>&1 || true
        systemctl enable pure-ftpd-mysql >/dev/null 2>&1 || true
        systemctl restart pure-ftpd-mysql >/dev/null 2>&1 || true
    fi

    log_ok "Pure-FTPd installed and configured successfully."
}

do_remove() {
    log_info "Removing Pure-FTPd module..."

    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        systemctl stop pure-ftpd-mysql >/dev/null 2>&1 || true
        systemctl disable pure-ftpd-mysql >/dev/null 2>&1 || true
        ufw delete allow 21/tcp >/dev/null 2>&1 || true
        ufw delete allow 40110:40210/tcp >/dev/null 2>&1 || true
    fi

    log_ok "Pure-FTPd module removed."
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

