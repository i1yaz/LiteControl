#!/usr/bin/env bash
# ==============================================================================
# LiteControl Core 08: Panel VirtualHost, Sudoers, CLI Symlink, Worker & MOTD
# Configures LiteControl to run on OpenLiteSpeed via isolated litecontrol_php
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

sync_files_to_install_dir() {
    if [[ "${REPO_ROOT}" != "${LC_BASE_DIR}" ]]; then
        log_info "Syncing LiteControl scripts, bin, and templates to ${LC_BASE_DIR}..."
        rsync -a "${REPO_ROOT}/bin/" "${LC_BASE_DIR}/bin/"
        rsync -a "${REPO_ROOT}/scripts/" "${LC_BASE_DIR}/scripts/"
        rsync -a "${REPO_ROOT}/templates/" "${LC_BASE_DIR}/templates/"
        if [[ -f "${REPO_ROOT}/VERSION" ]]; then
            cp "${REPO_ROOT}/VERSION" "${LC_BASE_DIR}/VERSION"
        fi
        if [[ -f "${REPO_ROOT}/litecontrol_upgrade.sh" ]]; then
            cp "${REPO_ROOT}/litecontrol_upgrade.sh" "${LC_BASE_DIR}/litecontrol_upgrade.sh"
            chmod 750 "${LC_BASE_DIR}/litecontrol_upgrade.sh"
        fi
        if [[ -d "${REPO_ROOT}/panel" ]]; then
            rsync -a "${REPO_ROOT}/panel/" "${LC_PANEL_DIR}/"
        fi
    fi

    if [[ -f "${LC_BASE_DIR}/VERSION" ]]; then
        sed -i 's/\r$//' "${LC_BASE_DIR}/VERSION"
        tr -d ' \t\r\n' < "${LC_BASE_DIR}/VERSION" > "${LC_CONF_DIR}/version"
        chmod 644 "${LC_CONF_DIR}/version"
    fi

    # Strip any CRLF line endings from scripts and binaries
    find "${LC_BASE_DIR}/bin" "${LC_BASE_DIR}/scripts" -type f -exec sed -i 's/\r$//' {} + 2>/dev/null || true

    chown -R root:root "${LC_BASE_DIR}/bin" "${LC_BASE_DIR}/scripts" "${LC_BASE_DIR}/templates"
    chmod 750 "${LC_BASE_DIR}/bin/lc-cli"
    find "${LC_BASE_DIR}/scripts" -type f -name "*.sh" -exec chmod 750 {} +
    ln -sf "${LC_BASE_DIR}/bin/lc-cli" /usr/local/bin/lc-cli
}

configure_sudoers() {
    log_info "Configuring scoped sudoers rule for '${LC_USER}'..."
    local sudoers_tpl="${LC_BASE_DIR}/templates/system/sudoers.litecontrol"
    local sudoers_dest="/etc/sudoers.d/litecontrol"

    cp "${sudoers_tpl}" "${sudoers_dest}"
    sed -i 's/\r$//' "${sudoers_dest}"
    chmod 440 "${sudoers_dest}"
    chown root:root "${sudoers_dest}"

    if ! visudo -cf "${sudoers_dest}" >/dev/null 2>&1; then
        rm -f "${sudoers_dest}"
        log_error "Invalid sudoers syntax detected; removed ${sudoers_dest}."
        return 1
    fi
    log_ok "Privilege separation configured (/etc/sudoers.d/litecontrol)."
}

bootstrap_panel_app() {
    local panel_port="$1"
    printf "%s" "${panel_port}" > "${LC_CONF_DIR}/port"
    chmod 644 "${LC_CONF_DIR}/port"

    # Generate initial admin password if not already created
    get_or_create_secret "panel_admin_password" 16 >/dev/null

    mkdir -p "${LC_PANEL_DIR}/public"

    # Write default .htaccess for Laravel routing if missing
    if [[ ! -f "${LC_PANEL_DIR}/public/.htaccess" ]]; then
        cat > "${LC_PANEL_DIR}/public/.htaccess" <<'EOF'
<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On

    # Handle Authorization Header
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Redirect Trailing Slashes If Not A Folder...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    # Send Requests To Front Controller...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
EOF
    fi

    # Ensure panel user has writable cache and config directory
    mkdir -p "${LC_BASE_DIR}/.config" "${LC_BASE_DIR}/.cache"
    chown -R "${LC_USER}:${LC_GROUP}" "${LC_BASE_DIR}/.config" "${LC_BASE_DIR}/.cache"

    # If full Laravel app is present (has artisan), configure .env & run migrations
    if [[ -f "${LC_PANEL_DIR}/artisan" ]]; then
        log_info "Laravel panel detected in ${LC_PANEL_DIR}. Running setup..."
        local db_pass
        db_pass=$(get_secret "mysql_panel_password")
        local srv_ip
        srv_ip=$(get_server_ip)

        if [[ ! -f "${LC_PANEL_DIR}/.env" && -f "${LC_PANEL_DIR}/.env.example" ]]; then
            cp "${LC_PANEL_DIR}/.env.example" "${LC_PANEL_DIR}/.env"
        fi

        if [[ -f "${LC_PANEL_DIR}/.env" ]]; then
            sed -E -i "s@^APP_URL=.*@APP_URL=https://${srv_ip}:${panel_port}@" "${LC_PANEL_DIR}/.env"
            sed -E -i "s@^LC_DEV_MOCK=.*@LC_DEV_MOCK=false@" "${LC_PANEL_DIR}/.env"
            sed -E -i "s@^DB_CONNECTION=.*@DB_CONNECTION=mysql@" "${LC_PANEL_DIR}/.env"
            sed -E -i "s@^#?[[:space:]]*DB_HOST=.*@DB_HOST=127.0.0.1@" "${LC_PANEL_DIR}/.env"
            sed -E -i "s@^#?[[:space:]]*DB_PORT=.*@DB_PORT=3306@" "${LC_PANEL_DIR}/.env"
            sed -E -i "s@^#?[[:space:]]*DB_DATABASE=.*@DB_DATABASE=litecontrol@" "${LC_PANEL_DIR}/.env"
            sed -E -i "s@^#?[[:space:]]*DB_USERNAME=.*@DB_USERNAME=litecontrol@" "${LC_PANEL_DIR}/.env"
            sed -E -i "s@^#?[[:space:]]*DB_PASSWORD=.*@DB_PASSWORD=${db_pass}@" "${LC_PANEL_DIR}/.env"
            sed -E -i "s@^QUEUE_CONNECTION=.*@QUEUE_CONNECTION=redis@" "${LC_PANEL_DIR}/.env"
        fi

        chown -R "${LC_USER}:${LC_GROUP}" "${LC_PANEL_DIR}"
        local composer_bin="/usr/local/bin/composer"
        if [[ -f "${LC_BASE_DIR}/bin/composer.phar" ]]; then
            composer_bin="/usr/local/bin/php ${LC_BASE_DIR}/bin/composer.phar"
        fi
        su -s /bin/bash "${LC_USER}" -c "cd '${LC_PANEL_DIR}' && ${composer_bin} install --no-dev -o --no-interaction"
        su -s /bin/bash "${LC_USER}" -c "cd '${LC_PANEL_DIR}' && /usr/local/bin/php artisan key:generate --force"
        su -s /bin/bash "${LC_USER}" -c "cd '${LC_PANEL_DIR}' && /usr/local/bin/php artisan migrate --force"
        su -s /bin/bash "${LC_USER}" -c "cd '${LC_PANEL_DIR}' && /usr/local/bin/php artisan db:seed --force" 2>/dev/null || true

        # Ensure admin user password matches generated panel_admin_password secret
        local admin_pass
        admin_pass=$(get_secret "panel_admin_password" 2>/dev/null || echo "")
        if [[ -n "${admin_pass}" ]]; then
            su -s /bin/bash "${LC_USER}" -c "cd '${LC_PANEL_DIR}' && /usr/local/bin/php artisan tinker --execute=\"\\App\\Models\\User::where('username', 'admin')->update(['password' => \\Illuminate\\Support\\Facades\\Hash::make('${admin_pass}')]);\"" 2>/dev/null || true
        fi

        # Enable Laravel queue worker service
        local worker_tpl="${LC_BASE_DIR}/templates/system/litecontrol-worker.service"
        sed "s/__PHP_VER__/${LC_PHP_VER}/g" "${worker_tpl}" > /etc/systemd/system/litecontrol-worker.service
        systemctl daemon-reload
        systemctl enable litecontrol-worker.service >/dev/null 2>&1 || true
        systemctl restart litecontrol-worker.service || true
    elif [[ ! -f "${LC_PANEL_DIR}/public/index.php" ]]; then
        # Create diagnostic readiness page until Phase 4 Laravel app is deployed
        cat > "${LC_PANEL_DIR}/public/index.php" <<'EOF'
<?php
declare(strict_types=1);
$user = posix_getpwuid(posix_geteuid())['name'] ?? get_current_user();
$phpVersion = PHP_VERSION;
$sapi = PHP_SAPI;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>LiteControl Panel — Core Ready</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .box { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 2rem 2.5rem; max-width: 560px; width: 100%; }
        h1 { margin: 0 0 0.5rem; color: #38bdf8; font-size: 1.5rem; }
        .meta { margin-top: 1.25rem; background: #0f172a; padding: 1rem; border-radius: 8px; font-family: monospace; font-size: 0.9rem; line-height: 1.7; color: #cbd5e1; }
        a { color: #38bdf8; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <div class="box">
        <h1>LiteControl Core Stack Active</h1>
        <p>Dedicated out-of-band Nginx + PHP-FPM panel daemon is serving the control panel.</p>
        <div class="meta">
            Process User : <?= htmlspecialchars($user) ?><br>
            PHP Version  : <?= htmlspecialchars($phpVersion) ?> (<?= htmlspecialchars($sapi) ?>)<br>
            phpMyAdmin   : <a href="/phpmyadmin/">/phpmyadmin/</a>
        </div>
    </div>
</body>
</html>
EOF
    fi

    chown -R "${LC_USER}:${LC_GROUP}" "${LC_PANEL_DIR}"
    chmod -R 755 "${LC_PANEL_DIR}"
    if [[ -d "${LC_PANEL_DIR}/storage" ]]; then
        chmod -R 775 "${LC_PANEL_DIR}/storage" "${LC_PANEL_DIR}/bootstrap/cache"
    fi
}

configure_ols_server() {
    local hostname_val
    hostname_val=$(hostname -f 2>/dev/null || hostname)

    ols_backup_config

    mkdir -p "${OLS_VHOSTS_DIR}/Example"

    cp "${LC_BASE_DIR}/templates/ols/default_vhconf.conf.tpl" "${OLS_VHOSTS_DIR}/Example/vhconf.conf"

    sed -e "s/__SERVER_NAME__/${hostname_val}/g" \
        -e "s/__PHP_VER__/${LC_PHP_VER}/g" \
        "${LC_BASE_DIR}/templates/ols/httpd_config.conf.tpl" > "${OLS_CONF}"

    chown -R lsadm:lsadm "${OLS_DIR}/conf"
    chown -R nobody:nogroup "${OLS_DIR}/Example" 2>/dev/null || true

    if ! ols_safe_reload; then
        log_error "Failed to reload OpenLiteSpeed with clean customer configuration."
        return 1
    fi
    log_ok "OpenLiteSpeed server configured for hosted websites."
}

configure_panel_server() {
    local panel_port="$1"

    log_info "Configuring dedicated Nginx & PHP-FPM stack for LiteControl Panel..."
    mkdir -p "${PANEL_NGINX_DIR}" "${PANEL_FPM_DIR}"
    mkdir -p /tmp/litecontrol-nginx-body /tmp/litecontrol-nginx-proxy /tmp/litecontrol-nginx-fastcgi /tmp/litecontrol-nginx-uwsgi /tmp/litecontrol-nginx-scgi
    chown -R "${LC_USER}:${LC_GROUP}" /tmp/litecontrol-nginx-* 2>/dev/null || true

    # Deploy fastcgi_params
    cp "${LC_BASE_DIR}/templates/panel/fastcgi_params.tpl" "${PANEL_NGINX_DIR}/fastcgi_params"

    # Deploy nginx.conf with panel_port
    sed "s/__PANEL_PORT__/${panel_port}/g" "${LC_BASE_DIR}/templates/panel/nginx.conf.tpl" > "${PANEL_NGINX_CONF}"
    panel_backup_config

    # Deploy php-fpm.conf
    cp "${LC_BASE_DIR}/templates/panel/php-fpm.conf.tpl" "${PANEL_FPM_CONF}"

    # Locate php-fpm binary (e.g. /usr/sbin/php-fpm8.3 or /usr/sbin/php-fpm)
    local php_fpm_bin
    php_fpm_bin=$(command -v php-fpm"${LC_PHP_VER:0:1}.${LC_PHP_VER:1:1}" 2>/dev/null || command -v php-fpm 2>/dev/null || echo "/usr/sbin/php-fpm")

    # Install Systemd units
    sed "s|__PHP_FPM_BIN__|${php_fpm_bin}|g" "${LC_BASE_DIR}/templates/system/litecontrol-php.service" > /etc/systemd/system/litecontrol-php.service
    cp "${LC_BASE_DIR}/templates/system/litecontrol-panel.service" /etc/systemd/system/litecontrol-panel.service

    systemctl daemon-reload
    systemctl enable litecontrol-php.service litecontrol-panel.service >/dev/null 2>&1 || true
    systemctl restart litecontrol-php.service || true

    if ! panel_safe_reload; then
        log_error "Failed to start LiteControl Panel Nginx service on port ${panel_port}."
        return 1
    fi
    log_ok "LiteControl Out-of-Band Panel server running on port ${panel_port}."
}

install_motd() {
    local motd_tpl="${LC_BASE_DIR}/templates/system/motd-litecontrol.sh"
    if [[ -f "${motd_tpl}" ]]; then
        cp "${motd_tpl}" /etc/profile.d/litecontrol.sh
        sed -i 's/\r$//' /etc/profile.d/litecontrol.sh
        chmod 755 /etc/profile.d/litecontrol.sh
    fi
}

main() {
    require_root
    detect_ubuntu_os
    init_litecontrol_dirs
    local panel_port="${1:-${LC_DEFAULT_PORT}}"

    log_step "08. Configuring LiteControl Panel VHost, Sudoers & CLI Engine"
    sync_files_to_install_dir
    configure_sudoers
    bootstrap_panel_app "${panel_port}"
    configure_ols_server
    configure_panel_server "${panel_port}"
    install_motd
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi

