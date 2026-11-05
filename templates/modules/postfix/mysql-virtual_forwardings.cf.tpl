user = {{DB_USER}}
password = {{DB_PASS}}
hosts = 127.0.0.1
dbname = {{DB_NAME}}
query = SELECT destination FROM email_forwardings WHERE source='%s' AND status=1

