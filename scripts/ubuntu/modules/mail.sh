#!/usr/bin/env bash
# ==============================================================================
# LiteControl Module: Mail Stack (Postfix + Dovecot + OpenDKIM) (`mail`)
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_DIR="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

ACTION="${1:-install}"
POSTFIX_CONF_DIR="${POSTFIX_CONF_DIR:-/etc/postfix}"
DOVECOT_CONF_DIR="${DOVECOT_CONF_DIR:-/etc/dovecot}"

do_install() {
    log_info "Configuring Postfix and Dovecot mail stack..."

    local srv_host
    srv_host=$(hostname 2>/dev/null || echo "mail.litecontrol.local")
    local db_user="litecontrol"
    local db_name="litecontrol"
    local db_pass
    db_pass=$(get_secret "mysql_panel_password" 2>/dev/null || echo "secret")
    local ssl_cert="${LC_SSL_DIR}/fullchain.pem"
    local ssl_key="${LC_SSL_DIR}/privkey.pem"

    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        echo "postfix postfix/mailname string ${srv_host}" | debconf-set-selections 2>/dev/null || true
        echo "postfix postfix/main_mailer_type string 'Internet Site'" | debconf-set-selections 2>/dev/null || true

        apt_install postfix postfix-mysql dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd dovecot-mysql opendkim opendkim-tools

        # Create vmail user and group (UID/GID 5000)
        groupadd -g 5000 vmail 2>/dev/null || true
        useradd -g vmail -u 5000 vmail -d /var/vmail -m 2>/dev/null || true
        chown -R vmail:vmail /var/vmail 2>/dev/null || true
    fi

    mkdir -p "${POSTFIX_CONF_DIR}" "${DOVECOT_CONF_DIR}"

    # 1. Postfix templates
    local tpl_main="${BASE_DIR}/templates/modules/postfix/main.cf.tpl"
    if [[ -f "${tpl_main}" ]]; then
        sed -e "s|{{HOSTNAME}}|${srv_host}|g" \
            -e "s|{{SSL_CERT}}|${ssl_cert}|g" \
            -e "s|{{SSL_KEY}}|${ssl_key}|g" \
            "${tpl_main}" > "${POSTFIX_CONF_DIR}/main.cf"
    fi

    for map_type in "domains" "mailboxes" "forwardings"; do
        local tpl_map="${BASE_DIR}/templates/modules/postfix/mysql-virtual_${map_type}.cf.tpl"
        if [[ -f "${tpl_map}" ]]; then
            sed -e "s|{{DB_USER}}|${db_user}|g" \
                -e "s|{{DB_PASS}}|${db_pass}|g" \
                -e "s|{{DB_NAME}}|${db_name}|g" \
                "${tpl_map}" > "${POSTFIX_CONF_DIR}/mysql-virtual_${map_type}.cf"
            chmod 640 "${POSTFIX_CONF_DIR}/mysql-virtual_${map_type}.cf" 2>/dev/null || true
        fi
    done

    # 2. Dovecot templates
    local tpl_dove="${BASE_DIR}/templates/modules/dovecot/dovecot.conf.tpl"
    if [[ -f "${tpl_dove}" ]]; then
        sed -e "s|{{SSL_CERT}}|${ssl_cert}|g" \
            -e "s|{{SSL_KEY}}|${ssl_key}|g" \
            "${tpl_dove}" > "${DOVECOT_CONF_DIR}/dovecot.conf"
    fi

    local tpl_sql="${BASE_DIR}/templates/modules/dovecot/dovecot-sql.conf.ext.tpl"
    if [[ -f "${tpl_sql}" ]]; then
        sed -e "s|{{DB_USER}}|${db_user}|g" \
            -e "s|{{DB_PASS}}|${db_pass}|g" \
            -e "s|{{DB_NAME}}|${db_name}|g" \
            "${tpl_sql}" > "${DOVECOT_CONF_DIR}/dovecot-sql.conf.ext"
        chmod 640 "${DOVECOT_CONF_DIR}/dovecot-sql.conf.ext" 2>/dev/null || true
    fi

    # 3. Open mail firewall ports
    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        for port in 25 465 587 110 995 143 993; do
            ufw allow "${port}/tcp" comment "Mail Port ${port}" >/dev/null 2>&1 || true
        done
        systemctl enable postfix dovecot >/dev/null 2>&1 || true
        systemctl restart postfix dovecot >/dev/null 2>&1 || true
    fi

    log_ok "Mail stack (Postfix + Dovecot) installed and configured successfully."
}

do_remove() {
    log_info "Removing Mail stack module..."

    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        systemctl stop postfix dovecot >/dev/null 2>&1 || true
        systemctl disable postfix dovecot >/dev/null 2>&1 || true
        for port in 25 465 587 110 995 143 993; do
            ufw delete allow "${port}/tcp" >/dev/null 2>&1 || true
        done
    fi

    log_ok "Mail stack module removed."
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

