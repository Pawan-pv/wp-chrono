#!/usr/bin/env bash
# Phase 4: White-Screen-of-Death recovery over real HTTP.
#
# Breaks the site with a fatal plugin and then a fatal theme, and repairs it
# using only the standalone Emergency Recovery Script (WordPress never loads).
#
#   bash tests/recovery-checks.sh [base_url]
#
# DESTRUCTIVE. Only run against dev-env.
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1


BASE="${1:-http://localhost:8088}"
ERS_PASS='Recovery-Pass-2026!'
JAR="$(mktemp)"; BODY="$(mktemp)"; WPJAR="$(mktemp)"
FAILS=0; PASSES=0
trap 'rm -f "$JAR" "$BODY" "$WPJAR"' EXIT

check() { # label, exit-code, extra
  if [ "$2" -eq 0 ]; then echo "PASS  $1${3:+  ($3)}"; PASSES=$((PASSES + 1)); else echo "FAIL  $1${3:+  ($3)}"; FAILS=$((FAILS + 1)); fi
}
wpx()   { MSYS_NO_PATHCONV=1 docker compose exec -T wp "$@"; }
# WP-CLI that still works while the site is broken.
wpsafe() { wpx wp --skip-plugins --skip-themes "$@" 2>/dev/null; }
code()  { curl -s -o /dev/null -w '%{http_code}' "$@"; }
ers()   { curl -s -b "$JAR" -c "$JAR" -o "$BODY" -w '%{http_code}' "$@"; }
csrf()  { grep -o 'name="csrf" value="[^"]*"' "$BODY" | head -1 | cut -d'"' -f4; }
# POST an authenticated action, follow the 303 back to the console.
act()   { local a="$1"; shift; ers -L --data-urlencode "action=$a" --data-urlencode "csrf=$(csrf)" "$@" "$ERS"; }
wp_admin_ok() { # log in to WordPress as chrono and load /wp-admin/
  rm -f "$WPJAR"
  curl -s -c "$WPJAR" -b 'wordpress_test_cookie=WP%20Cookie%20check' -o /dev/null --data-urlencode "log=${1:-chrono}" --data-urlencode "pwd=${2:-chrono-pass}" -d 'testcookie=1' "$BASE/wp-login.php"
  code -b "$WPJAR" "$BASE/wp-admin/"
}

echo "== Setup =="
ERS="$(wpx wp --user=chrono chrono recovery generate --password="$ERS_PASS" --porcelain 2>/dev/null | tr -d '\r')"
[[ "$ERS" =~ /wp-chrono-recovery-[a-z0-9]{16}\.php$ ]]; check 'recovery script generated' $? "$ERS"
ERS="${ERS/http:\/\/localhost:8088/$BASE}"
C="$(code "$BASE/wp-content/plugins/wp-chrono/includes/Recovery/ers-template.php")"
T="$(curl -s -o /dev/null -w '%{content_type}' "$BASE/wp-content/plugins/wp-chrono/includes/Recovery/ers-template.php.txt")"
[ "$C" = 404 ] && [[ "$T" != text/html* ]]; check 'template inside the plugin is a non-executable .php.txt (never run as PHP)' $? "no .php copy (HTTP $C); .php.txt served as ${T:-nothing}"
grep -q 'WPC_ERS_ARMED' <(wpx sh -c "cat /var/www/html/$(basename "$ERS")"); check 'generated script is armed' $?
! wpx sh -c "grep -q \"$ERS_PASS\" /var/www/html/$(basename "$ERS")"; check 'plain password is not stored in the script' $?

SNAP="$(wpx wp --user=chrono chrono snapshot create --label=healthy 2>/dev/null >/dev/null; wpx wp --user=chrono chrono snapshot list --format=ids 2>/dev/null | tr ' ' '\n' | sort -n | tail -1 | tr -d '\r')"
[ -n "$SNAP" ]; check 'healthy snapshot taken' $? "#$SNAP"
C="$(code "$BASE/")"; [ "$C" = 200 ]; check 'site healthy before the crash' $? "HTTP $C"

echo; echo "== Inject a fatal plugin (WSOD) =="
wpx sh -c 'mkdir -p /var/www/html/wp-content/plugins/wpc-crasher && printf "%s\n" "<?php" "/* Plugin Name: WPC Crasher */" "throw new Error(\"Simulated WSOD\");" > /var/www/html/wp-content/plugins/wpc-crasher/wpc-crasher.php'
wpsafe eval 'update_option("active_plugins", array_values(array_unique(array_merge((array) get_option("active_plugins"), array("wpc-crasher/wpc-crasher.php")))));'
C="$(code "$BASE/")"; [ "$C" = 500 ]; check 'front end is dead' $? "HTTP $C"
C="$(code "$BASE/wp-admin/")"; [ "$C" = 500 ]; check 'wp-admin is dead' $? "HTTP $C"

echo; echo "== Emergency Recovery Script while WordPress is down =="
C="$(ers "$ERS")"; [ "$C" = 200 ] && grep -q 'Unlock recovery console' "$BODY"; check 'ERS login page loads (HTTP 200) with WordPress dead' $? "HTTP $C"
C="$(ers --data-urlencode action=login --data-urlencode "csrf=$(csrf)" --data-urlencode password=wrong "$ERS")"; [ "$C" = 401 ] && grep -q 'Incorrect password' "$BODY"; check 'wrong password rejected' $? "HTTP $C"
C="$(ers -L --data-urlencode action=login --data-urlencode "csrf=$(csrf)" --data-urlencode "password=$ERS_PASS" "$ERS")"; [ "$C" = 200 ] && grep -q 'Crash diagnostics' "$BODY"; check 'correct password opens the console' $? "HTTP $C"
grep -q 'Simulated WSOD' "$BODY"; check 'debug.log tail shows the fatal error' $?
grep -q 'likely culprit: plugin <code>wpc-crasher</code>' "$BODY"; check 'culprit plugin identified' $?
C="$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -d 'action=plugins_off' "$ERS")"; [ "$C" = 403 ]; check 'action without CSRF token rejected' $? "HTTP $C"

act plugin_toggle --data-urlencode plugin=wpc-crasher/wpc-crasher.php >/dev/null
grep -q 'Deactivated wpc-crasher/wpc-crasher.php' "$BODY"; check 'crashing plugin deactivated from the ERS' $?
C="$(code "$BASE/")"; [ "$C" = 200 ]; check 'front end back (HTTP 200)' $? "HTTP $C"
C="$(wp_admin_ok)"; [ "$C" = 200 ]; check 'wp-admin back (HTTP 200, logged in)' $? "HTTP $C"

act plugin_toggle --data-urlencode plugin=wpc-crasher/wpc-crasher.php >/dev/null
C="$(code "$BASE/")"; [ "$C" = 500 ]; check 'plugin toggled back on from the ERS (site dies again)' $? "HTTP $C"

echo; echo "== Snapshot rollback from the ERS =="
act snapshot_restore --data-urlencode "snapshot=$SNAP" --data-urlencode confirm=RESTORE >/dev/null
grep -q "Restored snapshot #$SNAP" "$BODY"; check 'atomic restore from tracking table' $? "$(grep -o "Restored snapshot #[^<]*" "$BODY" | head -1)"
C="$(code "$BASE/")"; [ "$C" = 200 ]; check 'site healthy after rollback' $? "HTTP $C"

# Damage the tracking tables; snapshots must be rebuilt from table COMMENTs.
wpsafe eval 'global $wpdb; $wpdb->query("RENAME TABLE {$wpdb->prefix}wpchrono_snapshots TO wpc_bak_s, {$wpdb->prefix}wpchrono_snapshot_tables TO wpc_bak_t");'
wpsafe eval 'update_option("active_plugins", array_values(array_unique(array_merge((array) get_option("active_plugins"), array("wpc-crasher/wpc-crasher.php")))));'
C="$(code "$BASE/")"; [ "$C" = 500 ]; check 'crash re-injected with tracking tables gone' $? "HTTP $C"
ers "$ERS" >/dev/null
grep -q 'table comments' "$BODY" && grep -q "#$SNAP" "$BODY"; check 'snapshots reconstructed from JSON table comments' $?
act snapshot_restore --data-urlencode "snapshot=$SNAP" --data-urlencode confirm=RESTORE >/dev/null
grep -q "Restored snapshot #$SNAP" "$BODY"; check 'atomic restore from reconstructed snapshot' $?
C="$(code "$BASE/")"; [ "$C" = 200 ]; check 'site healthy after comment-based rollback' $? "HTTP $C"
wpsafe eval 'global $wpdb; $wpdb->query("RENAME TABLE wpc_bak_s TO {$wpdb->prefix}wpchrono_snapshots, wpc_bak_t TO {$wpdb->prefix}wpchrono_snapshot_tables");'

echo; echo "== Fatal theme =="
wpx sh -c 'mkdir -p /var/www/html/wp-content/themes/wpc-broken && printf "%s\n" "/*" "Theme Name: WPC Broken" "*/" > /var/www/html/wp-content/themes/wpc-broken/style.css && printf "%s\n" "<?php" "throw new Error(\"Broken theme\");" > /var/www/html/wp-content/themes/wpc-broken/functions.php && echo "<?php" > /var/www/html/wp-content/themes/wpc-broken/index.php'
wpsafe eval 'update_option("template", "wpc-broken"); update_option("stylesheet", "wpc-broken");'
C="$(code "$BASE/")"; [ "$C" = 500 ]; check 'broken theme kills the site' $? "HTTP $C"
ers "$ERS" >/dev/null
act theme_switch --data-urlencode theme=twentytwentyfive >/dev/null
grep -q 'Switched theme to Twenty Twenty-Five' "$BODY"; check 'switched to a default theme from the ERS' $?
C="$(code "$BASE/")"; [ "$C" = 200 ]; check 'site healthy after theme switch' $? "HTTP $C"

echo; echo "== URL repair & emergency admin =="
wpsafe option update siteurl 'http://broken.invalid' >/dev/null; wpsafe option update home 'http://broken.invalid' >/dev/null
ers "$ERS" >/dev/null
act urls_fix --data-urlencode "siteurl=$BASE" --data-urlencode "home=$BASE" >/dev/null
[ "$(wpsafe option get siteurl | tr -d '\r')" = "$BASE" ] && [ "$(wpsafe option get home | tr -d '\r')" = "$BASE" ]; check 'siteurl / home repaired' $?

act admin_create --data-urlencode login=ers-admin --data-urlencode email=ers@example.test --data-urlencode 'password=Emergency-Admin-1!' >/dev/null
grep -q 'Administrator ers-admin created' "$BODY"; check 'emergency administrator created' $?
C="$(wp_admin_ok ers-admin 'Emergency-Admin-1!')"; [ "$C" = 200 ]; check 'emergency admin can log in to wp-admin' $? "HTTP $C"
wpsafe user get ers-admin --field=roles | grep -q administrator; check 'emergency admin has the administrator role' $?

echo; echo "== Session security =="
sed -i 's/\(wpc_ers\t[^.]*\.\).*/\1Zm9yZ2Vk/' "$JAR"
ers "$ERS" >/dev/null; grep -q 'Unlock recovery console' "$BODY"; check 'tampered session cookie is rejected' $?

for i in 1 2 3 4 5; do ers --data-urlencode action=login --data-urlencode "csrf=$(csrf)" --data-urlencode password="wrong-$i" "$ERS" >/dev/null; done
C="$(ers --data-urlencode action=login --data-urlencode "csrf=$(csrf)" --data-urlencode "password=$ERS_PASS" "$ERS")"
[ "$C" = 429 ] && grep -q 'Too many failed attempts' "$BODY"; check 'lockout after 5 failures blocks even the right password' $? "HTTP $C"
wpx sh -c 'rm -f /tmp/wpc-ers-*.json'

C="$(ers -L --data-urlencode action=login --data-urlencode "csrf=$(csrf)" --data-urlencode "password=$ERS_PASS" "$ERS")"; [ "$C" = 200 ]; check 'login works again once the window clears' $? "HTTP $C"
ers -d "action=self_destruct" --data-urlencode "csrf=$(csrf)" "$ERS" >/dev/null
C="$(code "$ERS")"; [ "$C" = 404 ]; check 'self-destruct removes the script' $? "HTTP $C"

# Cleanup
wpx sh -c 'rm -rf /var/www/html/wp-content/plugins/wpc-crasher /var/www/html/wp-content/themes/wpc-broken'
wpsafe user delete ers-admin --yes >/dev/null

echo; [ "$FAILS" -eq 0 ] && echo "All checks passed ($PASSES passed)" || echo "$FAILS check(s) FAILED ($PASSES passed)"
exit "$FAILS"
