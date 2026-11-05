#!/usr/bin/env bash
# ==============================================================================
# LiteControl Core 06: phpMyAdmin Installation & Secure Configuration
# Served under the LiteControl Panel listener at /phpmyadmin/
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

PMA_VERSION="${PMA_VERSION:-5.2.1}"

install_phpmyadmin() {
    log_step "06. Installing and Configuring phpMyAdmin (${PMA_VERSION})"

    local pma_url="https://files.phpmyadmin.net/phpMyAdmin/${PMA_VERSION}/phpMyAdmin-${PMA_VERSION}-all-languages.tar.gz"
    local tmp_tar="/tmp/phpmyadmin.tar.gz"

    if [[ ! -f "${LC_PMA_DIR}/index.php" ]]; then
        log_info "Downloading phpMyAdmin ${PMA_VERSION} from official mirror..."
        wget -qO "${tmp_tar}" "${pma_url}"
        mkdir -p "${LC_PMA_DIR}"
        tar -xzf "${tmp_tar}" --strip-components=1 -C "${LC_PMA_DIR}"
        rm -f "${tmp_tar}"
    else
        log_info "phpMyAdmin files already present at ${LC_PMA_DIR}."
    fi

    mkdir -p "${LC_PMA_DIR}/tmp"
    chmod 700 "${LC_PMA_DIR}/tmp"

    # Generate 32-character blowfish secret
    local blowfish
    blowfish=$(get_or_create_secret "pma_blowfish_secret" 32)

    local tpl_file="${SCRIPT_DIR}/../../../templates/phpmyadmin/config.inc.php.tpl"
    if [[ -f "${tpl_file}" ]]; then
        sed "s/__BLOWFISH_SECRET__/${blowfish}/g" "${tpl_file}" > "${LC_PMA_DIR}/config.inc.php"
    fi

    # Remove setup directory if present for production security
    rm -rf "${LC_PMA_DIR}/setup"

    chown -R "${LC_USER}:${LC_GROUP}" "${LC_PMA_DIR}"
    chmod -R 750 "${LC_PMA_DIR}"
    chmod 640 "${LC_PMA_DIR}/config.inc.php"

    mark_module_installed "phpmyadmin"
    log_ok "phpMyAdmin configured at ${LC_PMA_DIR}."
}

main() {
    require_root
    detect_ubuntu_os
    init_litecontrol_dirs
    install_phpmyadmin
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi

