package mail

import (
	"bytes"
	"fmt"
	"text/template"
)

// TemplateData holds all parameters used to render mail stack OS configuration files.
type TemplateData struct {
	Hostname       string
	DBHost         string
	DBName         string
	DBUser         string
	DBPassword     string
	FallbackCert   string
	FallbackKey    string
	DovecotSeries  DovecotSeries
	PasswordScheme PasswordScheme
	RedisBinary    string
	RspamdUser     string
}

func renderTemplate(name, tpl string, data TemplateData) (string, error) {
	t, err := template.New(name).Parse(tpl)
	if err != nil {
		return "", fmt.Errorf("parse template %s: %w", name, err)
	}
	var buf bytes.Buffer
	if err := t.Execute(&buf, data); err != nil {
		return "", fmt.Errorf("execute template %s: %w", name, err)
	}
	return buf.String(), nil
}

const postfixMainCFTpl = `# Managed by LiteControl lc-mail — do not edit manually.
compatibility_level = 3.6

myhostname = {{.Hostname}}
mydestination = localhost
myorigin = $myhostname
mynetworks = 127.0.0.0/8 [::ffff:127.0.0.0]/104 [::1]/128
inet_interfaces = all
inet_protocols = all

# Virtual Mailboxes, Domains, Aliases & Sender Login Maps (non-UNION views via proxy:mysql)
virtual_mailbox_domains = proxy:mysql:/etc/postfix/sql/domains.cf
virtual_mailbox_maps = proxy:mysql:/etc/postfix/sql/mailboxes.cf
virtual_alias_maps = proxy:mysql:/etc/postfix/sql/mailbox-self.cf, proxy:mysql:/etc/postfix/sql/aliases.cf
smtpd_sender_login_maps = proxy:mysql:/etc/postfix/sql/sender-mailboxes.cf, proxy:mysql:/etc/postfix/sql/sender-aliases.cf
virtual_transport = lmtp:unix:private/dovecot-lmtp

# SASL Auth via Dovecot
smtpd_sasl_type = dovecot
smtpd_sasl_path = private/auth
smtpd_sasl_auth_enable = yes
smtpd_sasl_security_options = noanonymous
smtpd_sasl_local_domain = $myhostname

# TLS & Per-Domain SNI
smtpd_tls_security_level = may
smtp_tls_security_level = may
smtpd_tls_auth_only = yes
smtpd_tls_protocols = !SSLv2, !SSLv3, !TLSv1, !TLSv1.1
smtpd_tls_mandatory_protocols = !SSLv2, !SSLv3, !TLSv1, !TLSv1.1
smtpd_tls_chain_files = {{.FallbackKey}}, {{.FallbackCert}}
tls_server_sni_maps = hash:/etc/postfix/vmail_ssl.map

# Recipient & Quota-Status Restrictions
smtpd_recipient_restrictions =
    permit_mynetworks,
    permit_sasl_authenticated,
    reject_unauth_destination,
    check_policy_service unix:private/quota-status

# Milter Separation (Rspamd-Light: empty smtpd_milters on port 25; submission/smtps set in master.cf)
smtpd_milters =
non_smtpd_milters = inet:127.0.0.1:11332
milter_protocol = 6
milter_mail_macros = i {mail_addr} {client_addr} {client_name} {auth_authen}
milter_default_action = accept

# SRS via postsrsd
sender_canonical_maps = tcp:127.0.0.1:10001
sender_canonical_classes = envelope_sender
recipient_canonical_maps = tcp:127.0.0.1:10002
recipient_canonical_classes = envelope_recipient,header_recipient
`

const postfixMasterCFTpl = `# Managed by LiteControl lc-mail — do not edit manually.
smtp      inet  n       -       y       -       -       smtpd
pickup    unix  n       -       y       60      1       pickup
cleanup   unix  n       -       y       -       0       cleanup
qmgr      unix  n       -       n       300     1       qmgr
tlsmgr    unix  -       -       y       1000?   1       tlsmgr
rewrite   unix  -       -       y       -       -       trivial-rewrite
bounce    unix  -       -       y       -       0       bounce
defer     unix  -       -       y       -       0       bounce
trace     unix  -       -       y       -       0       bounce
verify    unix  -       -       y       -       1       verify
flush     unix  n       -       y       1000?   0       flush
proxymap  unix  -       -       n       -       -       proxymap
proxywrite unix -       -       n       -       1       proxymap
smtp      unix  -       -       y       -       -       smtp
relay     unix  -       -       y       -       -       smtp
showq     unix  n       -       y       -       -       showq
error     unix  -       -       y       -       -       error
retry     unix  -       -       y       -       -       error
discard   unix  -       -       y       -       -       discard
local     unix  -       n       n       -       -       local
virtual   unix  -       n       n       -       -       virtual
lmtp      unix  -       -       y       -       -       lmtp
anvil     unix  -       -       y       -       1       anvil
scache    unix  -       -       y       -       1       scache
postlog   unix-dgram n  -       n       -       1       postlogd

submission inet n       -       y       -       -       smtpd
  -o syslog_name=postfix/submission
  -o smtpd_tls_security_level=encrypt
  -o smtpd_sasl_auth_enable=yes
  -o smtpd_tls_auth_only=yes
  -o smtpd_reject_unlisted_recipient=no
  -o smtpd_sender_restrictions=reject_sender_login_mismatch
  -o smtpd_recipient_restrictions=permit_sasl_authenticated,reject
  -o smtpd_milters=inet:127.0.0.1:11332
  -o milter_default_action=tempfail

smtps     inet  n       -       y       -       -       smtpd
  -o syslog_name=postfix/smtps
  -o smtpd_tls_wrappermode=yes
  -o smtpd_sasl_auth_enable=yes
  -o smtpd_reject_unlisted_recipient=no
  -o smtpd_sender_restrictions=reject_sender_login_mismatch
  -o smtpd_recipient_restrictions=permit_sasl_authenticated,reject
  -o smtpd_milters=inet:127.0.0.1:11332
  -o milter_default_action=tempfail
`

const postfixSQLDomainsTpl = `# Managed by LiteControl lc-mail
user = {{.DBUser}}
password = {{.DBPassword}}
hosts = {{.DBHost}}
dbname = {{.DBName}}
query = SELECT domain FROM v_postfix_domains WHERE domain = '%s'
`

const postfixSQLMailboxesTpl = `# Managed by LiteControl lc-mail
user = {{.DBUser}}
password = {{.DBPassword}}
hosts = {{.DBHost}}
dbname = {{.DBName}}
query = SELECT maildir FROM v_postfix_mailboxes WHERE email = '%s'
`

const postfixSQLMailboxSelfTpl = `# Managed by LiteControl lc-mail
user = {{.DBUser}}
password = {{.DBPassword}}
hosts = {{.DBHost}}
dbname = {{.DBName}}
query = SELECT destination_email FROM v_postfix_mailbox_self WHERE source_email = '%s'
`

const postfixSQLAliasesTpl = `# Managed by LiteControl lc-mail
user = {{.DBUser}}
password = {{.DBPassword}}
hosts = {{.DBHost}}
dbname = {{.DBName}}
query = SELECT destination_email FROM v_postfix_aliases WHERE source_email = '%s'
`

const postfixSQLSenderMailboxesTpl = `# Managed by LiteControl lc-mail
user = {{.DBUser}}
password = {{.DBPassword}}
hosts = {{.DBHost}}
dbname = {{.DBName}}
query = SELECT sasl_login FROM v_postfix_sender_mailboxes WHERE sender_email = '%s'
`

const postfixSQLSenderAliasesTpl = `# Managed by LiteControl lc-mail
user = {{.DBUser}}
password = {{.DBPassword}}
hosts = {{.DBHost}}
dbname = {{.DBName}}
query = SELECT sasl_login FROM v_postfix_sender_aliases WHERE sender_email = '%s'
`

const dovecot23SQLTpl = `# Managed by LiteControl lc-mail (Dovecot 2.3)
driver = mysql
connect = host={{.DBHost}} dbname={{.DBName}} user={{.DBUser}} password={{.DBPassword}}
default_pass_scheme = {{.PasswordScheme}}

password_query = SELECT email AS user, password_hash AS password \
  FROM v_dovecot_users \
  WHERE email = '%u' \
    AND (('%s' = 'imap' AND allow_imap = 1) \
      OR ('%s' = 'pop3' AND allow_pop3 = 1) \
      OR ('%s' = 'smtp' AND allow_smtp = 1) \
      OR ('%s' IN ('lmtp', 'sieve', 'managesieve') AND allow_imap = 1))

user_query = SELECT '/var/vmail/%d/%n' AS home, \
  'maildir:/var/vmail/%d/%n/Maildir' AS mail, \
  5000 AS uid, 5000 AS gid, \
  CONCAT('*:bytes=', quota_mb * 1048576) AS quota_rule \
  FROM v_dovecot_users WHERE email = '%u'

iterate_query = SELECT email AS user FROM v_dovecot_users
`

const dovecot23CoreTpl = `# Managed by LiteControl lc-mail (Dovecot 2.3)
protocols = imap pop3 lmtp sieve
ssl = required
disable_plaintext_auth = yes
ssl_cert = <{{.FallbackCert}}
ssl_key = <{{.FallbackKey}}
login_trusted_networks = 127.0.0.1

mail_location = maildir:/var/vmail/%d/%n/Maildir
mail_uid = 5000
mail_gid = 5000
mail_privileged_group = vmail

passdb {
  driver = sql
  args = /etc/dovecot/dovecot-sql.conf.ext
}

userdb {
  driver = sql
  args = /etc/dovecot/dovecot-sql.conf.ext
}

plugin {
  quota = count:User quota
  quota_vsizes = yes
  quota_status_success = DUNNO
  quota_status_nouser = DUNNO
  quota_status_overquota = "552 5.2.2 Mailbox is full"
  sieve = file:~/sieve;active=~/.dovecot.sieve
  sieve_before = /var/vmail/sieve/global-before.sieve
}

service lmtp {
  unix_listener /var/spool/postfix/private/dovecot-lmtp {
    mode = 0600
    user = postfix
    group = postfix
  }
}

service auth {
  unix_listener /var/spool/postfix/private/auth {
    mode = 0660
    user = postfix
    group = postfix
  }
}

service quota-status {
  executable = quota-status -p postfix
  unix_listener /var/spool/postfix/private/quota-status {
    mode = 0660
    user = postfix
    group = postfix
  }
  client_limit = 1
}

!include_try /etc/dovecot/conf.d/sni/*.conf
`

const dovecot24SQLTpl = `# Managed by LiteControl lc-mail (Dovecot 2.4+)
sql_driver = mysql
mysql {{.DBHost}} {
  dbname = {{.DBName}}
  user = {{.DBUser}}
  password = {{.DBPassword}}
}

passdb sql {
  default_password_scheme = {{.PasswordScheme}}
  query = SELECT email AS user, password_hash AS password \
    FROM v_dovecot_users \
    WHERE email = '%{user}' \
      AND (('%{protocol}' = 'imap' AND allow_imap = 1) \
        OR ('%{protocol}' = 'pop3' AND allow_pop3 = 1) \
        OR ('%{protocol}' = 'smtp' AND allow_smtp = 1) \
        OR ('%{protocol}' IN ('lmtp', 'sieve', 'managesieve') AND allow_imap = 1))
}

userdb sql {
  query = SELECT '/var/vmail/%{user | domain}/%{user | username}' AS home, \
    5000 AS uid, 5000 AS gid, \
    CONCAT(quota_mb, 'M') AS quota_storage_size \
    FROM v_dovecot_users WHERE email = '%{user}'
  iterate_query = SELECT email AS user FROM v_dovecot_users
}
`

const dovecot24CoreTpl = `# Managed by LiteControl lc-mail (Dovecot 2.4+)
dovecot_config_version = 2.4.0
dovecot_storage_version = 2.4.0

protocols = imap pop3 lmtp sieve
ssl = required
auth_allow_cleartext = no
ssl_server_cert_file = {{.FallbackCert}}
ssl_server_key_file = {{.FallbackKey}}
login_trusted_networks = 127.0.0.1

mail_driver = maildir
mail_path = /var/vmail/%{user | domain}/%{user | username}/Maildir
mail_uid = 5000
mail_gid = 5000

quota_status_success = DUNNO
quota_status_nouser = DUNNO
quota_status_overquota = "552 5.2.2 Mailbox is full"

service lmtp {
  unix_listener /var/spool/postfix/private/dovecot-lmtp {
    mode = 0600
    user = postfix
    group = postfix
  }
}

service auth {
  unix_listener /var/spool/postfix/private/auth {
    mode = 0660
    user = postfix
    group = postfix
  }
}

service quota-status {
  executable = quota-status -p postfix
  unix_listener /var/spool/postfix/private/quota-status {
    mode = 0660
    user = postfix
    group = postfix
  }
  client_limit = 1
}

!include_try /etc/dovecot/conf.d/auth-sql.conf.ext
!include_try /etc/dovecot/conf.d/sni/*.conf
`

const rspamdDkimSigningTpl = `# Managed by LiteControl lc-mail (Rspamd-Light)
enabled = true;
sign_authenticated = true;
sign_local = false;
allow_username_mismatch = false;
allow_hdrfrom_mismatch = false;
use_domain = "envelope";
selector_map = "/etc/rspamd/local.d/lc_dkim_selectors.map";
path = "/var/lib/rspamd/dkim/$domain.$selector.key";
`

const rspamdRedisTpl = `# Managed by LiteControl lc-mail (Dedicated lc-rspamd-redis socket)
servers = "/run/lc-rspamd-redis/redis.sock";
`

const rspamdRatelimitTpl = `# Managed by LiteControl lc-mail (Outbound ratelimit per authenticated user & local UID)
rates {
  user = {
    bucket = {
      burst = 100;
      rate = "100 / 1h";
    }
  }
  local_uid = {
    selector = "uid";
    bucket = {
      burst = 50;
      rate = "50 / 1h";
    }
  }
}
`

const rspamdWorkerProxyTpl = `# Managed by LiteControl lc-mail
bind_socket = "127.0.0.1:11332";
milter = yes;
timeout = 120s;
upstream "local" {
  default = yes;
  self_scan = yes;
}
`

const rspamdWorkerNormalTpl = `# Managed by LiteControl lc-mail
bind_socket = "127.0.0.1:11333";
allow_file_and_shm_inputs = false;
`

const rspamdWorkerControllerTpl = `# Managed by LiteControl lc-mail (Disabled by default)
enabled = false;
bind_socket = "127.0.0.1:11334";
`

const lcRspamdRedisConfTpl = `# Managed by LiteControl lc-mail (Dedicated Rspamd Redis — isolated from Laravel Redis)
port 0
unixsocket /run/lc-rspamd-redis/redis.sock
unixsocketperm 660
maxmemory 32mb
maxmemory-policy volatile-ttl
save ""
appendonly no
daemonize no
supervised systemd
`

const lcRspamdRedisServiceTpl = `# Managed by LiteControl lc-mail
[Unit]
Description=LiteControl Dedicated Rspamd Redis Instance
After=network.target

[Service]
Type=notify
User=root
Group={{.RspamdUser}}
RuntimeDirectory=lc-rspamd-redis
RuntimeDirectoryMode=0750
ExecStart={{.RedisBinary}} /etc/lc-rspamd-redis/redis.conf
Restart=on-failure
RestartSec=3

[Install]
WantedBy=multi-user.target
`
