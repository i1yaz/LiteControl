# Pure-FTPd MySQL Configuration Template for LiteControl
MYSQLSocket      /var/run/mysqld/mysqld.sock
MYSQLUser        {{DB_USER}}
MYSQLPassword    {{DB_PASS}}
MYSQLDatabase    {{DB_NAME}}
MYSQLCrypt       argon2

MYSQLGetPW       SELECT password FROM ftp_accounts WHERE status = 1 AND username = '\L'
MYSQLGetUID      SELECT uid FROM ftp_accounts WHERE status = 1 AND username = '\L'
MYSQLGetGID      SELECT gid FROM ftp_accounts WHERE status = 1 AND username = '\L'
MYSQLGetDir      SELECT dir FROM ftp_accounts WHERE status = 1 AND username = '\L'
MySQLGetQTASZ    SELECT quota_mb FROM ftp_accounts WHERE status = 1 AND username = '\L'

