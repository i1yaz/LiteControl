# ==============================================================================
# LiteControl System VirtualHost: Panel Reverse Proxy (lc_panel_proxy)
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

extprocessor lc_panel_backend {
  type                    proxy
  address                 https://127.0.0.1:__PANEL_PORT__
  maxConns                100
  pcKeepAliveTimeout      60
  initTimeout             60
  retryTimeout            0
  respBuffer              0
}

context /.well-known/acme-challenge/ {
  location                $DOC_ROOT/.well-known/acme-challenge/
  allowBrowse             1
  rewrite  {
    enable                0
  }
  addDefaultCharset       off
}

context / {
  type                    proxy
  handler                 lc_panel_backend
  addDefaultCharset       off
}

rewrite  {
  enable                  1
  autoLoadHtaccess        0
  rules                   <<<END_rules
RewriteCond %{HTTPS} !=on
RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]
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
