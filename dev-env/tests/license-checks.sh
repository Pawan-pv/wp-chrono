#!/usr/bin/env bash
# Phase 6: licensing. Starts the offline mock license server, runs
# license-checks.php, then exercises `wp chrono license …`. Leaves a test Pro
# license active (later suites use Blueprints + Cloud).
#
#   bash tests/license-checks.sh
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1
wpx() { MSYS_NO_PATHCONV=1 docker compose exec -T wp "$@"; }

wpx sh -c 'pkill -f "[m]ock-license.php"; rm -f /tmp/wpc-mock-license.log; true'
MSYS_NO_PATHCONV=1 docker compose exec -d wp sh -c 'php -S 127.0.0.1:9100 /tests/mock-license.php >/tmp/wpc-mock-license-server.log 2>&1'
sleep 1

OUT="$(wpx wp eval-file /tests/license-checks.php --user=chrono 2>&1)"
echo "$OUT" | grep -vE '^(All checks passed|[0-9]+ check\(s\) FAILED)'
FAILS="$(echo "$OUT" | grep -c '^FAIL')"; PASSES="$(echo "$OUT" | grep -c '^PASS')"
check() { if [ "$2" -eq 0 ]; then echo "PASS  $1${3:+  ($3)}"; PASSES=$((PASSES + 1)); else echo "FAIL  $1${3:+  ($3)}"; FAILS=$((FAILS + 1)); fi; }

echo; echo "== WP-CLI =="
W() { wpx wp --user=chrono "$@" 2>&1; }
KEY="$(wpx cat /tmp/wpc-test-license.key)"
W chrono license deactivate | grep -q 'stay free'; check 'wp chrono license deactivate' $?
OUT="$(W chrono blueprint create 'Locked')"; echo "$OUT" | grep -q 'Pro feature'; check 'CLI blueprint create blocked without a license' $?
OUT="$(W chrono license activate 'WPC1.bad.key')"; echo "$OUT" | grep -q 'damaged\|not valid'; check 'CLI rejects an invalid key' $?
W chrono license activate "$KEY" | grep -q 'License activated: pro plan (appsumo)'; check 'wp chrono license activate <key>' $?
W chrono license status | grep -q 'unlocked'; check 'wp chrono license status shows unlocked features' $?
REQS="$(wpx sh -c 'wc -l < /tmp/wpc-mock-license.log 2>/dev/null || echo 0' | tr -d '\r ')"
[ "$REQS" -ge 5 ]; check 'mock license server answered real HTTP calls' $? "$REQS requests"

wpx sh -c 'pkill -f "[m]ock-license.php"; true'
echo; [ "$FAILS" -eq 0 ] && echo "All checks passed ($PASSES passed)" || echo "$FAILS check(s) FAILED ($PASSES passed)"
exit "$FAILS"
