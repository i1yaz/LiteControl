# PowerDNS Main Configuration Template for LiteControl
daemon=yes
guardian=yes
setuid=pdns
setgid=pdns
local-address=0.0.0.0,::
local-port=53
webserver=no

launch=gmysql
gmysql-host=127.0.0.1
gmysql-port=3306
gmysql-user={{DB_USER}}
gmysql-password={{DB_PASS}}
gmysql-dbname={{DB_NAME}}
gmysql-dnssec=yes

default-ttl=3600
default-soa-mail=hostmaster.{{HOSTNAME}}
default-soa-name=ns1.{{HOSTNAME}}

