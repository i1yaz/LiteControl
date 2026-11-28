#!/usr/bin/env bash
# ==============================================================================
# LiteControl Webmail (SnappyMail) Shell Provisioner
# Manages SnappyMail installation, removal, and status using the proven
# `vhost.sh` (OpenLiteSpeed vhost + listener mapping) and `ssl.sh` (ACME TLS)
# shell workflows.
# ==============================================================================

WEBMAIL_USER="lc-webmail"
WEBMAIL_GROUP="lc-webmail"
WEBMAIL_BASE_DIR="/var/lib/lc-webmail"
WEBMAIL_DATA_DIR="${WEBMAIL_BASE_DIR}/data"
WEBMAIL_STATE_FILE="/var/lib/lc-mail/webmail.json"
SNAPPYMAIL_DOWNLOAD_URL="${LC_SNAPPYMAIL_URL:-https://snappymail.eu/repository/latest.tar.gz}"

_webmail_validate_hostname() {
    local host="$1"
    if ! [[ "${host}" =~ ^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)+$ ]]; then
        echo "Invalid webmail hostname '${host}' (must be a valid FQDN)." >&2
        return 1
    fi
}

_webmail_read_state_hostname() {
    if [[ ! -f "${WEBMAIL_STATE_FILE}" ]]; then
        return 0
    fi
    grep -oE '"hostname"[[:space:]]*:[[:space:]]*"[^"]+"' "${WEBMAIL_STATE_FILE}" 2>/dev/null \
        | head -n 1 \
        | sed -E 's/.*"hostname"[[:space:]]*:[[:space:]]*"([^"]+)".*/\1/' || true
}

_webmail_write_snappymail_configs() {
    local app_dir="$1"
    local config_dir="${WEBMAIL_DATA_DIR}/_data_/_default_/configs"
    local domains_dir="${WEBMAIL_DATA_DIR}/_data_/_default_/domains"
    mkdir -p "${config_dir}" "${domains_dir}"

    cat > "${config_dir}/application.ini" <<'EOF'
; Managed by LiteControl (lc-cli mail webmail-install)
[webmail]
title = "LiteControl Webmail"
loading_description = "Webmail"
theme = "Default"
allow_additional_accounts = On
allow_additional_identities = On

[security]
hide_x_mailer_header = On
allow_admin_panel = Off

[imap]
show_real_ip = On
EOF

    cat > "${domains_dir}/default.json" <<'EOF'
{
  "IMAP": {
    "host": "127.0.0.1",
    "port": 993,
    "type": "SSL",
    "shortLogin": false,
    "verifySsl": false
  },
  "SMTP": {
    "host": "127.0.0.1",
    "port": 465,
    "type": "SSL",
    "shortLogin": false,
    "auth": true,
    "verifySsl": false
  }
}
EOF

    # Point SnappyMail to the isolated data directory outside docRoot
    cat > "${app_dir}/include.php" <<EOF
<?php
define('APP_DATA_FOLDER_PATH', '${WEBMAIL_DATA_DIR}/');
EOF
}

cmd_webmail_install() {
    local driver="snappymail"
    local hostname=""

    while [[ $# -gt 0 ]]; do
        case "$1" in
            --driver=*)
                driver="${1#*=}"
                ;;
            --driver)
                shift
                driver="${1:-}"
                ;;
            --hostname=*)
                hostname="${1#*=}"
                ;;
            --hostname)
                shift
                hostname="${1:-}"
                ;;
            *)
                ;;
        esac
        shift || true
    done

    driver="${driver,,}"
    hostname="${hostname,,}"

    if [[ "${driver}" != "snappymail" ]]; then
        echo "Unsupported webmail driver '${driver}' (expected snappymail)." >&2
        return 1
    fi

    if [[ -z "${hostname}" ]]; then
        echo "--hostname is required." >&2
        return 1
    fi

    _webmail_validate_hostname "${hostname}"

    # Place SnappyMail app inside vhost.sh's native vhRoot layout so OLS `restrained 1`
    # and `setfacl u:nobody:x` work seamlessly without path/permission mismatches.
    local app_dir="${LC_HOME_ROOT}/${WEBMAIL_USER}/${hostname}/public_html"

    # 1. Ensure isolated lc-webmail system user exists
    if ! id -u "${WEBMAIL_USER}" >/dev/null 2>&1; then
        useradd --system --no-create-home --shell /usr/sbin/nologin --home-dir "${LC_HOME_ROOT}/${WEBMAIL_USER}" "${WEBMAIL_USER}"
    fi

    # 2. Create directory layout
    mkdir -p "${app_dir}" "${WEBMAIL_DATA_DIR}"

    # 3. Download and extract SnappyMail if not already present
    if [[ ! -f "${app_dir}/index.php" && "${LC_TEST_MODE:-0}" != "1" ]]; then
        local tmp_tar
        tmp_tar="$(mktemp /tmp/snappymail-XXXXXX.tar.gz)"
        if ! curl -fsSL --retry 3 --connect-timeout 15 --max-time 180 -o "${tmp_tar}" "${SNAPPYMAIL_DOWNLOAD_URL}"; then
            rm -f "${tmp_tar}"
            echo "Failed to download SnappyMail from ${SNAPPYMAIL_DOWNLOAD_URL}." >&2
            return 1
        fi
        tar -xzf "${tmp_tar}" -C "${app_dir}"
        rm -f "${tmp_tar}"
    fi

    # 4. Write SnappyMail configs and set isolated permissions
    _webmail_write_snappymail_configs "${app_dir}"
    chown -R "${WEBMAIL_USER}:${WEBMAIL_GROUP}" "${WEBMAIL_BASE_DIR}" "${app_dir}"
    chmod 0755 "${WEBMAIL_BASE_DIR}"
    chmod 0750 "${WEBMAIL_DATA_DIR}"

    # 5. Clean up any legacy Go-generated vhost directory if present
    if [[ -d /usr/local/lsws/conf/vhosts/lc-webmail ]]; then
        rm -rf /usr/local/lsws/conf/vhosts/lc-webmail
    fi

    # 6. Idempotently provision OpenLiteSpeed vhost & listener mapping via proven vhost.sh
    if [[ -f "${LC_CONF_DIR}/vhosts/${hostname}.conf" || -d "${OLS_VHOSTS_DIR}/${hostname}" ]]; then
        LC_JSON_OUTPUT=0 "${SCRIPT_DIR}/vhost.sh" remove "${hostname}" >&2 || true
    fi
    LC_JSON_OUTPUT=0 "${SCRIPT_DIR}/vhost.sh" add "${hostname}" "${WEBMAIL_USER}" "${LC_PHP_VER:-83}" "${app_dir}" >&2

    # 7. Issue & bind TLS certificate via proven ssl.sh (best-effort if DNS not yet propagated)
    local tls_issued="false"
    if LC_JSON_OUTPUT=0 "${SCRIPT_DIR}/ssl.sh" issue "${hostname}" "${app_dir}" >&2; then
        tls_issued="true"
    fi

    # 8. Persist webmail state metadata & module marker
    mkdir -p "$(dirname "${WEBMAIL_STATE_FILE}")"
    cat > "${WEBMAIL_STATE_FILE}" <<EOF
{
  "driver": "${driver}",
  "hostname": "${hostname}",
  "app_dir": "${app_dir}",
  "tls_issued": ${tls_issued}
}
EOF
    chmod 0644 "${WEBMAIL_STATE_FILE}"
    mark_module_installed "mail_webmail"

    printf '{"status":"installed","driver":"%s","hostname":"%s","app_dir":"%s","tls_issued":%s}\n' \
        "${driver}" "${hostname}" "${app_dir}" "${tls_issued}"
}

cmd_webmail_uninstall() {
    local hostname
    hostname="$(_webmail_read_state_hostname)"

    if [[ -n "${hostname}" ]]; then
        LC_JSON_OUTPUT=0 "${SCRIPT_DIR}/vhost.sh" remove "${hostname}" --delete-files >&2 || true
        rm -rf "${LC_HOME_ROOT}/${WEBMAIL_USER}/${hostname}"
    fi

    # Clean up legacy Go-generated vhost dir if present
    if [[ -d /usr/local/lsws/conf/vhosts/lc-webmail ]]; then
        rm -rf /usr/local/lsws/conf/vhosts/lc-webmail
    fi

    rm -rf "${WEBMAIL_BASE_DIR}"
    rm -f "${WEBMAIL_STATE_FILE}"
    mark_module_removed "mail_webmail"

    printf '{"status":"removed","driver":"snappymail","hostname":"%s"}\n' "${hostname}"
}

cmd_webmail_status() {
    local installed="false"
    local running="false"
    local hostname=""

    if [[ -f "${WEBMAIL_STATE_FILE}" ]]; then
        installed="true"
        hostname="$(_webmail_read_state_hostname)"
        if systemctl is-active --quiet lshttpd 2>/dev/null || systemctl is-active --quiet lsws 2>/dev/null; then
            running="true"
        fi
    fi

    printf '{"installed":%s,"running":%s,"driver":"snappymail","hostname":"%s"}\n' \
        "${installed}" "${running}" "${hostname}"
}
