#!/usr/bin/env bash
# ==============================================================================
# LiteControl - Phase 1 Unit Tests
# Tests:
#   1. LF line-ending enforcement across all scripts, bin, and templates
#   2. Bash syntax validation (`bash -n`) across all scripts
#   3. Shared library (`common.sh`) unit functions (secrets, modules, JSON, OLS backup/rollback)
#   4. Template placeholder & rendering integrity
#   5. `lc-cli` dispatcher unit tests (version, help, --json mode, error handling)
# ==============================================================================

set -euo pipefail

TESTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${TESTS_DIR}/../.." && pwd)"

ASSERTIONS=0
FAILURES=0

assert_eq() {
    local expected="$1"
    local actual="$2"
    local msg="$3"
    ASSERTIONS=$((ASSERTIONS + 1))
    if [[ "${expected}" != "${actual}" ]]; then
        echo "  [FAIL] ${msg}: expected '${expected}', got '${actual}'"
        FAILURES=$((FAILURES + 1))
    else
        echo "  [OK]   ${msg}"
    fi
}

assert_contains() {
    local haystack="$1"
    local needle="$2"
    local msg="$3"
    ASSERTIONS=$((ASSERTIONS + 1))
    if [[ "${haystack}" != *"${needle}"* ]]; then
        echo "  [FAIL] ${msg}: output did not contain '${needle}'. Actual: ${haystack}"
        FAILURES=$((FAILURES + 1))
    else
        echo "  [OK]   ${msg}"
    fi
}

assert_file_exists() {
    local filepath="$1"
    local msg="$2"
    ASSERTIONS=$((ASSERTIONS + 1))
    if [[ ! -f "${filepath}" ]]; then
        echo "  [FAIL] ${msg}: file '${filepath}' does not exist"
        FAILURES=$((FAILURES + 1))
    else
        echo "  [OK]   ${msg}"
    fi
}

# Create isolated sandbox directory for unit testing filesystem operations
SANDBOX_DIR="$(mktemp -d 2>/dev/null || mktemp -d -t 'lc_test')"
trap 'rm -rf "${SANDBOX_DIR}"' EXIT

export LC_BASE_DIR="${SANDBOX_DIR}/usr/local/litecontrol"
export LC_PANEL_DIR="${LC_BASE_DIR}/panel"
export LC_PMA_DIR="${LC_BASE_DIR}/phpmyadmin"
export LC_CONF_DIR="${SANDBOX_DIR}/etc/litecontrol"
export LC_SECRETS_DIR="${LC_CONF_DIR}/secrets"
export LC_SSL_DIR="${LC_CONF_DIR}/ssl"
export LC_MODULES_DIR="${LC_CONF_DIR}/modules"
export LC_LOG_DIR="${SANDBOX_DIR}/var/log/litecontrol"
export LC_INSTALL_LOG="${LC_LOG_DIR}/install.log"
export OLS_DIR="${SANDBOX_DIR}/usr/local/lsws"
export OLS_CONF="${OLS_DIR}/conf/httpd_config.conf"
export OLS_VHOSTS_DIR="${OLS_DIR}/conf/vhosts"
export OLS_CTRL="${OLS_DIR}/bin/lswsctrl"
export OLS_BIN="${OLS_DIR}/bin/lshttpd"

# Stub chown/chmod for unprivileged Windows/Git-Bash test environment
chown() { return 0; }
export -f chown

# Source common.sh after setting sandbox env vars
# shellcheck source=../../scripts/ubuntu/lib/common.sh
source "${REPO_ROOT}/scripts/ubuntu/lib/common.sh"

# ------------------------------------------------------------------------------
# 1. Line Ending (LF) & Syntax Validation Tests
# ------------------------------------------------------------------------------
echo "1. Testing LF line endings & bash -n syntax..."
while IFS= read -r -d '' file; do
    ASSERTIONS=$((ASSERTIONS + 1))
    if grep -q $'\r' "${file}"; then
        echo "  [FAIL] CRLF detected in ${file}"
        FAILURES=$((FAILURES + 1))
    fi
    if [[ "${file}" == *.sh || "${file}" == */lc-cli ]]; then
        ASSERTIONS=$((ASSERTIONS + 1))
        if ! bash -n "${file}"; then
            echo "  [FAIL] Syntax error in ${file}"
            FAILURES=$((FAILURES + 1))
        fi
    fi
done < <(find "${REPO_ROOT}/install.sh" "${REPO_ROOT}/bin" "${REPO_ROOT}/scripts" "${REPO_ROOT}/templates" -type f -print0)
echo "  [OK]   All scripts & templates verified for LF line endings and valid Bash syntax."

# ------------------------------------------------------------------------------
# 2. Shared Library (`common.sh`) Unit Tests
# ------------------------------------------------------------------------------
echo "2. Testing common.sh secret, module, and JSON helpers..."
init_litecontrol_dirs

if [[ -d "${LC_SECRETS_DIR}" && -d "${LC_MODULES_DIR}" && -d "${LC_LOG_DIR}" ]]; then
    ASSERTIONS=$((ASSERTIONS + 1))
    echo "  [OK]   Sandbox directories initialized"
else
    ASSERTIONS=$((ASSERTIONS + 1))
    FAILURES=$((FAILURES + 1))
    echo "  [FAIL] Sandbox directories missing"
fi

# Test secret save/get
save_secret "test_db_pass" "SuperSecret123"
got_secret=$(get_secret "test_db_pass")
assert_eq "SuperSecret123" "${got_secret}" "save_secret and get_secret roundtrip"

# Test get_or_create_secret preserves existing secret
existing_secret=$(get_or_create_secret "test_db_pass" 20)
assert_eq "SuperSecret123" "${existing_secret}" "get_or_create_secret preserves existing secret"

# Test get_or_create_secret generates new secret of requested length
new_secret=$(get_or_create_secret "auto_gen_secret" 24)
assert_eq "24" "${#new_secret}" "get_or_create_secret generates 24-char password"

# Test module state tracking
mark_module_installed "redis"
if is_module_installed "redis"; then
    assert_eq "true" "true" "mark_module_installed marks redis as installed"
else
    assert_eq "true" "false" "mark_module_installed marks redis as installed"
fi

mark_module_removed "redis"
if is_module_installed "redis"; then
    assert_eq "false" "true" "mark_module_removed removes redis installed marker"
else
    assert_eq "false" "false" "mark_module_removed removes redis installed marker"
fi

# Test JSON response formatting
json_out=$(json_response "success" "Hello \"World\"" '{"port":8090}')
assert_eq '{"status":"success","message":"Hello \"World\"","data":{"port":8090}}' "${json_out}" "json_response escapes quotes and formats JSON"

# ------------------------------------------------------------------------------
# 3. OpenLiteSpeed Atomic Backup & Auto-Rollback Tests
# ------------------------------------------------------------------------------
echo "3. Testing OpenLiteSpeed atomic config test & auto-rollback..."
mkdir -p "${OLS_DIR}/conf" "${OLS_DIR}/bin"
echo "serverName valid-config" > "${OLS_CONF}"
ols_backup_config
assert_file_exists "${OLS_CONF}.last_good" "ols_backup_config creates .last_good snapshot"

# Create mock lshttpd & lswsctrl that pass when config contains 'valid-config' and fail on 'broken-config'
cat > "${OLS_BIN}" <<'EOF'
#!/usr/bin/env bash
if grep -q "broken-config" "${OLS_CONF}"; then
    echo "[ERROR] Syntax error in httpd_config.conf" >&2
    exit 1
fi
exit 0
EOF
cat > "${OLS_CTRL}" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF
chmod +x "${OLS_BIN}" "${OLS_CTRL}"

# Test valid reload
echo "serverName valid-config-updated" > "${OLS_CONF}"
if ols_safe_reload; then
    assert_eq "serverName valid-config-updated" "$(cat "${OLS_CONF}")" "ols_safe_reload keeps valid updated config"
else
    assert_eq "0" "1" "ols_safe_reload should succeed on valid config"
fi

# Test broken config triggers auto-rollback to .last_good!
echo "serverName broken-config" > "${OLS_CONF}"
if ols_safe_reload 2>/dev/null; then
    assert_eq "1" "0" "ols_safe_reload should return non-zero on broken config"
else
    assert_eq "serverName valid-config-updated" "$(cat "${OLS_CONF}")" "ols_safe_reload automatically rolls back broken config to .last_good"
fi

# ------------------------------------------------------------------------------
# 4. Template Placeholder Integrity Tests
# ------------------------------------------------------------------------------
echo "4. Testing OLS & system configuration templates..."
httpd_tpl=$(cat "${REPO_ROOT}/templates/ols/httpd_config.conf.tpl")
assert_contains "${httpd_tpl}" "extprocessor litecontrol_php" "httpd_config.conf.tpl defines isolated litecontrol_php pool"
assert_contains "${httpd_tpl}" "listener panel" "httpd_config.conf.tpl defines dedicated panel listener"
assert_contains "${httpd_tpl}" "# BEGIN LITECONTROL VHOSTS" "httpd_config.conf.tpl contains VHOSTS marker"

panel_tpl=$(cat "${REPO_ROOT}/templates/ols/panel_vhconf.conf.tpl")
assert_contains "${panel_tpl}" "context /phpmyadmin/" "panel_vhconf.conf.tpl serves /phpmyadmin/ context"
assert_contains "${panel_tpl}" "lsapi:litecontrol_php" "panel_vhconf.conf.tpl binds to litecontrol_php"

# ------------------------------------------------------------------------------
# 5. `lc-cli` Dispatcher Unit Tests
# ------------------------------------------------------------------------------
echo "5. Testing bin/lc-cli dispatcher..."
ver_out=$(bash "${REPO_ROOT}/bin/lc-cli" version)
assert_contains "${ver_out}" "LiteControl CLI v1.0.0" "lc-cli version human output"

ver_json=$(bash "${REPO_ROOT}/bin/lc-cli" --json version)
assert_eq '{"status":"success","message":"LiteControl v1.0.0","data":{"version":"1.0.0"}}' "${ver_json}" "lc-cli --json version output"

help_out=$(bash "${REPO_ROOT}/bin/lc-cli" help)
assert_contains "${help_out}" "Management Subcommands:" "lc-cli help output"

if unknown_json=$(bash "${REPO_ROOT}/bin/lc-cli" --json invalid_cmd 2>/dev/null); then
    assert_eq "1" "0" "lc-cli should exit non-zero on unknown command"
else
    assert_contains "${unknown_json}" '"status":"error"' "lc-cli --json returns error JSON on unknown command"
fi

echo ""
echo "Phase 1 Unit Tests Completed: ${ASSERTIONS} assertions, ${FAILURES} failures."
[[ "${FAILURES}" -eq 0 ]]
