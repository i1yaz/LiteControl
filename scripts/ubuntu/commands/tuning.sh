#!/usr/bin/env bash
# ==============================================================================
# LiteControl CLI Command: `lc-cli tuning`
# Powers Configuration -> LiteSpeed Tuning in the Panel UI.
# Subcommands:
#   get
#   set <key=value> [key=value ...]
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

ALLOWED_KEYS=(
    "maxConnections"
    "maxSSLConnections"
    "connTimeout"
    "maxKeepAliveReq"
    "keepAliveTimeout"
    "maxReqURLLen"
    "maxReqHeaderSize"
    "maxReqBodySize"
    "maxDynRespHeaderSize"
    "maxDynRespSize"
    "enableGzipCompress"
    "gzipCompressLevel"
    "brStaticCompressLevel"
    "quicEnable"
)

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

is_allowed_key() {
    local key="$1"
    for k in "${ALLOWED_KEYS[@]}"; do
        if [[ "${k}" == "${key}" ]]; then
            return 0
        fi
    done
    return 1
}

read_tuning_key() {
    local key="$1"
    local default_val="$2"
    local val
    val=$(awk -v k="${key}" '/^tuning[[:space:]]*\{/,/^\}/ { if ($1 == k) { print $2; exit } }' "${OLS_CONF}")
    printf "%s" "${val:-${default_val}}"
}

cmd_tuning_get() {
    if [[ ! -f "${OLS_CONF}" ]]; then
        emit_error "OpenLiteSpeed configuration not found at ${OLS_CONF}."
    fi

    local max_conn max_ssl conn_timeout max_ka ka_timeout max_body gzip_en gzip_lvl quic_en
    max_conn=$(read_tuning_key "maxConnections" "10000")
    max_ssl=$(read_tuning_key "maxSSLConnections" "10000")
    conn_timeout=$(read_tuning_key "connTimeout" "300")
    max_ka=$(read_tuning_key "maxKeepAliveReq" "10000")
    ka_timeout=$(read_tuning_key "keepAliveTimeout" "5")
    max_body=$(read_tuning_key "maxReqBodySize" "2047M")
    gzip_en=$(read_tuning_key "enableGzipCompress" "1")
    gzip_lvl=$(read_tuning_key "gzipCompressLevel" "6")
    quic_en=$(read_tuning_key "quicEnable" "1")

    local data_json
    data_json=$(printf '{"maxConnections":%s,"maxSSLConnections":%s,"connTimeout":%s,"maxKeepAliveReq":%s,"keepAliveTimeout":%s,"maxReqBodySize":"%s","enableGzipCompress":%s,"gzipCompressLevel":%s,"quicEnable":%s}' \
        "${max_conn}" "${max_ssl}" "${conn_timeout}" "${max_ka}" "${ka_timeout}" \
        "$(json_escape "${max_body}")" "${gzip_en}" "${gzip_lvl}" "${quic_en}")

    emit_success "LiteSpeed tuning parameters retrieved." "${data_json}"
}

cmd_tuning_set() {
    if [[ $# -eq 0 ]]; then
        emit_error "Usage: lc-cli tuning set <key=value> [key=value ...]"
    fi
    if [[ ! -f "${OLS_CONF}" ]]; then
        emit_error "OpenLiteSpeed configuration not found at ${OLS_CONF}."
    fi

    ols_backup_config

    for pair in "$@"; do
        local key="${pair%%=*}"
        local val="${pair#*=}"
        if [[ "${key}" == "${pair}" || -z "${val}" ]]; then
            ols_restore_config
            emit_error "Invalid argument '${pair}'. Expected format: key=value"
        fi
        if ! is_allowed_key "${key}"; then
            ols_restore_config
            emit_error "Unsupported tuning key '${key}'."
        fi
        if ! [[ "${val}" =~ ^[a-zA-Z0-9]+$ ]]; then
            ols_restore_config
            emit_error "Invalid value '${val}' for '${key}'."
        fi

        awk -v k="${key}" -v v="${val}" '
            /^tuning[[:space:]]*\{/ { in_tuning=1 }
            in_tuning && $1 == k { $0 = sprintf("  %-23s %s", k, v) }
            in_tuning && /^\}/ { in_tuning=0 }
            { print }
        ' "${OLS_CONF}" > "${OLS_CONF}.tmp" && mv "${OLS_CONF}.tmp" "${OLS_CONF}"
    done

    if ! ols_safe_reload; then
        emit_error "OpenLiteSpeed rejected tuning changes; rolled back to last good configuration."
    fi

    cmd_tuning_get
}

SUBCOMMAND="${1:-get}"
shift || true

case "${SUBCOMMAND}" in
    get)
        cmd_tuning_get
        ;;
    set)
        cmd_tuning_set "$@"
        ;;
    *)
        emit_error "Usage: lc-cli tuning <get|set> [key=value ...]"
        ;;
esac

