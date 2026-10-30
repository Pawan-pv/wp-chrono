#!/usr/bin/env bash
# Milestone 1 end-to-end over real HTTP: a bad plugin update (v2.0 changes a
# database row AND fatals) is undone completely — v1.0 files + v1.0 database —
# first through the REST API, then through the standalone Emergency Recovery
# Script while WordPress is completely dead.
#
#   bash tests/update-rollback-checks.sh [base_url]
#
# DESTRUCTIVE. Only run against dev-env.
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

BASE="${1:-http://localhost:8088}"
ERS_PASS='Rollback-Pass-2026!'
JAR="$(mktemp)"; BODY="$(mktemp)"; WPJAR="$(mktemp)"
FAILS=0; PASSES=0
trap 'rm -f "$JAR" "$BODY" "$WPJAR"' EXIT

check() { # label, exit-code, extra
  if [ "$2" -eq 0 ]; then echo "PASS  $1${3:+  ($3)}"; PASSES=$((PASSES + 1)); else echo "FAIL  $1${3:+  ($3)}"; FAILS=$((FAILS + 1)); fi
}
wpx()    { MSYS_NO_PATHCONV=1 docker compose exec -T wp "$@"; }
# WP-CLI that works while wpc-bad fatals on load.
wpsafe() { wpx wp --skip-plugins=wpc-bad "$@" 2>/dev/null; }
code()   { curl -s -o /dev/null -w '%{http_code}' "$@"; }
field()  { grep -o "^$1=.*" | head -1 | cut -d= -f2 | tr -d '\r'; }
disk_version() { wpsafe eval 'echo get_file_data( WP_PLUGIN_DIR . "/wpc-bad/wpc-bad.php", array( "v" => "Version" ) )["v"];' | tr -d '\r'; }
marker() { wpsafe option get wpc_bad_marker | tr -d '\r'; }
wp_login() { # user, pass → cookie jar
  rm -f "$WPJAR"
  curl -s -c "$WPJAR" -b 'wordpress_test_cookie=WP%20Cookie%20check' -o /dev/null --data-urlencode "log=$1" --data-urlencode "pwd=$2" -d 'testcookie=1' "$BASE/wp-login.php"
}
api() { # method, route, [json]
  curl -s -o "$BODY" -w '%{http_code}' -b "$WPJAR" -c "$WPJAR" -X "$1" \
    -H "X-WP-Nonce: $REST" -H "X-WP-Chrono-Nonce: $CHRONO" -H 'Content-Type: application/json' \
    ${3:+--data "$3"} "$BASE/?rest_route=/wp-chrono/v1/$2"
}
json() { grep -o "\"$1\":\"\?[^,\"}]*" "$BODY" | head -1 | sed 's/.*:"\{0,1\}//'; }

# ---------------------------------------------------------------------------
echo "== Bad update #1: DB change + front-end fatal → undo over the REST API =="
OUT="$(wpx wp --user=chrono eval-file /tests/rollback-fixture.php front 2>/dev/null)"
SNAP="$(echo "$OUT" | field SNAP)"
[ "$(echo "$OUT" | field UPGRADED)" = yes ] && [ "$(echo "$OUT" | field VERSION)" = 2.0 ]; check 'real Plugin_Upgrader updated wpc-bad 1.0 → 2.0' $?
[ "$(echo "$OUT" | field ARCHIVES)" = 1 ] && [ "$SNAP" -gt 0 ]; check 'pre-update auto-snapshot holds the v1.0 folder archive' $? "#$SNAP"
C="$(code "$BASE/")"; [ "$C" = 500 ]; check 'v2.0 crashes the front end' $? "HTTP $C"
[ "$(marker)" = v2 ]; check 'v2.0 changed the database (wpc_bad_marker = v2)' $?

wp_login chrono chrono-pass
PAGE="$(curl -s -b "$WPJAR" -c "$WPJAR" "$BASE/wp-admin/admin.php?page=wp-chrono")"
REST="$(echo "$PAGE" | grep -o '"restNonce":"[^"]*"' | cut -d'"' -f4)"
CHRONO="$(echo "$PAGE" | grep -o '"chronoNonce":"[^"]*"' | cut -d'"' -f4)"
[ -n "$REST" ] && [ -n "$CHRONO" ]; check 'wp-admin still reachable, nonces read' $?

C="$(api POST "snapshots/$SNAP/restore" '{"confirm":"RESTORE","mode":"full"}')"
JOB="$(json job)"
[ "$C" = 200 ] && [ -n "$JOB" ]; check 'POST /snapshots/{id}/restore mode=full: files staged, job started' $? "HTTP $C, job $JOB"
for _ in $(seq 1 200); do
  grep -q '"done":true' "$BODY" && break
  C="$(api POST snapshots/restore/tick "{\"job\":\"$JOB\",\"confirm\":\"RESTORE\"}")"
  [ "$C" = 200 ] || break
done
grep -q '"done":true' "$BODY" && [ "$(json files_restored)" = 1 ]; check 'atomic table swap + folder swap completed' $? "files_restored=$(json files_restored), swap $(json swap_ms) ms"
grep -q 'Reverted plugin wpc-bad to v1.0' "$BODY"; check 'restore log reports the reverted plugin' $?
[ "$(disk_version)" = 1.0 ]; check 'plugin files back to v1.0 on disk' $? "v$(disk_version)"
M="$(marker)"; SQLM="$(wpx mariadb -uroot -N -e "SELECT option_value FROM wordpress.wp_options WHERE option_name='wpc_bad_marker'" 2>/dev/null | tr -d '\r')"
[ "$M" = v1 ]; check 'database back to v1 (wpc_bad_marker = v1)' $? "wp-cli: '${M}', SQL: '${SQLM}'"
C="$(code "$BASE/")"; [ "$C" = 200 ]; check 'front end healthy again' $? "HTTP $C"
MAINT="$(wpx sh -c 'test -e /var/www/html/.maintenance && echo left || echo gone' | tr -d '')"
[ "$MAINT" = gone ]; check 'maintenance window closed after the swap (.maintenance removed)' $? "$MAINT"
NEW_REST="$(json rest)"; NEW_CHRONO="$(json chrono)"
REST="$NEW_REST"; CHRONO="$NEW_CHRONO"
C="$(api GET status)"; [ "$C" = 200 ]; check 'fresh nonces from the restore keep the session alive' $? "HTTP $C"

# ---------------------------------------------------------------------------
echo; echo "== Bad update #2: DB change + fatal on EVERY request → undo from the ERS =="
OUT="$(wpx wp --user=chrono eval-file /tests/rollback-fixture.php hard 2>/dev/null)"
SNAP="$(echo "$OUT" | field SNAP)"
[ "$(echo "$OUT" | field VERSION)" = 2.0 ] && [ "$(echo "$OUT" | field ARCHIVES)" = 1 ]; check 'wpc-bad updated again (v1.0 archived)' $? "#$SNAP"
ERS="$(wpsafe --user=chrono chrono recovery generate --password="$ERS_PASS" --porcelain | tr -d '\r')"
ERS="${ERS/http:\/\/localhost:8088/$BASE}"
C="$(code "$BASE/")"; [ "$C" = 500 ]; check 'front end dead (WSOD)' $? "HTTP $C"
C="$(code "$BASE/wp-admin/")"; [ "$C" = 500 ]; check 'wp-admin dead' $? "HTTP $C"
C="$(code "$BASE/?rest_route=/wp-chrono/v1/status")"; [ "$C" = 500 ]; check 'REST API dead too — only the ERS can help' $? "HTTP $C"
[ "$(marker)" = v2 ]; check 'v2.0 changed the database again' $?

ers()  { curl -s -b "$JAR" -c "$JAR" -o "$BODY" -w '%{http_code}' "$@"; }
csrf() { grep -o 'name="csrf" value="[^"]*"' "$BODY" | head -1 | cut -d'"' -f4; }
act()  { local a="$1"; shift; ers -L --data-urlencode "action=$a" --data-urlencode "csrf=$(csrf)" "$@" "$ERS"; }
ers "$ERS" >/dev/null
C="$(ers -L --data-urlencode action=login --data-urlencode "csrf=$(csrf)" --data-urlencode "password=$ERS_PASS" "$ERS")"
[ "$C" = 200 ] && grep -q 'Crash diagnostics' "$BODY"; check 'ERS console opens with WordPress dead' $? "HTTP $C"
grep -q 'Undo last update (files + database)' "$BODY" && grep -q "<code>#$SNAP</code>" "$BODY"; check 'ERS offers "Undo last update" for the pre-update snapshot' $?
grep -A3 '<code>wpc-bad</code>' "$BODY" | grep -q 'crashing'; check 'ERS links the crashing plugin to the update' $?
grep -q 'Undo Last Update (Files + DB)' "$BODY"; check 'one-click "Undo Last Update (Files + DB)" button' $?

act undo_update --data-urlencode "snapshot=$SNAP" --data-urlencode confirm=RESTORE >/dev/null
grep -q 'Undid the update: reverted 1 of 1' "$BODY"; check 'ERS undo: folder + database swapped' $? "$(grep -o 'Undid the update[^<]*' "$BODY" | head -1 | cut -c1-140)"
[ "$(disk_version)" = 1.0 ]; check 'plugin files back to v1.0 on disk' $? "v$(disk_version)"
M="$(marker)"; SQLM="$(wpx mariadb -uroot -N -e "SELECT option_value FROM wordpress.wp_options WHERE option_name='wpc_bad_marker'" 2>/dev/null | tr -d '')"
[ "$M" = v1 ]; check 'database back to v1' $? "wp-cli: '${M}', SQL: '${SQLM}'"
MAINT="$(wpx sh -c 'test -e /var/www/html/.maintenance && echo left || echo gone' | tr -d '')"
[ "$MAINT" = gone ]; check 'ERS maintenance window closed (.maintenance removed)' $? "$MAINT"
C="$(code "$BASE/")"; [ "$C" = 200 ]; check 'front end back (HTTP 200)' $? "HTTP $C"
wp_login chrono chrono-pass
C="$(code -b "$WPJAR" "$BASE/wp-admin/")"; [ "$C" = 200 ]; check 'wp-admin back (HTTP 200, logged in)' $? "HTTP $C"
LEFT="$(wpx sh -c 'ls -d /var/www/html/wp-content/plugins/.wpc-* /var/www/html/wp-content/upgrade/wpc-rollback-* 2>/dev/null | wc -l' | tr -d '\r ')"
[ "$LEFT" = 0 ]; check 'no staging leftovers' $? "$LEFT"

ers -d "action=self_destruct" --data-urlencode "csrf=$(csrf)" "$ERS" >/dev/null

# Cleanup
wpsafe plugin deactivate wpc-bad >/dev/null
wpx sh -c 'rm -rf /var/www/html/wp-content/plugins/wpc-bad'
wpsafe option delete wpc_bad_marker >/dev/null

echo; [ "$FAILS" -eq 0 ] && echo "All checks passed ($PASSES passed)" || echo "$FAILS check(s) FAILED ($PASSES passed)"
exit "$FAILS"
