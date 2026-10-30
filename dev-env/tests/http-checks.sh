#!/usr/bin/env bash
# Real-browser-style checks over HTTP: cookie login, nonce extraction from the
# admin page, REST auth, zero front-end footprint, and session survival across
# a full reset (new auth cookie + fresh nonces must keep working).
#
#   bash tests/http-checks.sh [base_url] [user] [password]
#
# DESTRUCTIVE: performs a full site reset. Only run against dev-env.
set -uo pipefail

BASE="${1:-http://localhost:8088}"
USER="${2:-chrono}"
PASS="${3:-chrono-pass}"
JAR="$(mktemp)"
BODY="$(mktemp)"
FAILS=0
trap 'rm -f "$JAR" "$BODY"' EXIT

check() { # label, condition-exit-code, extra
  if [ "$2" -eq 0 ]; then echo "PASS  $1${3:+  ($3)}"; else echo "FAIL  $1${3:+  ($3)}"; FAILS=$((FAILS + 1)); fi
}

nonces() { # prints "rest chrono" from the WP Chrono admin page
  local page
  page="$(curl -s -b "$JAR" -c "$JAR" "$BASE/wp-admin/admin.php?page=wp-chrono")"
  echo "$(echo "$page" | grep -o '"restNonce":"[^"]*"' | cut -d'"' -f4) $(echo "$page" | grep -o '"chronoNonce":"[^"]*"' | cut -d'"' -f4)"
}

api() { # method, route, rest-nonce, chrono-nonce, [json]
  curl -s -o "$BODY" -w '%{http_code}' -b "$JAR" -c "$JAR" -X "$1" \
    -H "X-WP-Nonce: $3" -H "X-WP-Chrono-Nonce: $4" -H 'Content-Type: application/json' \
    ${5:+--data "$5"} "$BASE/?rest_route=/wp-chrono/v1/$2"
}

echo "== Front-end footprint =="
HOME_HTML="$(curl -s "$BASE/")"
echo "$HOME_HTML" | grep -qi 'wp-chrono'
[ $? -ne 0 ]; check 'public homepage references no wp-chrono asset' $?

echo; echo "== Login & nonces =="
curl -s -c "$JAR" -b "wordpress_test_cookie=WP%20Cookie%20check" -o /dev/null \
  --data-urlencode "log=$USER" --data-urlencode "pwd=$PASS" -d 'wp-submit=Log+In&testcookie=1' "$BASE/wp-login.php"
read -r REST CHRONO <<<"$(nonces)"
[ -n "$REST" ] && [ -n "$CHRONO" ]; check 'admin page exposes both nonces' $? "rest=$REST chrono=$CHRONO"

ADMIN_HTML="$(curl -s -b "$JAR" "$BASE/wp-admin/index.php")"
echo "$ADMIN_HTML" | grep -q 'wp-chrono/build/index.js'
[ $? -ne 0 ]; check 'other admin screens do not load the SPA bundle' $?

echo; echo "== REST auth over HTTP =="
CODE="$(api GET status "$REST" "$CHRONO")"; [ "$CODE" = 200 ]; check 'GET status with nonces' $? "HTTP $CODE"
CODE="$(api GET status "$REST" '')"; [ "$CODE" = 403 ]; check 'GET status without chrono nonce' $? "HTTP $CODE"
CODE="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/?rest_route=/wp-chrono/v1/status")"
[ "$CODE" = 401 ] || [ "$CODE" = 403 ]; check 'anonymous GET status' $? "HTTP $CODE"
CODE="$(api GET reset/preview "$REST" "$CHRONO")"; [ "$CODE" = 200 ]; check 'GET reset/preview' $? "HTTP $CODE"

echo; echo "== Full reset keeps the session alive =="
CODE="$(api POST reset/full "$REST" "$CHRONO" '{"confirm":"RESET","keep_theme":true}')"
[ "$CODE" = 200 ]; check 'POST reset/full' $? "HTTP $CODE"
NEW_REST="$(grep -o '"rest":"[^"]*"' "$BODY" | cut -d'"' -f4)"
NEW_CHRONO="$(grep -o '"chrono":"[^"]*"' "$BODY" | cut -d'"' -f4)"
[ -n "$NEW_REST" ] && [ -n "$NEW_CHRONO" ]; check 'response carries fresh nonces' $?

CODE="$(api GET status "$NEW_REST" "$NEW_CHRONO")"; [ "$CODE" = 200 ]; check 'fresh nonces + re-issued cookie accepted after reset' $? "HTTP $CODE"
CODE="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/wp-admin/admin.php?page=wp-chrono")"
[ "$CODE" = 200 ]; check 'still signed in to wp-admin after reset' $? "HTTP $CODE"

echo; echo "== Snapshot restore keeps the session alive =="
REST="$NEW_REST"; CHRONO="$NEW_CHRONO"
CODE="$(api POST snapshots "$REST" "$CHRONO" '{"label":"http-checks"}')"
SNAP="$(grep -o '"id":[0-9]*' "$BODY" | head -1 | cut -d: -f2)"
[ "$CODE" = 201 ] && [ -n "$SNAP" ]; check 'POST snapshots' $? "HTTP $CODE, #$SNAP"
TICKS=0
until grep -q '"done":true' "$BODY" || [ "$TICKS" -ge 200 ]; do
  api POST "snapshots/$SNAP/build" "$REST" "$CHRONO" >/dev/null; TICKS=$((TICKS + 1))
done
grep -q '"done":true' "$BODY"; check 'snapshot built over HTTP' $? "$TICKS ticks"

CODE="$(api POST "snapshots/$SNAP/restore" "$REST" "$CHRONO" '{"confirm":"RESTORE"}')"
JOB="$(grep -o '"job":"[a-z0-9]*"' "$BODY" | cut -d'"' -f4)"
[ "$CODE" = 200 ] && [ -n "$JOB" ]; check 'restore job started' $? "HTTP $CODE, job $JOB"
TICKS=0
until grep -q '"done":true' "$BODY" || [ "$TICKS" -ge 200 ]; do
  CODE="$(api POST snapshots/restore/tick "$REST" "$CHRONO" "{\"job\":\"$JOB\",\"confirm\":\"RESTORE\"}")"; TICKS=$((TICKS + 1))
  [ "$CODE" = 200 ] || break
done
grep -q '"phase":"swapped"' "$BODY"; check 'atomic swap completed' $? "HTTP $CODE, $(grep -o '"swap_ms":[0-9]*' "$BODY")"
RESTORE_REST="$(grep -o '"rest":"[^"]*"' "$BODY" | cut -d'"' -f4)"
RESTORE_CHRONO="$(grep -o '"chrono":"[^"]*"' "$BODY" | cut -d'"' -f4)"
[ -n "$RESTORE_REST" ] && [ -n "$RESTORE_CHRONO" ]; check 'restore response carries fresh nonces' $?
CODE="$(api GET status "$RESTORE_REST" "$RESTORE_CHRONO")"; [ "$CODE" = 200 ]; check 'fresh nonces accepted after restore (no 403)' $? "HTTP $CODE"
CODE="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/wp-admin/admin.php?page=wp-chrono")"
[ "$CODE" = 200 ]; check 'still signed in to wp-admin after restore' $? "HTTP $CODE"

echo; [ "$FAILS" -eq 0 ] && echo 'All checks passed' || echo "$FAILS check(s) FAILED"
exit "$FAILS"
