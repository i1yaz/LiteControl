# ==============================================================================
# LiteControl System VirtualHost: Standalone Mail ACME Challenge (lc_mail_acme)
# Serves only /.well-known/acme-challenge/ on port 80 for mail.<domain> TLS.
# Managed by LiteControl (/usr/local/litecontrol/bin/lc-cli ssl mail-ssl)
# ==============================================================================

docRoot                   __ACME_WEBROOT__
vhDomain                  *
adminEmails               root@localhost
enableGzip                0

accessControl  {
  allow                   *
}

context /.well-known/acme-challenge/ {
  location                __ACME_WEBROOT__/.well-known/acme-challenge/
  allowBrowse             1
  rewrite  {
    enable                0
  }
  addDefaultCharset       off
  accessControl  {
    allow                 *
  }
}

context / {
  location                __ACME_WEBROOT__
  allowBrowse             0
  accessControl  {
    deny                  *
  }
}
