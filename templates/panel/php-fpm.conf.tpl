[global]
pid = /run/litecontrol-php-fpm.pid
error_log = /var/log/litecontrol/panel-php-error.log
log_level = warning
daemonize = no

[litecontrol]
user = litecontrol
group = litecontrol
listen = /run/litecontrol-php-fpm.sock
listen.owner = litecontrol
listen.group = litecontrol
listen.mode = 0660

pm = ondemand
pm.max_children = 15
pm.process_idle_timeout = 10s
pm.max_requests = 500

chdir = /usr/local/litecontrol/panel

env[PATH] = /usr/local/bin:/usr/bin:/bin
env[TMP] = /tmp
env[TMPDIR] = /tmp
env[TEMP] = /tmp

php_admin_value[upload_max_filesize] = 256M
php_admin_value[post_max_size] = 256M
php_admin_value[memory_limit] = 256M
php_admin_value[max_execution_time] = 300
php_admin_value[expose_php] = Off
php_admin_flag[log_errors] = on
php_admin_value[error_log] = /var/log/litecontrol/panel-php-error.log

