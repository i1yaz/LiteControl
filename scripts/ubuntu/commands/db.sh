#!/usr/bin/env bash
# ==============================================================================
# LiteControl CLI Command: `lc-cli db`
# Subcommands:
#   list
#   create-db <db_name>
#   delete-db <db_name>
#   create-user <db_user> <password>
#   grant <db_user> <db_name>
#   password <db_user> <new_password>
#   delete-user <db_user>
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

PROTECTED_DBS=(
    "mysql" "information_schema" "performance_schema" "sys"
    "litecontrol" "pdns" "roundcube"
)

PROTECTED_USERS=(
    "root" "litecontrol" "debian-sys-maint" "mysql.sys"
    "mysql.session" "mysql.infoschema" "pdns"
)

DB_META_DIR="${LC_CONF_DIR}/databases"

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

validate_db_name() {
    local db="$1"
    if ! [[ "${db}" =~ ^[a-zA-Z0-9_]{1,64}$ ]]; then
        emit_error "Invalid database name '${db}'. Must be 1-64 alphanumeric characters or underscores."
    fi
}

validate_db_user() {
    local user="$1"
    if ! [[ "${user}" =~ ^[a-zA-Z0-9_]{1,32}$ ]]; then
        emit_error "Invalid database username '${user}'. Must be 1-32 alphanumeric characters or underscores."
    fi
}

is_protected_db() {
    local db="$1"
    for p in "${PROTECTED_DBS[@]}"; do
        if [[ "${db,,}" == "${p,,}" ]]; then
            return 0
        fi
    done
    return 1
}

is_protected_user() {
    local user="$1"
    for p in "${PROTECTED_USERS[@]}"; do
        if [[ "${user,,}" == "${p,,}" ]]; then
            return 0
        fi
    done
    return 1
}

mysql_query() {
    local sql="$1"
    if [[ "${LC_TEST_MODE:-0}" == "1" ]]; then
        return 0
    fi

    local root_pass
    root_pass=$(get_secret "mysql_root_password" 2>/dev/null || echo "")

    if [[ -f /root/.my.cnf ]]; then
        mysql --defaults-file=/root/.my.cnf -s -N -e "${sql}"
    elif [[ -n "${root_pass}" ]]; then
        mysql -u root -p"${root_pass}" -s -N -e "${sql}"
    else
        mysql -u root -s -N -e "${sql}"
    fi
}

# Ensure metadata directory exists
mkdir -p "${DB_META_DIR}/dbs" "${DB_META_DIR}/users"

# ------------------------------------------------------------------------------
# Subcommand Handlers
# ------------------------------------------------------------------------------

cmd_db_list() {
    local items=()

    if [[ "${LC_TEST_MODE:-0}" == "1" ]]; then
        # Read from test metadata directory
        for f in "${DB_META_DIR}/dbs"/*.conf; do
            [[ -e "$f" ]] || continue
            local db_name tables size_bytes charset collation
            db_name="$(basename "$f" .conf)"
            # shellcheck disable=SC1090
            source "$f"
            items+=("$(printf '{"name":"%s","tables":%s,"size_bytes":%s,"charset":"%s","collation":"%s"}' \
                "$(json_escape "${db_name}")" \
                "${tables:-0}" \
                "${size_bytes:-0}" \
                "$(json_escape "${charset:-utf8mb4}")" \
                "$(json_escape "${collation:-utf8mb4_unicode_ci}")")")
        done
    else
        local query="SELECT s.SCHEMA_NAME, COALESCE(t.tbl_count, 0), COALESCE(t.total_bytes, 0), s.DEFAULT_CHARACTER_SET_NAME, s.DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA s LEFT JOIN (SELECT TABLE_SCHEMA, COUNT(*) as tbl_count, SUM(DATA_LENGTH + INDEX_LENGTH) as total_bytes FROM information_schema.TABLES GROUP BY TABLE_SCHEMA) t ON s.SCHEMA_NAME = t.TABLE_SCHEMA ORDER BY s.SCHEMA_NAME;"
        local raw
        raw=$(mysql_query "${query}" 2>/dev/null || true)
        while IFS=$'\t' read -r schema_name tbl_cnt total_b cset colln; do
            [[ -n "${schema_name}" ]] || continue
            items+=("$(printf '{"name":"%s","tables":%s,"size_bytes":%s,"charset":"%s","collation":"%s"}' \
                "$(json_escape "${schema_name}")" \
                "${tbl_cnt:-0}" \
                "${total_b:-0}" \
                "$(json_escape "${cset:-utf8mb4}")" \
                "$(json_escape "${colln:-utf8mb4_unicode_ci}")")")
        done <<< "${raw}"
    fi

    local array_json
    local joined=""
    local sep=""
    for it in "${items[@]}"; do
        joined+="${sep}${it}"
        sep=","
    done
    array_json="[${joined}]"

    if [[ "${LC_JSON_OUTPUT:-0}" == "1" ]]; then
        json_response "success" "Database list retrieved." "${array_json}"
    else
        printf "%-25s %-10s %-15s %-12s %s\n" "DATABASE" "TABLES" "SIZE" "CHARSET" "COLLATION"
        echo "--------------------------------------------------------------------------------"
        if [[ "${LC_TEST_MODE:-0}" == "1" ]]; then
            for f in "${DB_META_DIR}/dbs"/*.conf; do
                [[ -e "$f" ]] || continue
                local db_name tables size_bytes charset collation
                db_name="$(basename "$f" .conf)"
                # shellcheck disable=SC1090
                source "$f"
                printf "%-25s %-10s %-15s %-12s %s\n" "${db_name}" "${tables:-0}" "${size_bytes:-0} B" "${charset:-utf8mb4}" "${collation:-utf8mb4_unicode_ci}"
            done
        else
            echo "${raw}" | awk -F'\t' '{printf "%-25s %-10s %-15s %-12s %s\n", $1, $2, $3" B", $4, $5}'
        fi
    fi
}

cmd_db_create_db() {
    local db_name="${1:-}"
    [[ -n "${db_name}" ]] || emit_error "Missing database name."
    validate_db_name "${db_name}"

    if is_protected_db "${db_name}"; then
        emit_error "Database name '${db_name}' is protected and reserved by LiteControl or system."
    fi

    if [[ -f "${DB_META_DIR}/dbs/${db_name}.conf" ]]; then
        emit_error "Database '${db_name}' already exists."
    fi

    local sql="CREATE DATABASE \`${db_name}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql_query "${sql}"

    cat > "${DB_META_DIR}/dbs/${db_name}.conf" <<EOF
db_name="${db_name}"
tables="0"
size_bytes="0"
charset="utf8mb4"
collation="utf8mb4_unicode_ci"
created_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
EOF

    local data_json
    data_json=$(printf '{"database":"%s","charset":"utf8mb4","collation":"utf8mb4_unicode_ci"}' "$(json_escape "${db_name}")")
    emit_success "Database '${db_name}' created successfully." "${data_json}"
}

cmd_db_delete_db() {
    local db_name="${1:-}"
    [[ -n "${db_name}" ]] || emit_error "Missing database name."
    validate_db_name "${db_name}"

    if is_protected_db "${db_name}"; then
        emit_error "Cannot delete protected system database '${db_name}'."
    fi

    local sql="DROP DATABASE IF EXISTS \`${db_name}\`;"
    mysql_query "${sql}"
    rm -f "${DB_META_DIR}/dbs/${db_name}.conf"

    local data_json
    data_json=$(printf '{"database":"%s"}' "$(json_escape "${db_name}")")
    emit_success "Database '${db_name}' deleted successfully." "${data_json}"
}

cmd_db_create_user() {
    local db_user="${1:-}"
    local password="${2:-}"
    [[ -n "${db_user}" ]] || emit_error "Missing database username."
    [[ -n "${password}" ]] || emit_error "Missing database user password."
    validate_db_user "${db_user}"

    if is_protected_user "${db_user}"; then
        emit_error "Database user '${db_user}' is protected and reserved."
    fi

    if [[ -f "${DB_META_DIR}/users/${db_user}.conf" ]]; then
        emit_error "Database user '${db_user}' already exists."
    fi

    # Escape single quotes in password for SQL statement
    local escaped_pw="${password//\'/\'\'}"
    local sql="CREATE USER IF NOT EXISTS '${db_user}'@'localhost' IDENTIFIED BY '${escaped_pw}'; FLUSH PRIVILEGES;"
    mysql_query "${sql}"

    cat > "${DB_META_DIR}/users/${db_user}.conf" <<EOF
db_user="${db_user}"
host="localhost"
created_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
EOF

    local data_json
    data_json=$(printf '{"user":"%s","host":"localhost"}' "$(json_escape "${db_user}")")
    emit_success "Database user '${db_user}' created successfully." "${data_json}"
}

cmd_db_grant() {
    local db_user="${1:-}"
    local db_name="${2:-}"
    [[ -n "${db_user}" ]] || emit_error "Missing database username."
    [[ -n "${db_name}" ]] || emit_error "Missing database name."
    validate_db_user "${db_user}"
    validate_db_name "${db_name}"

    if is_protected_db "${db_name}"; then
        emit_error "Cannot grant access to protected database '${db_name}'."
    fi

    local sql="GRANT ALL PRIVILEGES ON \`${db_name}\`.* TO '${db_user}'@'localhost'; FLUSH PRIVILEGES;"
    mysql_query "${sql}"

    if [[ -f "${DB_META_DIR}/users/${db_user}.conf" ]]; then
        echo "grant_${db_name}=1" >> "${DB_META_DIR}/users/${db_user}.conf"
    fi

    local data_json
    data_json=$(printf '{"user":"%s","database":"%s"}' "$(json_escape "${db_user}")" "$(json_escape "${db_name}")")
    emit_success "Privileges on '${db_name}' granted to '${db_user}'." "${data_json}"
}

cmd_db_password() {
    local db_user="${1:-}"
    local new_password="${2:-}"
    [[ -n "${db_user}" ]] || emit_error "Missing database username."
    [[ -n "${new_password}" ]] || emit_error "Missing new password."
    validate_db_user "${db_user}"

    if is_protected_user "${db_user}"; then
        emit_error "Cannot alter password for protected user '${db_user}' via lc-cli db password."
    fi

    local escaped_pw="${new_password//\'/\'\'}"
    local sql="ALTER USER '${db_user}'@'localhost' IDENTIFIED BY '${escaped_pw}'; FLUSH PRIVILEGES;"
    mysql_query "${sql}"

    local data_json
    data_json=$(printf '{"user":"%s"}' "$(json_escape "${db_user}")")
    emit_success "Password for database user '${db_user}' updated." "${data_json}"
}

cmd_db_delete_user() {
    local db_user="${1:-}"
    [[ -n "${db_user}" ]] || emit_error "Missing database username."
    validate_db_user "${db_user}"

    if is_protected_user "${db_user}"; then
        emit_error "Cannot delete protected system user '${db_user}'."
    fi

    local sql="DROP USER IF EXISTS '${db_user}'@'localhost'; FLUSH PRIVILEGES;"
    mysql_query "${sql}"
    rm -f "${DB_META_DIR}/users/${db_user}.conf"

    local data_json
    data_json=$(printf '{"user":"%s"}' "$(json_escape "${db_user}")")
    emit_success "Database user '${db_user}' deleted." "${data_json}"
}

# ------------------------------------------------------------------------------
# Dispatcher
# ------------------------------------------------------------------------------

ACTION="${1:-}"
shift || true

case "${ACTION}" in
    list)
        cmd_db_list "$@"
        ;;
    create-db)
        cmd_db_create_db "$@"
        ;;
    delete-db)
        cmd_db_delete_db "$@"
        ;;
    create-user)
        cmd_db_create_user "$@"
        ;;
    grant)
        cmd_db_grant "$@"
        ;;
    password)
        cmd_db_password "$@"
        ;;
    delete-user)
        cmd_db_delete_user "$@"
        ;;
    help|--help|-h|"")
        cat <<EOF
Usage: lc-cli db <command> [arguments...]

Commands:
  list                                  List all MySQL databases with size and table count
  create-db   <db_name>                 Create a new database (utf8mb4)
  delete-db   <db_name>                 Delete an existing database
  create-user <db_user> <password>      Create a new MySQL user
  grant       <db_user> <db_name>       Grant all privileges on db to user
  password    <db_user> <new_password>  Change user password
  delete-user <db_user>                 Delete a MySQL user
EOF
        ;;
    *)
        emit_error "Unknown db command '${ACTION}'."
        ;;
esac

