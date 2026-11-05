docRoot                   /usr/local/litecontrol/panel/public
enableGzip                1
enableBr                  1

errorlog /var/log/litecontrol/panel-error.log {
  useServer               0
  logLevel                WARN
  rollingSize             10M
}

accesslog /var/log/litecontrol/panel-access.log {
  useServer               0
  rollingSize             10M
  keepDays                30
  compressArchive         1
}

index  {
  useServer               0
  indexFiles              index.php, index.html
  autoIndex               0
}

scripthandler  {
  add                     lsapi:litecontrol_php php
}

accessControl  {
  allow                   *
}

context /phpmyadmin/ {
  location                /usr/local/litecontrol/phpmyadmin/
  allowBrowse             1
  indexFiles              index.php

  accessControl  {
    allow                 *
  }
  addDefaultCharset       off
}

context / {
  location                $DOC_ROOT/
  allowBrowse             1
  indexFiles              index.php

  rewrite  {
    enable                1
    inherit               1
    RewriteFile           .htaccess
  }
}

rewrite  {
  enable                  1
  autoLoadHtaccess        1
}

vhssl  {
  keyFile                 /etc/litecontrol/ssl/privkey.pem
  certFile                /etc/litecontrol/ssl/fullchain.pem
  certChain               1
  sslProtocol             24
  enableECDHE             1
  renegProtection         1
  sslSessionCache         1
  enableSpdy              15
}

