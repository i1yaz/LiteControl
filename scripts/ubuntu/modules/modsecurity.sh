#!/usr/bin/env bash
# ==============================================================================
# LiteControl Module: OpenLiteSpeed ModSecurity WAF & OWASP CRS (`modsecurity`)
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

ACTION="${1:-install}"
MODSEC_DIR="${OLS_DIR}/conf/modsec"

do_install() {
    log_info "Enabling OpenLiteSpeed ModSecurity Web Application Firewall..."

    mkdir -p "${MODSEC_DIR}/rules"

    # Setup base CRS configuration
    if [[ ! -f "${MODSEC_DIR}/crs-setup.conf" ]]; then
        cat > "${MODSEC_DIR}/crs-setup.conf" <<'EOF'
# OWASP ModSecurity Core Rule Set (LiteControl Base)
SecDefaultAction "phase:1,log,auditlog,deny,status:403"
SecDefaultAction "phase:2,log,auditlog,deny,status:403"
SecAction \
 "id:900990,\
  phase:1,\
  nolog,\
  pass,\
  t:none,\
  setvar:tx.crs_setup_version=330"
EOF
    fi

    # Create default base rule if rules dir is empty
    if [[ ! -f "${MODSEC_DIR}/rules/REQUEST-901-INITIALIZATION.conf" ]]; then
        cat > "${MODSEC_DIR}/rules/REQUEST-901-INITIALIZATION.conf" <<'EOF'
SecAction \
 "id:901001,\
  phase:1,\
  nolog,\
  pass,\
  t:none,\
  setvar:tx.anomaly_score_blocking=on,\
  setvar:tx.inbound_anomaly_score_threshold=5"
EOF
    fi

    # Add mod_security block to httpd_config.conf if not already present
    if [[ -f "${OLS_CONF}" ]] && ! grep -q "module mod_security" "${OLS_CONF}"; then
        cat >> "${OLS_CONF}" <<EOF

module mod_security {
  mod_security.so
  modsec_rules            \`
SecRuleEngine On
SecRequestBodyAccess On
SecResponseBodyAccess Off
SecAuditEngine RelevantOnly
SecAuditLog ${OLS_DIR}/logs/modsec_audit.log
Include ${MODSEC_DIR}/crs-setup.conf
Include ${MODSEC_DIR}/rules/*.conf
\`
}
EOF
    fi

    ols_safe_reload
    log_ok "ModSecurity WAF enabled and loaded in OpenLiteSpeed."
}

do_remove() {
    log_info "Disabling OpenLiteSpeed ModSecurity WAF..."

    if [[ -f "${OLS_CONF}" ]]; then
        awk '
            /module mod_security \{/ { in_modsec=1; next }
            in_modsec && /^\}/ { in_modsec=0; next }
            !in_modsec { print }
        ' "${OLS_CONF}" > "${OLS_CONF}.tmp" && mv "${OLS_CONF}.tmp" "${OLS_CONF}"
    fi

    ols_safe_reload
    log_ok "ModSecurity WAF disabled."
}

case "${ACTION}" in
    install)
        do_install
        ;;
    remove)
        do_remove
        ;;
    *)
        log_error "Usage: $0 <install|remove>"
        exit 1
        ;;
esac

