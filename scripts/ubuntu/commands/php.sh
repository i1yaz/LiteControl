#!/usr/bin/env bash
# ==============================================================================
# LiteControl CLI Command: `lc-cli php`
# Powers the PHP Menu (MultiPHP INI Editor, MultiPHP Manager, PHP Extension,
# PHP Version, PHP Modules) and Configuration -> PHP Limit.
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

SUPPORTED_PHP=("74" "80" "81" "82" "83" "84" "85" "86")
COMMON_EXTENSIONS=("mysql" "curl" "intl" "opcache" "redis" "imagick" "igbinary" "memcached" "apcu" "soap" "ldap" "pgsql" "sqlite3" "snmp" "tidy" "imap")

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

normalize_ver() {
    local raw="${1:-}"
    raw="${raw#lsphp}"
    raw="${raw//./}"
    if ! [[ "${raw}" =~ ^(74|80|81|82|83|84|85|86)$ ]]; then
        emit_error "Unsupported PHP version '${1}'. Supported: 74, 80, 81, 82, 83, 84, 85, 86."
    fi
    printf "%s" "${raw}"
}

get_ini_path() {
    local ver="$1"
    local dotted="${ver:0:1}.${ver:1:1}"
    local candidate1="${OLS_DIR}/lsphp${ver}/etc/php/${dotted}/litespeed/php.ini"
    local candidate2="${OLS_DIR}/lsphp${ver}/etc/php.ini"

    if [[ -f "${candidate1}" ]]; then
        printf "%s" "${candidate1}"
    elif [[ -f "${candidate2}" ]]; then
        printf "%s" "${candidate2}"
    else
        printf "%s" "${candidate1}"
    fi
}

cmd_php_list() {
    local items=""
    local sep=""

    for ver in "${SUPPORTED_PHP[@]}"; do
        local dotted="${ver:0:1}.${ver:1:1}"
        local bin="${OLS_DIR}/lsphp${ver}/bin/php"
        local installed="false"
        if [[ -x "${bin}" ]] || is_module_installed "lsphp${ver}"; then
            installed="true"
        fi
        local is_panel="false"
        if [[ "${ver}" == "${LC_PHP_VER}" ]]; then
            is_panel="true"
        fi
        local ini_path
        ini_path=$(get_ini_path "${ver}")

        items+="${sep}$(printf '{"version":"%s","dotted":"%s","installed":%s,"is_panel_php":%s,"ini_path":"%s"}' \
            "${ver}" "${dotted}" "${installed}" "${is_panel}" "$(json_escape "${ini_path}")")"
        sep=","
    done

    local data_json="[${items}]"
    if [[ "${LC_JSON_OUTPUT:-0}" == "1" ]]; then
        json_response "success" "PHP versions retrieved." "${data_json}"
    else
        echo "${data_json}"
    fi
}

cmd_php_install() {
    local ver
    ver=$(normalize_ver "${1:-}")
    local dotted="${ver:0:1}.${ver:1:1}"

    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        if ! apt-cache show "lsphp${ver}" >/dev/null 2>&1; then
            add-apt-repository -y ppa:openlitespeed/php >/dev/null 2>&1 || true
            apt_update
        fi

        if ! apt-cache show "lsphp${ver}" >/dev/null 2>&1; then
            emit_error "LSPHP ${dotted} (lsphp${ver}) is not available in OpenLiteSpeed repositories for this OS release."
        fi

        apt_install "lsphp${ver}" "lsphp${ver}-common" "lsphp${ver}-mysql" "lsphp${ver}-curl" "lsphp${ver}-intl" "lsphp${ver}-opcache" || true
        for ext in redis imagick igbinary; do
            if apt-cache show "lsphp${ver}-${ext}" >/dev/null 2>&1; then
                apt_install "lsphp${ver}-${ext}" || true
            fi
        done
    else
        # Create sandbox mock php binary & php.ini
        mkdir -p "${OLS_DIR}/lsphp${ver}/bin" "${OLS_DIR}/lsphp${ver}/etc/php/${dotted}/litespeed"
        cat > "${OLS_DIR}/lsphp${ver}/bin/php" <<'EOF'
#!/usr/bin/env bash
if [[ "${1:-}" == "-m" ]]; then
    printf "Core\ndate\njson\nmbstring\nmysqli\nopcache\nPDO\nredis\n"
fi
EOF
        cp "${OLS_DIR}/lsphp${ver}/bin/php" "${OLS_DIR}/lsphp${ver}/bin/lsphp"
        chmod +x "${OLS_DIR}/lsphp${ver}/bin/php" "${OLS_DIR}/lsphp${ver}/bin/lsphp"
        cat > "${OLS_DIR}/lsphp${ver}/etc/php/${dotted}/litespeed/php.ini" <<'EOF'
memory_limit = 256M
upload_max_filesize = 128M
post_max_size = 128M
max_execution_time = 300
max_input_vars = 3000
display_errors = Off
EOF
    fi

    mark_module_installed "lsphp${ver}"

    # Register extprocessor lsphp<ver> in httpd_config.conf if not already present
    if [[ -f "${OLS_CONF}" ]] && ! grep -q "extprocessor lsphp${ver} " "${OLS_CONF}"; then
        ols_backup_config
        local php_block
        php_block=$(cat <<EOF
# PHP_START:${ver}
extprocessor lsphp${ver} {
  type                    lsapi
  address                 uds://tmp/lshttpd/lsphp${ver}.sock
  maxConns                10
  env                     PHP_LSAPI_CHILDREN=10
  env                     LSAPI_AVOID_FORK=200M
  initTimeout             60
  retryTimeout            0
  persistConn             1
  respBuffer              0
  autoStart               1
  path                    lsphp${ver}/bin/lsphp
  backlog                 100
  instances               1
  priority                0
  memSoftLimit            2047M
  memHardLimit            2047M
  procSoftLimit           1400
  procHardLimit           1500
}
# PHP_END:${ver}
EOF
)
        awk -v block="${php_block}" -v v="${ver}" '
            /# END LITECONTROL PHP/ { print block }
            /# END LITECONTROL SCRIPTHANDLERS/ { print "  add                     lsapi:lsphp" v " php" v " # SH_PHP:" v }
            { print }
        ' "${OLS_CONF}" > "${OLS_CONF}.tmp" && mv "${OLS_CONF}.tmp" "${OLS_CONF}"
        ols_safe_reload || true
    fi

    local data_json
    data_json=$(printf '{"version":"%s","dotted":"%s","installed":true}' "${ver}" "${dotted}")
    emit_success "PHP ${dotted} (lsphp${ver}) installed." "${data_json}"
}

cmd_php_remove() {
    local ver
    ver=$(normalize_ver "${1:-}")
    local dotted="${ver:0:1}.${ver:1:1}"

    if [[ "${ver}" == "${LC_PHP_VER}" ]]; then
        emit_error "Cannot uninstall lsphp${ver} because it is the primary PHP runtime for the LiteControl Panel."
    fi

    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        DEBIAN_FRONTEND=noninteractive apt-get remove -y -qq "lsphp${ver}*" 2>/dev/null || true
    else
        rm -rf "${OLS_DIR}/lsphp${ver}"
    fi

    mark_module_removed "lsphp${ver}"

    if [[ -f "${OLS_CONF}" ]]; then
        ols_backup_config
        awk -v v="${ver}" '
            $0 == "# PHP_START:" v { skip=1; next }
            $0 == "# PHP_END:" v { skip=0; next }
            index($0, "# SH_PHP:" v) > 0 { next }
            !skip { print }
        ' "${OLS_CONF}" > "${OLS_CONF}.tmp" && mv "${OLS_CONF}.tmp" "${OLS_CONF}"
        ols_safe_reload || true
    fi

    local data_json
    data_json=$(printf '{"version":"%s","removed":true}' "${ver}")
    emit_success "PHP ${dotted} (lsphp${ver}) removed." "${data_json}"
}

cmd_php_ini_get() {
    local ver
    ver=$(normalize_ver "${1:-}")
    local ini_file
    ini_file=$(get_ini_path "${ver}")

    if [[ ! -f "${ini_file}" ]]; then
        emit_error "php.ini not found for lsphp${ver} at ${ini_file}."
    fi

    read_ini_val() {
        local key="$1"
        local default_val="$2"
        local val
        val=$(awk -F= -v k="${key}" '$1 ~ "^[[:space:]]*"k"[[:space:]]*$" { gsub(/^[[:space:]]+|[[:space:]]+$/, "", $2); print $2; exit }' "${ini_file}")
        printf "%s" "${val:-${default_val}}"
    }

    local mem upload post exec_time input_vars disp_err
    mem=$(read_ini_val "memory_limit" "256M")
    upload=$(read_ini_val "upload_max_filesize" "128M")
    post=$(read_ini_val "post_max_size" "128M")
    exec_time=$(read_ini_val "max_execution_time" "300")
    input_vars=$(read_ini_val "max_input_vars" "3000")
    disp_err=$(read_ini_val "display_errors" "Off")

    local data_json
    data_json=$(printf '{"version":"%s","ini_path":"%s","directives":{"memory_limit":"%s","upload_max_filesize":"%s","post_max_size":"%s","max_execution_time":"%s","max_input_vars":"%s","display_errors":"%s"}}' \
        "${ver}" \
        "$(json_escape "${ini_file}")" \
        "$(json_escape "${mem}")" \
        "$(json_escape "${upload}")" \
        "$(json_escape "${post}")" \
        "$(json_escape "${exec_time}")" \
        "$(json_escape "${input_vars}")" \
        "$(json_escape "${disp_err}")")

    emit_success "PHP INI settings retrieved for lsphp${ver}." "${data_json}"
}

cmd_php_ini_set() {
    local ver
    ver=$(normalize_ver "${1:-}")
    local key="${2:-}"
    local val="${3:-}"

    if ! [[ "${key}" =~ ^[a-zA-Z0-9_.]+$ ]]; then
        emit_error "Invalid PHP INI directive name '${key}'."
    fi
    if [[ -z "${val}" ]]; then
        emit_error "Directive value is required."
    fi

    local ini_file
    ini_file=$(get_ini_path "${ver}")
    if [[ ! -f "${ini_file}" ]]; then
        emit_error "php.ini not found at ${ini_file}."
    fi

    if grep -qE "^[[:space:]]*;?[[:space:]]*${key}[[:space:]]*=" "${ini_file}"; then
        sed -i -E "s|^[[:space:]]*;?[[:space:]]*${key}[[:space:]]*=.*|${key} = ${val}|" "${ini_file}"
    else
        printf "\n%s = %s\n" "${key}" "${val}" >> "${ini_file}"
    fi

    local data_json
    data_json=$(printf '{"version":"%s","directive":"%s","value":"%s"}' \
        "${ver}" "$(json_escape "${key}")" "$(json_escape "${val}")")
    emit_success "Updated '${key} = ${val}' in lsphp${ver}." "${data_json}"
}

cmd_php_ext_list() {
    local ver
    ver=$(normalize_ver "${1:-}")
    local php_bin="${OLS_DIR}/lsphp${ver}/bin/php"

    local loaded=""
    if [[ -x "${php_bin}" ]]; then
        loaded=$("${php_bin}" -m 2>/dev/null | tr '\n' ',' || true)
    fi

    local items=""
    local sep=""
    for ext in "${COMMON_EXTENSIONS[@]}"; do
        local is_active="false"
        if [[ ",${loaded,,}," == *",${ext,,},"* ]] || is_module_installed "lsphp${ver}-${ext}"; then
            is_active="true"
        fi
        items+="${sep}$(printf '{"extension":"%s","package":"lsphp%s-%s","enabled":%s}' \
            "${ext}" "${ver}" "${ext}" "${is_active}")"
        sep=","
    done

    local data_json="[${items}]"
    emit_success "PHP extensions for lsphp${ver}." "${data_json}"
}

cmd_php_ext_toggle() {
    local action="$1" # enable | disable
    local ver
    ver=$(normalize_ver "${2:-}")
    local ext="${3:-}"

    if ! [[ "${ext}" =~ ^[a-z0-9_-]+$ ]]; then
        emit_error "Invalid extension name '${ext}'."
    fi

    if [[ "${action}" == "enable" ]]; then
        if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
            apt_install "lsphp${ver}-${ext}"
        fi
        mark_module_installed "lsphp${ver}-${ext}"
        emit_success "Enabled extension lsphp${ver}-${ext}." "{\"version\":\"${ver}\",\"extension\":\"${ext}\",\"enabled\":true}"
    else
        if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
            DEBIAN_FRONTEND=noninteractive apt-get remove -y -qq "lsphp${ver}-${ext}" 2>/dev/null || true
        fi
        mark_module_removed "lsphp${ver}-${ext}"
        emit_success "Disabled extension lsphp${ver}-${ext}." "{\"version\":\"${ver}\",\"extension\":\"${ext}\",\"enabled\":false}"
    fi
}

cmd_php_limits() {
    local mode="${1:-get}"
    shift || true

    if [[ ! -f "${OLS_CONF}" ]]; then
        emit_error "OpenLiteSpeed config not found at ${OLS_CONF}."
    fi

    if [[ "${mode}" == "get" ]]; then
        local max_conns mem_soft mem_hard proc_soft proc_hard
        max_conns=$(awk '/extprocessor lsphp \{/,/\}/ { if ($1 == "maxConns") print $2 }' "${OLS_CONF}" | head -n 1)
        mem_soft=$(awk '/extprocessor lsphp \{/,/\}/ { if ($1 == "memSoftLimit") print $2 }' "${OLS_CONF}" | head -n 1)
        mem_hard=$(awk '/extprocessor lsphp \{/,/\}/ { if ($1 == "memHardLimit") print $2 }' "${OLS_CONF}" | head -n 1)
        proc_soft=$(awk '/extprocessor lsphp \{/,/\}/ { if ($1 == "procSoftLimit") print $2 }' "${OLS_CONF}" | head -n 1)
        proc_hard=$(awk '/extprocessor lsphp \{/,/\}/ { if ($1 == "procHardLimit") print $2 }' "${OLS_CONF}" | head -n 1)

        local data_json
        data_json=$(printf '{"maxConns":%s,"memSoftLimit":"%s","memHardLimit":"%s","procSoftLimit":%s,"procHardLimit":%s}' \
            "${max_conns:-10}" \
            "${mem_soft:-2047M}" \
            "${mem_hard:-2047M}" \
            "${proc_soft:-1400}" \
            "${proc_hard:-1500}")
        emit_success "PHP LSAPI limits retrieved." "${data_json}"
    elif [[ "${mode}" == "set" ]]; then
        local max_conns="${1:-10}"
        local mem_soft="${2:-2047M}"
        local mem_hard="${3:-2047M}"
        local proc_soft="${4:-1400}"
        local proc_hard="${5:-1500}"

        ols_backup_config
        awk -v mc="${max_conns}" -v ms="${mem_soft}" -v mh="${mem_hard}" -v ps="${proc_soft}" -v ph="${proc_hard}" '
            /^extprocessor lsphp \{/ { in_block=1 }
            in_block && $1 == "maxConns" { $0 = "  maxConns                " mc }
            in_block && $1 == "env" && $2 ~ /^PHP_LSAPI_CHILDREN=/ { $0 = "  env                     PHP_LSAPI_CHILDREN=" mc }
            in_block && $1 == "memSoftLimit" { $0 = "  memSoftLimit            " ms }
            in_block && $1 == "memHardLimit" { $0 = "  memHardLimit            " mh }
            in_block && $1 == "procSoftLimit" { $0 = "  procSoftLimit           " ps }
            in_block && $1 == "procHardLimit" { $0 = "  procHardLimit           " ph }
            in_block && /^\}/ { in_block=0 }
            { print }
        ' "${OLS_CONF}" > "${OLS_CONF}.tmp" && mv "${OLS_CONF}.tmp" "${OLS_CONF}"

        if ! ols_safe_reload; then
            emit_error "Failed to apply PHP LSAPI limits; rolled back."
        fi

        local data_json
        data_json=$(printf '{"maxConns":%s,"memSoftLimit":"%s","memHardLimit":"%s","procSoftLimit":%s,"procHardLimit":%s}' \
            "${max_conns}" "${mem_soft}" "${mem_hard}" "${proc_soft}" "${proc_hard}")
        emit_success "PHP LSAPI limits updated." "${data_json}"
    else
        emit_error "Usage: lc-cli php limits <get|set> [maxConns] [memSoftLimit] [memHardLimit] [procSoftLimit] [procHardLimit]"
    fi
}

SUBCOMMAND="${1:-}"
shift || true

case "${SUBCOMMAND}" in
    list)
        cmd_php_list
        ;;
    install)
        cmd_php_install "$@"
        ;;
    remove)
        cmd_php_remove "$@"
        ;;
    ini-get)
        cmd_php_ini_get "$@"
        ;;
    ini-set)
        cmd_php_ini_set "$@"
        ;;
    ext-list|modules)
        cmd_php_ext_list "$@"
        ;;
    ext-enable)
        cmd_php_ext_toggle "enable" "$@"
        ;;
    ext-disable)
        cmd_php_ext_toggle "disable" "$@"
        ;;
    limits)
        cmd_php_limits "$@"
        ;;
    *)
        emit_error "Usage: lc-cli php <list|install|remove|ini-get|ini-set|ext-list|ext-enable|ext-disable|limits> [args...]"
        ;;
esac

