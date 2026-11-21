#!/usr/bin/env bash
# ==============================================================================
# LiteControl CLI Command: `lc-cli pdns`
# Delegates to the compiled Go CLI binary (`lc-cli-go pdns ...`) or builds/runs
# from `cli/` when Go is installed.
# Subcommands:
#   status
#   apply-config --payload <json>
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_DIR="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

GO_CLI_BIN="${LC_GO_CLI_BIN:-${BASE_DIR}/bin/lc-cli-go}"
PDNS_CLI_LOG="${LC_PDNS_CLI_LOG:-/var/log/litecontrol/pdns-cli.log}"

log_pdns_raw() {
    mkdir -p "$(dirname "${PDNS_CLI_LOG}")" 2>/dev/null || true
    printf '[%s] %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*" >> "${PDNS_CLI_LOG}" 2>/dev/null || true
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

    log_pdns_raw "lc-cli-go missing at ${GO_CLI_BIN}; go_bin='${go_bin:-none}', cli_dir_exists=$([[ -d "${BASE_DIR}/cli" ]] && echo yes || echo no), PATH='${PATH}'"

    if [[ -n "${go_bin}" && -d "${BASE_DIR}/cli" ]]; then
        mkdir -p "$(dirname "${GO_CLI_BIN}")"
        local build_out=""
        if build_out=$(cd "${BASE_DIR}/cli" && CGO_ENABLED=0 "${go_bin}" build -ldflags="-s -w" -o "${GO_CLI_BIN}.tmp" . 2>&1); then
            chmod 0750 "${GO_CLI_BIN}.tmp"
            mv -f "${GO_CLI_BIN}.tmp" "${GO_CLI_BIN}"
            log_pdns_raw "Successfully built ${GO_CLI_BIN} using ${go_bin}"
            return 0
        else
            log_pdns_raw "go build failed: ${build_out}"
            rm -f "${GO_CLI_BIN}.tmp" 2>/dev/null || true
            if [[ "${LC_JSON_OUTPUT:-0}" == "1" ]]; then
                json_response "error" "Failed to build LiteControl Go CLI binary (see ${PDNS_CLI_LOG}): ${build_out}"
            else
                log_error "Failed to build LiteControl Go CLI binary (see ${PDNS_CLI_LOG}): ${build_out}"
            fi
            exit 1
        fi
    fi

    local msg="LiteControl Go CLI binary not found at ${GO_CLI_BIN} (go='${go_bin:-not installed}', cli_dir='${BASE_DIR}/cli'). See ${PDNS_CLI_LOG}."
    log_pdns_raw "${msg}"

    if [[ "${LC_JSON_OUTPUT:-0}" == "1" ]]; then
        json_response "error" "${msg}"
    else
        log_error "${msg}"
    fi
    exit 1
}

ensure_go_cli_binary
exec "${GO_CLI_BIN}" pdns "$@"
