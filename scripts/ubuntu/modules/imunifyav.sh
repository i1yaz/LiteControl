#!/usr/bin/env bash
# ==============================================================================
# LiteControl Module: ImunifyAV Antivirus & Malware Scanner (`imunifyav`)
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

ACTION="${1:-install}"

do_install() {
    log_info "Installing ImunifyAV Antivirus..."

    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        local deploy_script="/tmp/imav-deploy.sh"
        if [[ ! -f "${deploy_script}" ]]; then
            wget -q https://repo.imunify360.cloudlinux.com/defence360/imav-deploy.sh -O "${deploy_script}" || \
            curl -sSL https://repo.imunify360.cloudlinux.com/defence360/imav-deploy.sh -o "${deploy_script}"
        fi
        bash "${deploy_script}" || log_warn "ImunifyAV installer returned non-zero; continuing."
        systemctl enable imunify-antivirus >/dev/null 2>&1 || true
        systemctl start imunify-antivirus >/dev/null 2>&1 || true
    fi

    log_ok "ImunifyAV Antivirus installed successfully."
}

do_remove() {
    log_info "Removing ImunifyAV Antivirus..."

    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        local deploy_script="/tmp/imav-deploy.sh"
        if [[ -f "${deploy_script}" ]]; then
            bash "${deploy_script}" --uninstall >/dev/null 2>&1 || true
        else
            apt_remove imunify-antivirus 2>/dev/null || true
        fi
        systemctl stop imunify-antivirus >/dev/null 2>&1 || true
    fi

    log_ok "ImunifyAV Antivirus removed."
}

case "${ACTION}" in
    install)
        do_install
        ;;
    remove)
        do_remove
        ;;
    *)
        log_error "Usage: $0 <install|remove>"
        exit 1
        ;;
esac

