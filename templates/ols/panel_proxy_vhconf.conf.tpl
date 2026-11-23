# ==============================================================================
# LiteControl System VirtualHost: Panel ACME & Redirect (lc_panel_proxy)
# Serves ACME HTTP-01 challenges on port 80 and redirects to the out-of-band
# Nginx panel on port __PANEL_PORT__ so OLS reloads never interrupt panel requests.
# Managed by LiteControl (/usr/local/litecontrol/bin/lc-cli ssl panel-ssl)
# ==============================================================================

docRoot                   __ACME_WEBROOT__
vhDomain                  __PANEL_DOMAIN__
adminEmails               root@localhost
enableGzip                1

index  {
  useServer               0
  indexFiles              index.html
}

accessControl  {
  allow                   *
}

context /.well-known/acme-challenge/ {
  location                $DOC_ROOT/.well-known/acme-challenge/
  allowBrowse             1
  rewrite  {
    enable                0
  }
  addDefaultCharset       off
}

rewrite  {
  enable                  1
  autoLoadHtaccess        0
  rules                   <<<END_rules
RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
RewriteRule ^(.*)$ https://__PANEL_DOMAIN__:__PANEL_PORT__/$1 [R=301,L]
  END_rules
}

vhssl  {
  keyFile                 __SSL_KEY__
  certFile                __SSL_CERT__
  certChain               1
  sslProtocol             24
  ciphers                 ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384
  enableECDHE             1
  renegProtection         1
  sslSessionCache         1
  enableSpdy              15
  enableQuic              1
}
