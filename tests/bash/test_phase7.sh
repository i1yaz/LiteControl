#!/usr/bin/env bash
# ==============================================================================
# LiteControl - Phase 7 Unit Tests
# Tests:
#   1. Custom LiteControl WebAdmin disabled notice template & replacement
#   2. `lc-cli server webadmin-status` (detects disabled vs enabled, parses allowed IPs and port)
#   3. `lc-cli server webadmin-enable` (creates enable_webadmin, populates allowed IPs, reloads OLS, syncs UFW/CSF)
#   4. `lc-cli server webadmin-disable` (removes enable_webadmin, removes UFW/CSF rules, reloads OLS)
#   5. `lc-cli --json server webadmin-*` contract tests
# ==============================================================================

set -euo pipefail

TESTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${TESTS_DIR}/../.." && pwd)"
CLI="${REPO_ROOT}/bin/lc-cli"

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

assert_not_contains() {
    local haystack="$1"
    local needle="$2"
    local msg="$3"
    ASSERTIONS=$((ASSERTIONS + 1))
    if [[ "${haystack}" == *"${needle}"* ]]; then
        echo "  [FAIL] ${msg}: output unexpectedly contained '${needle}'. Actual: ${haystack}"
        FAILURES=$((FAILURES + 1))
    else
        echo "  [OK]   ${msg}"
    fi
}

assert_file_exists() {
    local file="$1"
    local msg="$2"
    ASSERTIONS=$((ASSERTIONS + 1))
    if [[ ! -f "${file}" ]]; then
        echo "  [FAIL] ${msg}: file does not exist: ${file}"
        FAILURES=$((FAILURES + 1))
    else
        echo "  [OK]   ${msg}"
    fi
}

assert_file_not_exists() {
    local file="$1"
    local msg="$2"
    ASSERTIONS=$((ASSERTIONS + 1))
    if [[ -f "${file}" ]]; then
        echo "  [FAIL] ${msg}: file should NOT exist: ${file}"
        FAILURES=$((FAILURES + 1))
    else
        echo "  [OK]   ${msg}"
    fi
}

# Setup Isolated Sandbox
SANDBOX_DIR="$(mktemp -d -t lc_phase7_test_XXXXXX 2>/dev/null || mktemp -d)"
export LC_BASE_DIR="${SANDBOX_DIR}/usr/local/litecontrol"
export LC_CONF_DIR="${SANDBOX_DIR}/etc/litecontrol"
export LC_SECRETS_DIR="${LC_CONF_DIR}/secrets"
export LC_LOG_DIR="${SANDBOX_DIR}/var/log/litecontrol"
export LC_INSTALL_LOG="${LC_LOG_DIR}/install.log"
export LC_MODULES_DIR="${LC_CONF_DIR}/modules"
export OLS_DIR="${SANDBOX_DIR}/usr/local/lsws"
export OLS_CONF="${OLS_DIR}/conf/httpd_config.conf"
export OLS_CTRL="${SANDBOX_DIR}/mock_lswsctrl.sh"
export OLS_BIN="${SANDBOX_DIR}/mock_lshttpd.sh"
export LC_TEST_MODE=1

mkdir -p "${LC_BASE_DIR}/bin" "${LC_CONF_DIR}" "${LC_SECRETS_DIR}" "${LC_LOG_DIR}" "${LC_MODULES_DIR}"
mkdir -p "${OLS_DIR}/conf" "${OLS_DIR}/admin/conf" "${OLS_DIR}/admin/html.open"

# Create mock lswsctrl and lshttpd
cat > "${OLS_CTRL}" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF
chmod +x "${OLS_CTRL}"

cat > "${OLS_BIN}" <<'EOF'
#!/usr/bin/env bash
if [[ "${1:-}" == "-t" ]]; then
    exit 0
fi
exit 0
EOF
chmod +x "${OLS_BIN}"

# Create mock admin_config.conf with listener on 7080
cat > "${OLS_DIR}/admin/conf/admin_config.conf" <<'EOF'
listener adminListener {
  address                 *:7080
  secure                  1
  keyFile                 $SERVER_ROOT/admin/conf/webadmin.key
  certFile                $SERVER_ROOT/admin/conf/webadmin.crt
}
EOF

# Copy templates into sandbox
mkdir -p "${LC_BASE_DIR}/templates/ols"
cp -f "${REPO_ROOT}/templates/ols/webadmin_notice.html.tpl" "${LC_BASE_DIR}/templates/ols/webadmin_notice.html.tpl"

echo "1. Testing Custom WebAdmin Notice Template & Installation..."
assert_file_exists "${REPO_ROOT}/templates/ols/webadmin_notice.html.tpl" "Custom notice template exists in repository"
assert_contains "$(cat "${REPO_ROOT}/templates/ols/webadmin_notice.html.tpl")" "Open LiteControl Panel" "Notice template provides direct link to LiteControl panel"
assert_contains "$(cat "${REPO_ROOT}/templates/ols/webadmin_notice.html.tpl")" "lc-cli server webadmin-enable" "Notice template documents CLI commands"

# Simulate notice file compilation as done in 04-openlitespeed.sh
sed -e "s/__SERVER_IP__/203.0.113.10/g" \
    -e "s/__PANEL_PORT__/8090/g" \
    "${REPO_ROOT}/templates/ols/webadmin_notice.html.tpl" > "${LC_CONF_DIR}/webadmin_notice.html"
assert_file_exists "${LC_CONF_DIR}/webadmin_notice.html" "Generated notice HTML exists"
assert_contains "$(cat "${LC_CONF_DIR}/webadmin_notice.html")" "https://203.0.113.10:8090/" "Notice HTML points to configured panel IP and port"

# Deploy notice into OLS admin html
cp -f "${LC_CONF_DIR}/webadmin_notice.html" "${OLS_DIR}/admin/html.open/disabled.html"
assert_file_exists "${OLS_DIR}/admin/html.open/disabled.html" "Deployed notice file exists in admin/html.open"

echo "2. Testing lc-cli server webadmin-status..."
out_status=$("${CLI}" --json server webadmin-status)
assert_contains "${out_status}" '"status":"success"' "webadmin-status returns success"
assert_contains "${out_status}" '"enabled":false' "webadmin-status initially reports disabled"
assert_contains "${out_status}" '"port":7080' "webadmin-status reports port 7080"
assert_file_not_exists "${OLS_DIR}/admin/conf/enable_webadmin" "enable_webadmin file initially does not exist"

echo "3. Testing lc-cli server webadmin-enable with whitelisted IPs..."
out_enable=$("${CLI}" --json server webadmin-enable "198.51.100.1, 203.0.113.0/24")
assert_contains "${out_enable}" '"status":"success"' "webadmin-enable returns success"
assert_contains "${out_enable}" '"enabled":true' "webadmin-enable reports enabled=true"
assert_contains "${out_enable}" '198.51.100.1,203.0.113.0/24' "webadmin-enable stores sanitized allowed IPs"
assert_file_exists "${OLS_DIR}/admin/conf/enable_webadmin" "enable_webadmin file created"
assert_contains "$(cat "${OLS_DIR}/admin/conf/enable_webadmin")" "198.51.100.1" "enable_webadmin contains first IP"
assert_contains "$(cat "${OLS_DIR}/admin/conf/enable_webadmin")" "203.0.113.0/24" "enable_webadmin contains second subnet"

# Verify mock firewall rule synchronization
mock_ufw="${LC_CONF_DIR}/mock_ufw_rules.conf"
assert_file_exists "${mock_ufw}" "Mock firewall rules file created"
assert_contains "$(cat "${mock_ufw}")" "7080/tcp|ALLOW IN" "UFW rule for port 7080 added"

# Re-check status now that it's enabled
out_status_enabled=$("${CLI}" --json server webadmin-status)
assert_contains "${out_status_enabled}" '"enabled":true' "webadmin-status reports enabled=true"
assert_contains "${out_status_enabled}" '198.51.100.1' "webadmin-status includes whitelisted IP"

echo "4. Testing lc-cli server webadmin-enable for all IPs (empty whitelist)..."
out_enable_all=$("${CLI}" --json server webadmin-enable)
assert_contains "${out_enable_all}" '"status":"success"' "webadmin-enable all IPs returns success"
assert_contains "${out_enable_all}" '"enabled":true' "webadmin-enable all IPs reports enabled=true"
assert_file_exists "${OLS_DIR}/admin/conf/enable_webadmin" "enable_webadmin file exists"
assert_eq "0" "$(wc -c < "${OLS_DIR}/admin/conf/enable_webadmin" | tr -d ' ')" "enable_webadmin is empty for unrestricted access"

echo "5. Testing lc-cli server webadmin-disable..."
out_disable=$("${CLI}" --json server webadmin-disable)
assert_contains "${out_disable}" '"status":"success"' "webadmin-disable returns success"
assert_contains "${out_disable}" '"enabled":false' "webadmin-disable reports enabled=false"
assert_file_not_exists "${OLS_DIR}/admin/conf/enable_webadmin" "enable_webadmin file deleted"
assert_not_contains "$(cat "${mock_ufw}")" "7080/tcp|" "UFW firewall rule for port 7080 removed"

# Verify status confirms disabled state
out_status_disabled=$("${CLI}" --json server webadmin-status)
assert_contains "${out_status_disabled}" '"enabled":false' "webadmin-status confirms disabled state"

# Clean up
rm -rf "${SANDBOX_DIR}"

echo ""
echo "Phase 7 Unit Tests Completed: ${ASSERTIONS} assertions, ${FAILURES} failures."
if [[ ${FAILURES} -gt 0 ]]; then
    exit 1
fi
