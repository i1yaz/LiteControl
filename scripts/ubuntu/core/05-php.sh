#!/usr/bin/env bash
# ==============================================================================
# LiteControl Core 05: Website LSPHP & Dedicated Panel Nginx + PHP-FPM Setup
# Note:
#   - Default website LSPHP (default: lsphp83) is installed for OpenLiteSpeed.
#   - Additional website versions (7.4 - 8.6) are installed via `lc-cli php install`.
#   - Dedicated Panel Nginx + PHP-FPM runs isolated from website LSPHP.
#   - Pass `--panel-only` (used by upgrades) to skip website LSPHP setup/tuning.
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

install_primary_lsphp() {
    local ver="${LC_PHP_VER}"
    local dotted_ver="${ver:0:1}.${ver:1:1}"

    log_step "05. Installing Default Website LSPHP (PHP ${dotted_ver})"

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

    # Extensions available in LiteSpeed repo (installed individually so missing sub-packages never abort)
    for ext in redis imagick igbinary memcached zip mbstring; do
        if apt-cache show "lsphp${ver}-${ext}" >/dev/null 2>&1; then
            apt_install "lsphp${ver}-${ext}" || true
        fi
    done

    local php_bin="${OLS_DIR}/lsphp${ver}/bin/php"
    if [[ ! -x "${php_bin}" ]]; then
        log_error "Failed to locate PHP binary at ${php_bin}"
        return 1
    fi

    # Locate and tune php.ini on first install only (preserve user MultiPHP INI edits on re-runs)
    local ini_file="${OLS_DIR}/lsphp${ver}/etc/php/${dotted_ver}/litespeed/php.ini"
    if [[ ! -f "${ini_file}" ]]; then
        ini_file="${OLS_DIR}/lsphp${ver}/etc/php.ini"
    fi

    if [[ -f "${ini_file}" && ! -f "${ini_file}.bak" ]]; then
        cp -a "${ini_file}" "${ini_file}.bak"
        sed -i 's/^;\?upload_max_filesize\s*=.*/upload_max_filesize = 128M/' "${ini_file}"
        sed -i 's/^;\?post_max_size\s*=.*/post_max_size = 128M/' "${ini_file}"
        sed -i 's/^;\?memory_limit\s*=.*/memory_limit = 256M/' "${ini_file}"
        sed -i 's/^;\?max_execution_time\s*=.*/max_execution_time = 300/' "${ini_file}"
        sed -i 's/^;\?expose_php\s*=.*/expose_php = Off/' "${ini_file}"
        log_ok "Tuned default PHP configuration at ${ini_file}"
    fi

    mark_module_installed "lsphp${ver}"
    log_ok "Default Website PHP ${dotted_ver} (lsphp${ver}) ready."
}

install_panel_nginx_and_fpm() {
    log_step "05b. Installing Dedicated Panel Nginx, PHP-FPM & Composer"

    local panel_ver="${LC_PANEL_PHP_VER:-86}"
    local dotted_panel_ver="${panel_ver:0:1}.${panel_ver:1:1}"

    # Ensure repository has the specific PHP version needed by panel (e.g. PHP 8.6)
    if ! apt-cache show "php${dotted_panel_ver}-fpm" >/dev/null 2>&1; then
        log_info "Adding packages.sury.org PHP repository for PHP ${dotted_panel_ver}-fpm..."
        apt_install lsb-release ca-certificates curl
        curl -sSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
        dpkg -i /tmp/debsuryorg-archive-keyring.deb
        rm -f /tmp/debsuryorg-archive-keyring.deb
        echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list
        apt_update
    fi

    # Install nginx and explicit version-matched php-fpm packages for out-of-band panel daemon
    apt_install nginx \
        "php${dotted_panel_ver}-fpm" \
        "php${dotted_panel_ver}-cli" \
        "php${dotted_panel_ver}-mysql" \
        "php${dotted_panel_ver}-curl" \
        "php${dotted_panel_ver}-intl" \
        "php${dotted_panel_ver}-mbstring" \
        "php${dotted_panel_ver}-xml" \
        "php${dotted_panel_ver}-zip" \
        "php${dotted_panel_ver}-redis" \
        "php${dotted_panel_ver}-bcmath" \
        "php${dotted_panel_ver}-gd" || \
    apt_install nginx php-fpm php-cli php-mysql php-curl php-intl php-mbstring php-xml php-zip php-redis php-bcmath php-gd || true

    # Immediately stop, disable, and prevent stock nginx from conflicting with OpenLiteSpeed on port 80/443
    systemctl stop nginx >/dev/null 2>&1 || true
    systemctl disable nginx >/dev/null 2>&1 || true
    rm -f /etc/nginx/sites-enabled/default

    # Stop and disable any default stock php*-fpm service (we run our isolated litecontrol-php service)
    for fpm_svc in $(systemctl list-unit-files 'php*-fpm.service' --no-legend 2>/dev/null | awk '{print $1}'); do
        systemctl stop "${fpm_svc}" >/dev/null 2>&1 || true
        systemctl disable "${fpm_svc}" >/dev/null 2>&1 || true
    done

    # Install Composer globally using Panel PHP CLI (decoupled from website LSPHP)
    if [[ ! -x /usr/local/bin/composer ]]; then
        local panel_php_bin
        panel_php_bin=$(get_panel_php_bin)
        if [[ -x "${panel_php_bin}" ]]; then
            log_info "Installing Composer..."
            local expected_sig
            expected_sig=$(curl -fsSL https://composer.github.io/installer.sig || true)
            "${panel_php_bin}" -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');" 2>/dev/null || true
            if [[ -n "${expected_sig}" && -f /tmp/composer-setup.php ]]; then
                local actual_sig
                actual_sig=$("${panel_php_bin}" -r "echo hash_file('sha384', '/tmp/composer-setup.php');" 2>/dev/null || true)
                if [[ "${expected_sig}" == "${actual_sig}" ]]; then
                    "${panel_php_bin}" /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
                    chmod +x /usr/local/bin/composer
                    log_ok "Composer installed at /usr/local/bin/composer."
                else
                    log_warn "Composer signature mismatch; falling back to apt composer package."
                    apt_install composer || true
                fi
            else
                apt_install composer || true
            fi
            rm -f /tmp/composer-setup.php
        fi
    fi

    log_ok "Panel Nginx, PHP-FPM, and Composer ready; default port 80 services deactivated."
}

main() {
    require_root
    detect_ubuntu_os
    init_litecontrol_dirs

    local panel_only=0
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --panel-only)
                panel_only=1
                shift
                ;;
            *)
                shift
                ;;
        esac
    done

    if [[ "${panel_only}" -eq 0 ]]; then
        install_primary_lsphp
    fi
    install_panel_nginx_and_fpm
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi

