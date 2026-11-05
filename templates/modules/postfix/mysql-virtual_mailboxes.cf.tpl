user = {{DB_USER}}
password = {{DB_PASS}}
hosts = 127.0.0.1
dbname = {{DB_NAME}}
query = SELECT maildir FROM email_users WHERE email='%s' AND status=1

