#!/usr/bin/env bash
# ==============================================================================
# LiteControl CLI Command: `lc-cli server`
# Powers Dashboard Metrics, Server Menu (Services, Process Manager, Time Zone,
# Restart, Change Hostname, System Statistics) & Configuration -> Error Logs.
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

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

cmd_server_stats() {
    local host_name
    host_name=$(cat "${LC_CONF_DIR}/hostname" 2>/dev/null || hostname 2>/dev/null || echo "localhost")
    local srv_ip
    srv_ip=$(get_server_ip)
    local srv_time
    srv_time=$(date '+%Y-%m-%d %H:%M:%S %Z')
    local tz
    tz=$(cat "${LC_CONF_DIR}/timezone" 2>/dev/null || timedatectl show -p Timezone --value 2>/dev/null || date '+%Z')

    local cpu_pct="5.0"
    if [[ -r /proc/stat ]]; then
        cpu_pct=$(awk '/^cpu / {u=$2+$4; t=$2+$4+$5; if (t>0) printf "%.1f", (u*100)/t; else print "0.0"}' /proc/stat)
    fi

    local ram_total=2048 ram_used=512 ram_pct="25.0"
    if command -v free >/dev/null 2>&1; then
        read -r ram_total ram_used ram_pct < <(free -m | awk 'NR==2{t=$2; u=$3; p=(t>0)?(u*100)/t:0; printf "%d %d %.1f\n", t, u, p}') || true
    fi

    local disk_total=40 disk_used=8 disk_pct="20"
    if command -v df >/dev/null 2>&1; then
        read -r disk_total disk_used disk_pct < <(df -BG / 2>/dev/null | awk 'NR==2{gsub(/G/,"",$2); gsub(/G/,"",$3); gsub(/%/,"",$5); printf "%d %d %d\n", $2, $3, $5}') || true
    fi

    local load_str="0.05, 0.03, 0.01"
    if [[ -r /proc/loadavg ]]; then
        load_str=$(awk '{printf "%s, %s, %s", $1, $2, $3}' /proc/loadavg)
    fi

    local uptime_str="up 1 day"
    if command -v uptime >/dev/null 2>&1; then
        uptime_str=$(uptime -p 2>/dev/null || uptime | awk -F'up ' '{print $2}' | cut -d, -f1,2)
    fi

    local ols_ver="1.8.2"
    if [[ -x "${OLS_BIN}" ]]; then
        ols_ver=$("${OLS_BIN}" -v 2>/dev/null | head -n 1 | awk '{print $1}' | sed 's|LiteSpeed/||' || echo "1.8.2")
    fi

    local os_pretty="Ubuntu 24.04 LTS"
    if [[ -f /etc/os-release ]]; then
        os_pretty=$(awk -F= '/^PRETTY_NAME=/{gsub(/"/,"",$2); print $2}' /etc/os-release)
    fi

    local data_json
    data_json=$(printf '{"hostname":"%s","server_ip":"%s","server_time":"%s","timezone":"%s","cpu_percent":%s,"ram_total_mb":%s,"ram_used_mb":%s,"ram_percent":%s,"disk_total_gb":%s,"disk_used_gb":%s,"disk_percent":%s,"load_avg":"%s","uptime":"%s","ols_version":"%s","os":"%s"}' \
        "$(json_escape "${host_name}")" \
        "$(json_escape "${srv_ip}")" \
        "$(json_escape "${srv_time}")" \
        "$(json_escape "${tz}")" \
        "${cpu_pct:-0}" \
        "${ram_total:-0}" \
        "${ram_used:-0}" \
        "${ram_pct:-0}" \
        "${disk_total:-0}" \
        "${disk_used:-0}" \
        "${disk_pct:-0}" \
        "$(json_escape "${load_str}")" \
        "$(json_escape "${uptime_str}")" \
        "$(json_escape "${ols_ver}")" \
        "$(json_escape "${os_pretty}")")

    emit_success "Server statistics retrieved." "${data_json}"
}

check_unit_state() {
    local svc="$1"
    if [[ "${LC_TEST_MODE:-0}" == "1" ]]; then
        printf "active"
        return 0
    fi
    if [[ "${svc}" == "openlitespeed" ]]; then
        if "${OLS_CTRL}" status >/dev/null 2>&1; then
            printf "active"
        else
            printf "inactive"
        fi
        return 0
    fi
    systemctl is-active "${svc}" 2>/dev/null || printf "inactive"
}

cmd_server_services() {
    local services=("litecontrol-panel" "litecontrol-php" "openlitespeed" "mariadb" "redis-server" "ufw" "cron" "ssh")

    # Include installed optional module services dynamically
    is_module_installed "ftp" && services+=("pure-ftpd-mysql")
    is_module_installed "dns" && services+=("pdns")
    is_module_installed "mail" && services+=("postfix" "dovecot" "opendkim")

    local items=""
    local sep=""
    for svc in "${services[@]}"; do
        local st
        st=$(check_unit_state "${svc}")
        items+="${sep}$(printf '{"service":"%s","status":"%s"}' "${svc}" "${st}")"
        sep=","
    done

    emit_success "Service statuses retrieved." "[${items}]"
}

cmd_server_service_action() {
    local svc="${1:-}"
    local action="${2:-}"

    if ! [[ "${action}" =~ ^(start|stop|restart|reload)$ ]]; then
        emit_error "Invalid service action '${action}'. Allowed: start, stop, restart, reload."
    fi

    case "${svc}" in
        litecontrol-panel)
            if [[ "${action}" == "stop" ]]; then
                emit_error "Stopping the panel web server from inside LiteControl is prohibited to prevent self-lockout."
            fi
            if [[ "${action}" == "reload" || "${action}" == "restart" ]]; then
                panel_safe_reload || emit_error "Panel web server reload failed."
            elif [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
                systemctl "${action}" "${PANEL_SYSTEMD_SERVICE}"
            fi
            ;;
        litecontrol-php)
            if [[ "${action}" == "stop" ]]; then
                emit_error "Stopping the panel PHP daemon from inside LiteControl is prohibited to prevent self-lockout."
            fi
            if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
                systemctl "${action}" "${PANEL_FPM_SYSTEMD_SERVICE}"
            fi
            ;;
        openlitespeed|lsws|lshttpd)
            if [[ "${action}" == "reload" || "${action}" == "restart" ]]; then
                ols_safe_reload || emit_error "OpenLiteSpeed reload failed."
            elif [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
                "${OLS_CTRL}" "${action}" >/dev/null 2>&1 || true
            fi
            ;;
        mariadb|redis-server|ufw|cron|ssh|pure-ftpd-mysql|pdns|postfix|dovecot|opendkim)
            if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
                systemctl "${action}" "${svc}"
            fi
            ;;
        *)
            emit_error "Service '${svc}' is not managed by LiteControl."
            ;;
    esac

    local data_json
    data_json=$(printf '{"service":"%s","action":"%s","status":"%s"}' "${svc}" "${action}" "$(check_unit_state "${svc}")")
    emit_success "Executed '${action}' on service '${svc}'." "${data_json}"
}

cmd_server_timezone() {
    local mode="${1:-get}"
    local new_tz="${2:-}"

    if [[ "${mode}" == "get" ]]; then
        local tz
        tz=$(cat "${LC_CONF_DIR}/timezone" 2>/dev/null || timedatectl show -p Timezone --value 2>/dev/null || echo "UTC")
        emit_success "Current timezone." "{\"timezone\":\"$(json_escape "${tz}")\"}"
    elif [[ "${mode}" == "set" ]]; then
        if ! [[ "${new_tz}" =~ ^[A-Za-z0-9_+-]+(/[A-Za-z0-9_+-]+)*$ ]]; then
            emit_error "Invalid timezone format '${new_tz}'."
        fi
        if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
            timedatectl set-timezone "${new_tz}"
        fi
        mkdir -p "${LC_CONF_DIR}"
        printf "%s" "${new_tz}" > "${LC_CONF_DIR}/timezone"
        emit_success "Timezone updated to '${new_tz}'." "{\"timezone\":\"$(json_escape "${new_tz}")\"}"
    else
        emit_error "Usage: lc-cli server timezone <get|set> [timezone]"
    fi
}

cmd_server_hostname() {
    local mode="${1:-get}"
    local new_host="${2:-}"

    if [[ "${mode}" == "get" ]]; then
        local h
        h=$(cat "${LC_CONF_DIR}/hostname" 2>/dev/null || hostname -f 2>/dev/null || hostname)
        emit_success "Current hostname." "{\"hostname\":\"$(json_escape "${h}")\"}"
    elif [[ "${mode}" == "set" ]]; then
        if ! [[ "${new_host}" =~ ^[a-zA-Z0-9]([a-zA-Z0-9.-]{0,61}[a-zA-Z0-9])?$ ]]; then
            emit_error "Invalid hostname '${new_host}'."
        fi
        if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
            hostnamectl set-hostname "${new_host}"
        fi
        mkdir -p "${LC_CONF_DIR}"
        printf "%s" "${new_host}" > "${LC_CONF_DIR}/hostname"
        if [[ -f "${OLS_CONF}" ]]; then
            sed -i "s/^serverName .*/serverName                ${new_host}/" "${OLS_CONF}"
            ols_safe_reload || true
        fi
        emit_success "Hostname updated to '${new_host}'." "{\"hostname\":\"$(json_escape "${new_host}")\"}"
    else
        emit_error "Usage: lc-cli server hostname <get|set> [hostname]"
    fi
}

cmd_server_processes() {
    local items=""
    local sep=""

    if [[ "${LC_TEST_MODE:-0}" == "1" ]]; then
        items='{"pid":101,"user":"root","cpu":"0.5","mem":"1.2","command":"lshttpd"},{"pid":102,"user":"litecontrol","cpu":"0.2","mem":"2.1","command":"lsphp"}'
    else
        while read -r pid user cpu mem comm; do
            [[ -n "${pid}" ]] || continue
            items+="${sep}$(printf '{"pid":%s,"user":"%s","cpu":"%s","mem":"%s","command":"%s"}' \
                "${pid}" "$(json_escape "${user}")" "${cpu}" "${mem}" "$(json_escape "${comm}")")"
            sep=","
        done < <(ps -eo pid=,user=,%cpu=,%mem=,comm= --sort=-%cpu | head -n 35)
    fi

    emit_success "Process list retrieved." "[${items}]"
}

cmd_server_kill_process() {
    local pid="${1:-}"
    if ! [[ "${pid}" =~ ^[0-9]+$ ]] || [[ "${pid}" -le 1 ]]; then
        emit_error "Invalid PID '${pid}'."
    fi
    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        kill -15 "${pid}" 2>/dev/null || kill -9 "${pid}" 2>/dev/null || emit_error "Failed to terminate PID ${pid}."
    fi
    emit_success "Process ${pid} terminated." "{\"pid\":${pid},\"terminated\":true}"
}

cmd_server_logs() {
    local log_type="${1:-ols-error}"
    local lines="${2:-100}"
    local domain="${3:-}"

    if ! [[ "${lines}" =~ ^[0-9]+$ ]] || [[ "${lines}" -gt 1000 ]]; then
        lines=100
    fi

    local log_file=""
    case "${log_type}" in
        ols-error)
            log_file="${OLS_DIR}/logs/error.log"
            ;;
        ols-access)
            log_file="${OLS_DIR}/logs/access.log"
            ;;
        panel-error)
            log_file="${LC_LOG_DIR}/panel-error.log"
            ;;
        domain)
            if [[ -z "${domain}" ]]; then
                emit_error "Domain name required for domain log lookup."
            fi
            local user=""
            if [[ -f "${LC_CONF_DIR}/vhosts/${domain}.conf" ]]; then
                user=$(awk -F= '/^USERNAME=/{print $2}' "${LC_CONF_DIR}/vhosts/${domain}.conf")
            fi
            log_file="${LC_HOME_ROOT}/${user}/${domain}/logs/error.log"
            if [[ ! -f "${log_file}" ]]; then
                log_file="${LC_HOME_ROOT}/${user}/logs/${domain}/error.log"
            fi
            ;;
        *)
            emit_error "Unknown log type '${log_type}'. Expected: ols-error, ols-access, panel-error, domain."
            ;;
    esac

    local content=""
    if [[ -f "${log_file}" ]]; then
        content=$(tail -n "${lines}" "${log_file}" 2>/dev/null || true)
    fi

    local data_json
    data_json=$(printf '{"log_type":"%s","file":"%s","lines":%s,"content":"%s"}' \
        "$(json_escape "${log_type}")" \
        "$(json_escape "${log_file}")" \
        "${lines}" \
        "$(json_escape "${content}")")
    emit_success "Log entries retrieved." "${data_json}"
}

cmd_server_webadmin_status() {
    local enable_file="${OLS_DIR}/admin/conf/enable_webadmin"
    local admin_port=7080
    local is_enabled=0
    local allowed_ips=""

    # Check if enable_webadmin file exists
    if [[ -f "${enable_file}" ]]; then
        is_enabled=1
        allowed_ips=$(grep -v '^[[:space:]]*#' "${enable_file}" 2>/dev/null | grep -v '^[[:space:]]*$' | tr '\n' ',' | sed 's/,$//' || true)
    fi

    # Read custom admin port from admin_config.conf if present
    local admin_conf="${OLS_DIR}/admin/conf/admin_config.conf"
    if [[ -f "${admin_conf}" ]]; then
        local detected_port
        detected_port=$(grep -E '^[[:space:]]*address[[:space:]]+' "${admin_conf}" | awk '{print $2}' | awk -F: '{print $NF}' | head -n1 || true)
        if [[ -n "${detected_port}" && "${detected_port}" =~ ^[0-9]+$ ]]; then
            admin_port="${detected_port}"
        fi
    fi

    local data_json
    data_json=$(printf '{"enabled":%s,"port":%d,"allowed_ips":"%s","enable_file":"%s"}' \
        "$([[ "${is_enabled}" -eq 1 ]] && echo "true" || echo "false")" \
        "${admin_port}" \
        "$(json_escape "${allowed_ips}")" \
        "$(json_escape "${enable_file}")")

    emit_success "WebAdmin status retrieved." "${data_json}"
}

cmd_server_webadmin_enable() {
    local raw_ips="${1:-}"
    local enable_file="${OLS_DIR}/admin/conf/enable_webadmin"
    local admin_port=7080

    mkdir -p "$(dirname "${enable_file}")"

    # Detect admin port from admin_config.conf if available
    local admin_conf="${OLS_DIR}/admin/conf/admin_config.conf"
    if [[ -f "${admin_conf}" ]]; then
        local detected_port
        detected_port=$(grep -E '^[[:space:]]*address[[:space:]]+' "${admin_conf}" | awk '{print $2}' | awk -F: '{print $NF}' | head -n1 || true)
        if [[ -n "${detected_port}" && "${detected_port}" =~ ^[0-9]+$ ]]; then
            admin_port="${detected_port}"
        fi
    fi

    if [[ -n "${raw_ips}" ]]; then
        # Format comma or space separated IPs into lines
        echo "${raw_ips}" | tr ',' '\n' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | grep -v '^$' > "${enable_file}"
    else
        # Empty file allows all IPs per OLS specification
        : > "${enable_file}"
    fi

    chmod 644 "${enable_file}" 2>/dev/null || true
    chown lsadm:lsadm "${enable_file}" 2>/dev/null || true

    # Synchronize firewall rules (UFW and CSF if installed)
    if [[ "${LC_TEST_MODE:-0}" == "1" ]]; then
        local mock_rules="${LC_CONF_DIR}/mock_ufw_rules.conf"
        mkdir -p "$(dirname "${mock_rules}")"
        echo "${admin_port}/tcp|ALLOW IN|${raw_ips:-Anywhere}" >> "${mock_rules}"
    else
        if command -v ufw >/dev/null 2>&1; then
            if [[ -n "${raw_ips}" ]]; then
                IFS=',' read -ra ADDR_ARRAY <<< "${raw_ips}"
                for ip in "${ADDR_ARRAY[@]}"; do
                    ip_trimmed=$(echo "${ip}" | tr -d '[:space:]')
                    if [[ -n "${ip_trimmed}" ]]; then
                        ufw allow from "${ip_trimmed}" to any port "${admin_port}" proto tcp comment "OLS WebAdmin" >/dev/null 2>&1 || true
                    fi
                done
            else
                ufw allow "${admin_port}/tcp" comment "OLS WebAdmin" >/dev/null 2>&1 || true
            fi
            ufw reload >/dev/null 2>&1 || true
        fi

        # Sync CSF if installed
        if [[ -f /etc/csf/csf.conf && -x /usr/sbin/csf ]]; then
            local tcp_in
            tcp_in=$(grep '^TCP_IN =' /etc/csf/csf.conf | tr -d '"' | awk -F= '{print $2}' | tr -d ' ')
            if [[ ",${tcp_in}," != *",${admin_port},"* ]]; then
                sed -i "s/^TCP_IN = .*/TCP_IN = \"${tcp_in},${admin_port}\"/" /etc/csf/csf.conf
                csf -r >/dev/null 2>&1 || true
            fi
        fi
    fi

    # Reload OLS safely
    ols_safe_reload || true

    local parsed_ips
    parsed_ips=$(grep -v '^[[:space:]]*#' "${enable_file}" 2>/dev/null | grep -v '^[[:space:]]*$' | tr '\n' ',' | sed 's/,$//' || true)

    local data_json
    data_json=$(printf '{"enabled":true,"port":%d,"allowed_ips":"%s"}' \
        "${admin_port}" \
        "$(json_escape "${parsed_ips}")")

    emit_success "OpenLiteSpeed WebAdmin console enabled on port ${admin_port}." "${data_json}"
}

cmd_server_webadmin_disable() {
    local enable_file="${OLS_DIR}/admin/conf/enable_webadmin"
    local admin_port=7080

    # Detect admin port
    local admin_conf="${OLS_DIR}/admin/conf/admin_config.conf"
    if [[ -f "${admin_conf}" ]]; then
        local detected_port
        detected_port=$(grep -E '^[[:space:]]*address[[:space:]]+' "${admin_conf}" | awk '{print $2}' | awk -F: '{print $NF}' | head -n1 || true)
        if [[ -n "${detected_port}" && "${detected_port}" =~ ^[0-9]+$ ]]; then
            admin_port="${detected_port}"
        fi
    fi

    rm -f "${enable_file}"

    # Remove firewall rules
    if [[ "${LC_TEST_MODE:-0}" == "1" ]]; then
        local mock_rules="${LC_CONF_DIR}/mock_ufw_rules.conf"
        if [[ -f "${mock_rules}" ]]; then
            sed -i "/^${admin_port}\/tcp|/d" "${mock_rules}" 2>/dev/null || true
        fi
    else
        if command -v ufw >/dev/null 2>&1; then
            ufw delete allow "${admin_port}/tcp" >/dev/null 2>&1 || true
            ufw reload >/dev/null 2>&1 || true
        fi

        if [[ -f /etc/csf/csf.conf && -x /usr/sbin/csf ]]; then
            sed -i "s/,${admin_port}//g; s/${admin_port},//g" /etc/csf/csf.conf
            csf -r >/dev/null 2>&1 || true
        fi
    fi

    # Reload OLS safely
    ols_safe_reload || true

    local data_json
    data_json=$(printf '{"enabled":false,"port":%d,"allowed_ips":""}' "${admin_port}")

    emit_success "OpenLiteSpeed WebAdmin console disabled." "${data_json}"
}

SUBCOMMAND="${1:-stats}"
shift || true

case "${SUBCOMMAND}" in
    stats)
        cmd_server_stats
        ;;
    services)
        cmd_server_services
        ;;
    service-action)
        cmd_server_service_action "$@"
        ;;
    timezone)
        cmd_server_timezone "$@"
        ;;
    hostname)
        cmd_server_hostname "$@"
        ;;
    processes)
        cmd_server_processes
        ;;
    kill-process)
        cmd_server_kill_process "$@"
        ;;
    logs)
        cmd_server_logs "$@"
        ;;
    webadmin-status)
        cmd_server_webadmin_status
        ;;
    webadmin-enable)
        cmd_server_webadmin_enable "$@"
        ;;
    webadmin-disable)
        cmd_server_webadmin_disable
        ;;
    *)
        emit_error "Usage: lc-cli server <stats|services|service-action|timezone|hostname|processes|kill-process|logs|webadmin-status|webadmin-enable|webadmin-disable> [args...]"
        ;;
esac

