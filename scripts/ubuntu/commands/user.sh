#!/usr/bin/env bash
# ==============================================================================
# LiteControl CLI Command: `lc-cli user`
# Subcommands:
#   add <username> <password> [quota_mb]
#   suspend <username>
#   unsuspend <username>
#   delete <username> [--remove-home]
#   quota <username> [new_quota_mb]
#   list
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

USERS_META_DIR="${LC_CONF_DIR}/users"

RESERVED_USERS=(
    "root" "admin" "litecontrol" "www-data" "nobody" "nogroup"
    "vmail" "mysql" "mariadb" "redis" "pdns" "postfix" "dovecot"
    "lsadm" "ubuntu" "debian" "daemon" "bin" "sys" "sync" "sshd"
)

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
        emit_error "Invalid username '${user}'. Must be 3-16 chars, lowercase alphanumeric or underscore, starting with a letter."
    fi
    for r in "${RESERVED_USERS[@]}"; do
        if [[ "${user}" == "${r}" ]]; then
            emit_error "Username '${user}' is reserved by the system."
        fi
    done
}

user_exists() {
    local user="$1"
    if [[ -f "${USERS_META_DIR}/${user}.conf" ]]; then
        return 0
    fi
    if [[ "${LC_TEST_MODE:-0}" != "1" ]] && id -u "${user}" >/dev/null 2>&1; then
        return 0
    fi
    return 1
}

get_dir_bytes() {
    local dir="$1"
    if [[ ! -d "${dir}" ]]; then
        printf "0"
        return 0
    fi
    if du -sb "${dir}" >/dev/null 2>&1; then
        du -sb "${dir}" 2>/dev/null | awk '{print $1}'
    else
        local kb
        kb=$(du -sk "${dir}" 2>/dev/null | awk '{print $1}')
        printf "%s" "$(( ${kb:-0} * 1024 ))"
    fi
}

apply_fs_quota() {
    local user="$1"
    local quota_mb="$2"
    if [[ "${LC_TEST_MODE:-0}" == "1" ]]; then
        return 0
    fi
    if command -v setquota >/dev/null 2>&1; then
        local soft_blocks=$(( quota_mb * 1024 ))
        local hard_blocks=$(( quota_mb * 1024 ))
        setquota -u "${user}" "${soft_blocks}" "${hard_blocks}" 0 0 / 2>/dev/null || true
    fi
}

cmd_user_add() {
    local user="${1:-}"
    local password="${2:-}"
    local quota_mb="${3:-0}"

    validate_username "${user}"

    if [[ "${#password}" -lt 8 ]]; then
        emit_error "Password must be at least 8 characters long."
    fi
    if ! [[ "${quota_mb}" =~ ^[0-9]+$ ]]; then
        emit_error "Quota MB must be a non-negative integer."
    fi
    if user_exists "${user}"; then
        emit_error "User '${user}' already exists."
    fi

    local home_dir="${LC_HOME_ROOT}/${user}"
    mkdir -p "${USERS_META_DIR}" "${LC_HOME_ROOT}"

    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        useradd -m -d "${home_dir}" -s /usr/sbin/nologin "${user}"
        printf "%s:%s\n" "${user}" "${password}" | chpasswd
    fi

    mkdir -p "${home_dir}/logs" "${home_dir}/backups" "${home_dir}/tmp"
    chmod 711 "${home_dir}"
    chmod 750 "${home_dir}/logs" "${home_dir}/backups" "${home_dir}/tmp"

    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        chown -R "${user}:${user}" "${home_dir}"
        if command -v setfacl >/dev/null 2>&1; then
            setfacl -m u:nobody:x "${home_dir}" 2>/dev/null || true
        fi
    fi

    apply_fs_quota "${user}" "${quota_mb}"

    cat > "${USERS_META_DIR}/${user}.conf" <<EOF
USERNAME=${user}
HOME_DIR=${home_dir}
QUOTA_MB=${quota_mb}
STATUS=active
CREATED_AT=$(date -Iseconds)
EOF

    local data_json
    data_json=$(printf '{"username":"%s","home_dir":"%s","quota_mb":%s,"status":"active"}' \
        "$(json_escape "${user}")" \
        "$(json_escape "${home_dir}")" \
        "${quota_mb}")

    emit_success "Hosting user '${user}' created successfully." "${data_json}"
}

cmd_user_suspend() {
    local user="${1:-}"
    local target_state="${2:-suspended}" # suspended | active

    validate_username "${user}"
    if ! user_exists "${user}"; then
        emit_error "User '${user}' does not exist."
    fi

    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        if [[ "${target_state}" == "suspended" ]]; then
            usermod -L "${user}" 2>/dev/null || true
        else
            usermod -U "${user}" 2>/dev/null || true
        fi
    fi

    if [[ -f "${USERS_META_DIR}/${user}.conf" ]]; then
        sed -i "s/^STATUS=.*/STATUS=${target_state}/" "${USERS_META_DIR}/${user}.conf"
    fi

    local data_json
    data_json=$(printf '{"username":"%s","status":"%s"}' "$(json_escape "${user}")" "${target_state}")
    emit_success "User '${user}' status updated to '${target_state}'." "${data_json}"
}

cmd_user_delete() {
    local user="${1:-}"
    local flag="${2:-}"

    validate_username "${user}"
    if ! user_exists "${user}"; then
        emit_error "User '${user}' does not exist."
    fi

    local home_dir="${LC_HOME_ROOT}/${user}"

    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        pkill -9 -u "${user}" 2>/dev/null || true
        if [[ "${flag}" == "--remove-home" ]]; then
            userdel -r "${user}" 2>/dev/null || rm -rf "${home_dir}"
        else
            userdel "${user}" 2>/dev/null || true
        fi
    else
        if [[ "${flag}" == "--remove-home" ]]; then
            rm -rf "${home_dir}"
        fi
    fi

    rm -f "${USERS_META_DIR}/${user}.conf"

    local data_json
    data_json=$(printf '{"username":"%s","deleted":true}' "$(json_escape "${user}")")
    emit_success "User '${user}' deleted." "${data_json}"
}

cmd_user_quota() {
    local user="${1:-}"
    local new_quota_mb="${2:-}"

    validate_username "${user}"
    if ! user_exists "${user}"; then
        emit_error "User '${user}' does not exist."
    fi

    local meta_file="${USERS_META_DIR}/${user}.conf"
    local home_dir="${LC_HOME_ROOT}/${user}"
    local quota_mb=0
    local status="active"

    if [[ -f "${meta_file}" ]]; then
        quota_mb=$(awk -F= '/^QUOTA_MB=/{print $2}' "${meta_file}")
        status=$(awk -F= '/^STATUS=/{print $2}' "${meta_file}")
    fi

    if [[ -n "${new_quota_mb}" ]]; then
        if ! [[ "${new_quota_mb}" =~ ^[0-9]+$ ]]; then
            emit_error "Quota MB must be a non-negative integer."
        fi
        quota_mb="${new_quota_mb}"
        apply_fs_quota "${user}" "${quota_mb}"
        if [[ -f "${meta_file}" ]]; then
            sed -i "s/^QUOTA_MB=.*/QUOTA_MB=${quota_mb}/" "${meta_file}"
        fi
    fi

    local used_bytes
    used_bytes=$(get_dir_bytes "${home_dir}")

    local data_json
    data_json=$(printf '{"username":"%s","home_dir":"%s","used_bytes":%s,"quota_mb":%s,"status":"%s"}' \
        "$(json_escape "${user}")" \
        "$(json_escape "${home_dir}")" \
        "${used_bytes}" \
        "${quota_mb:-0}" \
        "$(json_escape "${status:-active}")")

    emit_success "Quota information for '${user}'." "${data_json}"
}

cmd_user_list() {
    mkdir -p "${USERS_META_DIR}"
    local items=""
    local sep=""

    for conf in "${USERS_META_DIR}"/*.conf; do
        [[ -e "${conf}" ]] || continue
        local user home_dir quota_mb status used_bytes
        user=$(awk -F= '/^USERNAME=/{print $2}' "${conf}")
        home_dir=$(awk -F= '/^HOME_DIR=/{print $2}' "${conf}")
        quota_mb=$(awk -F= '/^QUOTA_MB=/{print $2}' "${conf}")
        status=$(awk -F= '/^STATUS=/{print $2}' "${conf}")
        used_bytes=$(get_dir_bytes "${home_dir}")

        items+="${sep}$(printf '{"username":"%s","home_dir":"%s","used_bytes":%s,"quota_mb":%s,"status":"%s"}' \
            "$(json_escape "${user}")" \
            "$(json_escape "${home_dir}")" \
            "${used_bytes}" \
            "${quota_mb:-0}" \
            "$(json_escape "${status:-active}")")"
        sep=","
    done

    local data_json="[${items}]"
    if [[ "${LC_JSON_OUTPUT:-0}" == "1" ]]; then
        json_response "success" "User list retrieved." "${data_json}"
    else
        echo "${data_json}"
    fi
}

cmd_user_ssh_key() {
    local user="${1:-}"
    local key_data="${2:-}"

    validate_username "${user}"
    if ! user_exists "${user}"; then
        emit_error "User '${user}' does not exist."
    fi

    local home_dir="${LC_HOME_ROOT}/${user}"
    local ssh_dir="${home_dir}/.ssh"
    local auth_file="${ssh_dir}/authorized_keys"

    mkdir -p "${ssh_dir}"

    if [[ -z "${key_data}" || "${key_data}" == "-" || "${key_data}" == "clear" ]]; then
        rm -f "${auth_file}"
    else
        # Normalize and write key
        printf "%s\n" "${key_data}" > "${auth_file}"
        chmod 600 "${auth_file}"
    fi

    chmod 700 "${ssh_dir}"

    if [[ "${LC_TEST_MODE:-0}" != "1" ]]; then
        chown -R "${user}:${user}" "${ssh_dir}" 2>/dev/null || true
    fi

    local data_json
    data_json=$(printf '{"username":"%s","has_key":%s}' \
        "$(json_escape "${user}")" \
        "$([[ -f "${auth_file}" && -s "${auth_file}" ]] && echo "true" || echo "false")")

    emit_success "SSH key configuration updated for '${user}'." "${data_json}"
}

SUBCOMMAND="${1:-}"
shift || true

case "${SUBCOMMAND}" in
    add)
        cmd_user_add "$@"
        ;;
    suspend)
        cmd_user_suspend "${1:-}" "suspended"
        ;;
    unsuspend)
        cmd_user_suspend "${1:-}" "active"
        ;;
    delete)
        cmd_user_delete "$@"
        ;;
    quota)
        cmd_user_quota "$@"
        ;;
    list)
        cmd_user_list
        ;;
    ssh-key)
        cmd_user_ssh_key "$@"
        ;;
    *)
        emit_error "Usage: lc-cli user <add|suspend|unsuspend|delete|quota|ssh-key|list> [args...]"
        ;;
esac

