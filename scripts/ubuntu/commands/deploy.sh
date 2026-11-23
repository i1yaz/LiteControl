#!/usr/bin/env bash
# ==============================================================================
# LiteControl CLI Command: `lc-cli deploy`
# Delegates to the compiled Go CLI binary (`lc-cli-go deploy ...`) or builds
# from `cli/` when Go is installed.
# Subcommands:
#   init      --user <user> --domain <domain> [--web-subpath <path>] [--seed-shared]
#   run       --spec <path>
#   rollback  --user <user> --domain <domain> --release <id> [--php <bin>]
#   env       --action <read|write> --user <user> --domain <domain> [--input-file <path>]
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_DIR="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

GO_CLI_BIN="${LC_GO_CLI_BIN:-${BASE_DIR}/bin/lc-cli-go}"
DEPLOY_CLI_LOG="${LC_DEPLOY_CLI_LOG:-/var/log/litecontrol/panel-error.log}"

log_deploy_raw() {
    mkdir -p "$(dirname "${DEPLOY_CLI_LOG}")" 2>/dev/null || true
    printf '[%s] %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*" >> "${DEPLOY_CLI_LOG}" 2>/dev/null || true
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

    log_deploy_raw "lc-cli-go missing at ${GO_CLI_BIN}; go_bin='${go_bin:-none}', cli_dir_exists=$([[ -d "${BASE_DIR}/cli" ]] && echo yes || echo no)"

    if [[ -n "${go_bin}" && -d "${BASE_DIR}/cli" ]]; then
        mkdir -p "$(dirname "${GO_CLI_BIN}")"
        local build_out=""
        if build_out=$(cd "${BASE_DIR}/cli" && CGO_ENABLED=0 "${go_bin}" build -ldflags="-s -w" -o "${GO_CLI_BIN}.tmp" . 2>&1); then
            chmod 0750 "${GO_CLI_BIN}.tmp"
            mv -f "${GO_CLI_BIN}.tmp" "${GO_CLI_BIN}"
            log_deploy_raw "Successfully built ${GO_CLI_BIN} using ${go_bin}"
            return 0
        else
            log_deploy_raw "go build failed: ${build_out}"
            rm -f "${GO_CLI_BIN}.tmp" 2>/dev/null || true
            echo "Failed to build LiteControl Go CLI binary (see ${DEPLOY_CLI_LOG}): ${build_out}" >&2
            exit 1
        fi
    fi

    local msg="LiteControl Go CLI binary not found at ${GO_CLI_BIN} (go='${go_bin:-not installed}', cli_dir='${BASE_DIR}/cli')."
    log_deploy_raw "${msg}"
    echo "${msg}" >&2
    exit 1
}

ensure_go_cli_binary
exec "${GO_CLI_BIN}" deploy "$@"
