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

    # Create dedicated 'phpmyadmin' MariaDB/MySQL user with root-equivalent privileges for signon
    local pma_signon_user="phpmyadmin"
    local pma_signon_password
    pma_signon_password=$(get_or_create_secret "pma_signon_password" 32)

    if command -v mysql >/dev/null 2>&1; then
        mysql -u root <<SQL
CREATE USER IF NOT EXISTS '${pma_signon_user}'@'localhost' IDENTIFIED BY '${pma_signon_password}';
ALTER USER '${pma_signon_user}'@'localhost' IDENTIFIED BY '${pma_signon_password}';
GRANT ALL PRIVILEGES ON *.* TO '${pma_signon_user}'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL
        log_ok "MariaDB/MySQL user '${pma_signon_user}'@'localhost' configured for signon."
    else
        log_warn "mysql client not found; skipping '${pma_signon_user}' user creation."
    fi

    local signon_tpl="${SCRIPT_DIR}/../../../templates/phpmyadmin/signon.php.tpl"
    if [[ -f "${signon_tpl}" ]]; then
        sed -e "s/__PMA_SIGNON_USER__/${pma_signon_user}/g" \
            -e "s/__PMA_SIGNON_PASSWORD__/${pma_signon_password}/g" \
            "${signon_tpl}" > "${LC_PMA_DIR}/signon.php"
    fi

    # Remove setup directory if present for production security
    rm -rf "${LC_PMA_DIR}/setup"

    chown -R "${LC_USER}:${LC_GROUP}" "${LC_PMA_DIR}"
    chmod -R 750 "${LC_PMA_DIR}"
    chmod 640 "${LC_PMA_DIR}/config.inc.php"
    [[ -f "${LC_PMA_DIR}/signon.php" ]] && chmod 640 "${LC_PMA_DIR}/signon.php"

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

