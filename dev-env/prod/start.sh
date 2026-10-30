#!/bin/sh
# Boots MariaDB + PHP-FPM (as the unprivileged "nginx" user, OPcache on) + nginx
# inside the wp-chrono-prod image. WP-CLI runs as the same user as PHP-FPM, so
# files it writes have the ownership a real site would have.
set -e

WP=/var/www/html
WEB_USER=nginx
cat > /usr/local/bin/wp <<EOF
#!/bin/sh
export HOME=/tmp/wp-cli-home
exec su-exec $WEB_USER php -d memory_limit=512M /cache/wp-cli.phar --path=$WP "\$@"
EOF
chmod +x /usr/local/bin/wp
mkdir -p /tmp/wp-cli-home && chown $WEB_USER /tmp/wp-cli-home

# --- MariaDB -----------------------------------------------------------------
mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
if [ ! -d /var/lib/mysql/mysql ]; then
  mariadb-install-db --user=mysql --datadir=/var/lib/mysql >/dev/null
fi
mariadbd --user=mysql --datadir=/var/lib/mysql --skip-networking=0 --port=3306 --bind-address=127.0.0.1 >/var/log/mariadb.log 2>&1 &
until mariadb -uroot -e 'SELECT 1' >/dev/null 2>&1; do sleep 1; done
mariadb -uroot -e "CREATE DATABASE IF NOT EXISTS wordpress;
  CREATE USER IF NOT EXISTS 'wp'@'127.0.0.1' IDENTIFIED BY 'wp';
  GRANT ALL ON wordpress.* TO 'wp'@'127.0.0.1'; FLUSH PRIVILEGES;"

# --- PHP-FPM: typical shared / VPS limits --------------------------------------
cat > /etc/php83/conf.d/99-host.ini <<'INI'
memory_limit = 256M
max_execution_time = 30
upload_max_filesize = 8M
post_max_size = 8M
opcache.enable = 1
opcache.validate_timestamps = 1
opcache.revalidate_freq = 2
INI
cat > /etc/php83/php-fpm.d/www.conf <<EOF
[www]
user = $WEB_USER
group = $WEB_USER
listen = /run/php-fpm.sock
listen.owner = $WEB_USER
listen.group = $WEB_USER
pm = dynamic
pm.max_children = 6
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
clear_env = no
catch_workers_output = yes
EOF

# --- nginx: the usual WordPress server block, stock 1 MB body limit -----------
cat > /etc/nginx/http.d/default.conf <<'NGINX'
server {
	listen 80 default_server;
	root /var/www/html;
	index index.php;
	client_max_body_size 1m;

	location / {
		try_files $uri $uri/ /index.php?$args;
	}
	# The hardening rule readme.txt recommends for nginx hosts. Regex locations
	# match in file order, so it must come before the PHP block.
	location ~* "^/wp-content/wp-chrono-[A-Za-z0-9]{16}/" {
		deny all;
	}
	location ~ \.php$ {
		try_files $uri =404;
		fastcgi_pass unix:/run/php-fpm.sock;
		fastcgi_index index.php;
		include fastcgi_params;
		fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
		fastcgi_read_timeout 60s;
	}
}
NGINX

# --- WordPress ---------------------------------------------------------------
if [ ! -f "$WP/wp-settings.php" ]; then
  tar -xzf "/cache/${WP_TARBALL:-wordpress.tar.gz}" -C "$WP" --strip-components=1
fi
# Everything but the bind-mounted plugin is owned by the PHP user, as on a host.
find "$WP" -path "$WP/wp-content/plugins/wp-chrono" -prune -o -exec chown $WEB_USER:$WEB_USER {} +
if [ ! -f "$WP/wp-config.php" ]; then
  wp config create --dbname=wordpress --dbuser=wp --dbpass=wp --dbhost=127.0.0.1 --skip-salts --skip-check \
    --extra-php <<'PHP'
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'FS_METHOD', 'direct' );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
PHP
fi
if ! wp core is-installed 2>/dev/null; then
  wp core install --url=http://localhost:8088 --title='Chrono Dev' --admin_user=admin \
    --admin_password=admin --admin_email=admin@example.test --skip-email
  wp user create chrono chrono@example.test --role=administrator --user_pass=chrono-pass
  wp rewrite structure '/%postname%/' --hard
  wp plugin activate wp-chrono
fi

php-fpm83 -D
echo "WordPress ready at http://localhost:8088 (chrono / chrono-pass) — nginx + PHP-FPM + OPcache"
exec nginx -g 'daemon off;'
