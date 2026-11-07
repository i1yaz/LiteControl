#!/usr/bin/env bash
# ==============================================================================
# LiteControl CLI Command: `lc-cli security`
# Subcommands:
#   ufw-status
#   ufw-allow <port> [proto] [source_ip]
#   ufw-deny <port> [proto] [source_ip]
#   ufw-delete <rule_num_or_spec>
#   panel-port <new_port>
#   ssh-port <new_port>
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

UFW_MOCK_RULES="${LC_CONF_DIR}/mock_ufw_rules.conf"
SSHD_CONFIG_FILE="${SSHD_CONFIG_FILE:-/etc/ssh/sshd_config}"

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

validate_port() {
    local p="$1"
    local min="${2:-1}"
    local max="${3:-65535}"
    if ! [[ "${p}" =~ ^[0-9]+$ ]] || [[ "${p}" -lt "${min}" ]] || [[ "${p}" -gt "${max}" ]]; then
        emit_error "Invalid port '${p}'. Must be between ${min} and ${max}."
    fi
}

# ------------------------------------------------------------------------------
# UFW Helpers
# ------------------------------------------------------------------------------

cmd_ufw_status() {
    local active=false
    local rules=()

    if [[ "${LC_TEST_MODE:-0}" == "1" ]]; then
        active=true
        if [[ -f "${UFW_MOCK_RULES}" ]]; then
            local idx=1
            while IFS='|' read -r to action from; do
                [[ -n "${to}" ]] || continue
                rules+=("$(printf '{"id":%d,"to":"%s","action":"%s","from":"%s"}' \
                    "${idx}" \
                    "$(json_escape "${to}")" \
                    "$(json_escape "${action}")" \
                    "$(json_escape "${from}")")")
                idx=$((idx + 1))
            done < "${UFW_MOCK_RULES}"
        fi
    else
        local raw
        raw=$(ufw status numbered 2>/dev/null || true)
        if echo "${raw}" | grep -q "Status: active"; then
            active=true
        fi

        # Parse numbered rules: [ 1] 80/tcp ALLOW IN Anywhere
        while IFS= read -r line; do
            if [[ "${line}" =~ ^\[[[:space:]]*([0-9]+)\][[:space:]]+([^[:space:]]+)[[:space:]]+(ALLOW|DENY|REJECT)[[:space:]]+IN[[:space:]]+(.*)$ ]]; then
                local num="${BASH_REMATCH[1]}"
                local to="${BASH_REMATCH[2]}"
                local act="${BASH_REMATCH[3]} IN"
                local from="${BASH_REMATCH[4]}"
                rules+=("$(printf '{"id":%d,"to":"%s","action":"%s","from":"%s"}' \
                    "${num}" \
                    "$(json_escape "${to}")" \
                    "$(json_escape "${act}")" \
                    "$(json_escape "${from}")")")
            fi
        done <<< "${raw}"
    fi

    local rules_json
    local joined=""
    local sep=""
    for r in "${rules[@]}"; do
        joined+="${sep}${r}"
        sep=","
    done
    rules_json="[${joined}]"

    local data_json
    data_json=$(printf '{"active":%s,"rules":%s}' "${active}" "${rules_json}")

    if [[ "${LC_JSON_OUTPUT:-0}" == "1" ]]; then
        json_response "success" "UFW status retrieved." "${data_json}"
    else
        echo "UFW Firewall Status: $(if ${active}; then echo 'active'; else echo 'inactive'; fi)"
        echo "--------------------------------------------------------"
        if [[ "${LC_TEST_MODE:-0}" == "1" && -f "${UFW_MOCK_RULES}" ]]; then
            cat "${UFW_MOCK_RULES}"
        else
            ufw status numbered || true
        fi
    fi
}

cmd_ufw_allow() {
    local port="$1"
    local proto="${2:-tcp}"
    local source_ip="${3:-}"

    if [[ "${port}" =~ ^[0-9]+$ ]]; then
        validate_port "${port}" 1 65535
    fi

    if [[ "${LC_TEST_MODE:-0}" == "1" ]]; then
        mkdir -p "$(dirname "${UFW_MOCK_RULES}")"
        echo "${port}/${proto}|ALLOW IN|${source_ip:-Anywhere}" >> "${UFW_MOCK_RULES}"
    else
        if [[ -n "${source_ip}" ]]; then
            ufw allow from "${source_ip}" to any port "${port}" proto "${proto}"
        else
            ufw allow "${port}/${proto}"
        fi
    fi

    local data_json
    data_json=$(printf '{"port":"%s","proto":"%s","action":"allow","source":"%s"}' \
        "$(json_escape "${port}")" \
        "$(json_escape "${proto}")" \
        "$(json_escape "${source_ip:-Anywhere}")")
    emit_success "Firewall rule added: ALLOW ${port}/${proto}." "${data_json}"
}

cmd_ufw_deny() {
    local port="$1"
    local proto="${2:-tcp}"
    local source_ip="${3:-}"

    if [[ "${port}" =~ ^[0-9]+$ ]]; then
        validate_port "${port}" 1 65535
    fi

    if [[ "${LC_TEST_MODE:-0}" == "1" ]]; then
        mkdir -p "$(dirname "${UFW_MOCK_RULES}")"
        echo "${port}/${proto}|DENY IN|${source_ip:-Anywhere}" >> "${UFW_MOCK_RULES}"
    else
        if [[ -n "${source_ip}" ]]; then
            ufw deny from "${source_ip}" to any port "${port}" proto "${proto}"
        else
            ufw deny "${port}/${proto}"
        fi
    fi

    local data_json
    data_json=$(printf '{"port":"%s","proto":"%s","action":"deny","source":"%s"}' \
        "$(json_escape "${port}")" \
        "$(json_escape "${proto}")" \
        "$(json_escape "${source_ip:-Anywhere}")")
    emit_success "Firewall rule added: DENY ${port}/${proto}." "${data_json}"
}

cmd_ufw_delete() {
    local spec="$1"

    if [[ "${LC_TEST_MODE:-0}" == "1" ]]; then
        if [[ -f "${UFW_MOCK_RULES}" ]]; then
            if [[ "${spec}" =~ ^[0-9]+$ ]]; then
                sed -i "${spec}d" "${UFW_MOCK_RULES}" 2>/dev/null || true
            else
                sed -i "\|${spec}|d" "${UFW_MOCK_RULES}" 2>/dev/null || true
            fi
        fi
    else
        if [[ "${spec}" =~ ^[0-9]+$ ]]; then
            echo "y" | ufw delete "${spec}"
        else
            ufw delete allow "${spec}" 2>/dev/null || ufw delete deny "${spec}" 2>/dev/null || ufw delete "${spec}"
        fi
    fi

    emit_success "Firewall rule '${spec}' deleted successfully." "{\"deleted\":\"${spec}\"}"
}

# ------------------------------------------------------------------------------
# Panel Port Change
# ------------------------------------------------------------------------------

cmd_panel_port() {
    local new_port="${1:-}"
    [[ -n "${new_port}" ]] || emit_error "Missing new panel port."
    validate_port "${new_port}" 1024 65535

    local cur_port="${LC_DEFAULT_PORT}"
    if [[ -f "${LC_CONF_DIR}/port" ]]; then
        cur_port=$(tr -d ' \t\r\n' < "${LC_CONF_DIR}/port")
    fi

    if [[ "${cur_port}" == "${new_port}" ]]; then
        emit_success "Panel is already configured on port ${new_port}." "{\"port\":${new_port}}"
        return 0
    fi

    # 1. Open new port in UFW first to prevent self-lockout
    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        ufw allow "${new_port}/tcp" comment "LiteControl Panel" >/dev/null 2>&1 || true
    fi

    # 2. Update Dedicated Panel Nginx configuration
    if [[ -f "${PANEL_NGINX_CONF}" ]]; then
        panel_backup_config
        sed -i -E "s/(listen[[:space:]]+(\[::\]:)?)[0-9]+([[:space:]]+ssl[[:space:]]+http2;)/\1${new_port}\3/g" "${PANEL_NGINX_CONF}"
    fi

    # Update runtime state
    mkdir -p "${LC_CONF_DIR}"
    echo "${new_port}" > "${LC_CONF_DIR}/port"

    # Also update APP_URL in Laravel .env if present
    if [[ -f "${LC_PANEL_DIR}/.env" ]]; then
        sed -i -E "s|^(APP_URL=https?://[^:]+):[0-9]+|\1:${new_port}|" "${LC_PANEL_DIR}/.env"
    fi

    # Update WebAdmin notice if present
    if [[ -f "${LC_CONF_DIR}/webadmin_notice.html" ]]; then
        sed -i -E "s|:[0-9]+/|:${new_port}/|g" "${LC_CONF_DIR}/webadmin_notice.html" 2>/dev/null || true
    fi
    for notice_dir in "${OLS_DIR}/admin/html.open" "${OLS_DIR}/admin/html"; do
        if [[ -f "${notice_dir}/view/service/webAdminNotice.php" ]]; then
            sed -i -E "s|:[0-9]+/|:${new_port}/|g" "${notice_dir}/view/service/webAdminNotice.php" 2>/dev/null || true
        fi
    done

    # 3. Reload Panel Nginx safely with auto-rollback
    if ! panel_safe_reload; then
        # Rollback port state and Nginx config
        panel_restore_config
        echo "${cur_port}" > "${LC_CONF_DIR}/port"
        emit_error "Failed to reload Panel Nginx with new port ${new_port}. Rolled back to ${cur_port}."
    fi

    # 4. Remove old port from UFW if different from common web ports
    if [[ "${LC_TEST_MODE:-0}" != "1" && "${cur_port}" != "80" && "${cur_port}" != "443" && "${cur_port}" != "22" ]]; then
        ufw delete allow "${cur_port}/tcp" >/dev/null 2>&1 || true
    fi

    local data_json
    data_json=$(printf '{"old_port":%s,"new_port":%s}' "${cur_port}" "${new_port}")
    emit_success "Panel port changed successfully from ${cur_port} to ${new_port}." "${data_json}"
}

# ------------------------------------------------------------------------------
# SSH Port Change
# ------------------------------------------------------------------------------

cmd_ssh_port() {
    local new_port="${1:-}"
    [[ -n "${new_port}" ]] || emit_error "Missing new SSH port."
    validate_port "${new_port}" 1 65535

    local cur_port=22
    if [[ -f "${SSHD_CONFIG_FILE}" ]]; then
        local p
        p=$(grep -E '^[[:space:]]*Port[[:space:]]+[0-9]+' "${SSHD_CONFIG_FILE}" | awk '{print $2}' | tail -n 1 || true)
        [[ -n "${p}" ]] && cur_port="${p}"
    fi

    if [[ "${cur_port}" == "${new_port}" ]]; then
        emit_success "SSH is already configured on port ${new_port}." "{\"port\":${new_port}}"
        return 0
    fi

    # 1. Open new SSH port in UFW first
    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        ufw allow "${new_port}/tcp" comment "SSH Port" >/dev/null 2>&1 || true
    fi

    # 2. Update sshd_config safely
    if [[ -f "${SSHD_CONFIG_FILE}" ]]; then
        local backup_sshd="${SSHD_CONFIG_FILE}.bak"
        cp -p "${SSHD_CONFIG_FILE}" "${backup_sshd}"

        if grep -qE '^[[:space:]]*Port[[:space:]]+' "${SSHD_CONFIG_FILE}"; then
            sed -i -E "s/^[[:space:]]*Port[[:space:]]+[0-9]+/Port ${new_port}/" "${SSHD_CONFIG_FILE}"
        else
            echo "Port ${new_port}" >> "${SSHD_CONFIG_FILE}"
        fi

        # Validate sshd syntax if not in test mode
        if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
            if ! sshd -t >/dev/null 2>&1; then
                mv -f "${backup_sshd}" "${SSHD_CONFIG_FILE}"
                emit_error "Invalid sshd configuration generated for port ${new_port}. Rolled back."
            fi
            rm -f "${backup_sshd}"

            systemctl restart ssh 2>/dev/null || systemctl restart sshd 2>/dev/null || true

            if [[ "${cur_port}" != "22" ]]; then
                ufw delete allow "${cur_port}/tcp" >/dev/null 2>&1 || true
            fi
        fi
    fi

    local data_json
    data_json=$(printf '{"old_port":%s,"new_port":%s}' "${cur_port}" "${new_port}")
    emit_success "SSH port changed successfully from ${cur_port} to ${new_port}." "${data_json}"
}

# ------------------------------------------------------------------------------
# Dispatcher
# ------------------------------------------------------------------------------

ACTION="${1:-}"
shift || true

case "${ACTION}" in
    ufw-status)
        cmd_ufw_status "$@"
        ;;
    ufw-allow)
        cmd_ufw_allow "$@"
        ;;
    ufw-deny)
        cmd_ufw_deny "$@"
        ;;
    ufw-delete)
        cmd_ufw_delete "$@"
        ;;
    panel-port)
        cmd_panel_port "$@"
        ;;
    ssh-port)
        cmd_ssh_port "$@"
        ;;
    help|--help|-h|"")
        cat <<EOF
Usage: lc-cli security <command> [arguments...]

Commands:
  ufw-status                           Get status and numbered list of firewall rules
  ufw-allow   <port> [proto] [ip]      Allow incoming traffic
  ufw-deny    <port> [proto] [ip]      Deny incoming traffic
  ufw-delete  <rule_num|rule_spec>     Delete a firewall rule
  panel-port  <new_port>               Change LiteControl panel port (1024-65535)
  ssh-port    <new_port>               Change SSH server port (1-65535)
EOF
        ;;
    *)
        emit_error "Unknown security command '${ACTION}'."
        ;;
esac

