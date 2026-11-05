#!/usr/bin/env bash
# ==============================================================================
# LiteControl CLI Command: `lc-cli backup`
# Subcommands:
#   create <username> [dest_dir]
#   restore <backup_file>
#   list [username]
#   schedule <list|add|remove> [args...]
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

CRON_BACKUP_FILE="${CRON_BACKUP_FILE:-/etc/cron.d/litecontrol-backups}"

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

validate_username() {
    local user="$1"
    if ! [[ "${user}" =~ ^[a-z][a-z0-9_]{2,15}$ ]]; then
        emit_error "Invalid username '${user}'."
    fi
}

format_bytes() {
    local b="$1"
    if [[ "${b}" -ge 1073741824 ]]; then
        printf "%.2f GB" "$(awk -v bytes="${b}" 'BEGIN { printf "%.2f", bytes / 1073741824 }')"
    elif [[ "${b}" -ge 1048576 ]]; then
        printf "%.2f MB" "$(awk -v bytes="${b}" 'BEGIN { printf "%.2f", bytes / 1048576 }')"
    elif [[ "${b}" -ge 1024 ]]; then
        printf "%.2f KB" "$(awk -v bytes="${b}" 'BEGIN { printf "%.2f", bytes / 1024 }')"
    else
        printf "%d B" "${b}"
    fi
}

# ------------------------------------------------------------------------------
# Subcommands
# ------------------------------------------------------------------------------

cmd_backup_create() {
    local username="${1:-}"
    local dest_dir="${2:-}"

    [[ -n "${username}" ]] || emit_error "Missing username for backup."
    validate_username "${username}"

    local user_home="${LC_HOME_ROOT}/${username}"
    if [[ ! -d "${user_home}" && "${LC_TEST_MODE:-0}" != "1" ]]; then
        emit_error "User home directory '${user_home}' not found."
    fi

    if [[ -z "${dest_dir}" ]]; then
        dest_dir="${user_home}/backups"
    fi
    mkdir -p "${dest_dir}"

    local ts
    ts="$(date +%Y%m%d_%H%M%S)"
    local archive_name="backup-${username}-${ts}.tar.gz"
    local archive_path="${dest_dir}/${archive_name}"

    local tmp_dir="/tmp/lc_backup_${username}_${ts}"
    mkdir -p "${tmp_dir}/data" "${tmp_dir}/databases" "${tmp_dir}/vhosts"

    # 1. Copy user files (excluding backups dir)
    if [[ -d "${user_home}" ]]; then
        if command -v rsync >/dev/null 2>&1; then
            rsync -a --exclude="backups" --exclude=".cache" "${user_home}/" "${tmp_dir}/data/" 2>/dev/null || true
        else
            cp -r "${user_home}/"* "${tmp_dir}/data/" 2>/dev/null || true
            rm -rf "${tmp_dir}/data/backups" 2>/dev/null || true
        fi
    fi

    # 2. Dump user databases
    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        local root_pass
        root_pass=$(get_secret "mysql_root_password" 2>/dev/null || echo "")
        local dbs=()
        if [[ -n "${root_pass}" ]]; then
            # Find databases starting with user_ or named user
            while IFS= read -r db; do
                [[ -n "${db}" ]] || continue
                dbs+=("${db}")
            done < <(mysql -u root -p"${root_pass}" -s -N -e "SHOW DATABASES LIKE '${username}%';" 2>/dev/null || true)
        fi

        for db in "${dbs[@]}"; do
            mysqldump -u root -p"${root_pass}" "${db}" > "${tmp_dir}/databases/${db}.sql" 2>/dev/null || true
        done
    else
        echo "MOCK SQL DUMP FOR ${username}" > "${tmp_dir}/databases/${username}_db.sql"
    fi

    # 3. Copy user vhost configs
    if [[ -d "${OLS_VHOSTS_DIR}" ]]; then
        for vh in "${OLS_VHOSTS_DIR}"/*; do
            [[ -d "${vh}" ]] || continue
            if grep -q "extUser[[:space:]]\+${username}" "${vh}/vhconf.conf" 2>/dev/null; then
                local dom
                dom="$(basename "${vh}")"
                mkdir -p "${tmp_dir}/vhosts/${dom}"
                cp -p "${vh}/vhconf.conf" "${tmp_dir}/vhosts/${dom}/" 2>/dev/null || true
            fi
        done
    fi

    # 4. Write metadata
    cat > "${tmp_dir}/meta.json" <<EOF
{
  "username": "${username}",
  "timestamp": "${ts}",
  "created_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "version": "1.0"
}
EOF

    # 5. Build tar.gz
    tar -czf "${archive_path}" -C "${tmp_dir}" .
    rm -rf "${tmp_dir}"

    local size_bytes
    if [[ -f "${archive_path}" ]]; then
        size_bytes=$(stat -c%s "${archive_path}" 2>/dev/null || stat -f%z "${archive_path}" 2>/dev/null || wc -c < "${archive_path}" | tr -d ' ')
    else
        size_bytes=0
    fi

    local data_json
    data_json=$(printf '{"username":"%s","filename":"%s","path":"%s","size_bytes":%s,"size_human":"%s","created_at":"%s"}' \
        "$(json_escape "${username}")" \
        "$(json_escape "${archive_name}")" \
        "$(json_escape "${archive_path}")" \
        "${size_bytes}" \
        "$(json_escape "$(format_bytes "${size_bytes}")")" \
        "$(json_escape "$(date -u +%Y-%m-%dT%H:%M:%SZ)")")

    emit_success "Backup created successfully: ${archive_name}." "${data_json}"
}

cmd_backup_restore() {
    local archive_path="${1:-}"
    [[ -n "${archive_path}" ]] || emit_error "Missing backup archive path to restore."

    if [[ ! -f "${archive_path}" ]]; then
        emit_error "Backup archive file '${archive_path}' does not exist."
    fi

    local tmp_dir="/tmp/lc_restore_$(date +%s)"
    mkdir -p "${tmp_dir}"

    if ! tar -xzf "${archive_path}" -C "${tmp_dir}" 2>/dev/null; then
        rm -rf "${tmp_dir}"
        emit_error "Failed to extract backup archive '${archive_path}'. File may be corrupt."
    fi

    local username=""
    if [[ -f "${tmp_dir}/meta.json" ]]; then
        username=$(grep -o '"username"[[:space:]]*:[[:space:]]*"[^"]*"' "${tmp_dir}/meta.json" | cut -d'"' -f4 || true)
    fi

    if [[ -z "${username}" ]]; then
        rm -rf "${tmp_dir}"
        emit_error "Unable to determine username from backup metadata."
    fi

    local user_home="${LC_HOME_ROOT}/${username}"
    mkdir -p "${user_home}"

    # 1. Restore files
    if [[ -d "${tmp_dir}/data" ]]; then
        cp -r "${tmp_dir}/data/"* "${user_home}/" 2>/dev/null || true
        if [[ "${LC_TEST_MODE:-0}" != "1" ]] && id -u "${username}" >/dev/null 2>&1; then
            chown -R "${username}:${username}" "${user_home}" 2>/dev/null || true
        fi
    fi

    # 2. Restore databases
    if [[ "${LC_TEST_MODE:-0}" != "1" && -d "${tmp_dir}/databases" ]]; then
        local root_pass
        root_pass=$(get_secret "mysql_root_password" 2>/dev/null || echo "")
        for sql_file in "${tmp_dir}/databases"/*.sql; do
            [[ -e "${sql_file}" ]] || continue
            local db_name
            db_name="$(basename "${sql_file}" .sql)"
            mysql -u root -p"${root_pass}" -e "CREATE DATABASE IF NOT EXISTS \`${db_name}\`;" 2>/dev/null || true
            mysql -u root -p"${root_pass}" "${db_name}" < "${sql_file}" 2>/dev/null || true
        done
    fi

    # 3. Restore vhost configs
    if [[ -d "${tmp_dir}/vhosts" ]]; then
        for vh in "${tmp_dir}/vhosts"/*; do
            [[ -d "${vh}" ]] || continue
            local dom
            dom="$(basename "${vh}")"
            mkdir -p "${OLS_VHOSTS_DIR}/${dom}"
            cp -p "${vh}/vhconf.conf" "${OLS_VHOSTS_DIR}/${dom}/" 2>/dev/null || true
        done
        ols_safe_reload || true
    fi

    rm -rf "${tmp_dir}"

    local data_json
    data_json=$(printf '{"restored":true,"username":"%s","file":"%s"}' \
        "$(json_escape "${username}")" \
        "$(json_escape "$(basename "${archive_path}")")")
    emit_success "Backup restored successfully for user '${username}'." "${data_json}"
}

cmd_backup_list() {
    local username="${1:-}"
    local search_dirs=()

    if [[ -n "${username}" ]]; then
        search_dirs=("${LC_HOME_ROOT}/${username}/backups")
    else
        search_dirs=("${LC_HOME_ROOT}"/*/backups "${LC_CONF_DIR}/backups")
    fi

    local items=()
    for sdir in "${search_dirs[@]}"; do
        [[ -d "${sdir}" ]] || continue
        for f in "${sdir}"/*.tar.gz; do
            [[ -e "$f" ]] || continue
            local fname sz_b sz_h ctime u
            fname="$(basename "$f")"
            sz_b=$(stat -c%s "$f" 2>/dev/null || stat -f%z "$f" 2>/dev/null || wc -c < "$f" | tr -d ' ')
            sz_h=$(format_bytes "${sz_b}")
            ctime=$(date -r "$f" -u +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date -u +%Y-%m-%dT%H:%M:%SZ)

            # Extract user from filename backup-<username>-<timestamp>.tar.gz
            if [[ "${fname}" =~ ^backup-([a-z0-9_]+)- ]]; then
                u="${BASH_REMATCH[1]}"
            else
                u="unknown"
            fi

            items+=("$(printf '{"username":"%s","filename":"%s","path":"%s","size_bytes":%s,"size_human":"%s","created_at":"%s"}' \
                "$(json_escape "${u}")" \
                "$(json_escape "${fname}")" \
                "$(json_escape "$f")" \
                "${sz_b}" \
                "$(json_escape "${sz_h}")" \
                "$(json_escape "${ctime}")")")
        done
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
        json_response "success" "Backup list retrieved." "${array_json}"
    else
        printf "%-15s %-35s %-12s %s\n" "USER" "FILENAME" "SIZE" "CREATED"
        echo "--------------------------------------------------------------------------------"
        for it in "${items[@]}"; do
            local u fn sz cr
            u=$(echo "${it}" | grep -o '"username":"[^"]*"' | cut -d'"' -f4)
            fn=$(echo "${it}" | grep -o '"filename":"[^"]*"' | cut -d'"' -f4)
            sz=$(echo "${it}" | grep -o '"size_human":"[^"]*"' | cut -d'"' -f4)
            cr=$(echo "${it}" | grep -o '"created_at":"[^"]*"' | cut -d'"' -f4)
            printf "%-15s %-35s %-12s %s\n" "${u}" "${fn}" "${sz}" "${cr}"
        done
    fi
}

cmd_backup_schedule() {
    local subaction="${1:-list}"
    shift || true

    case "${subaction}" in
        list)
            local schedules=()
            if [[ -f "${CRON_BACKUP_FILE}" ]]; then
                while IFS= read -r line; do
                    [[ -n "${line}" && ! "${line}" =~ ^# ]] || continue
                    schedules+=("$(printf '{"cron":"%s"}' "$(json_escape "${line}")")")
                done < "${CRON_BACKUP_FILE}"
            fi

            local joined=""
            local sep=""
            for s in "${schedules[@]}"; do
                joined+="${sep}${s}"
                sep=","
            done

            emit_success "Backup schedules retrieved." "[${joined}]"
            ;;
        add)
            local user="${1:-}"
            local freq="${2:-daily}"
            local ret="${3:-7}"
            [[ -n "${user}" ]] || emit_error "Missing username for backup schedule."
            validate_username "${user}"

            local cron_sched="0 2 * * *"
            case "${freq}" in
                daily)   cron_sched="0 2 * * *" ;;
                weekly)  cron_sched="0 2 * * 0" ;;
                monthly) cron_sched="0 2 1 * *" ;;
                *) emit_error "Unsupported frequency '${freq}'. Use daily, weekly, or monthly." ;;
            esac

            mkdir -p "$(dirname "${CRON_BACKUP_FILE}")"
            # Remove any existing job for this user
            if [[ -f "${CRON_BACKUP_FILE}" ]]; then
                sed -i "/backup create ${user}[[:space:]]/d" "${CRON_BACKUP_FILE}" 2>/dev/null || true
            fi

            echo "${cron_sched} root /usr/local/litecontrol/bin/lc-cli backup create ${user} # retention:${ret}" >> "${CRON_BACKUP_FILE}"
            chmod 644 "${CRON_BACKUP_FILE}"

            local data_json
            data_json=$(printf '{"username":"%s","frequency":"%s","cron":"%s","retention":%s}' \
                "$(json_escape "${user}")" \
                "$(json_escape "${freq}")" \
                "$(json_escape "${cron_sched}")" \
                "${ret}")
            emit_success "Backup schedule added for user '${user}'." "${data_json}"
            ;;
        remove)
            local user="${1:-}"
            [[ -n "${user}" ]] || emit_error "Missing username to remove backup schedule."
            if [[ -f "${CRON_BACKUP_FILE}" ]]; then
                sed -i "/backup create ${user}[[:space:]]/d" "${CRON_BACKUP_FILE}" 2>/dev/null || true
            fi
            emit_success "Backup schedule removed for user '${user}'." "{\"username\":\"${user}\"}"
            ;;
        *)
            emit_error "Usage: lc-cli backup schedule <list|add|remove> ..."
            ;;
    esac
}

# ------------------------------------------------------------------------------
# Dispatcher
# ------------------------------------------------------------------------------

ACTION="${1:-}"
shift || true

case "${ACTION}" in
    create)
        cmd_backup_create "$@"
        ;;
    restore)
        cmd_backup_restore "$@"
        ;;
    list)
        cmd_backup_list "$@"
        ;;
    schedule)
        cmd_backup_schedule "$@"
        ;;
    help|--help|-h|"")
        cat <<EOF
Usage: lc-cli backup <command> [arguments...]

Commands:
  create   <username> [dest_dir]            Create full account backup (.tar.gz)
  restore  <backup_file>                    Restore account files and databases
  list     [username]                       List available backups
  schedule <list|add|remove> [args...]      Manage automated backup cron jobs
EOF
        ;;
    *)
        emit_error "Unknown backup command '${ACTION}'."
        ;;
esac
