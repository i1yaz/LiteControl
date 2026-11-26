#!/usr/bin/env bash
# ==============================================================================
# LiteControl CLI Command: `lc-cli module`
# Subcommands:
#   list
#   status <module_name>
#   install <module_name>
#   remove <module_name>
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

MODULES_INSTALLERS_DIR="${SCRIPT_DIR}/../modules"

SUPPORTED_MODULES=("ftp" "dns" "mail" "mail_core" "mail_filter" "clamav" "mail_webmail" "modsecurity" "imunifyav")

emit_error() {
    local msg="$1"
    if [[ "${LC_JSON_OUTPUT:-0}" == "1" ]]; then
        json_response "error" "${msg}"
    else
        log_error "${msg}"
    fi
    exit 1
}

emit_success() {
    local msg="$1"
    local data_json="${2:-null}"
    if [[ "${LC_JSON_OUTPUT:-0}" == "1" ]]; then
        json_response "success" "${msg}" "${data_json}"
    else
        log_ok "${msg}"
    fi
}

normalize_module_name() {
    case "$1" in
        powerdns) echo "dns" ;;
        *) echo "$1" ;;
    esac
}

validate_module() {
    local m="$1"
    for sm in "${SUPPORTED_MODULES[@]}"; do
        if [[ "${m}" == "${sm}" ]]; then
            return 0
        fi
    done
    emit_error "Unsupported module '${m}'. Supported modules are: ${SUPPORTED_MODULES[*]}."
}

get_module_title() {
    case "$1" in
        ftp) echo "Pure-FTPd" ;;
        dns) echo "PowerDNS Server" ;;
        mail) echo "Mail Stack (Postfix & Dovecot)" ;;
        mail_core) echo "Mail Server Core (Postfix & Dovecot)" ;;
        mail_filter) echo "Mail Anti-Spam Filter (Rspamd)" ;;
        clamav) echo "ClamAV Antivirus" ;;
        mail_webmail) echo "Webmail (SnappyMail)" ;;
        modsecurity) echo "OpenLiteSpeed ModSecurity & OWASP CRS" ;;
        imunifyav) echo "ImunifyAV Malware Scanner" ;;
        *) echo "$1" ;;
    esac
}

get_module_desc() {
    case "$1" in
        ftp) echo "Secure FTP server with MySQL virtual accounts and passive port range (40110-40210)." ;;
        dns) echo "Authoritative DNS nameserver powered by PowerDNS with MySQL backend and DNSSEC." ;;
        mail) echo "Complete email server with Postfix, Dovecot IMAP/POP3, OpenDKIM signing, and MySQL virtual mailboxes." ;;
        mail_core) echo "Core mail stack: Postfix, Dovecot, Rspamd-Light (DKIM & outbound ratelimit), and postsrsd." ;;
        mail_filter) echo "Full inbound anti-spam filtering and Sieve Junk auto-filing via Rspamd." ;;
        clamav) echo "Optional ClamAV virus scanner for inbound mail (requires >= 4 GB RAM)." ;;
        mail_webmail) echo "Isolated SnappyMail webmail client served via OpenLiteSpeed." ;;
        modsecurity) echo "Web Application Firewall (WAF) integrated into OpenLiteSpeed with OWASP Core Rule Set." ;;
        imunifyav) echo "Automated Linux malware detector and web hosting security scanner." ;;
        *) echo "LiteControl extension module." ;;
    esac
}

get_module_driver() {
    case "$1" in
        ftp) echo "pure-ftpd" ;;
        dns) echo "powerdns" ;;
        mail_core) echo "postfix_dovecot" ;;
        mail_filter) echo "rspamd" ;;
        clamav) echo "clamav" ;;
        mail_webmail) echo "snappymail" ;;
        modsecurity) echo "modsecurity" ;;
        imunifyav) echo "imunifyav" ;;
        *) echo "$1" ;;
    esac
}

get_module_service() {
    case "$1" in
        ftp) echo "pure-ftpd-mysql" ;;
        dns) echo "pdns" ;;
        mail|mail_core) echo "postfix" ;;
        mail_filter) echo "rspamd" ;;
        clamav) echo "clamav-daemon" ;;
        mail_webmail) echo "lshttpd" ;;
        modsecurity) echo "openlitespeed" ;;
        imunifyav) echo "imunify-antivirus" ;;
        *) echo "$1" ;;
    esac
}

check_module_installed() {
    local mod="$1"
    if is_module_installed "${mod}"; then
        return 0
    fi
    if [[ "${LC_TEST_MODE:-0}" == "1" ]]; then
        return 1
    fi
    case "${mod}" in
        mail_core)
            [[ -f /etc/systemd/system/lc-rspamd-redis.service && -f /etc/postfix/sql/domains.cf && -f /etc/dovecot/conf.d/99-litecontrol.conf ]]
            ;;
        mail_filter)
            if check_module_installed "mail_core" && [[ -f /etc/rspamd/local.d/lc_filter_mode ]]; then
                local mode
                mode=$(tr -d ' \t\r\n' < /etc/rspamd/local.d/lc_filter_mode 2>/dev/null || true)
                [[ "${mode}" == "full" || "${mode}" == "full+clamav" ]]
            else
                return 1
            fi
            ;;
        clamav)
            if check_module_installed "mail_core" && [[ -f /etc/rspamd/local.d/lc_filter_mode ]]; then
                local mode
                mode=$(tr -d ' \t\r\n' < /etc/rspamd/local.d/lc_filter_mode 2>/dev/null || true)
                [[ "${mode}" == "full+clamav" ]]
            else
                return 1
            fi
            ;;
        mail_webmail)
            [[ -f /var/lib/lc-mail/webmail.json && -f /usr/local/lsws/conf/vhosts/lc-webmail/vhconf.conf ]]
            ;;
        *)
            return 1
            ;;
    esac
}

get_module_state() {
    local mod="$1"
    if ! check_module_installed "${mod}"; then
        echo "not_installed"
        return 0
    fi
    if [[ "${LC_TEST_MODE:-0}" == "1" ]]; then
        echo "active"
        return 0
    fi
    local svc
    svc="$(get_module_service "${mod}")"
    if [[ "${mod}" == "modsecurity" ]]; then
        if grep -q "module mod_security" "${OLS_CONF}" 2>/dev/null; then
            echo "active"
        else
            echo "inactive"
        fi
    elif [[ "${mod}" == "mail_core" ]]; then
        if systemctl is-active --quiet postfix 2>/dev/null && systemctl is-active --quiet dovecot 2>/dev/null; then
            echo "active"
        else
            echo "inactive"
        fi
    elif [[ "${mod}" == "clamav" ]]; then
        if systemctl is-active --quiet clamav-daemon 2>/dev/null || systemctl is-active --quiet clamd@scan 2>/dev/null; then
            echo "active"
        else
            echo "inactive"
        fi
    elif [[ "${mod}" == "mail_webmail" ]]; then
        if systemctl is-active --quiet lshttpd 2>/dev/null || systemctl is-active --quiet lsws 2>/dev/null; then
            echo "active"
        else
            echo "inactive"
        fi
    else
        systemctl is-active --quiet "${svc}" 2>/dev/null && echo "active" || echo "inactive"
    fi
}

# ------------------------------------------------------------------------------
# Subcommands
# ------------------------------------------------------------------------------

cmd_module_list() {
    local items=()

    for mod in "${SUPPORTED_MODULES[@]}"; do
        local inst=false
        if check_module_installed "${mod}"; then
            inst=true
        fi
        local state
        state="$(get_module_state "${mod}")"
        local title
        title="$(get_module_title "${mod}")"
        local desc
        desc="$(get_module_desc "${mod}")"
        local driver
        driver="$(get_module_driver "${mod}")"
        local svc
        svc="$(get_module_service "${mod}")"

        items+=("$(printf '{"name":"%s","title":"%s","description":"%s","driver":"%s","installed":%s,"status":"%s","service":"%s"}' \
            "$(json_escape "${mod}")" \
            "$(json_escape "${title}")" \
            "$(json_escape "${desc}")" \
            "$(json_escape "${driver}")" \
            "${inst}" \
            "$(json_escape "${state}")" \
            "$(json_escape "${svc}")")")
    done

    local array_json
    local joined=""
    local sep=""
    for it in "${items[@]}"; do
        joined+="${sep}${it}"
        sep=","
    done
    array_json="[${joined}]"

    if [[ "${LC_JSON_OUTPUT:-0}" == "1" ]]; then
        json_response "success" "Module list retrieved." "${array_json}"
    else
        printf "%-15s %-35s %-12s %s\n" "MODULE" "NAME" "STATUS" "INSTALLED"
        echo "--------------------------------------------------------------------------------"
        for mod in "${SUPPORTED_MODULES[@]}"; do
            local inst="no"
            if check_module_installed "${mod}"; then
                inst="yes"
            fi
            local state
            state="$(get_module_state "${mod}")"
            local title
            title="$(get_module_title "${mod}")"
            printf "%-15s %-35s %-12s %s\n" "${mod}" "${title}" "${state}" "${inst}"
        done
    fi
}

cmd_module_status() {
    local mod="${1:-}"
    [[ -n "${mod}" ]] || emit_error "Missing module name."
    mod="$(normalize_module_name "${mod}")"
    validate_module "${mod}"

    local inst=false
    if check_module_installed "${mod}"; then
        inst=true
    fi
    local state
    state="$(get_module_state "${mod}")"
    local title
    title="$(get_module_title "${mod}")"
    local desc
    desc="$(get_module_desc "${mod}")"
    local driver
    driver="$(get_module_driver "${mod}")"
    local svc
    svc="$(get_module_service "${mod}")"

    local data_json
    data_json=$(printf '{"name":"%s","title":"%s","description":"%s","driver":"%s","installed":%s,"status":"%s","service":"%s"}' \
        "$(json_escape "${mod}")" \
        "$(json_escape "${title}")" \
        "$(json_escape "${desc}")" \
        "$(json_escape "${driver}")" \
        "${inst}" \
        "$(json_escape "${state}")" \
        "$(json_escape "${svc}")")

    emit_success "Module '${mod}' status retrieved." "${data_json}"
}

cmd_module_install() {
    local mod="${1:-}"
    [[ -n "${mod}" ]] || emit_error "Missing module name."
    mod="$(normalize_module_name "${mod}")"
    validate_module "${mod}"

    local installer="${MODULES_INSTALLERS_DIR}/${mod}.sh"
    if [[ ! -f "${installer}" ]]; then
        emit_error "Installer script for module '${mod}' not found at ${installer}."
    fi

    log_step "Installing optional module: ${mod}..."
    bash "${installer}" install

    mark_module_installed "${mod}"

    local data_json
    data_json=$(printf '{"name":"%s","installed":true,"status":"active"}' "$(json_escape "${mod}")")
    emit_success "Module '${mod}' installed successfully." "${data_json}"
}

cmd_module_remove() {
    local mod="${1:-}"
    [[ -n "${mod}" ]] || emit_error "Missing module name."
    mod="$(normalize_module_name "${mod}")"
    validate_module "${mod}"

    local installer="${MODULES_INSTALLERS_DIR}/${mod}.sh"
    if [[ ! -f "${installer}" ]]; then
        emit_error "Installer script for module '${mod}' not found at ${installer}."
    fi

    log_step "Removing optional module: ${mod}..."
    bash "${installer}" remove

    mark_module_removed "${mod}"

    local data_json
    data_json=$(printf '{"name":"%s","installed":false,"status":"not_installed"}' "$(json_escape "${mod}")")
    emit_success "Module '${mod}' removed successfully." "${data_json}"
}

# ------------------------------------------------------------------------------
# Dispatcher
# ------------------------------------------------------------------------------

ACTION="${1:-}"
shift || true

case "${ACTION}" in
    list)
        cmd_module_list "$@"
        ;;
    status)
        cmd_module_status "$@"
        ;;
    install)
        cmd_module_install "$@"
        ;;
    remove)
        cmd_module_remove "$@"
        ;;
    help|--help|-h|"")
        cat <<EOF
Usage: lc-cli module <command> [arguments...]

Commands:
  list                           List all optional modules and install states
  status  <module_name>          Check status of a module
  install <module_name>          Install and configure an optional module
  remove  <module_name>          Disable or remove an optional module

Supported Modules:
  ftp, dns, mail, modsecurity, imunifyav
EOF
        ;;
    *)
        emit_error "Unknown module command '${ACTION}'."
        ;;
esac

