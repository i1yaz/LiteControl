#!/usr/bin/env bash
# ==============================================================================
# LiteControl CLI Command: `lc-cli mail`
# Delegates to the compiled Go CLI binary (`lc-cli-go mail ...`) or builds
# from `cli/` when Go is installed.
# Also injects DB credentials & non-interactive env and updates module markers.
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_DIR="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

GO_CLI_BIN="${LC_GO_CLI_BIN:-${BASE_DIR}/bin/lc-cli-go}"
MAIL_CLI_LOG="${LC_MAIL_CLI_LOG:-/var/log/litecontrol/panel-error.log}"

log_mail_raw() {
    mkdir -p "$(dirname "${MAIL_CLI_LOG}")" 2>/dev/null || true
    printf '[%s] %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*" >> "${MAIL_CLI_LOG}" 2>/dev/null || true
}

ensure_go_cli_binary() {
    if [[ -x "${GO_CLI_BIN}" ]]; then
        return 0
    fi

    local go_bin=""
    go_bin=$(command -v go 2>/dev/null || true)
    if [[ -z "${go_bin}" && -x /usr/local/go/bin/go ]]; then
        go_bin="/usr/local/go/bin/go"
    fi

    log_mail_raw "lc-cli-go missing at ${GO_CLI_BIN}; go_bin='${go_bin:-none}', cli_dir_exists=$([[ -d "${BASE_DIR}/cli" ]] && echo yes || echo no)"

    if [[ -n "${go_bin}" && -d "${BASE_DIR}/cli" ]]; then
        mkdir -p "$(dirname "${GO_CLI_BIN}")"
        local build_out=""
        if build_out=$(cd "${BASE_DIR}/cli" && CGO_ENABLED=0 "${go_bin}" build -ldflags="-s -w" -o "${GO_CLI_BIN}.tmp" . 2>&1); then
            chmod 0750 "${GO_CLI_BIN}.tmp"
            mv -f "${GO_CLI_BIN}.tmp" "${GO_CLI_BIN}"
            log_mail_raw "Successfully built ${GO_CLI_BIN} using ${go_bin}"
            return 0
        else
            log_mail_raw "go build failed: ${build_out}"
            rm -f "${GO_CLI_BIN}.tmp" 2>/dev/null || true
            echo "Failed to build LiteControl Go CLI binary (see ${MAIL_CLI_LOG}): ${build_out}" >&2
            exit 1
        fi
    fi

    local msg="LiteControl Go CLI binary not found at ${GO_CLI_BIN} (go='${go_bin:-not installed}', cli_dir='${BASE_DIR}/cli')."
    log_mail_raw "${msg}"
    echo "${msg}" >&2
    exit 1
}

read_panel_env() {
    local key="$1"
    local env_file="${LC_PANEL_DIR}/.env"
    if [[ ! -f "${env_file}" ]]; then
        return 0
    fi
    local line
    line=$(grep -E "^${key}=" "${env_file}" 2>/dev/null | tail -n 1 || true)
    line="${line#*=}"
    line="${line%\"}"
    line="${line#\"}"
    line="${line%\'}"
    line="${line#\'}"
    printf "%s" "${line}"
}

ensure_go_cli_binary

export DEBIAN_FRONTEND=noninteractive

# Provide persistent read-only MariaDB password for Postfix/Dovecot/reconcile
if [[ -z "${MAIL_RO_DB_PASSWORD:-}" && "${LC_TEST_MODE:-0}" != "1" ]]; then
    export MAIL_RO_DB_PASSWORD="$(get_or_create_secret "mail_ro_db_password" 24)"
fi

if [[ -z "${MAIL_DB_HOST:-}" ]]; then
    panel_db_host="$(read_panel_env "DB_HOST")"
    export MAIL_DB_HOST="${panel_db_host:-127.0.0.1}"
fi

if [[ -z "${MAIL_DB_NAME:-}" ]]; then
    panel_db_name="$(read_panel_env "DB_DATABASE")"
    export MAIL_DB_NAME="${panel_db_name:-litecontrol}"
fi

SUBCMD="${1:-}"

if "${GO_CLI_BIN}" mail "$@"; then
    case "${SUBCMD}" in
        install-core)
            mark_module_installed "mail_core"
            ;;
        uninstall-core)
            mark_module_removed "mail_core"
            mark_module_removed "mail_filter"
            mark_module_removed "clamav"
            ;;
        filter-enable)
            mark_module_installed "mail_filter"
            for arg in "$@"; do
                if [[ "${arg}" == "--with-clamav" || "${arg}" == "--with-clamav=true" ]]; then
                    mark_module_installed "clamav"
                fi
            done
            ;;
        filter-demote)
            mark_module_removed "mail_filter"
            mark_module_removed "clamav"
            ;;
        webmail-install)
            mark_module_installed "mail_webmail"
            ;;
        webmail-uninstall)
            mark_module_removed "mail_webmail"
            ;;
    esac
    exit 0
else
    exit $?
fi
