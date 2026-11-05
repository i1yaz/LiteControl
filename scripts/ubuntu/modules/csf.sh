#!/usr/bin/env bash
# ==============================================================================
# LiteControl Module: ConfigServer Security & Firewall (`csf`)
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

ACTION="${1:-install}"
CSF_CONF="${CSF_CONF:-/etc/csf/csf.conf}"

do_install() {
    log_info "Installing ConfigServer Security & Firewall (CSF)..."

    local panel_port="${LC_DEFAULT_PORT}"
    if [[ -f "${LC_CONF_DIR}/port" ]]; then
        panel_port=$(tr -d ' \t\r\n' < "${LC_CONF_DIR}/port")
    fi

    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        apt_install perl libwww-perl liblwp-protocol-https-perl libgd-graph-perl iptables wget

        if [[ ! -f /usr/sbin/csf ]]; then
            local tmp_csf="/tmp/csf"
            mkdir -p "${tmp_csf}"
            wget -qO /tmp/csf.tgz https://download.configserver.com/csf.tgz
            tar -xzf /tmp/csf.tgz -C /tmp/
            (cd /tmp/csf && sh install.sh)
            rm -rf /tmp/csf /tmp/csf.tgz
        fi

        # Configure csf.conf
        if [[ -f "${CSF_CONF}" ]]; then
            sed -i 's/^TESTING = "1"/TESTING = "0"/' "${CSF_CONF}"
            sed -i 's/^RESTRICT_SYSLOG = "0"/RESTRICT_SYSLOG = "3"/' "${CSF_CONF}"

            # Ensure panel port is in TCP_IN
            if ! grep -E "^TCP_IN = .*(\b|,)${panel_port}(\b|,)" "${CSF_CONF}" >/dev/null; then
                sed -i -E "s/^(TCP_IN = \".*)\"/\1,${panel_port}\"/" "${CSF_CONF}"
            fi

            csf -r >/dev/null 2>&1 || true
            systemctl enable csf lfd >/dev/null 2>&1 || true
        fi
    else
        mkdir -p "$(dirname "${CSF_CONF}")"
        cat > "${CSF_CONF}" <<EOF
TESTING = "0"
TCP_IN = "20,21,22,25,53,80,110,143,443,465,587,993,995,${panel_port}"
TCP_OUT = "20,21,22,25,53,80,110,113,443,587,993,995"
EOF
    fi

    log_ok "ConfigServer Firewall (CSF) installed and activated."
}

do_remove() {
    log_info "Removing ConfigServer Security & Firewall (CSF)..."

    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        if [[ -x /etc/csf/uninstall.sh ]]; then
            sh /etc/csf/uninstall.sh >/dev/null 2>&1 || true
        else
            systemctl stop csf lfd >/dev/null 2>&1 || true
            systemctl disable csf lfd >/dev/null 2>&1 || true
        fi
    fi

    log_ok "ConfigServer Firewall (CSF) removed."
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

