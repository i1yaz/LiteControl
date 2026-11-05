driver = mysql
connect = host=127.0.0.1 dbname={{DB_NAME}} user={{DB_USER}} password={{DB_PASS}}
default_pass_scheme = ARGON2ID
password_query = SELECT email as user, password FROM email_users WHERE email='%u' AND status=1

