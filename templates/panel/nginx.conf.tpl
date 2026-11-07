# ==============================================================================
# LiteControl Out-of-Band Panel Web Server Configuration (Nginx)
# Managed by LiteControl (/usr/local/litecontrol/bin/lc-cli)
# ==============================================================================

user litecontrol litecontrol;
worker_processes auto;
pid /run/litecontrol-nginx.pid;
error_log /var/log/litecontrol/panel-error.log warn;

events {
    worker_connections 1024;
    multi_accept on;
}

http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;

    access_log /var/log/litecontrol/panel-access.log;

    sendfile on;
    tcp_nopush on;
    tcp_nodelay on;
    keepalive_timeout 65;
    types_hash_max_size 2048;
    server_tokens off;
    client_max_body_size 256M;

    # Temp paths scoped to /tmp so unprivileged litecontrol worker never hits permission errors
    client_body_temp_path /tmp/litecontrol-nginx-body;
    proxy_temp_path       /tmp/litecontrol-nginx-proxy;
    fastcgi_temp_path     /tmp/litecontrol-nginx-fastcgi;
    uwsgi_temp_path       /tmp/litecontrol-nginx-uwsgi;
    scgi_temp_path        /tmp/litecontrol-nginx-scgi;

    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types text/plain text/css text/xml application/json application/javascript application/xml+rss image/svg+xml;

    server {
        listen __PANEL_PORT__ ssl http2;
        listen [::]:__PANEL_PORT__ ssl http2;
        server_name _;

        ssl_certificate     /etc/litecontrol/ssl/fullchain.pem;
        ssl_certificate_key /etc/litecontrol/ssl/privkey.pem;
        ssl_protocols       TLSv1.2 TLSv1.3;
        ssl_prefer_server_ciphers on;
        ssl_session_cache   shared:PanelSSL:10m;
        ssl_session_timeout 1d;

        # Automatically redirect plain HTTP requests sent to the HTTPS panel port
        error_page 497 https://$host:$server_port$request_uri;

        # Security Headers
        add_header X-Frame-Options "SAMEORIGIN" always;
        add_header X-Content-Type-Options "nosniff" always;
        add_header Referrer-Policy "strict-origin-when-cross-origin" always;

        root /usr/local/litecontrol/panel/public;
        index index.php index.html;

        charset utf-8;

        # ----------------------------------------------------------------------
        # 1. phpMyAdmin Sub-Application (/phpmyadmin/)
        # ----------------------------------------------------------------------
        location ^~ /phpmyadmin/ {
            alias /usr/local/litecontrol/phpmyadmin/;
            index index.php;

            location ~ ^/phpmyadmin/(.+\.php)$ {
                alias /usr/local/litecontrol/phpmyadmin/$1;
                fastcgi_pass unix:/run/litecontrol-php-fpm.sock;
                fastcgi_index index.php;
                include /etc/litecontrol/nginx/fastcgi_params;
                fastcgi_param SCRIPT_FILENAME $request_filename;
                fastcgi_read_timeout 300;
            }

            location ~* ^/phpmyadmin/(.+\.(jpg|jpeg|gif|css|png|js|ico|html|xml|txt|woff|woff2|ttf|svg))$ {
                alias /usr/local/litecontrol/phpmyadmin/$1;
                expires 7d;
                access_log off;
            }
        }

        location = /phpmyadmin {
            return 301 /phpmyadmin/;
        }

        # ----------------------------------------------------------------------
        # 2. LiteControl Laravel + Inertia.js Panel Application (/)
        # ----------------------------------------------------------------------
        location / {
            try_files $uri $uri/ /index.php?$query_string;
        }

        location = /favicon.ico { access_log off; log_not_found off; }
        location = /robots.txt  { access_log off; log_not_found off; }

        error_page 404 /index.php;

        location ~ \.php$ {
            fastcgi_pass unix:/run/litecontrol-php-fpm.sock;
            fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
            include /etc/litecontrol/nginx/fastcgi_params;
            fastcgi_hide_header X-Powered-By;
            fastcgi_read_timeout 300;
            fastcgi_buffers 16 16k;
            fastcgi_buffer_size 32k;
        }

        location ~ /\.(?!well-known).* {
            deny all;
        }
    }
}

