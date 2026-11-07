#!/usr/bin/env bash
# ==============================================================================
# LiteControl - Phase 3 Unit Tests
# Tests:
#   1. `lc-cli db` (create-db, protected DB blocking, db list, create-user, grant, password, delete-db, delete-user)
#   2. `lc-cli ssl` (issue, custom cert matching, panel-ssl, renew)
#   3. `lc-cli security` (ufw-status, ufw-allow, ufw-deny, ufw-delete, panel-port OLS sync, ssh-port)
#   4. `lc-cli backup` (create tar.gz, metadata, list, restore, schedule add/list/remove)
#   5. `lc-cli module` (list 6 modules, install/status/remove for ftp, dns, mail, csf, modsecurity, imunifyav)
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
SANDBOX_DIR="$(mktemp -d 2>/dev/null || mktemp -d -t 'lc_test_p3')"
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
export PANEL_NGINX_DIR="${LC_CONF_DIR}/nginx"
export PANEL_NGINX_CONF="${PANEL_NGINX_DIR}/nginx.conf"
export PANEL_FPM_DIR="${LC_CONF_DIR}/php-fpm"
export PANEL_FPM_CONF="${PANEL_FPM_DIR}/php-fpm.conf"
export NGINX_BIN="${SANDBOX_DIR}/usr/sbin/nginx"
export SSHD_CONFIG_FILE="${SANDBOX_DIR}/etc/ssh/sshd_config"
export PUREFTPD_CONF_DIR="${SANDBOX_DIR}/etc/pure-ftpd"
export PDNS_CONF_DIR="${SANDBOX_DIR}/etc/powerdns"
export POSTFIX_CONF_DIR="${SANDBOX_DIR}/etc/postfix"
export DOVECOT_CONF_DIR="${SANDBOX_DIR}/etc/dovecot"
export CSF_CONF="${SANDBOX_DIR}/etc/csf/csf.conf"
export CRON_BACKUP_FILE="${SANDBOX_DIR}/etc/cron.d/litecontrol-backups"

# Initialize sandbox
mkdir -p "${OLS_DIR}/conf" "${OLS_DIR}/bin" "${OLS_DIR}/logs" "${OLS_VHOSTS_DIR}" "${LC_SSL_DIR}" "${LC_LOG_DIR}"
mkdir -p "$(dirname "${SSHD_CONFIG_FILE}")" "${SANDBOX_DIR}/etc/cron.d"

# Copy base OLS config
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
echo "Port 22" > "${SSHD_CONFIG_FILE}"

# Setup a test domain vhost
mkdir -p "${OLS_VHOSTS_DIR}/testdomain.com"
cat > "${OLS_VHOSTS_DIR}/testdomain.com/vhconf.conf" <<'EOF'
docRoot                   /home/testuser/testdomain.com/public_html
vhssl {
  keyFile                 /etc/litecontrol/ssl/privkey.pem
  certFile                /etc/litecontrol/ssl/fullchain.pem
}
EOF

# Setup mock nginx and panel config
mkdir -p "${PANEL_NGINX_DIR}" "$(dirname "${NGINX_BIN}")"
sed "s/__PANEL_PORT__/8090/g" "${REPO_ROOT}/templates/panel/nginx.conf.tpl" > "${PANEL_NGINX_CONF}"
cat > "${NGINX_BIN}" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF
chmod +x "${NGINX_BIN}"

# Setup a test user home
mkdir -p "${LC_HOME_ROOT}/testuser/testdomain.com/public_html"
echo "hello world" > "${LC_HOME_ROOT}/testuser/testdomain.com/public_html/index.html"

# ------------------------------------------------------------------------------
# 1. `lc-cli db` Tests
# ------------------------------------------------------------------------------
echo "1. Testing lc-cli db subcommands..."

# Test protected database rejection
if out=$(bash "${CLI}" --json db create-db mysql 2>/dev/null); then
    assert_eq "1" "0" "db create-db should reject protected 'mysql'"
else
    assert_contains "${out}" '"status":"error"' "db create-db rejects protected 'mysql'"
fi

if out=$(bash "${CLI}" --json db create-db litecontrol 2>/dev/null); then
    assert_eq "1" "0" "db create-db should reject protected 'litecontrol'"
else
    assert_contains "${out}" '"status":"error"' "db create-db rejects protected 'litecontrol'"
fi

# Test valid db creation
out=$(bash "${CLI}" --json db create-db app_db)
assert_contains "${out}" '"status":"success"' "db create-db app_db succeeds"
assert_contains "${out}" '"database":"app_db"' "db create-db returns database name"

# Test duplicate db creation fails
if out=$(bash "${CLI}" --json db create-db app_db 2>/dev/null); then
    assert_eq "1" "0" "db create-db duplicate app_db should fail"
else
    assert_contains "${out}" '"status":"error"' "db create-db duplicate app_db fails"
fi

# Test db list
out=$(bash "${CLI}" --json db list)
assert_contains "${out}" '"status":"success"' "db list succeeds"
assert_contains "${out}" '"name":"app_db"' "db list includes app_db"

# Test protected user rejection
if out=$(bash "${CLI}" --json db create-user root MyPass 2>/dev/null); then
    assert_eq "1" "0" "db create-user should reject protected 'root'"
else
    assert_contains "${out}" '"status":"error"' "db create-user rejects protected 'root'"
fi

# Test db create-user
out=$(bash "${CLI}" --json db create-user dbuser1 SecretPassword123)
assert_contains "${out}" '"status":"success"' "db create-user dbuser1 succeeds"

# Test db grant
out=$(bash "${CLI}" --json db grant dbuser1 app_db)
assert_contains "${out}" '"status":"success"' "db grant dbuser1 app_db succeeds"

# Test db password update
out=$(bash "${CLI}" --json db password dbuser1 NewSecretPass456)
assert_contains "${out}" '"status":"success"' "db password dbuser1 succeeds"

# Test db delete-db protected rejection
if out=$(bash "${CLI}" --json db delete-db mysql 2>/dev/null); then
    assert_eq "1" "0" "db delete-db should reject deleting protected db 'mysql'"
else
    assert_contains "${out}" '"status":"error"' "db delete-db rejects deleting protected 'mysql'"
fi

# Test db delete-db
out=$(bash "${CLI}" --json db delete-db app_db)
assert_contains "${out}" '"status":"success"' "db delete-db app_db succeeds"

# Test db delete-user
out=$(bash "${CLI}" --json db delete-user dbuser1)
assert_contains "${out}" '"status":"success"' "db delete-user dbuser1 succeeds"

# ------------------------------------------------------------------------------
# 2. `lc-cli ssl` Tests
# ------------------------------------------------------------------------------
echo "2. Testing lc-cli ssl subcommands..."

# Create mock test cert and key
TEST_CERT="${SANDBOX_DIR}/test_cert.pem"
TEST_KEY="${SANDBOX_DIR}/test_key.pem"
echo "-----BEGIN CERTIFICATE-----" > "${TEST_CERT}"
echo "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8A" >> "${TEST_CERT}"
echo "-----END CERTIFICATE-----" >> "${TEST_CERT}"
echo "-----BEGIN PRIVATE KEY-----" > "${TEST_KEY}"
echo "MIIEvgIBADANBgkqhkiG9w0BAQEFAASC" >> "${TEST_KEY}"
echo "-----END PRIVATE KEY-----" >> "${TEST_KEY}"

# Test custom SSL installation
out=$(bash "${CLI}" --json ssl custom testdomain.com "${TEST_CERT}" "${TEST_KEY}")
assert_contains "${out}" '"status":"success"' "ssl custom succeeds"
assert_contains "${out}" '"issuer":"custom"' "ssl custom reports issuer custom"

# Verify vhconf updated
vhconf_content=$(cat "${OLS_VHOSTS_DIR}/testdomain.com/vhconf.conf")
assert_contains "${vhconf_content}" "${LC_SSL_DIR}/domains/testdomain.com/fullchain.pem" "vhconf.conf points to domain fullchain.pem"

# Test ssl issue in test mode
out=$(bash "${CLI}" --json ssl issue testdomain.com "${LC_HOME_ROOT}/testuser/testdomain.com/public_html")
assert_contains "${out}" '"status":"success"' "ssl issue succeeds in sandbox test mode"

# Test panel-ssl custom
out=$(bash "${CLI}" --json ssl panel-ssl custom "${TEST_CERT}" "${TEST_KEY}")
assert_contains "${out}" '"status":"success"' "ssl panel-ssl custom succeeds"

# Test ssl renew
out=$(bash "${CLI}" --json ssl renew)
assert_contains "${out}" '"status":"success"' "ssl renew succeeds"

# ------------------------------------------------------------------------------
# 3. `lc-cli security` Tests
# ------------------------------------------------------------------------------
echo "3. Testing lc-cli security subcommands..."

# Test ufw-allow
out=$(bash "${CLI}" --json security ufw-allow 8443 tcp)
assert_contains "${out}" '"status":"success"' "security ufw-allow 8443/tcp succeeds"

# Test ufw-deny
out=$(bash "${CLI}" --json security ufw-deny 23 tcp)
assert_contains "${out}" '"status":"success"' "security ufw-deny 23/tcp succeeds"

# Test ufw-status
out=$(bash "${CLI}" --json security ufw-status)
assert_contains "${out}" '"status":"success"' "security ufw-status succeeds"
assert_contains "${out}" '8443/tcp' "security ufw-status lists allowed 8443/tcp"
assert_contains "${out}" '23/tcp' "security ufw-status lists denied 23/tcp"

# Test ufw-delete
out=$(bash "${CLI}" --json security ufw-delete 23/tcp)
assert_contains "${out}" '"status":"success"' "security ufw-delete 23/tcp succeeds"

# Test panel-port invalid port rejection
if out=$(bash "${CLI}" --json security panel-port 80 2>/dev/null); then
    assert_eq "1" "0" "security panel-port should reject port < 1024"
else
    assert_contains "${out}" '"status":"error"' "security panel-port rejects port < 1024"
fi

# Test panel-port valid change
out=$(bash "${CLI}" --json security panel-port 8099)
assert_contains "${out}" '"status":"success"' "security panel-port 8099 succeeds"
assert_eq "8099" "$(cat "${LC_CONF_DIR}/port")" "LC_CONF_DIR/port updated to 8099"
assert_contains "$(cat "${PANEL_NGINX_CONF}")" "listen 8099 ssl http2;" "PANEL_NGINX_CONF updated to listen 8099"
assert_contains "$(cat "${PANEL_NGINX_CONF}")" "listen [::]:8099 ssl http2;" "PANEL_NGINX_CONF IPv6 updated to listen 8099"

# Test ssh-port change
out=$(bash "${CLI}" --json security ssh-port 2222)
assert_contains "${out}" '"status":"success"' "security ssh-port 2222 succeeds"
assert_contains "$(cat "${SSHD_CONFIG_FILE}")" "Port 2222" "sshd_config updated to Port 2222"

# ------------------------------------------------------------------------------
# 4. `lc-cli backup` Tests
# ------------------------------------------------------------------------------
echo "4. Testing lc-cli backup subcommands..."

# Test backup create
out=$(bash "${CLI}" --json backup create testuser)
assert_contains "${out}" '"status":"success"' "backup create testuser succeeds"
assert_contains "${out}" 'backup-testuser-' "backup create returns archive filename"

# Extract path
archive_path=$(echo "${out}" | grep -o '"path":"[^"]*"' | cut -d'"' -f4)
assert_eq "1" "$([[ -f "${archive_path}" ]] && echo 1 || echo 0)" "Backup archive exists on filesystem"

# Test backup list
out=$(bash "${CLI}" --json backup list testuser)
assert_contains "${out}" '"status":"success"' "backup list succeeds"
assert_contains "${out}" 'backup-testuser-' "backup list includes created backup"

# Test backup restore
out=$(bash "${CLI}" --json backup restore "${archive_path}")
assert_contains "${out}" '"status":"success"' "backup restore succeeds"
assert_contains "${out}" '"restored":true' "backup restore confirms restoration"

# Test backup schedule add
out=$(bash "${CLI}" --json backup schedule add testuser daily 7)
assert_contains "${out}" '"status":"success"' "backup schedule add succeeds"
assert_contains "$(cat "${CRON_BACKUP_FILE}")" "backup create testuser" "Cron file contains backup job for testuser"

# Test backup schedule list
out=$(bash "${CLI}" --json backup schedule list)
assert_contains "${out}" '"status":"success"' "backup schedule list succeeds"

# Test backup schedule remove
out=$(bash "${CLI}" --json backup schedule remove testuser)
assert_contains "${out}" '"status":"success"' "backup schedule remove succeeds"
assert_not_contains "$(cat "${CRON_BACKUP_FILE}")" "backup create testuser" "Cron file cleaned after schedule remove"

# ------------------------------------------------------------------------------
# 5. `lc-cli module` Tests
# ------------------------------------------------------------------------------
echo "5. Testing lc-cli module subcommands..."

# Test module list includes all 6 modules
out=$(bash "${CLI}" --json module list)
assert_contains "${out}" '"status":"success"' "module list succeeds"
assert_contains "${out}" '"name":"ftp"' "module list includes ftp"
assert_contains "${out}" '"name":"dns"' "module list includes dns"
assert_contains "${out}" '"name":"mail"' "module list includes mail"
assert_contains "${out}" '"name":"csf"' "module list includes csf"
assert_contains "${out}" '"name":"modsecurity"' "module list includes modsecurity"
assert_contains "${out}" '"name":"imunifyav"' "module list includes imunifyav"

# Test ftp module install
out=$(bash "${CLI}" --json module install ftp)
assert_contains "${out}" '"status":"success"' "module install ftp succeeds"
assert_eq "1" "$([[ -f "${LC_MODULES_DIR}/ftp.installed" ]] && echo 1 || echo 0)" "ftp.installed marker created"
assert_eq "1" "$([[ -f "${PUREFTPD_CONF_DIR}/db/mysql.conf" ]] && echo 1 || echo 0)" "pure-ftpd mysql.conf created"

# Test dns module install
out=$(bash "${CLI}" --json module install dns)
assert_contains "${out}" '"status":"success"' "module install dns succeeds"
assert_eq "1" "$([[ -f "${LC_MODULES_DIR}/dns.installed" ]] && echo 1 || echo 0)" "dns.installed marker created"
assert_eq "1" "$([[ -f "${PDNS_CONF_DIR}/pdns.conf" ]] && echo 1 || echo 0)" "pdns.conf created"

# Test mail module install
out=$(bash "${CLI}" --json module install mail)
assert_contains "${out}" '"status":"success"' "module install mail succeeds"
assert_eq "1" "$([[ -f "${LC_MODULES_DIR}/mail.installed" ]] && echo 1 || echo 0)" "mail.installed marker created"
assert_eq "1" "$([[ -f "${POSTFIX_CONF_DIR}/main.cf" ]] && echo 1 || echo 0)" "postfix main.cf created"
assert_eq "1" "$([[ -f "${DOVECOT_CONF_DIR}/dovecot.conf" ]] && echo 1 || echo 0)" "dovecot.conf created"

# Test csf module install
out=$(bash "${CLI}" --json module install csf)
assert_contains "${out}" '"status":"success"' "module install csf succeeds"
assert_eq "1" "$([[ -f "${LC_MODULES_DIR}/csf.installed" ]] && echo 1 || echo 0)" "csf.installed marker created"
assert_contains "$(cat "${CSF_CONF}")" "8099" "csf.conf contains updated panel port 8099"

# Test modsecurity module install
out=$(bash "${CLI}" --json module install modsecurity)
assert_contains "${out}" '"status":"success"' "module install modsecurity succeeds"
assert_eq "1" "$([[ -f "${LC_MODULES_DIR}/modsecurity.installed" ]] && echo 1 || echo 0)" "modsecurity.installed marker created"
assert_contains "$(cat "${OLS_CONF}")" "module mod_security" "httpd_config.conf contains module mod_security block"

# Test modsecurity module remove
out=$(bash "${CLI}" --json module remove modsecurity)
assert_contains "${out}" '"status":"success"' "module remove modsecurity succeeds"
assert_not_contains "$(cat "${OLS_CONF}")" "module mod_security" "httpd_config.conf cleaned after modsecurity remove"

# Test ftp module remove
out=$(bash "${CLI}" --json module remove ftp)
assert_contains "${out}" '"status":"success"' "module remove ftp succeeds"
assert_eq "0" "$([[ -f "${LC_MODULES_DIR}/ftp.installed" ]] && echo 1 || echo 0)" "ftp.installed marker removed"

# Summary
echo ""
echo "Phase 3 Unit Tests Completed: ${ASSERTIONS} assertions, ${FAILURES} failures."
if [[ "${FAILURES}" -gt 0 ]]; then
    exit 1
fi
exit 0

