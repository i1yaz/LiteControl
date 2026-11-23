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
REPO_ROOT="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

DOMAINS_META_DIR="${LC_CONF_DIR}/domains"
VHOSTS_META_DIR="${LC_CONF_DIR}/vhosts"
ACME_WEBROOT="${LC_ACME_WEBROOT:-/var/www/litecontrol-acme}"
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

verify_dns_precheck() {
    local hostname="$1"

    if [[ "${LC_TEST_MODE:-0}" == "1" ]]; then
        return 0
    fi

    local resolved=""
    if command -v dig >/dev/null 2>&1; then
        resolved=$(dig +short A "${hostname}" 2>/dev/null | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' || true)
        if [[ -z "${resolved}" ]]; then
            resolved=$(dig +short AAAA "${hostname}" 2>/dev/null | grep -E '^[0-9a-fA-F:]+$' || true)
        fi
    elif command -v getent >/dev/null 2>&1; then
        resolved=$(getent ahosts "${hostname}" 2>/dev/null | awk '{print $1}' | sort -u || true)
    else
        # No DNS lookup tool available; let acme.sh handle validation
        return 0
    fi

    if [[ -z "${resolved}" ]]; then
        emit_error "DNS pre-check failed: '${hostname}' does not resolve yet. Create an A/AAAA record pointing to this server and wait for DNS propagation."
    fi

    local first_ip
    first_ip=$(printf "%s\n" "${resolved}" | head -n 1)
    if [[ "${first_ip}" =~ ^(127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.) || "${first_ip}" == "::1" ]]; then
        emit_error "DNS pre-check failed: '${hostname}' resolves to private/loopback IP '${first_ip}'. Point it to your server's public IP first."
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

domain_resolves_public() {
    local hostname="$1"
    local resolved=""
    if command -v dig >/dev/null 2>&1; then
        resolved=$(dig +time=2 +tries=1 +short A "${hostname}" 2>/dev/null | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' || true)
        if [[ -z "${resolved}" ]]; then
            resolved=$(dig +time=2 +tries=1 +short AAAA "${hostname}" 2>/dev/null | grep -E '^[0-9a-fA-F:]+$' || true)
        fi
    elif command -v getent >/dev/null 2>&1; then
        resolved=$(getent ahosts "${hostname}" 2>/dev/null | awk '{print $1}' | sort -u || true)
    else
        return 1
    fi

    [[ -n "${resolved}" ]] || return 1

    local first_ip
    first_ip=$(printf "%s\n" "${resolved}" | head -n 1)
    if [[ "${first_ip}" =~ ^(127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.) || "${first_ip}" == "::1" ]]; then
        return 1
    fi

    return 0
}

cmd_ssl_issue() {
    local domain="${1:-}"
    local doc_root="${2:-}"
    local email="${3:-}"

    [[ -n "${domain}" ]] || emit_error "Missing domain name."
    [[ -n "${doc_root}" ]] || emit_error "Missing document root directory."
    validate_domain "${domain}"
    domain="${domain,,}"
    verify_dns_precheck "${domain}"

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

        local acme_email="${email:-admin@${domain}}"
        "${ACME_BIN}" --register-account -m "${acme_email}" --server letsencrypt >/dev/null 2>&1 || true

        local -a domain_args=(-d "${domain}")
        if [[ "${domain}" != www.* ]] && domain_resolves_public "www.${domain}"; then
            domain_args+=(-d "www.${domain}")
        fi

        if [[ "${LC_JSON_OUTPUT:-0}" == "1" ]]; then
            local acme_out=""
            if ! acme_out=$("${ACME_BIN}" --issue "${domain_args[@]}" -w "${doc_root}" --server letsencrypt --keylength ec-256 --force 2>&1); then
                local acme_err
                acme_err=$(printf "%s\n" "${acme_out}" | grep -E "Invalid response|error|Error|detail" | tail -n 1 || true)
                emit_error "Let's Encrypt issuance failed for ${domain}. Please verify DNS points to this server and webroot is accessible.${acme_err:+ (${acme_err})}"
            fi

            "${ACME_BIN}" --install-cert -d "${domain}" --ecc \
                --key-file "${key_file}" \
                --fullchain-file "${cert_file}" >/dev/null 2>&1
        else
            log_info "Requesting Let's Encrypt certificate for ${domain} via acme.sh..."
            if ! "${ACME_BIN}" --issue "${domain_args[@]}" -w "${doc_root}" --server letsencrypt --keylength ec-256 --force; then
                emit_error "Let's Encrypt issuance failed for ${domain}. Please verify DNS points to this server and webroot is accessible."
            fi

            "${ACME_BIN}" --install-cert -d "${domain}" --ecc \
                --key-file "${key_file}" \
                --fullchain-file "${cert_file}"
        fi

        chmod 600 "${key_file}" 2>/dev/null || true
        chmod 644 "${cert_file}" 2>/dev/null || true
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

resolve_panel_proxy_tpl() {
    if [[ -f "${LC_BASE_DIR}/templates/ols/panel_proxy_vhconf.conf.tpl" ]]; then
        printf "%s" "${LC_BASE_DIR}/templates/ols/panel_proxy_vhconf.conf.tpl"
    else
        printf "%s" "${REPO_ROOT}/templates/ols/panel_proxy_vhconf.conf.tpl"
    fi
}

setup_panel_proxy_vhost() {
    local hostname="$1"
    local vh_name="lc_panel_proxy"
    local vh_dir="${OLS_VHOSTS_DIR}/${vh_name}"
    local vh_conf="${vh_dir}/vhconf.conf"
    local panel_port="${LC_DEFAULT_PORT:-8090}"

    if [[ -s "${LC_CONF_DIR}/port" ]]; then
        panel_port=$(tr -d ' \t\r\n' < "${LC_CONF_DIR}/port")
    fi

    # Prevent colliding with an existing user-hosted website domain
    if [[ -f "${VHOSTS_META_DIR}/${hostname}.conf" ]]; then
        emit_error "Domain '${hostname}' is already configured as a hosted website. Choose a dedicated subdomain (e.g. panel.${hostname})."
    fi

    mkdir -p "${ACME_WEBROOT}/.well-known/acme-challenge" "${vh_dir}"
    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        chown -R "${LC_USER}:${LC_GROUP}" "${ACME_WEBROOT}" 2>/dev/null || true
        chmod 755 "${ACME_WEBROOT}" "${ACME_WEBROOT}/.well-known" "${ACME_WEBROOT}/.well-known/acme-challenge"
    fi

    local tpl_file
    tpl_file=$(resolve_panel_proxy_tpl)
    if [[ ! -f "${tpl_file}" ]]; then
        emit_error "Panel proxy template not found at '${tpl_file}'."
    fi

    sed -e "s|__ACME_WEBROOT__|${ACME_WEBROOT}|g" \
        -e "s|__PANEL_DOMAIN__|${hostname}|g" \
        -e "s|__PANEL_PORT__|${panel_port}|g" \
        -e "s|__SSL_KEY__|${LC_SSL_DIR}/privkey.pem|g" \
        -e "s|__SSL_CERT__|${LC_SSL_DIR}/fullchain.pem|g" \
        "${tpl_file}" > "${vh_conf}"

    if [[ -f "${OLS_CONF}" ]]; then
        ols_backup_config

        # Remove any existing lc_panel_proxy block/maps first, strip legacy inline map comments,
        # and ensure catch-all 'map Example *' sits after specific vhost maps
        awk -v vh="${vh_name}" '
            $0 == "# VHOST_START:" vh { skip=1; next }
            $0 == "# VHOST_END:" vh { skip=0; next }
            index($0, "# MAP_HTTP:" vh) > 0 { next }
            index($0, "# MAP_HTTPS:" vh) > 0 { next }
            $1 == "map" && $2 == vh { next }
            $1 == "map" && $2 == "Example" && $3 == "*" { next }
            !skip {
                if ($1 == "map") {
                    sub(/[[:space:]]+# MAP_HTTPS?:[^[:space:]]+[[:space:]]*$/, "")
                }
                print
                if ($0 ~ /# END LITECONTROL MAP_HTTP$/ || $0 ~ /# END LITECONTROL MAP_HTTPS$/) {
                    print "  map                     Example *"
                }
            }
        ' "${OLS_CONF}" > "${OLS_CONF}.tmp" && mv "${OLS_CONF}.tmp" "${OLS_CONF}"

        local vhost_block
        vhost_block=$(cat <<EOF
# VHOST_START:${vh_name}
virtualhost ${vh_name} {
  vhRoot                  ${ACME_WEBROOT}
  configFile              conf/vhosts/${vh_name}/vhconf.conf
  allowSymbolLink         1
  enableScript            0
  restrained              1
  setUIDMode              0
}
# VHOST_END:${vh_name}
EOF
)

        awk -v block="${vhost_block}" -v vh="${vh_name}" -v dom="${hostname}" '
            /# END LITECONTROL VHOSTS$/ { print block }
            /# END LITECONTROL MAP_HTTP$/ {
                print "  # MAP_HTTP:" vh
                print "  map                     " vh " " dom
            }
            /# END LITECONTROL MAP_HTTPS$/ {
                print "  # MAP_HTTPS:" vh
                print "  map                     " vh " " dom
            }
            { print }
        ' "${OLS_CONF}" > "${OLS_CONF}.tmp" && mv "${OLS_CONF}.tmp" "${OLS_CONF}"

        if ! ols_safe_reload; then
            local detail="${OLS_LAST_ERROR:+ (${OLS_LAST_ERROR})}"
            emit_error "Failed to reload OpenLiteSpeed after configuring panel proxy for '${hostname}'; configuration rolled back.${detail}"
        fi
    fi
}

persist_panel_domain() {
    local hostname="$1"
    mkdir -p "${LC_CONF_DIR}"
    printf "%s\n" "${hostname}" > "${LC_CONF_DIR}/panel_domain"
    chmod 644 "${LC_CONF_DIR}/panel_domain" 2>/dev/null || true

    if [[ -f "${LC_PANEL_DIR}/.env" ]]; then
        if grep -qE '^APP_URL=' "${LC_PANEL_DIR}/.env"; then
            sed -i -E "s|^APP_URL=.*|APP_URL=https://${hostname}|" "${LC_PANEL_DIR}/.env"
        else
            printf "\nAPP_URL=https://%s\n" "${hostname}" >> "${LC_PANEL_DIR}/.env"
        fi
    fi
}

cmd_ssl_panel() {
    local action="${1:-}"
    shift || true

    case "${action}" in
        issue)
            local hostname="${1:-}"
            local email="${2:-}"
            [[ -n "${hostname}" ]] || emit_error "Missing panel hostname for SSL issuance."
            validate_domain "${hostname}"
            hostname="${hostname,,}"
            verify_dns_precheck "${hostname}"

            local panel_cert="${LC_SSL_DIR}/fullchain.pem"
            local panel_key="${LC_SSL_DIR}/privkey.pem"
            mkdir -p "${LC_SSL_DIR}"

            # Configure OLS system reverse-proxy vhost first so port 80 serves ACME HTTP-01 challenges
            setup_panel_proxy_vhost "${hostname}"

            if [[ "${LC_TEST_MODE:-0}" == "1" ]]; then
                openssl req -x509 -nodes -days 90 -newkey rsa:2048 \
                    -keyout "${panel_key}" \
                    -out "${panel_cert}" \
                    -subj "/CN=${hostname}" >/dev/null 2>&1 || true
            else
                local acme_email="${email:-admin@${hostname}}"
                "${ACME_BIN}" --register-account -m "${acme_email}" --server letsencrypt >/dev/null 2>&1 || true

                local acme_out=""
                if [[ "${LC_JSON_OUTPUT:-0}" == "1" ]]; then
                    if ! acme_out=$("${ACME_BIN}" --issue -d "${hostname}" -w "${ACME_WEBROOT}" --server letsencrypt --keylength ec-256 --force 2>&1); then
                        local acme_err
                        acme_err=$(printf "%s\n" "${acme_out}" | grep -E "Invalid response|error|Error|detail" | tail -n 1 || true)
                        emit_error "Failed to issue certificate for panel hostname ${hostname}.${acme_err:+ (${acme_err})}"
                    fi
                    "${ACME_BIN}" --install-cert -d "${hostname}" --ecc \
                        --key-file "${panel_key}" \
                        --fullchain-file "${panel_cert}" \
                        --reloadcmd "systemctl reload ${PANEL_SYSTEMD_SERVICE} 2>/dev/null || true; ${OLS_CTRL} reload 2>/dev/null || true" >/dev/null 2>&1
                else
                    "${ACME_BIN}" --issue -d "${hostname}" -w "${ACME_WEBROOT}" --server letsencrypt --keylength ec-256 --force || \
                        emit_error "Failed to issue certificate for panel hostname ${hostname}."

                    "${ACME_BIN}" --install-cert -d "${hostname}" --ecc \
                        --key-file "${panel_key}" \
                        --fullchain-file "${panel_cert}" \
                        --reloadcmd "systemctl reload ${PANEL_SYSTEMD_SERVICE} 2>/dev/null || true; ${OLS_CTRL} reload 2>/dev/null || true"
                fi
            fi

            persist_panel_domain "${hostname}"
            apply_panel_ip_access_config "disabled"
            ols_safe_reload
            panel_safe_reload
            if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
                local panel_port
                panel_port=$(get_panel_port)
                (sleep 3 && ufw delete allow "${panel_port}/tcp" >/dev/null 2>&1 || true) </dev/null >/dev/null 2>&1 &
            fi
            emit_success "Panel SSL certificate issued and HTTPS proxy enabled for '${hostname}'. Direct IP access has been disabled." "{\"hostname\":\"${hostname}\",\"proxy_enabled\":true,\"ip_access\":\"disabled\",\"url\":\"https://${hostname}\"}"
            ;;
        custom)
            local cert_src="${1:-}"
            local key_src="${2:-}"
            local hostname="${3:-}"
            [[ -n "${cert_src}" ]] || emit_error "Missing panel certificate file."
            [[ -n "${key_src}" ]] || emit_error "Missing panel private key file."
            validate_ssl_pair "${cert_src}" "${key_src}"

            mkdir -p "${LC_SSL_DIR}"
            cp -f "${cert_src}" "${LC_SSL_DIR}/fullchain.pem"
            cp -f "${key_src}" "${LC_SSL_DIR}/privkey.pem"
            chmod 600 "${LC_SSL_DIR}/privkey.pem"
            chmod 644 "${LC_SSL_DIR}/fullchain.pem"

            if [[ -n "${hostname}" ]]; then
                validate_domain "${hostname}"
                hostname="${hostname,,}"
                setup_panel_proxy_vhost "${hostname}"
                persist_panel_domain "${hostname}"
                apply_panel_ip_access_config "disabled"
            fi

            ols_safe_reload
            panel_safe_reload
            if [[ -n "${hostname}" ]]; then
                if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
                    local panel_port
                    panel_port=$(get_panel_port)
                    (sleep 3 && ufw delete allow "${panel_port}/tcp" >/dev/null 2>&1 || true) </dev/null >/dev/null 2>&1 &
                fi
                emit_success "Panel custom SSL certificate updated and HTTPS proxy enabled for '${hostname}'. Direct IP access has been disabled." "{\"installed\":true,\"hostname\":\"${hostname}\",\"proxy_enabled\":true,\"ip_access\":\"disabled\",\"url\":\"https://${hostname}\"}"
            else
                emit_success "Panel custom SSL certificate updated successfully." '{"installed":true}'
            fi
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

