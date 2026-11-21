#!/usr/bin/env bash
# ==============================================================================
# LiteControl Module: PowerDNS Authoritative Nameserver (`dns`)
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_DIR="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

ACTION="${1:-install}"
PDNS_CONF_DIR="${PDNS_CONF_DIR:-/etc/powerdns}"

do_install() {
    log_info "Configuring PowerDNS module..."

    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        apt_install pdns-server pdns-backend-mysql

        # Safely disable systemd-resolved 127.0.0.53 stub listener without breaking outbound resolution
        if [[ -f /etc/systemd/resolved.conf ]]; then
            sed -i 's/^#DNSStubListener=.*/DNSStubListener=no/' /etc/systemd/resolved.conf
            sed -i 's/^DNSStubListener=.*/DNSStubListener=no/' /etc/systemd/resolved.conf
            if ! grep -q "^DNSStubListener=" /etc/systemd/resolved.conf; then
                echo "DNSStubListener=no" >> /etc/systemd/resolved.conf
            fi
            systemctl restart systemd-resolved 2>/dev/null || true
            # Point resolv.conf to resolved upstream servers if file exists
            if [[ -f /run/systemd/resolve/resolv.conf ]]; then
                ln -sf /run/systemd/resolve/resolv.conf /etc/resolv.conf 2>/dev/null || true
            fi
        fi
    fi

    local db_user="litecontrol"
    local db_name="litecontrol"
    local db_pass
    db_pass=$(get_secret "mysql_panel_password" 2>/dev/null || echo "secret")
    local srv_host
    srv_host=$(hostname 2>/dev/null || echo "localhost")

    mkdir -p "${PDNS_CONF_DIR}" "${PDNS_CONF_DIR}/pdns.d"
    chmod 0750 "${PDNS_CONF_DIR}" "${PDNS_CONF_DIR}/pdns.d" 2>/dev/null || true
    chown root:pdns "${PDNS_CONF_DIR}" "${PDNS_CONF_DIR}/pdns.d" 2>/dev/null || true

    # Configure pdns.conf
    local tpl="${BASE_DIR}/templates/modules/powerdns/pdns.conf.tpl"
    if [[ -f "${tpl}" ]]; then
        sed -e "s|{{DB_USER}}|${db_user}|g" \
            -e "s|{{DB_PASS}}|${db_pass}|g" \
            -e "s|{{DB_NAME}}|${db_name}|g" \
            -e "s|{{HOSTNAME}}|${srv_host}|g" \
            "${tpl}" > "${PDNS_CONF_DIR}/pdns.conf"
        chmod 0640 "${PDNS_CONF_DIR}/pdns.conf" 2>/dev/null || true
        chown root:pdns "${PDNS_CONF_DIR}/pdns.conf" 2>/dev/null || true
    fi

    # Initialize PowerDNS schema into litecontrol database
    local schema_file="${BASE_DIR}/templates/modules/powerdns/schema.mysql.sql"
    if [[ "${LC_TEST_MODE:-0}" != "1" && -f "${schema_file}" ]]; then
        local root_pass
        root_pass=$(get_secret "mysql_root_password" 2>/dev/null || echo "")
        mysql -u root -p"${root_pass}" "${db_name}" < "${schema_file}" 2>/dev/null || true
    fi

    # Open firewall ports and ensure Go CLI binary (`lc-cli-go`) is compiled for `lc-cli pdns`
    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        local go_cli_bin="${LC_GO_CLI_BIN:-${BASE_DIR}/bin/lc-cli-go}"
        if [[ ! -x "${go_cli_bin}" && -d "${BASE_DIR}/cli" ]]; then
            local go_bin=""
            go_bin=$(command -v go 2>/dev/null || true)
            if [[ -z "${go_bin}" && -x /usr/local/go/bin/go ]]; then
                go_bin="/usr/local/go/bin/go"
            fi
            if [[ -z "${go_bin}" ]]; then
                log_info "Installing Go compiler to build LiteControl PowerDNS CLI shim..."
                apt_install golang-go
                go_bin=$(command -v go 2>/dev/null || true)
            fi
            if [[ -n "${go_bin}" ]]; then
                log_info "Building LiteControl Go CLI binary (${go_cli_bin})..."
                mkdir -p "$(dirname "${go_cli_bin}")"
                (cd "${BASE_DIR}/cli" && CGO_ENABLED=0 "${go_bin}" build -ldflags="-s -w" -o "${go_cli_bin}.tmp" .)
                chmod 0750 "${go_cli_bin}.tmp"
                mv -f "${go_cli_bin}.tmp" "${go_cli_bin}"
            fi
        fi

        ufw allow 53/tcp comment "DNS TCP" >/dev/null 2>&1 || true
        ufw allow 53/udp comment "DNS UDP" >/dev/null 2>&1 || true
        systemctl enable pdns >/dev/null 2>&1 || true
        systemctl restart pdns >/dev/null 2>&1 || true
    fi

    log_ok "PowerDNS nameserver installed and configured successfully."
}

do_remove() {
    log_info "Removing PowerDNS module..."

    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        systemctl stop pdns >/dev/null 2>&1 || true
        systemctl disable pdns >/dev/null 2>&1 || true
        ufw delete allow 53/tcp >/dev/null 2>&1 || true
        ufw delete allow 53/udp >/dev/null 2>&1 || true
    fi

    log_ok "PowerDNS module removed."
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

