docRoot                   __DOC_ROOT__
vhDomain                  __DOMAIN__
vhAliases                 www.__DOMAIN__
adminEmails               __ADMIN_EMAIL__
enableGzip                1
enableBr                  1

errorlog __VH_LOG_DIR__/error.log {
  useServer               0
  logLevel                ERROR
  rollingSize             10M
  keepDays                14
}

accesslog __VH_LOG_DIR__/access.log {
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
  add                     lsapi:__PHP_HANDLER__ php
}

extprocessor __VH_NAME___php {
  type                    lsapi
  address                 uds://tmp/lshttpd/__VH_NAME___php.sock
  maxConns                10
  env                     PHP_LSAPI_CHILDREN=10
  env                     LSAPI_AVOID_FORK=200M
  initTimeout             60
  retryTimeout            0
  persistConn             1
  respBuffer              0
  autoStart               2
  path                    /usr/local/lsws/__PHP_VERSION__/bin/lsphp
  backlog                 100
  instances               1
  extUser                 __SYS_USER__
  extGroup                __SYS_GROUP__
  memSoftLimit            2047M
  memHardLimit            2047M
  procSoftLimit           400
  procHardLimit           500
}

accessControl  {
  allow                   *
}

context / {
  location                $DOC_ROOT/
  allowBrowse             1

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
  keyFile                 __SSL_KEY__
  certFile                __SSL_CERT__
  certChain               1
  sslProtocol             24
  enableECDHE             1
  renegProtection         1
  sslSessionCache         1
  enableSpdy              15
}

