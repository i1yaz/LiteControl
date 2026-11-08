#!/usr/bin/env bash
# ==============================================================================
# LiteControl Core 02: MariaDB Installation, Hardening & Panel Database Setup
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

install_and_secure_mariadb() {
    log_step "02. Installing and Securing MariaDB Server"

    apt_install mariadb-server mariadb-client

    systemctl enable mariadb >/dev/null 2>&1 || true
    systemctl start mariadb

    local root_pass
    root_pass=$(get_or_create_secret "mysql_root_password" 24)
    local panel_db_pass
    panel_db_pass=$(get_or_create_secret "mysql_panel_password" 24)

    log_info "Applying MariaDB security hardening and configuring root credentials..."

    # Use socket auth if available (default on fresh Ubuntu MariaDB) or existing /root/.my.cnf
    mysql -u root <<EOF
ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('${root_pass}');
DELETE FROM mysql.global_priv WHERE User='';
DELETE FROM mysql.global_priv WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
CREATE DATABASE IF NOT EXISTS \`litecontrol\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'litecontrol'@'localhost' IDENTIFIED BY '${panel_db_pass}';
ALTER USER 'litecontrol'@'localhost' IDENTIFIED BY '${panel_db_pass}';
GRANT ALL PRIVILEGES ON \`litecontrol\`.* TO 'litecontrol'@'localhost';
CREATE USER IF NOT EXISTS 'litecontrol'@'127.0.0.1' IDENTIFIED BY '${panel_db_pass}';
ALTER USER 'litecontrol'@'127.0.0.1' IDENTIFIED BY '${panel_db_pass}';
GRANT ALL PRIVILEGES ON \`litecontrol\`.* TO 'litecontrol'@'127.0.0.1';
FLUSH PRIVILEGES;
EOF

    # Write root .my.cnf so root CLI commands (lc-cli db) can run non-interactively
    cat > /root/.my.cnf <<EOF
[client]
user=root
password=${root_pass}
host=localhost
EOF
    chmod 600 /root/.my.cnf
    chown root:root /root/.my.cnf

    mark_module_installed "mariadb"
    log_ok "MariaDB installed, hardened, and 'litecontrol' database provisioned."
}

main() {
    require_root
    detect_ubuntu_os
    init_litecontrol_dirs
    install_and_secure_mariadb
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi

