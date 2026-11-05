#!/usr/bin/env bash
# ==============================================================================
# LiteControl CLI Command: `lc-cli ssl`
# Subcommands:
#   issue <domain> <doc_root> [email]
#   custom <domain> <cert_file> <key_file>
#   renew [domain]
#   panel-ssl <issue|custom> <domain_or_cert> [key_file]
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

DOMAINS_META_DIR="${LC_CONF_DIR}/domains"
ACME_BIN="${ACME_BIN:-/root/.acme.sh/acme.sh}"

emit_error() {
    local msg="$1"
    if [[ "${LC_JSON_OUTPUT:-0}" == "1" ]]; then
        json_response "error" "${msg}"
    else
        log_error "${msg}"
    fi
    exit 1
}

emit_success() {
    local msg="$1"
    local data_json="${2:-null}"
    if [[ "${LC_JSON_OUTPUT:-0}" == "1" ]]; then
        json_response "success" "${msg}" "${data_json}"
    else
        log_ok "${msg}"
    fi
}

validate_domain() {
    local d="$1"
    if ! [[ "${d}" =~ ^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$ ]]; then
        emit_error "Invalid domain name '${d}'."
    fi
}

validate_ssl_pair() {
    local cert="$1"
    local key="$2"

    if [[ ! -f "${cert}" ]]; then
        emit_error "Certificate file '${cert}' does not exist."
    fi
    if [[ ! -f "${key}" ]]; then
        emit_error "Private key file '${key}' does not exist."
    fi

    if [[ "${LC_TEST_MODE:-0}" == "1" ]]; then
        return 0
    fi

    # Check certificate validity
    if ! openssl x509 -noout -in "${cert}" >/dev/null 2>&1; then
        emit_error "File '${cert}' is not a valid X.509 certificate."
    fi

    # Compare public keys between cert and private key
    local cert_pub key_pub
    cert_pub=$(openssl x509 -noout -pubkey -in "${cert}" 2>/dev/null || true)
    key_pub=$(openssl pkey -pubout -in "${key}" 2>/dev/null || true)

    if [[ -n "${cert_pub}" && -n "${key_pub}" && "${cert_pub}" != "${key_pub}" ]]; then
        emit_error "Certificate '${cert}' and Private Key '${key}' do not match!"
    fi
}

apply_vhost_ssl() {
    local domain="$1"
    local cert_file="$2"
    local key_file="$3"
    local vhconf="${OLS_VHOSTS_DIR}/${domain}/vhconf.conf"

    if [[ -f "${vhconf}" ]]; then
        # Check if vhssl block already exists
        if grep -q "vhssl" "${vhconf}"; then
            # Replace keyFile and certFile inside vhssl
            sed -i -E "s|keyFile[[:space:]]+.*|keyFile ${key_file}|g" "${vhconf}"
            sed -i -E "s|certFile[[:space:]]+.*|certFile ${cert_file}|g" "${vhconf}"
        else
            cat >> "${vhconf}" <<EOF

vhssl {
  keyFile                 ${key_file}
  certFile                ${cert_file}
  certChain               1
  sslProtocol             24
  ciphers                 ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384
  enableECDHE             1
  renegProtection         1
  sslSessionCache         1
  enableSpdy              15
  enableQuic              1
}
EOF
        fi
    fi

    # Update domain metadata
    if [[ -f "${DOMAINS_META_DIR}/${domain}.conf" ]]; then
        sed -i 's/^ssl_enabled=.*/ssl_enabled=1/' "${DOMAINS_META_DIR}/${domain}.conf" 2>/dev/null || true
        sed -i "s|^ssl_cert=.*|ssl_cert=\"${cert_file}\"|" "${DOMAINS_META_DIR}/${domain}.conf" 2>/dev/null || true
        sed -i "s|^ssl_key=.*|ssl_key=\"${key_file}\"|" "${DOMAINS_META_DIR}/${domain}.conf" 2>/dev/null || true
    fi
}

# ------------------------------------------------------------------------------
# Subcommands
# ------------------------------------------------------------------------------

cmd_ssl_issue() {
    local domain="${1:-}"
    local doc_root="${2:-}"
    local email="${3:-}"

    [[ -n "${domain}" ]] || emit_error "Missing domain name."
    [[ -n "${doc_root}" ]] || emit_error "Missing document root directory."
    validate_domain "${domain}"

    local domain_ssl_dir="${LC_SSL_DIR}/domains/${domain}"
    mkdir -p "${domain_ssl_dir}"

    local cert_file="${domain_ssl_dir}/fullchain.pem"
    local key_file="${domain_ssl_dir}/privkey.pem"

    if [[ "${LC_TEST_MODE:-0}" == "1" ]]; then
        # Generate self-signed cert in test mode
        openssl req -x509 -nodes -days 90 -newkey rsa:2048 \
            -keyout "${key_file}" \
            -out "${cert_file}" \
            -subj "/CN=${domain}" >/dev/null 2>&1 || {
            echo "mock-cert" > "${cert_file}"
            echo "mock-key" > "${key_file}"
        }
    else
        if [[ ! -x "${ACME_BIN}" ]]; then
            emit_error "acme.sh is not installed at ${ACME_BIN}."
        fi

        local email_arg=""
        if [[ -n "${email}" ]]; then
            "${ACME_BIN}" --register-account -m "${email}" >/dev/null 2>&1 || true
        fi

        log_info "Requesting Let's Encrypt certificate for ${domain} via acme.sh..."
        if ! "${ACME_BIN}" --issue -d "${domain}" -d "www.${domain}" -w "${doc_root}" --keylength ec-256 --force; then
            emit_error "Let's Encrypt issuance failed for ${domain}. Please verify DNS points to this server and webroot is accessible."
        fi

        "${ACME_BIN}" --install-cert -d "${domain}" --ecc \
            --key-file "${key_file}" \
            --fullchain-file "${cert_file}"
    fi

    apply_vhost_ssl "${domain}" "${cert_file}" "${key_file}"
    ols_safe_reload

    local data_json
    data_json=$(printf '{"domain":"%s","cert_file":"%s","key_file":"%s","issuer":"letsencrypt"}' \
        "$(json_escape "${domain}")" \
        "$(json_escape "${cert_file}")" \
        "$(json_escape "${key_file}")")
    emit_success "Let's Encrypt SSL certificate issued successfully for '${domain}'." "${data_json}"
}

cmd_ssl_custom() {
    local domain="${1:-}"
    local cert_src="${2:-}"
    local key_src="${3:-}"

    [[ -n "${domain}" ]] || emit_error "Missing domain name."
    [[ -n "${cert_src}" ]] || emit_error "Missing certificate file."
    [[ -n "${key_src}" ]] || emit_error "Missing private key file."
    validate_domain "${domain}"
    validate_ssl_pair "${cert_src}" "${key_src}"

    local domain_ssl_dir="${LC_SSL_DIR}/domains/${domain}"
    mkdir -p "${domain_ssl_dir}"

    local cert_file="${domain_ssl_dir}/fullchain.pem"
    local key_file="${domain_ssl_dir}/privkey.pem"

    cp -f "${cert_src}" "${cert_file}"
    cp -f "${key_src}" "${key_file}"
    chmod 600 "${key_file}"
    chmod 644 "${cert_file}"

    apply_vhost_ssl "${domain}" "${cert_file}" "${key_file}"
    ols_safe_reload

    local data_json
    data_json=$(printf '{"domain":"%s","cert_file":"%s","key_file":"%s","issuer":"custom"}' \
        "$(json_escape "${domain}")" \
        "$(json_escape "${cert_file}")" \
        "$(json_escape "${key_file}")")
    emit_success "Custom SSL certificate installed successfully for '${domain}'." "${data_json}"
}

cmd_ssl_renew() {
    local domain="${1:-}"
    if [[ "${LC_TEST_MODE:-0}" == "1" ]]; then
        ols_safe_reload
        emit_success "SSL certificates renewed successfully." '{"renewed":true}'
        return 0
    fi

    if [[ ! -x "${ACME_BIN}" ]]; then
        emit_error "acme.sh not found."
    fi

    if [[ -n "${domain}" ]]; then
        "${ACME_BIN}" --renew -d "${domain}" --ecc --force || emit_error "Failed to renew certificate for ${domain}."
    else
        "${ACME_BIN}" --cron || true
    fi

    ols_safe_reload
    emit_success "SSL certificate renewal completed." '{"renewed":true}'
}

cmd_ssl_panel() {
    local action="${1:-}"
    shift || true

    case "${action}" in
        issue)
            local hostname="${1:-}"
            local email="${2:-}"
            [[ -n "${hostname}" ]] || emit_error "Missing panel hostname for SSL issuance."

            local panel_cert="${LC_SSL_DIR}/fullchain.pem"
            local panel_key="${LC_SSL_DIR}/privkey.pem"

            if [[ "${LC_TEST_MODE:-0}" == "1" ]]; then
                openssl req -x509 -nodes -days 90 -newkey rsa:2048 \
                    -keyout "${panel_key}" \
                    -out "${panel_cert}" \
                    -subj "/CN=${hostname}" >/dev/null 2>&1 || true
            else
                local webroot="${LC_PANEL_DIR}/public"
                mkdir -p "${webroot}"
                if [[ -n "${email}" ]]; then
                    "${ACME_BIN}" --register-account -m "${email}" >/dev/null 2>&1 || true
                fi
                "${ACME_BIN}" --issue -d "${hostname}" -w "${webroot}" --keylength ec-256 --force || \
                    emit_error "Failed to issue certificate for panel hostname ${hostname}."

                "${ACME_BIN}" --install-cert -d "${hostname}" --ecc \
                    --key-file "${panel_key}" \
                    --fullchain-file "${panel_cert}"
            fi

            ols_safe_reload
            emit_success "Panel SSL certificate issued for '${hostname}'." "{\"hostname\":\"${hostname}\"}"
            ;;
        custom)
            local cert_src="${1:-}"
            local key_src="${2:-}"
            [[ -n "${cert_src}" ]] || emit_error "Missing panel certificate file."
            [[ -n "${key_src}" ]] || emit_error "Missing panel private key file."
            validate_ssl_pair "${cert_src}" "${key_src}"

            cp -f "${cert_src}" "${LC_SSL_DIR}/fullchain.pem"
            cp -f "${key_src}" "${LC_SSL_DIR}/privkey.pem"
            chmod 600 "${LC_SSL_DIR}/privkey.pem"
            chmod 644 "${LC_SSL_DIR}/fullchain.pem"

            ols_safe_reload
            emit_success "Panel custom SSL certificate updated successfully." '{"installed":true}'
            ;;
        *)
            emit_error "Usage: lc-cli ssl panel-ssl <issue|custom> ..."
            ;;
    esac
}

# ------------------------------------------------------------------------------
# Dispatcher
# ------------------------------------------------------------------------------

ACTION="${1:-}"
shift || true

case "${ACTION}" in
    issue)
        cmd_ssl_issue "$@"
        ;;
    custom)
        cmd_ssl_custom "$@"
        ;;
    renew)
        cmd_ssl_renew "$@"
        ;;
    panel-ssl)
        cmd_ssl_panel "$@"
        ;;
    help|--help|-h|"")
        cat <<EOF
Usage: lc-cli ssl <command> [arguments...]

Commands:
  issue    <domain> <doc_root> [email]       Issue Let's Encrypt SSL certificate
  custom   <domain> <cert_file> <key_file>   Install custom SSL certificate
  renew    [domain]                          Renew certificate(s)
  panel-ssl issue <hostname> [email]         Issue Let's Encrypt SSL for panel
  panel-ssl custom <cert_file> <key_file>    Install custom SSL for panel listener
EOF
        ;;
    *)
        emit_error "Unknown ssl command '${ACTION}'."
        ;;
esac

