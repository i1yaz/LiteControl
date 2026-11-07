#!/usr/bin/env bash
# ==============================================================================
# LiteControl Core 04: OpenLiteSpeed Web Server Installation & Initial Setup
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

install_openlitespeed() {
    log_step "04. Installing OpenLiteSpeed Web Server"

    if [[ ! -x "${OLS_BIN}" ]]; then
        log_info "Adding official LiteSpeed repository..."
        wget -qO /tmp/openlitespeed-repo.sh https://repo.litespeed.sh
        bash /tmp/openlitespeed-repo.sh
        rm -f /tmp/openlitespeed-repo.sh

        apt_update
        apt_install openlitespeed
    else
        log_info "OpenLiteSpeed is already installed at ${OLS_DIR}."
    fi

    # Set WebAdmin console password
    local ols_pass
    ols_pass=$(get_or_create_secret "ols_admin_password" 20)

    if [[ -x "${OLS_DIR}/admin/fcgi-bin/admin_php" ]]; then
        local enc_pass
        enc_pass=$("${OLS_DIR}/admin/fcgi-bin/admin_php" -q "${OLS_DIR}/admin/misc/htpasswd.php" "${ols_pass}")
        printf "admin:%s\n" "${enc_pass}" > "${OLS_DIR}/admin/conf/htpasswd"
        chown lsadm:lsadm "${OLS_DIR}/admin/conf/htpasswd"
        chmod 600 "${OLS_DIR}/admin/conf/htpasswd"
        log_ok "OpenLiteSpeed WebAdmin credentials updated."
    fi

    # Install custom LiteControl WebAdmin disabled notice template
    local webadmin_notice_tpl="${SCRIPT_DIR}/../../../templates/ols/webadmin_notice.html.tpl"
    if [[ ! -f "${webadmin_notice_tpl}" ]]; then
        webadmin_notice_tpl="${LC_BASE_DIR}/templates/ols/webadmin_notice.html.tpl"
    fi
    mkdir -p "${LC_CONF_DIR}"
    if [[ -f "${webadmin_notice_tpl}" ]]; then
        local srv_ip panel_port
        srv_ip=$(get_server_ip)
        panel_port=$(get_panel_port)
        sed -e "s/__SERVER_IP__/${srv_ip}/g" \
            -e "s/__PANEL_PORT__/${panel_port}/g" \
            "${webadmin_notice_tpl}" > "${LC_CONF_DIR}/webadmin_notice.html"
        chmod 644 "${LC_CONF_DIR}/webadmin_notice.html"
    fi

    # Deploy custom notice into OLS admin html if directory exists
    for admin_html in "${OLS_DIR}/admin/html.open" "${OLS_DIR}/admin/html"; do
        if [[ -d "${admin_html}" && -f "${LC_CONF_DIR}/webadmin_notice.html" ]]; then
            cp -f "${LC_CONF_DIR}/webadmin_notice.html" "${admin_html}/disabled.html" 2>/dev/null || true
            cp -f "${LC_CONF_DIR}/webadmin_notice.html" "${admin_html}/notice.html" 2>/dev/null || true
        fi
    done

    # Remove insecure default sample scripts in Example/html
    local example_html="${OLS_DIR}/Example/html"
    if [[ -d "${example_html}" ]]; then
        rm -f "${example_html}/phpinfo.php" \
              "${example_html}/upload.php" \
              "${example_html}/upload.html" \
              "${example_html}/index.html"
    fi

    # Create clean default catch-all page
    mkdir -p "${example_html}/default"
    cat > "${example_html}/default/index.html" <<'EOF'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome | Powered by LiteControl</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 2.5rem; max-width: 480px; text-align: center; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.4); }
        h1 { margin-top: 0; color: #38bdf8; font-size: 1.75rem; }
        p { color: #94a3b8; line-height: 1.6; }
        .badge { display: inline-block; margin-top: 1rem; padding: 0.35rem 0.85rem; background: rgba(56, 189, 248, 0.15); color: #38bdf8; border-radius: 9999px; font-size: 0.8rem; font-weight: 600; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Server is Online</h1>
        <p>This web server is running OpenLiteSpeed and managed by <strong>LiteControl</strong>. No website has been configured for this domain yet.</p>
        <span class="badge">OpenLiteSpeed + LiteControl</span>
    </div>
</body>
</html>
EOF

    systemctl enable "${OLS_SYSTEMD_SERVICE}" >/dev/null 2>&1 || true
    "${OLS_CTRL}" start >/dev/null 2>&1 || true

    mark_module_installed "openlitespeed"
    log_ok "OpenLiteSpeed installed and running (${OLS_SYSTEMD_SERVICE})."
}

main() {
    require_root
    detect_ubuntu_os
    init_litecontrol_dirs
    install_openlitespeed
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi

