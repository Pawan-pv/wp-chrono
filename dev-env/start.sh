#!/bin/sh
# Boots MariaDB + WordPress (PHP built-in server) inside the wp-chrono-dev image.
# Salts are deliberately NOT written to wp-config.php, so they live in wp_options
# and the reset/restore code paths that preserve DB-stored salts get exercised.
set -e

WP=/var/www/html
cat > /usr/local/bin/wp <<'EOF'
#!/bin/sh
exec php -d memory_limit=512M /cache/wp-cli.phar --allow-root --path=/var/www/html "$@"
EOF
chmod +x /usr/local/bin/wp

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

# --- WordPress ---------------------------------------------------------------
if [ ! -f "$WP/wp-settings.php" ]; then
  # WP_TARBALL picks the WordPress release under test (default: cache/wordpress.tar.gz).
  tar -xzf "/cache/${WP_TARBALL:-wordpress.tar.gz}" -C "$WP" --strip-components=1
fi
if [ ! -f "$WP/wp-config.php" ]; then
  wp config create --dbname=wordpress --dbuser=wp --dbpass=wp --dbhost=127.0.0.1 --skip-salts --skip-check \
    --extra-php <<'PHP'
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'FS_METHOD', 'direct' );
// Offline by design: no outbound HTTP, so the test suites provably need no network.
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
PHP
fi
if ! wp core is-installed 2>/dev/null; then
  wp core install --url=http://localhost:8088 --title='Chrono Dev' --admin_user=admin \
    --admin_password=admin --admin_email=admin@example.test --skip-email
  # A second admin (ID 2) so ID preservation across resets is actually tested.
  wp user create chrono chrono@example.test --role=administrator --user_pass=chrono-pass
  wp plugin activate wp-chrono
fi

echo "WordPress ready at http://localhost:8088 (chrono / chrono-pass)"
PHP_CLI_SERVER_WORKERS=4 exec php -d memory_limit=512M -d upload_max_filesize=64M -S 0.0.0.0:80 -t "$WP"
