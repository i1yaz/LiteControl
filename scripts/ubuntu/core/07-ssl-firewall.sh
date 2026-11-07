#!/usr/bin/env bash
# ==============================================================================
# LiteControl Core 07: SSL Engine (acme.sh), Default Certs & UFW Firewall
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

install_acme_sh() {
    log_step "07. Configuring SSL Engine (acme.sh) and UFW Firewall"

    if [[ ! -f /root/.acme.sh/acme.sh ]]; then
        log_info "Installing acme.sh SSL client..."
        curl -fsSL https://get.acme.sh | sh -s email=admin@localhost >/dev/null 2>&1 || true
    fi

    if [[ -x /root/.acme.sh/acme.sh ]]; then
        /root/.acme.sh/acme.sh --set-default-ca --server letsencrypt >/dev/null 2>&1 || true
        /root/.acme.sh/acme.sh --upgrade --auto-upgrade >/dev/null 2>&1 || true
        log_ok "acme.sh configured with Let's Encrypt CA."
    fi
}

generate_initial_ssl_certs() {
    mkdir -p "${LC_SSL_DIR}"
    local key_file="${LC_SSL_DIR}/privkey.pem"
    local cert_file="${LC_SSL_DIR}/fullchain.pem"

    if [[ ! -f "${key_file}" || ! -f "${cert_file}" ]]; then
        local srv_ip
        srv_ip=$(get_server_ip)
        log_info "Generating initial self-signed SSL certificate for ${srv_ip}..."
        openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
            -keyout "${key_file}" \
            -out "${cert_file}" \
            -subj "/C=US/ST=Server/L=Cloud/O=LiteControl/CN=${srv_ip}" >/dev/null 2>&1
        chmod 600 "${key_file}"
        chmod 644 "${cert_file}"
        log_ok "Generated SSL certificate at ${LC_SSL_DIR}."
    fi
}

configure_ufw_firewall() {
    local panel_port="${1:-${LC_DEFAULT_PORT}}"
    apt_install ufw

    # Detect active SSH port so we never lock out custom SSH configurations
    local ssh_port="22"
    if [[ -f /etc/ssh/sshd_config ]]; then
        local detected_ssh
        detected_ssh=$(awk '/^Port [0-9]+/{print $2}' /etc/ssh/sshd_config | head -n 1 || true)
        if [[ -n "${detected_ssh}" ]]; then
            ssh_port="${detected_ssh}"
        fi
    fi

    log_info "Configuring UFW firewall rules (SSH: ${ssh_port}, HTTP: 80, HTTPS/QUIC: 443, Panel: ${panel_port}, OLS WebAdmin: 7080)..."

    ufw allow "${ssh_port}/tcp" comment "SSH" >/dev/null 2>&1 || true
    ufw allow 22/tcp comment "SSH Default" >/dev/null 2>&1 || true
    ufw allow 80/tcp comment "HTTP" >/dev/null 2>&1 || true
    ufw allow 443/tcp comment "HTTPS" >/dev/null 2>&1 || true
    ufw allow 443/udp comment "HTTP3 QUIC" >/dev/null 2>&1 || true
    ufw allow "${panel_port}/tcp" comment "LiteControl Panel" >/dev/null 2>&1 || true
    ufw allow 7080/tcp comment "OLS WebAdmin" >/dev/null 2>&1 || true

    systemctl enable ufw >/dev/null 2>&1 || true
    ufw --force enable >/dev/null 2>&1 || true
    ufw reload >/dev/null 2>&1 || true

    mark_module_installed "ufw"
    log_ok "UFW firewall enabled and core ports opened."
}

main() {
    require_root
    detect_ubuntu_os
    init_litecontrol_dirs
    local panel_port="${1:-${LC_DEFAULT_PORT}}"
    install_acme_sh
    generate_initial_ssl_certs
    configure_ufw_firewall "${panel_port}"
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi

