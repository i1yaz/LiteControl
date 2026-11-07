#!/usr/bin/env bash
# ==============================================================================
# LiteControl - Phase 2 Unit Tests
# Tests:
#   1. `lc-cli user` (add, validation, suspend, unsuspend, quota, list, delete)
#   2. `lc-cli vhost` (add, httpd_config.conf markers, php-switch, ssl, list, remove)
#   3. `lc-cli php` (list, install, ini-get, ini-set, ext-enable/disable, limits, panel PHP protection, remove)
#   4. `lc-cli tuning` (get, set, invalid key rejection)
#   5. `lc-cli server` (stats, services, service-action, timezone, hostname, processes, logs)
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
        echo "  [FAIL] ${msg}: output unexpectedly contained '${needle}'."
        FAILURES=$((FAILURES + 1))
    else
        echo "  [OK]   ${msg}"
    fi
}

# Create isolated sandbox
SANDBOX_DIR="$(mktemp -d 2>/dev/null || mktemp -d -t 'lc_test_p2')"
trap 'rm -rf "${SANDBOX_DIR}"' EXIT

export LC_TEST_MODE=1
export LC_BASE_DIR="${SANDBOX_DIR}/usr/local/litecontrol"
export LC_PANEL_DIR="${LC_BASE_DIR}/panel"
export LC_PMA_DIR="${LC_BASE_DIR}/phpmyadmin"
export LC_CONF_DIR="${SANDBOX_DIR}/etc/litecontrol"
export LC_SECRETS_DIR="${LC_CONF_DIR}/secrets"
export LC_SSL_DIR="${LC_CONF_DIR}/ssl"
export LC_MODULES_DIR="${LC_CONF_DIR}/modules"
export LC_LOG_DIR="${SANDBOX_DIR}/var/log/litecontrol"
export LC_HOME_ROOT="${SANDBOX_DIR}/home"
export OLS_DIR="${SANDBOX_DIR}/usr/local/lsws"
export OLS_CONF="${OLS_DIR}/conf/httpd_config.conf"
export OLS_VHOSTS_DIR="${OLS_DIR}/conf/vhosts"
export OLS_CTRL="${OLS_DIR}/bin/lswsctrl"
export OLS_BIN="${OLS_DIR}/bin/lshttpd"
export LC_PHP_VER="83"

# Initialize sandbox OLS environment from templates
mkdir -p "${OLS_DIR}/conf" "${OLS_DIR}/bin" "${OLS_DIR}/logs" "${OLS_VHOSTS_DIR}" "${LC_SSL_DIR}" "${LC_LOG_DIR}"
sed -e "s/__SERVER_NAME__/test.local/g" \
    -e "s/__PHP_VER__/83/g" \
    -e "s/__PANEL_PORT__/8090/g" \
    "${REPO_ROOT}/templates/ols/httpd_config.conf.tpl" > "${OLS_CONF}"

cat > "${OLS_BIN}" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF
cat > "${OLS_CTRL}" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF
chmod +x "${OLS_BIN}" "${OLS_CTRL}"
touch "${LC_SSL_DIR}/privkey.pem" "${LC_SSL_DIR}/fullchain.pem"
echo "2026-11-06 [WARN] Test OLS log line" > "${OLS_DIR}/logs/error.log"

# ------------------------------------------------------------------------------
# 1. `lc-cli user` Tests
# ------------------------------------------------------------------------------
echo "1. Testing lc-cli user subcommands..."

# Reserved user check
if err_out=$(bash "${CLI}" --json user add root SecretPass123 2>/dev/null); then
    assert_eq "1" "0" "user add should reject reserved username 'root'"
else
    assert_contains "${err_out}" '"status":"error"' "user add rejects reserved username 'root'"
fi

# Add valid user
add_out=$(bash "${CLI}" --json user add alice SecretPass123 2048)
assert_contains "${add_out}" '"status":"success"' "user add alice succeeds"
assert_contains "${add_out}" '"quota_mb":2048' "user add sets quota_mb 2048"

# Suspend & Unsuspend
susp_out=$(bash "${CLI}" --json user suspend alice)
assert_contains "${susp_out}" '"status":"suspended"' "user suspend alice sets suspended state"

unsusp_out=$(bash "${CLI}" --json user unsuspend alice)
assert_contains "${unsusp_out}" '"status":"active"' "user unsuspend alice restores active state"

# Quota update
quota_out=$(bash "${CLI}" --json user quota alice 4096)
assert_contains "${quota_out}" '"quota_mb":4096' "user quota updates quota to 4096 MB"

# List users
list_out=$(bash "${CLI}" --json user list)
assert_contains "${list_out}" '"username":"alice"' "user list includes alice"

# Delete user
del_out=$(bash "${CLI}" --json user delete alice --remove-home)
assert_contains "${del_out}" '"deleted":true' "user delete alice succeeds"

# ------------------------------------------------------------------------------
# 2. `lc-cli vhost` Tests
# ------------------------------------------------------------------------------
echo "2. Testing lc-cli vhost subcommands..."
bash "${CLI}" --json user add bob SecretPass123 1024 >/dev/null

# Add vhost
vh_add=$(bash "${CLI}" --json vhost add example.com bob 83)
assert_contains "${vh_add}" '"domain":"example.com"' "vhost add example.com succeeds"
assert_contains "$(cat "${OLS_CONF}")" "virtualhost example.com {" "httpd_config.conf contains virtualhost example.com block"
assert_contains "$(cat "${OLS_CONF}")" "# MAP_HTTP:example.com" "httpd_config.conf contains HTTP listener map for example.com"
assert_contains "$(cat "${OLS_VHOSTS_DIR}/example.com/vhconf.conf")" "extUser                 bob" "vhconf.conf configures SuEXEC extUser bob"

# Switch PHP version
vh_php=$(bash "${CLI}" --json vhost php-switch example.com 82)
assert_contains "${vh_php}" '"php_version":"82"' "vhost php-switch updates to PHP 82"
assert_contains "$(cat "${OLS_VHOSTS_DIR}/example.com/vhconf.conf")" "/usr/local/lsws/lsphp82/bin/lsphp" "vhconf.conf binary path updated to lsphp82"

# Custom SSL update
vh_ssl=$(bash "${CLI}" --json vhost ssl example.com "${LC_SSL_DIR}/fullchain.pem" "${LC_SSL_DIR}/privkey.pem")
assert_contains "${vh_ssl}" '"ssl_enabled":true' "vhost ssl enables SSL metadata"

# List vhosts
vh_list=$(bash "${CLI}" --json vhost list)
assert_contains "${vh_list}" '"domain":"example.com"' "vhost list returns example.com"

# Remove vhost
vh_rem=$(bash "${CLI}" --json vhost remove example.com --delete-files)
assert_contains "${vh_rem}" '"removed":true' "vhost remove example.com succeeds"
assert_not_contains "$(cat "${OLS_CONF}")" "virtualhost example.com" "httpd_config.conf cleaned after vhost remove"

# ------------------------------------------------------------------------------
# 3. `lc-cli php` Tests
# ------------------------------------------------------------------------------
echo "3. Testing lc-cli php subcommands..."
php_inst=$(bash "${CLI}" --json php install 82)
assert_contains "${php_inst}" '"version":"82"' "php install 82 succeeds"
assert_contains "$(cat "${OLS_CONF}")" "extprocessor lsphp82" "httpd_config.conf registers extprocessor lsphp82"

php_ini_set=$(bash "${CLI}" --json php ini-set 82 memory_limit 512M)
assert_contains "${php_ini_set}" '"value":"512M"' "php ini-set updates memory_limit to 512M"

php_ini_get=$(bash "${CLI}" --json php ini-get 82)
assert_contains "${php_ini_get}" '"memory_limit":"512M"' "php ini-get reads updated memory_limit 512M"

php_ext_en=$(bash "${CLI}" --json php ext-enable 82 imagick)
assert_contains "${php_ext_en}" '"enabled":true' "php ext-enable enables lsphp82-imagick"

php_ext_list=$(bash "${CLI}" --json php ext-list 82)
assert_contains "${php_ext_list}" '"extension":"imagick","package":"lsphp82-imagick","enabled":true' "php ext-list reflects enabled imagick"

php_lim_set=$(bash "${CLI}" --json php limits set 25 1024M 2048M 800 900)
assert_contains "${php_lim_set}" '"maxConns":25' "php limits set updates maxConns to 25"

php_lim_get=$(bash "${CLI}" --json php limits get)
assert_contains "${php_lim_get}" '"maxConns":25' "php limits get reads updated maxConns 25"

# Protect panel PHP (83) from removal
if panel_rm=$(bash "${CLI}" --json php remove 83 2>/dev/null); then
    assert_eq "1" "0" "php remove should protect panel PHP 83"
else
    assert_contains "${panel_rm}" '"status":"error"' "php remove blocks uninstalling panel PHP 83"
fi

php_rm=$(bash "${CLI}" --json php remove 82)
assert_contains "${php_rm}" '"removed":true' "php remove 82 succeeds"
assert_not_contains "$(cat "${OLS_CONF}")" "extprocessor lsphp82" "httpd_config.conf removes extprocessor lsphp82"

# ------------------------------------------------------------------------------
# 4. `lc-cli tuning` Tests
# ------------------------------------------------------------------------------
echo "4. Testing lc-cli tuning subcommands..."
tune_set=$(bash "${CLI}" --json tuning set maxConnections=15000 keepAliveTimeout=8 quicEnable=0)
assert_contains "${tune_set}" '"maxConnections":15000' "tuning set updates maxConnections to 15000"
assert_contains "${tune_set}" '"keepAliveTimeout":8' "tuning set updates keepAliveTimeout to 8"
assert_contains "${tune_set}" '"quicEnable":0' "tuning set updates quicEnable to 0"

# ------------------------------------------------------------------------------
# 5. `lc-cli server` Tests
# ------------------------------------------------------------------------------
echo "5. Testing lc-cli server subcommands..."
srv_stats=$(bash "${CLI}" --json server stats)
assert_contains "${srv_stats}" '"cpu_percent":' "server stats returns cpu_percent"
assert_contains "${srv_stats}" '"ram_total_mb":' "server stats returns ram_total_mb"

srv_svcs=$(bash "${CLI}" --json server services)
assert_contains "${srv_svcs}" '"service":"litecontrol-panel"' "server services lists litecontrol-panel"
assert_contains "${srv_svcs}" '"service":"litecontrol-php"' "server services lists litecontrol-php"
assert_contains "${srv_svcs}" '"service":"openlitespeed"' "server services lists openlitespeed"
assert_contains "${srv_svcs}" '"service":"redis-server"' "server services lists redis-server"

srv_tz=$(bash "${CLI}" --json server timezone set Asia/Karachi)
assert_contains "${srv_tz}" '"timezone":"Asia/Karachi"' "server timezone set updates timezone"

srv_host=$(bash "${CLI}" --json server hostname set panel.litecontrol.test)
assert_contains "${srv_host}" '"hostname":"panel.litecontrol.test"' "server hostname set updates hostname"
assert_contains "$(cat "${OLS_CONF}")" "serverName                panel.litecontrol.test" "server hostname updates OLS serverName"

srv_procs=$(bash "${CLI}" --json server processes)
assert_contains "${srv_procs}" '"command":"lshttpd"' "server processes returns process list"

echo "2026-11-06 [WARN] Test OLS log line" >> "${OLS_DIR}/logs/error.log"
srv_logs=$(bash "${CLI}" --json server logs ols-error 10)
assert_contains "${srv_logs}" 'Test OLS log line' "server logs ols-error tails log content"

echo ""
echo "Phase 2 Unit Tests Completed: ${ASSERTIONS} assertions, ${FAILURES} failures."
[[ "${FAILURES}" -eq 0 ]]

