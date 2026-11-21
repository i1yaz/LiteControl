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

ensure_go_cli_binary() {
    if [[ -x "${GO_CLI_BIN}" ]]; then
        return 0
    fi

    if command -v go >/dev/null 2>&1 && [[ -d "${BASE_DIR}/cli" ]]; then
        mkdir -p "$(dirname "${GO_CLI_BIN}")"
        (cd "${BASE_DIR}/cli" && CGO_ENABLED=0 go build -ldflags="-s -w" -o "${GO_CLI_BIN}.tmp" .)
        chmod 0750 "${GO_CLI_BIN}.tmp"
        mv -f "${GO_CLI_BIN}.tmp" "${GO_CLI_BIN}"
        return 0
    fi

    if [[ "${LC_JSON_OUTPUT:-0}" == "1" ]]; then
        json_response "error" "LiteControl Go CLI binary not found at ${GO_CLI_BIN}."
    else
        log_error "LiteControl Go CLI binary not found at ${GO_CLI_BIN}."
    fi
    exit 1
}

ensure_go_cli_binary
exec "${GO_CLI_BIN}" pdns "$@"
