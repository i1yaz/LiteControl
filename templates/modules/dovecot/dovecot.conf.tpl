# Dovecot Master Configuration for LiteControl
protocols = imap pop3 lmtp

listen = *, ::

mail_location = maildir:/var/vmail/%d/%n

mail_uid = 5000
mail_gid = 5000

auth_mechanisms = plain login

ssl = yes
ssl_cert = <{{SSL_CERT}}
ssl_key = <{{SSL_KEY}}
ssl_min_protocol = TLSv1.2

passdb {
  driver = sql
  args = /etc/dovecot/dovecot-sql.conf.ext
}

userdb {
  driver = static
  args = uid=5000 gid=5000 home=/var/vmail/%d/%n
}

service lmtp {
  unix_listener /var/spool/postfix/private/dovecot-lmtp {
    mode = 0660
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

