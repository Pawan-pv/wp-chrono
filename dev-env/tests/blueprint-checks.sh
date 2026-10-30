#!/usr/bin/env bash
# Milestone 3.1: blueprint engine (blueprint-checks.php) plus a real
# multipart upload of a plugin zip over HTTP, as the Blueprints screen does.
#
#   bash tests/blueprint-checks.sh [base_url]
#
# DESTRUCTIVE (runs a full reset). Only run against dev-env.
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

BASE="${1:-http://localhost:8088}"
JAR="$(mktemp)"; BODY="$(mktemp)"; ZIP="$(mktemp -d)/wpc-bp-gamma-0.9.zip"
trap 'rm -f "$JAR" "$BODY" "$ZIP"' EXIT
wpx() { MSYS_NO_PATHCONV=1 docker compose exec -T wp "$@"; }

OUT="$(wpx wp eval-file /tests/blueprint-checks.php --user=chrono 2>&1)"
echo "$OUT" | grep -vE '^(BLUEPRINT=|All checks passed|[0-9]+ check\(s\) FAILED)'
FAILS="$(echo "$OUT" | grep -c '^FAIL')"
PASSES="$(echo "$OUT" | grep -c '^PASS')"
ID="$(echo "$OUT" | grep -o '^BLUEPRINT=[a-z0-9]*' | cut -d= -f2)"

check() { if [ "$2" -eq 0 ]; then echo "PASS  $1${3:+  ($3)}"; PASSES=$((PASSES + 1)); else echo "FAIL  $1${3:+  ($3)}"; FAILS=$((FAILS + 1)); fi; }

echo; echo "== Upload a plugin .zip over HTTP (multipart, as the Blueprints screen does) =="
wpx cat /tmp/wpc-packages/wpc-bp-gamma-0.9.zip > "$ZIP"
[ -s "$ZIP" ]; check 'test plugin zip fetched from the container' $? "$(wc -c < "$ZIP" | tr -d ' ') bytes"
curl -s -c "$JAR" -b 'wordpress_test_cookie=WP%20Cookie%20check' -o /dev/null --data-urlencode log=chrono --data-urlencode pwd=chrono-pass -d testcookie=1 "$BASE/wp-login.php"
PAGE="$(curl -s -b "$JAR" -c "$JAR" "$BASE/wp-admin/admin.php?page=wp-chrono")"
REST="$(echo "$PAGE" | grep -o '"restNonce":"[^"]*"' | cut -d'"' -f4)"
CHRONO="$(echo "$PAGE" | grep -o '"chronoNonce":"[^"]*"' | cut -d'"' -f4)"
# (No ";type=" suffix: Git Bash would mangle the path at the ";".)
C="$(curl -s -o "$BODY" -w '%{http_code}' -b "$JAR" -H "X-WP-Nonce: $REST" -H "X-WP-Chrono-Nonce: $CHRONO" -F "package=@$ZIP" "$BASE/?rest_route=/wp-chrono/v1/blueprints/$ID/packages")"
[ "$C" = 200 ] && grep -q '"slug":"wpc-bp-gamma"' "$BODY" && grep -q '"version":"0.9"' "$BODY"; check 'POST /blueprints/{id}/packages accepts a real multipart upload' $? "HTTP $C"
EVIL="$(dirname "$ZIP")/evil.php"; echo '<?php echo "pwned";' > "$EVIL"
C="$(curl -s -o "$BODY" -w '%{http_code}' -b "$JAR" -H "X-WP-Nonce: $REST" -H "X-WP-Chrono-Nonce: $CHRONO" -F "package=@$EVIL" "$BASE/?rest_route=/wp-chrono/v1/blueprints/$ID/packages")"
[ "$C" = 400 ]; check 'non-zip upload (evil.php) rejected' $? "HTTP $C"
rm -f "$EVIL"
C="$(curl -s -o /dev/null -w '%{http_code}' -F "package=@$ZIP" "$BASE/?rest_route=/wp-chrono/v1/blueprints/$ID/packages")"
[ "$C" = 401 ] || [ "$C" = 403 ]; check 'anonymous upload rejected' $? "HTTP $C"

wpx wp --user=chrono eval "( new WPChrono\Services\BlueprintService() )->delete( '$ID' );" >/dev/null 2>&1
echo; [ "$FAILS" -eq 0 ] && echo "All checks passed ($PASSES passed)" || echo "$FAILS check(s) FAILED ($PASSES passed)"
exit "$FAILS"
