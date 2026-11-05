# Postfix Main Configuration for LiteControl
smtpd_banner = $myhostname ESMTP LiteControl
biff = no
append_dot_mydomain = no
readme_directory = no

compatibility_level = 2

# TLS parameters
smtpd_tls_cert_file = {{SSL_CERT}}
smtpd_tls_key_file = {{SSL_KEY}}
smtpd_tls_security_level = may
smtpd_tls_session_cache_database = btree:${data_directory}/smtpd_scache
smtp_tls_security_level = may
smtp_tls_session_cache_database = btree:${data_directory}/smtp_scache
smtpd_tls_protocols = !SSLv2, !SSLv3, !TLSv1, !TLSv1.1
smtp_tls_protocols = !SSLv2, !SSLv3, !TLSv1, !TLSv1.1

myhostname = {{HOSTNAME}}
alias_maps = hash:/etc/aliases
alias_database = hash:/etc/aliases
mydestination = localhost.$mydomain, localhost
relayhost = 
mynetworks = 127.0.0.0/8 [::ffff:127.0.0.0]/104 [::1]/128
mailbox_size_limit = 0
recipient_delimiter = +
inet_interfaces = all
inet_protocols = all

# Virtual Mailbox & Domain Settings
virtual_mailbox_domains = mysql:/etc/postfix/mysql-virtual_domains.cf
virtual_mailbox_maps = mysql:/etc/postfix/mysql-virtual_mailboxes.cf
virtual_alias_maps = mysql:/etc/postfix/mysql-virtual_forwardings.cf
virtual_transport = lmtp:unix:private/dovecot-lmtp

# Dovecot SASL Authentication
smtpd_sasl_type = dovecot
smtpd_sasl_path = private/auth
smtpd_sasl_auth_enable = yes
smtpd_recipient_restrictions =
    permit_mynetworks,
    permit_sasl_authenticated,
    reject_unauth_destination

