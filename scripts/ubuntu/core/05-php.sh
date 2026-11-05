#!/usr/bin/env bash
# ==============================================================================
# LiteControl Core 05: Default LSPHP (8.3), Extensions & Composer Setup
# Note: Only the primary PHP version is installed during core setup.
# Additional versions (7.4 - 8.5) are installed on-demand via `lc-cli php install`.
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

install_primary_lsphp() {
    local ver="${LC_PHP_VER}"
    local dotted_ver="${ver:0:1}.${ver:1:1}"

    log_step "05. Installing Primary LSPHP (PHP ${dotted_ver}) & Composer"

    # Ensure LiteSpeed repo or PPA is available for lsphp packages
    if ! apt-cache show "lsphp${ver}" >/dev/null 2>&1; then
        add-apt-repository -y ppa:openlitespeed/php >/dev/null 2>&1 || true
        apt_update
    fi

    apt_install \
        "lsphp${ver}" \
        "lsphp${ver}-common" \
        "lsphp${ver}-mysql" \
        "lsphp${ver}-curl" \
        "lsphp${ver}-intl" \
        "lsphp${ver}-opcache"

    # Optional extensions available in LiteSpeed repo (installed individually so missing sub-packages never abort)
    for ext in redis imagick igbinary memcached; do
        if apt-cache show "lsphp${ver}-${ext}" >/dev/null 2>&1; then
            apt_install "lsphp${ver}-${ext}" || true
        fi
    done

    local php_bin="${OLS_DIR}/lsphp${ver}/bin/php"
    if [[ ! -x "${php_bin}" ]]; then
        log_error "Failed to locate PHP binary at ${php_bin}"
        return 1
    fi

    # Symlink /usr/local/bin/php to primary lsphp CLI
    ln -sf "${php_bin}" /usr/local/bin/php

    # Locate and tune php.ini
    local ini_file="${OLS_DIR}/lsphp${ver}/etc/php/${dotted_ver}/litespeed/php.ini"
    if [[ ! -f "${ini_file}" ]]; then
        ini_file="${OLS_DIR}/lsphp${ver}/etc/php.ini"
    fi

    if [[ -f "${ini_file}" ]]; then
        cp -a "${ini_file}" "${ini_file}.bak"
        sed -i 's/^;\?upload_max_filesize\s*=.*/upload_max_filesize = 128M/' "${ini_file}"
        sed -i 's/^;\?post_max_size\s*=.*/post_max_size = 128M/' "${ini_file}"
        sed -i 's/^;\?memory_limit\s*=.*/memory_limit = 256M/' "${ini_file}"
        sed -i 's/^;\?max_execution_time\s*=.*/max_execution_time = 300/' "${ini_file}"
        sed -i 's/^;\?expose_php\s*=.*/expose_php = Off/' "${ini_file}"
        log_ok "Tuned PHP configuration at ${ini_file}"
    fi

    # Install Composer globally using primary lsphp
    if [[ ! -x /usr/local/bin/composer ]]; then
        log_info "Installing Composer..."
        local expected_sig
        expected_sig=$(curl -fsSL https://composer.github.io/installer.sig)
        "${php_bin}" -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
        local actual_sig
        actual_sig=$("${php_bin}" -r "echo hash_file('sha384', '/tmp/composer-setup.php');")
        if [[ "${expected_sig}" == "${actual_sig}" ]]; then
            "${php_bin}" /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
            chmod +x /usr/local/bin/composer
            log_ok "Composer installed at /usr/local/bin/composer."
        else
            log_warn "Composer signature mismatch; falling back to apt composer package."
            apt_install composer || true
        fi
        rm -f /tmp/composer-setup.php
    fi

    mark_module_installed "lsphp${ver}"
    log_ok "PHP ${dotted_ver} (lsphp${ver}) and Composer ready."
}

main() {
    require_root
    detect_ubuntu_os
    init_litecontrol_dirs
    install_primary_lsphp
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi

