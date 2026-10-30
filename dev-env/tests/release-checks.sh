#!/usr/bin/env bash
# Phase 6: builds dist/wp-chrono-<version>.zip with scripts/build-release.sh,
# inspects it, installs it into a brand-new WordPress (own database, offline
# tarball) with `wp plugin install <zip> --activate`, exercises the installed
# copy, runs the plugin audit on it, and uninstalls it cleanly.
#
#   bash tests/release-checks.sh [--skip-build]
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1
FAILS=0; PASSES=0
check() { if [ "$2" -eq 0 ]; then echo "PASS  $1${3:+  ($3)}"; PASSES=$((PASSES + 1)); else echo "FAIL  $1${3:+  ($3)}"; FAILS=$((FAILS + 1)); fi; }
wpx() { MSYS_NO_PATHCONV=1 docker compose exec -T wp "$@"; }
REL=/var/www/rel
relwp() { wpx php -d memory_limit=512M /cache/wp-cli.phar --allow-root --path="$REL" --url=http://rel.test "$@" 2>&1; }
# DB queries: stderr dropped (the mysql client warns about its deprecated program name).
relq()  { wpx php -d memory_limit=512M /cache/wp-cli.phar --allow-root --path="$REL" --url=http://rel.test db query "$1" --skip-column-names 2>/dev/null | tr -d '\r '; }

echo "== Build the release zip =="
VERSION="$(sed -n "s/^define( 'WP_CHRONO_VERSION', '\([^']*\)' );/\1/p" ../wp-chrono/wp-chrono.php)"
ZIP="../wp-chrono/dist/wp-chrono-$VERSION.zip"
OUT="$(bash ../wp-chrono/scripts/build-release.sh "${1:-}" 2>&1)"; CODE=$?
[ "$CODE" = 0 ] && [ -s "$ZIP" ]; check "scripts/build-release.sh produced dist/wp-chrono-$VERSION.zip" $? "$(echo "$OUT" | tail -1 | cut -c1-90)"
[ "$VERSION" = "$(sed -n 's/^Stable tag: *//p' ../wp-chrono/readme.txt | tr -d '\r')" ]; check "version $VERSION matches the readme Stable tag" $? "$VERSION"
sha256sum -c "$ZIP.sha256" --status 2>/dev/null || (cd "$(dirname "$ZIP")" && sha256sum -c "$(basename "$ZIP").sha256" --status); check 'SHA-256 checksum file matches' $?

wpx sh -c 'cat > /tmp/wp-chrono-release.zip' < "$ZIP"
LIST="$(wpx php -r '$z = new ZipArchive(); $z->open("/tmp/wp-chrono-release.zip"); for ($i = 0; $i < $z->numFiles; $i++) echo $z->getNameIndex($i), "\n";' | tr -d '\r')"
! echo "$LIST" | grep -qvE '^wp-chrono(/|$)'; check 'every entry sits under wp-chrono/ with forward slashes' $?
! echo "$LIST" | grep -qE '(^|/)(src|node_modules|dev-env|tests?|scripts|\.git|dist)(/|$)|\.map$|\.tsx?$|package(-lock)?\.json$'; check 'zip excludes src/, node_modules/, dev-env/, tests/, scripts/, .git/, source maps' $?
for f in wp-chrono.php uninstall.php readme.txt build/index.js build/index.css languages/wp-chrono.pot includes/Recovery/ers-template.php.txt; do
  echo "$LIST" | grep -qx "wp-chrono/$f" || { echo "FAIL  zip contains $f"; FAILS=$((FAILS + 1)); continue; }
done
check 'zip contains plugin file, readme, compiled SPA, POT and recovery template' 0 "$(echo "$LIST" | grep -vc '/$') files"

echo; echo "== Install the zip on a fresh WordPress (offline) =="
wpx sh -c "
  set -e
  mariadb -uroot -e \"DROP DATABASE IF EXISTS wordpress_rel; CREATE DATABASE wordpress_rel; GRANT ALL ON wordpress_rel.* TO 'wp'@'127.0.0.1'; FLUSH PRIVILEGES;\"
  rm -rf $REL && mkdir -p $REL && tar -xzf \"/cache/${WP_TARBALL:-wordpress.tar.gz}\" -C $REL --strip-components=1
" || check 'fresh WordPress unpacked' 1
relwp config create --dbname=wordpress_rel --dbuser=wp --dbpass=wp --dbhost=127.0.0.1 --skip-check >/dev/null
relwp config set WP_HTTP_BLOCK_EXTERNAL true --raw >/dev/null
relwp core install --url=http://rel.test --title='Release Test' --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email >/dev/null
[ "$(relwp plugin list --field=name | grep -c wp-chrono)" = 0 ]; check 'fresh site has no WP Chrono yet' $?
OUT="$(relwp plugin install /tmp/wp-chrono-release.zip --activate)"
echo "$OUT" | grep -q 'Plugin installed successfully' && echo "$OUT" | grep -q "Plugin 'wp-chrono' activated"; check "wp plugin install dist/wp-chrono-$VERSION.zip --activate" $? "$(echo "$OUT" | tail -1)"
[ "$(relwp plugin get wp-chrono --field=status)" = active ] && [ "$(relwp plugin get wp-chrono --field=version)" = "$VERSION" ]; check "plugin active at version $VERSION" $?
[ "$(relq "SHOW TABLES LIKE 'wp_wpchrono_snapshots'")" = wp_wpchrono_snapshots ]; check 'activation created the tracking tables' $?
ROUTES="$(relwp eval 'echo count( preg_grep( "#^/wp-chrono/v1/#", array_keys( rest_get_server()->get_routes() ) ) );' | tr -d '\r')"
relwp eval 'exit( isset( rest_get_server()->get_routes()["/wp-chrono/v1/snapshots/import/chunk"] ) && isset( rest_get_server()->get_routes()["/wp-chrono/v1/license"] ) ? 0 : 1 );' >/dev/null; check 'REST API registered' $? "$ROUTES routes"
relwp option get wp_chrono_onboarding | grep -q 'welcome_seen'; check 'first activation queues the welcome checklist' $?
OUT="$(relwp --user=admin chrono snapshot create --label='Release smoke')"; echo "$OUT" | grep -q 'ready and verified'; check 'wp chrono snapshot create (verified) on the installed copy' $?
ID="$(relwp --user=admin chrono snapshot list --format=ids | tr ' ' '\n' | sort -n | tail -1 | tr -d '\r')"
relwp option update blogname 'Changed after snapshot' >/dev/null
OUT="$(relwp --user=admin chrono snapshot restore "$ID" --yes)"; echo "$OUT" | grep -q 'atomic swap' && [ "$(relwp option get blogname | tr -d '\r')" = 'Release Test' ]; check 'restore on the installed copy' $?
OUT="$(relwp --user=admin chrono license status)"; echo "$OUT" | grep -q 'locked (Pro)' && echo "$OUT" | grep -q 'recovery_script'; check 'fresh install: free tier, restore features free' $?
OUT="$(relwp --user=admin chrono recovery generate --porcelain)"; echo "$OUT" | grep -q 'wp-chrono-recovery-'; check 'recovery script generates from the .php.txt template' $?
relwp --user=admin chrono recovery delete >/dev/null

echo; echo "== Audit of the installed copy =="
AUDIT="$(wpx php /tests/plugin-audit.php "$REL/wp-content/plugins/wp-chrono")"
echo "$AUDIT" | grep -E '^(PASS|FAIL|NOTE)'
FAILS=$((FAILS + $(echo "$AUDIT" | grep -c '^FAIL'))); PASSES=$((PASSES + $(echo "$AUDIT" | grep -c '^PASS')))
if wpx test -f /cache/plugin-check.zip; then
  relwp plugin install /cache/plugin-check.zip --activate >/dev/null
  # Rows are "line,column,type,code,message", grouped under "FILE: <path>" lines.
  PC="$(relwp plugin check wp-chrono --format=csv --fields=line,column,type,code,message 2>&1 | tr -d '\r')"
  echo "$PC" | grep -E '^FILE:|,(ERROR|WARNING),' | sed 's/^/      /'
  # plugin_updater_detected is expected: this build updates from the WP Chrono API
  # (Update URI). A wordpress.org build must ship without includes/Services/Updater.php.
  ERRS="$(echo "$PC" | grep ',ERROR,' | grep -vc ',plugin_updater_detected,')"; WARNS="$(echo "$PC" | grep -c ',WARNING,')"
  echo "$PC" | grep -q ',plugin_updater_detected,' && echo "NOTE  Plugin Check flags the self-hosted updater (expected; not allowed on wordpress.org)"
  echo "$PC" | grep -qE '^FILE:|No errors found|Checks complete' || echo "$PC" | tail -5 | sed 's/^/      /'
  [ "$ERRS" = 0 ]; check 'official Plugin Check: no errors' $? "$ERRS errors, $WARNS warnings"
  relwp plugin deactivate plugin-check >/dev/null; relwp plugin delete plugin-check >/dev/null
else
  echo "NOTE  official Plugin Check not run (drop plugin-check.zip into dev-env/cache/ to enable)"
fi

echo; echo "== Uninstall with \"keep snapshots\" on =="
relwp chrono snapshot create --label=keep-test >/dev/null 2>&1
relwp eval '$s = WPChrono\Services\Settings::update( array( "keep_data_on_uninstall" => true ) ); echo $s["keep_data_on_uninstall"] ? "on" : "off";' >/dev/null
relwp chrono recovery generate --password=Keep-Test-Pass-2026 --porcelain >/dev/null 2>&1
ERS_FILE="$(relq "SELECT option_value FROM wp_options WHERE option_name='wp_chrono_recovery'" | grep -o 'wp-chrono-recovery-[a-z0-9]*\.php' | head -1)"
KEPT_BEFORE="$(relq "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '\\_wpc\\_%'")"
relwp plugin deactivate wp-chrono >/dev/null; relwp plugin uninstall wp-chrono >/dev/null
KEPT_AFTER="$(relq "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '\\_wpc\\_%'")"
[ "${KEPT_BEFORE:-0}" -gt 0 ] && [ "$KEPT_AFTER" = "$KEPT_BEFORE" ] && [ "$(relq "SELECT COUNT(*) FROM wp_wpchrono_snapshots")" -gt 0 ]; check 'keep_data_on_uninstall: snapshot copies and records survive deletion' $? "$KEPT_BEFORE → $KEPT_AFTER copies"
[ -n "$ERS_FILE" ] && ! wpx test -e "$REL/$ERS_FILE"; check 'the Emergency Recovery Script is removed even when data is kept' $? "$ERS_FILE"
relwp plugin install /tmp/wp-chrono-release.zip --activate >/dev/null
N="$(relwp chrono snapshot list --format=ids | wc -w)"
[ "${N:-0}" -gt 0 ]; check 'reinstall picks the kept snapshots up again' $? "$N snapshots"
relwp eval 'WPChrono\Services\Settings::update( array( "keep_data_on_uninstall" => false ) );' >/dev/null

echo; echo "== Another WordPress install in the same database is never touched =="
# A neighbour install with prefix wp_shop_ (its tables also start with "wp_"),
# with its own WP Chrono snapshot copy and tracking table.
relq "CREATE TABLE wp_shop_options (option_id int); CREATE TABLE wp_shop_posts (ID int); CREATE TABLE wp_shop_postmeta (meta_id int); CREATE TABLE wp_shop_term_taxonomy (term_taxonomy_id int); CREATE TABLE wp_shop_wpchrono_snapshot_tables (id int); CREATE TABLE \`_wpc_7_$(printf '%s' wp_shop_posts | md5sum | cut -c1-8)_wp_shop_posts\` (ID int) COMMENT='{\"wpc\":1,\"snap\":\"7\",\"orig\":\"wp_shop_posts\"}'; INSERT INTO wp_shop_posts VALUES (1);" >/dev/null
OWN="$(relwp eval 'echo WPChrono\Services\Database::belongs_to_site( "wp_shop_posts" ) ? "mine" : "foreign";' | tr -d '\r')"
[ "$OWN" = foreign ]; check 'wp_shop_* recognised as another install (not in snapshots / resets)' $? "$OWN"
INSITE="$(relwp eval 'echo isset( WPChrono\Services\Database::site_tables()["wp_shop_posts"] ) ? "listed" : "excluded";' | tr -d '\r')"
[ "$INSITE" = excluded ]; check 'site_tables() excludes the neighbour install' $? "$INSITE"

echo; echo "== Uninstall leaves nothing behind =="
STORAGE="$(relwp option get wp_chrono_storage_dir | tr -d '\r')"
# Transients are stored as _transient_<name> / _site_transient_<name>, outside the wp_chrono_ prefix.
relwp eval 'set_transient( "wp_chrono_license_check", time(), HOUR_IN_SECONDS ); set_transient( "_wpchrono_auto_snap_lock", array(), HOUR_IN_SECONDS ); set_site_transient( "wp_chrono_update_info", array( "version" => "" ), HOUR_IN_SECONDS );' >/dev/null
relwp plugin deactivate wp-chrono >/dev/null; relwp plugin uninstall wp-chrono >/dev/null
LEFT="$(relq "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND (TABLE_NAME LIKE '\\_wpc\\_%' OR TABLE_NAME LIKE '%wpchrono%') AND TABLE_NAME NOT LIKE '%wp\\_shop\\_%'")"
NEIGHBOUR="$(relq "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%wp\\_shop\\_%'")"
[ "$NEIGHBOUR" = 6 ] && [ "$(relq "SELECT COUNT(*) FROM wp_shop_posts")" = 1 ]; check "uninstall kept the neighbour install's tables and its snapshot copy" $? "$NEIGHBOUR of 6 tables"
OPTS="$(relq "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE '%wp\\_chrono%' OR option_name LIKE '%\\_wpchrono\\_%'")"
[ "$LEFT" = 0 ] && [ "$OPTS" = 0 ] && ! wpx test -d "$REL/wp-content/$STORAGE" && ! wpx test -d "$REL/wp-content/plugins/wp-chrono"; check 'uninstall removed tables, snapshot copies, options, transients, storage and files' $? "tables $LEFT, options $OPTS"

wpx sh -c "rm -rf $REL /tmp/wp-chrono-release.zip; mariadb -uroot -e 'DROP DATABASE IF EXISTS wordpress_rel;'"
echo; [ "$FAILS" -eq 0 ] && echo "All checks passed ($PASSES passed)" || echo "$FAILS check(s) FAILED ($PASSES passed)"
exit "$FAILS"
