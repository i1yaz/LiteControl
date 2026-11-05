docRoot                   $VH_ROOT/html/default
enableGzip                1

errorlog $VH_ROOT/logs/error.log {
  useServer               1
  logLevel                WARN
  rollingSize             10M
}

accesslog $VH_ROOT/logs/access.log {
  useServer               0
  rollingSize             10M
  keepDays                30
  compressArchive         0
}

index  {
  useServer               0
  indexFiles              index.html, index.php
  autoIndex               0
}

expires  {
  enableExpires           1
}

accessControl  {
  allow                   *
}

context / {
  location                $DOC_ROOT/
  allowBrowse             1

  rewrite  {
    RewriteFile           .htaccess
  }
}

context /blocked/ {
  allowBrowse             0
}

rewrite  {
  enable                  1
  autoLoadHtaccess        1
}

