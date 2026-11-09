#!/usr/bin/env bash
# ==============================================================================
# LiteControl CLI Command: `lc-cli vhost`
# Subcommands:
#   add <domain> <username> [php_ver] [doc_root]
#   remove <domain> [--delete-files]
#   php-switch <domain> <php_ver>
#   ssl <domain> <cert_file> <key_file>
#   list
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

VHOSTS_META_DIR="${LC_CONF_DIR}/vhosts"

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
    local domain="$1"
    if ! [[ "${domain}" =~ ^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)+$ ]]; then
        emit_error "Invalid domain name '${domain}'."
    fi
}

normalize_php_ver() {
    local raw="${1:-83}"
    raw="${raw#lsphp}"
    raw="${raw//./}"
    if ! [[ "${raw}" =~ ^(74|80|81|82|83|84|85)$ ]]; then
        emit_error "Unsupported PHP version '${1}'. Expected one of: 74, 80, 81, 82, 83, 84, 85."
    fi
    printf "%s" "${raw}"
}

resolve_tpl_file() {
    if [[ -f "${LC_BASE_DIR}/templates/ols/user_vhconf.conf.tpl" ]]; then
        printf "%s" "${LC_BASE_DIR}/templates/ols/user_vhconf.conf.tpl"
    else
        printf "%s" "${REPO_ROOT}/templates/ols/user_vhconf.conf.tpl"
    fi
}

cmd_vhost_add() {
    local domain="${1:-}"
    local user="${2:-}"
    local raw_php="${3:-${LC_PHP_VER}}"
    local custom_docroot="${4:-}"

    validate_domain "${domain}"
    domain="${domain,,}"

    if [[ -z "${user}" ]]; then
        emit_error "Username is required: lc-cli vhost add <domain> <username> [php_ver] [doc_root]"
    fi

    local php_ver
    php_ver=$(normalize_php_ver "${raw_php}")

    if [[ -f "${VHOSTS_META_DIR}/${domain}.conf" || -d "${OLS_VHOSTS_DIR}/${domain}" ]]; then
        emit_error "VirtualHost for domain '${domain}' already exists."
    fi

    local home_dir="${LC_HOME_ROOT}/${user}"
    local doc_root="${custom_docroot:-${home_dir}/${domain}/public_html}"
    local log_dir="${home_dir}/${domain}/logs"
    local vh_dir="${OLS_VHOSTS_DIR}/${domain}"
    local vh_conf="${vh_dir}/vhconf.conf"
    local vh_safe_name="${domain//[^a-zA-Z0-9_]/_}"

    mkdir -p "${VHOSTS_META_DIR}" "${doc_root}" "${log_dir}" "${vh_dir}"

    if [[ ! -f "${doc_root}/index.php" && ! -f "${doc_root}/index.html" ]]; then
        cat > "${doc_root}/index.php" <<EOF
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Welcome to ${domain}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 2.5rem; text-align: center; max-width: 480px; }
        h1 { color: #38bdf8; margin-top: 0; }
        p { color: #94a3b8; }
    </style>
</head>
<body>
    <div class="card">
        <h1>${domain}</h1>
        <p>Website is active and powered by OpenLiteSpeed &amp; LiteControl (PHP <?= PHP_VERSION ?>).</p>
    </div>
</body>
</html>
EOF
    fi

    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        chown -R "${user}:${user}" "${home_dir}/${domain}" "${log_dir}" 2>/dev/null || true
        chmod 750 "${doc_root}" "${log_dir}"
        if command -v setfacl >/dev/null 2>&1; then
            setfacl -m u:nobody:x "${home_dir}" 2>/dev/null || true
            setfacl -m u:nobody:x "${home_dir}/${domain}" 2>/dev/null || true
            setfacl -m u:nobody:rx "${doc_root}" 2>/dev/null || true
        fi
    fi

    local tpl_file
    tpl_file=$(resolve_tpl_file)
    local ssl_key="${LC_SSL_DIR}/privkey.pem"
    local ssl_cert="${LC_SSL_DIR}/fullchain.pem"

    sed -e "s|__DOC_ROOT__|${doc_root}|g" \
        -e "s|__DOMAIN__|${domain}|g" \
        -e "s|__ADMIN_EMAIL__|admin@${domain}|g" \
        -e "s|__VH_LOG_DIR__|${log_dir}|g" \
        -e "s|__PHP_HANDLER__|${vh_safe_name}_php|g" \
        -e "s|__VH_NAME__|${vh_safe_name}|g" \
        -e "s|__PHP_VERSION__|lsphp${php_ver}|g" \
        -e "s|__SYS_USER__|${user}|g" \
        -e "s|__SYS_GROUP__|${user}|g" \
        -e "s|__SSL_KEY__|${ssl_key}|g" \
        -e "s|__SSL_CERT__|${ssl_cert}|g" \
        "${tpl_file}" > "${vh_conf}"

    # Update httpd_config.conf with atomic rollback safety
    ols_backup_config

    local vhost_block
    vhost_block=$(cat <<EOF
# VHOST_START:${domain}
virtualhost ${domain} {
  vhRoot                  ${home_dir}/${domain}
  configFile              conf/vhosts/${domain}/vhconf.conf
  allowSymbolLink         1
  enableScript            1
  restrained              1
  setUIDMode              2
}
# VHOST_END:${domain}
EOF
)

    awk -v block="${vhost_block}" -v dom="${domain}" '
        /# END LITECONTROL VHOSTS/ { print block }
        /# END LITECONTROL MAP_HTTP/ { print "  map                     " dom " " dom ", www." dom " # MAP_HTTP:" dom }
        /# END LITECONTROL MAP_HTTPS/ { print "  map                     " dom " " dom ", www." dom " # MAP_HTTPS:" dom }
        { print }
    ' "${OLS_CONF}" > "${OLS_CONF}.tmp" && mv "${OLS_CONF}.tmp" "${OLS_CONF}"

    if ! ols_safe_reload; then
        rm -rf "${vh_dir}"
        emit_error "Failed to reload OpenLiteSpeed after adding '${domain}'; configuration rolled back."
    fi

    cat > "${VHOSTS_META_DIR}/${domain}.conf" <<EOF
DOMAIN=${domain}
USERNAME=${user}
PHP_VER=${php_ver}
DOC_ROOT=${doc_root}
SSL_ENABLED=false
CREATED_AT=$(date -Iseconds)
EOF

    local data_json
    data_json=$(printf '{"domain":"%s","username":"%s","php_version":"%s","doc_root":"%s","ssl_enabled":false}' \
        "$(json_escape "${domain}")" \
        "$(json_escape "${user}")" \
        "${php_ver}" \
        "$(json_escape "${doc_root}")")

    emit_success "VirtualHost '${domain}' added and active." "${data_json}"
}

cmd_vhost_remove() {
    local domain="${1:-}"
    local flag="${2:-}"

    validate_domain "${domain}"
    domain="${domain,,}"

    if [[ ! -f "${VHOSTS_META_DIR}/${domain}.conf" && ! -d "${OLS_VHOSTS_DIR}/${domain}" ]]; then
        emit_error "VirtualHost '${domain}' does not exist."
    fi

    local doc_root=""
    if [[ -f "${VHOSTS_META_DIR}/${domain}.conf" ]]; then
        doc_root=$(awk -F= '/^DOC_ROOT=/{print $2}' "${VHOSTS_META_DIR}/${domain}.conf")
    fi

    ols_backup_config

    # Remove vhost block and listener maps for this domain from httpd_config.conf
    if [[ -f "${OLS_CONF}" ]]; then
        awk -v dom="${domain}" '
            $0 == "# VHOST_START:" dom { skip=1; next }
            $0 == "# VHOST_END:" dom { skip=0; next }
            index($0, "# MAP_HTTP:" dom) > 0 { next }
            index($0, "# MAP_HTTPS:" dom) > 0 { next }
            !skip { print }
        ' "${OLS_CONF}" > "${OLS_CONF}.tmp" && mv "${OLS_CONF}.tmp" "${OLS_CONF}"
    fi

    rm -rf "${OLS_VHOSTS_DIR:?}/${domain}"

    if ! ols_safe_reload; then
        emit_error "Failed to reload OpenLiteSpeed after removing '${domain}'; rolled back."
    fi

    if [[ "${flag}" == "--delete-files" && -n "${doc_root}" && -d "${doc_root}" ]]; then
        rm -rf "${doc_root}"
    fi

    rm -f "${VHOSTS_META_DIR}/${domain}.conf"

    local data_json
    data_json=$(printf '{"domain":"%s","removed":true}' "$(json_escape "${domain}")")
    emit_success "VirtualHost '${domain}' removed." "${data_json}"
}

cmd_vhost_php_switch() {
    local domain="${1:-}"
    local raw_php="${2:-}"

    validate_domain "${domain}"
    domain="${domain,,}"
    local php_ver
    php_ver=$(normalize_php_ver "${raw_php}")

    local vh_conf="${OLS_VHOSTS_DIR}/${domain}/vhconf.conf"
    if [[ ! -f "${vh_conf}" ]]; then
        emit_error "VirtualHost config for '${domain}' not found at ${vh_conf}."
    fi

    if [[ "${LC_TEST_MODE:-0}" != "1" && ! -x "${OLS_DIR}/lsphp${php_ver}/bin/lsphp" ]]; then
        emit_error "PHP version lsphp${php_ver} is not installed. Install it first with 'lc-cli php install ${php_ver}'."
    fi

    cp -a "${vh_conf}" "${vh_conf}.bak"
    sed -i "s|/usr/local/lsws/lsphp[0-9]*/bin/lsphp|/usr/local/lsws/lsphp${php_ver}/bin/lsphp|g" "${vh_conf}"

    if ! ols_safe_reload; then
        mv "${vh_conf}.bak" "${vh_conf}"
        emit_error "Failed to switch PHP version for '${domain}'; rolled back."
    fi
    rm -f "${vh_conf}.bak"

    if [[ -f "${VHOSTS_META_DIR}/${domain}.conf" ]]; then
        sed -i "s/^PHP_VER=.*/PHP_VER=${php_ver}/" "${VHOSTS_META_DIR}/${domain}.conf"
    fi

    local data_json
    data_json=$(printf '{"domain":"%s","php_version":"%s"}' "$(json_escape "${domain}")" "${php_ver}")
    emit_success "Switched '${domain}' to PHP ${php_ver:0:1}.${php_ver:1:1} (lsphp${php_ver})." "${data_json}"
}

cmd_vhost_ssl() {
    local domain="${1:-}"
    local cert_file="${2:-}"
    local key_file="${3:-}"

    validate_domain "${domain}"
    domain="${domain,,}"

    local vh_conf="${OLS_VHOSTS_DIR}/${domain}/vhconf.conf"
    if [[ ! -f "${vh_conf}" ]]; then
        emit_error "VirtualHost config for '${domain}' not found."
    fi
    if [[ ! -f "${cert_file}" || ! -f "${key_file}" ]]; then
        emit_error "SSL certificate or private key file does not exist."
    fi

    cp -a "${vh_conf}" "${vh_conf}.bak"
    sed -i "s|^  keyFile .*|  keyFile                 ${key_file}|" "${vh_conf}"
    sed -i "s|^  certFile .*|  certFile                ${cert_file}|" "${vh_conf}"

    if ! ols_safe_reload; then
        mv "${vh_conf}.bak" "${vh_conf}"
        emit_error "OpenLiteSpeed rejected SSL configuration for '${domain}'; rolled back."
    fi
    rm -f "${vh_conf}.bak"

    if [[ -f "${VHOSTS_META_DIR}/${domain}.conf" ]]; then
        sed -i "s/^SSL_ENABLED=.*/SSL_ENABLED=true/" "${VHOSTS_META_DIR}/${domain}.conf"
    fi

    local data_json
    data_json=$(printf '{"domain":"%s","ssl_enabled":true,"cert_file":"%s"}' \
        "$(json_escape "${domain}")" \
        "$(json_escape "${cert_file}")")
    emit_success "SSL certificate updated for '${domain}'." "${data_json}"
}

cmd_vhost_docroot() {
    local domain="${1:-}"
    local new_docroot="${2:-}"

    validate_domain "${domain}"
    domain="${domain,,}"

    if [[ -z "${new_docroot}" ]]; then
        emit_error "New document root is required: lc-cli vhost docroot <domain> <new_docroot>"
    fi

    local vh_conf="${OLS_VHOSTS_DIR}/${domain}/vhconf.conf"
    if [[ ! -f "${vh_conf}" ]]; then
        emit_error "VirtualHost configuration for '${domain}' does not exist."
    fi

    local user=""
    if [[ -f "${VHOSTS_META_DIR}/${domain}.conf" ]]; then
        user=$(awk -F= '/^USERNAME=/{print $2}' "${VHOSTS_META_DIR}/${domain}.conf")
    fi

    mkdir -p "${new_docroot}"

    if [[ -n "${user}" && "${LC_TEST_MODE:-0}" != "1" ]]; then
        chown -R "${user}:${user}" "${new_docroot}" 2>/dev/null || true
        chmod 750 "${new_docroot}"
        if command -v setfacl >/dev/null 2>&1; then
            setfacl -m u:nobody:rx "${new_docroot}" 2>/dev/null || true
        fi
    fi

    cp "${vh_conf}" "${vh_conf}.bak"
    sed -i -E "s|^docRoot[[:space:]]+.*|docRoot                   ${new_docroot}|g" "${vh_conf}"

    if ! ols_safe_reload; then
        mv "${vh_conf}.bak" "${vh_conf}"
        ols_safe_reload || true
        emit_error "OpenLiteSpeed rejected document root change for '${domain}'; configuration rolled back."
    fi
    rm -f "${vh_conf}.bak"

    if [[ -f "${VHOSTS_META_DIR}/${domain}.conf" ]]; then
        sed -i "s|^DOC_ROOT=.*|DOC_ROOT=${new_docroot}|" "${VHOSTS_META_DIR}/${domain}.conf"
    fi

    local data_json
    data_json=$(printf '{"domain":"%s","doc_root":"%s"}' \
        "$(json_escape "${domain}")" \
        "$(json_escape "${new_docroot}")")
    emit_success "Document root updated successfully for '${domain}'." "${data_json}"
}

cmd_vhost_list() {
    mkdir -p "${VHOSTS_META_DIR}"
    local items=""
    local sep=""

    for conf in "${VHOSTS_META_DIR}"/*.conf; do
        [[ -e "${conf}" ]] || continue
        local domain user php_ver doc_root ssl_enabled
        domain=$(awk -F= '/^DOMAIN=/{print $2}' "${conf}")
        user=$(awk -F= '/^USERNAME=/{print $2}' "${conf}")
        php_ver=$(awk -F= '/^PHP_VER=/{print $2}' "${conf}")
        doc_root=$(awk -F= '/^DOC_ROOT=/{print $2}' "${conf}")
        ssl_enabled=$(awk -F= '/^SSL_ENABLED=/{print $2}' "${conf}")
        [[ "${ssl_enabled}" == "true" ]] || ssl_enabled="false"

        items+="${sep}$(printf '{"domain":"%s","username":"%s","php_version":"%s","doc_root":"%s","ssl_enabled":%s}' \
            "$(json_escape "${domain}")" \
            "$(json_escape "${user}")" \
            "${php_ver:-83}" \
            "$(json_escape "${doc_root}")" \
            "${ssl_enabled}")"
        sep=","
    done

    local data_json="[${items}]"
    if [[ "${LC_JSON_OUTPUT:-0}" == "1" ]]; then
        json_response "success" "VirtualHost list retrieved." "${data_json}"
    else
        echo "${data_json}"
    fi
}

cmd_vhost_suspend() {
    local domain="${1:-}"
    validate_domain "${domain}"
    domain="${domain,,}"

    local vh_conf="${OLS_VHOSTS_DIR}/${domain}/vhconf.conf"
    if [[ ! -f "${vh_conf}" ]]; then
        emit_error "VirtualHost config for '${domain}' not found."
    fi

    cp -a "${vh_conf}" "${vh_conf}.bak"
    # Set allowBrowse to 0 in context / to suspend website
    sed -i -E '/context \/ \{/,/\}/ s/allowBrowse[[:space:]]+[0-9]+/allowBrowse             0/' "${vh_conf}"

    if ! ols_safe_reload; then
        mv "${vh_conf}.bak" "${vh_conf}"
        emit_error "Failed to suspend '${domain}'; rolled back."
    fi
    rm -f "${vh_conf}.bak"

    if [[ -f "${VHOSTS_META_DIR}/${domain}.conf" ]]; then
        sed -i "s/^STATUS=.*/STATUS=suspended/" "${VHOSTS_META_DIR}/${domain}.conf" 2>/dev/null || echo "STATUS=suspended" >> "${VHOSTS_META_DIR}/${domain}.conf"
    fi

    local data_json
    data_json=$(printf '{"domain":"%s","status":"suspended"}' "$(json_escape "${domain}")")
    emit_success "VirtualHost '${domain}' suspended." "${data_json}"
}

cmd_vhost_unsuspend() {
    local domain="${1:-}"
    validate_domain "${domain}"
    domain="${domain,,}"

    local vh_conf="${OLS_VHOSTS_DIR}/${domain}/vhconf.conf"
    if [[ ! -f "${vh_conf}" ]]; then
        emit_error "VirtualHost config for '${domain}' not found."
    fi

    cp -a "${vh_conf}" "${vh_conf}.bak"
    # Restore allowBrowse to 1 in context /
    sed -i -E '/context \/ \{/,/\}/ s/allowBrowse[[:space:]]+[0-9]+/allowBrowse             1/' "${vh_conf}"

    if ! ols_safe_reload; then
        mv "${vh_conf}.bak" "${vh_conf}"
        emit_error "Failed to unsuspend '${domain}'; rolled back."
    fi
    rm -f "${vh_conf}.bak"

    if [[ -f "${VHOSTS_META_DIR}/${domain}.conf" ]]; then
        sed -i "s/^STATUS=.*/STATUS=active/" "${VHOSTS_META_DIR}/${domain}.conf" 2>/dev/null || echo "STATUS=active" >> "${VHOSTS_META_DIR}/${domain}.conf"
    fi

    local data_json
    data_json=$(printf '{"domain":"%s","status":"active"}' "$(json_escape "${domain}")")
    emit_success "VirtualHost '${domain}' unsuspended." "${data_json}"
}

cmd_vhost_alias_add() {
    local domain="${1:-}"
    local alias="${2:-}"

    validate_domain "${domain}"
    validate_domain "${alias}"
    domain="${domain,,}"
    alias="${alias,,}"

    local vh_conf="${OLS_VHOSTS_DIR}/${domain}/vhconf.conf"
    if [[ ! -f "${vh_conf}" ]]; then
        emit_error "VirtualHost config for '${domain}' not found."
    fi

    cp -a "${vh_conf}" "${vh_conf}.bak"
    # Append alias to vhAliases in vhconf.conf
    sed -i -E "s|^vhAliases[[:space:]]+(.*)|vhAliases                 \1, ${alias}, www.${alias}|" "${vh_conf}"

    # Also map alias in httpd_config.conf
    ols_backup_config
    if [[ -f "${OLS_CONF}" ]]; then
        awk -v dom="${domain}" -v al="${alias}" '
            $0 ~ "# MAP_HTTP:" dom {
                print $0
                print "  map                     " dom " " al ", www." al " # MAP_HTTP:" dom
                next
            }
            $0 ~ "# MAP_HTTPS:" dom {
                print $0
                print "  map                     " dom " " al ", www." al " # MAP_HTTPS:" dom
                next
            }
            { print }
        ' "${OLS_CONF}" > "${OLS_CONF}.tmp" && mv "${OLS_CONF}.tmp" "${OLS_CONF}"
    fi

    if ! ols_safe_reload; then
        mv "${vh_conf}.bak" "${vh_conf}"
        ols_safe_reload || true
        emit_error "OpenLiteSpeed rejected alias addition for '${alias}'; rolled back."
    fi
    rm -f "${vh_conf}.bak"

    local data_json
    data_json=$(printf '{"domain":"%s","alias":"%s"}' "$(json_escape "${domain}")" "$(json_escape "${alias}")")
    emit_success "Domain alias '${alias}' registered for '${domain}'." "${data_json}"
}

cmd_vhost_conf_get() {
    local domain="${1:-}"
    validate_domain "${domain}"
    domain="${domain,,}"

    local vh_conf="${OLS_VHOSTS_DIR}/${domain}/vhconf.conf"
    if [[ ! -f "${vh_conf}" ]]; then
        emit_error "VirtualHost configuration for '${domain}' does not exist."
    fi

    local content
    content=$(cat "${vh_conf}")

    local data_json
    data_json=$(printf '{"domain":"%s","conf":"%s"}' \
        "$(json_escape "${domain}")" \
        "$(json_escape "${content}")")

    emit_success "Configuration retrieved for '${domain}'." "${data_json}"
}

cmd_vhost_size() {
    local domain="${1:-}"
    validate_domain "${domain}"
    domain="${domain,,}"

    local user=""
    if [[ -f "${VHOSTS_META_DIR}/${domain}.conf" ]]; then
        user=$(awk -F= '/^USERNAME=/{print $2}' "${VHOSTS_META_DIR}/${domain}.conf")
    fi

    local target_dir="${LC_HOME_ROOT}/${user}/${domain}"
    if [[ -z "${user}" || ! -d "${target_dir}" ]]; then
        target_dir="${OLS_VHOSTS_DIR}/${domain}"
    fi

    local size_bytes=0
    if [[ -d "${target_dir}" ]]; then
        size_bytes=$(du -sb "${target_dir}" 2>/dev/null | awk '{print $1}')
        size_bytes="${size_bytes:-0}"
    fi

    local data_json
    data_json=$(printf '{"domain":"%s","size_bytes":%s}' "$(json_escape "${domain}")" "${size_bytes}")
    emit_success "Size calculated for '${domain}'." "${data_json}"
}

SUBCOMMAND="${1:-}"
shift || true

case "${SUBCOMMAND}" in
    add)
        cmd_vhost_add "$@"
        ;;
    remove)
        cmd_vhost_remove "$@"
        ;;
    suspend)
        cmd_vhost_suspend "$@"
        ;;
    unsuspend)
        cmd_vhost_unsuspend "$@"
        ;;
    alias-add)
        cmd_vhost_alias_add "$@"
        ;;
    conf-get)
        cmd_vhost_conf_get "$@"
        ;;
    size)
        cmd_vhost_size "$@"
        ;;
    php-switch)
        cmd_vhost_php_switch "$@"
        ;;
    ssl)
        cmd_vhost_ssl "$@"
        ;;
    docroot)
        cmd_vhost_docroot "$@"
        ;;
    list)
        cmd_vhost_list
        ;;
    *)
        emit_error "Usage: lc-cli vhost <add|remove|suspend|unsuspend|alias-add|conf-get|size|php-switch|ssl|docroot|list> [args...]"
        ;;
esac

