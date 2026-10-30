#!/usr/bin/env bash
# Runs every WP Chrono check end-to-end on a fresh, offline stack and prints a
# result matrix. No external network is used: images are local, WordPress has
# WP_HTTP_BLOCK_EXTERNAL on, and the browser test drives an installed Chrome.
#
#   bash tests/autonomous-full-suite.sh
#   SUITES='^(license-checks|rollback-checks)' bash tests/autonomous-full-suite.sh
#
# SUITES (extended regex, matched against the suite names printed in the
# matrix) runs a subset in the usual order — CI splits the suite this way. The
# fresh stack and the offline proof always run. Blueprints, cloud, the UI and
# the CLI block need the test Pro license that license-checks.sh leaves active.
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1
ROOT="$(cd .. && pwd)"
LOGDIR="tests/artifacts/logs"
mkdir -p "$LOGDIR"
SUITES="${SUITES:-}"

declare -a NAMES RESULTS
TOTAL_FAIL=0
SELECTED=0

record() { # name, logfile, exit-code, seconds
  local pass fail
  pass=$(grep -cE '^PASS' "$2" || true)
  fail=$(grep -cE '^FAIL' "$2" || true)
  if [ "$3" -ne 0 ] && [ "$fail" -eq 0 ]; then fail=1; fi
  TOTAL_FAIL=$((TOTAL_FAIL + fail))
  NAMES+=("$1")
  RESULTS+=("$(printf '%-6s %4s pass %3s fail %5ss' "$([ "$fail" -eq 0 ] && echo OK || echo FAILED)" "$pass" "$fail" "$4")")
  printf '  %-46s %s\n' "$1" "${RESULTS[-1]}"
}

run() { # name, command...
  local name="$1"; shift
  case "$name" in
    'Stack boots'*|'Outbound HTTP'*) ;;
    *) if [ -n "$SUITES" ] && ! echo "$name" | grep -qE "$SUITES"; then return 0; fi
       SELECTED=$((SELECTED + 1)) ;;
  esac
  local log="$LOGDIR/$(echo "$name" | tr -c 'a-zA-Z0-9' '-').log"
  local start=$SECONDS
  "$@" >"$log" 2>&1
  record "$name" "$log" $? $((SECONDS - start))
}

wpx() { MSYS_NO_PATHCONV=1 docker compose exec -T wp "$@"; }

lint() { # image, label
  if ! docker image inspect "$1" >/dev/null 2>&1; then
    echo "FAIL  image $1 missing (see dev-env/README.md)"; return 1
  fi
  MSYS_NO_PATHCONV=1 docker run --rm --pull=never -v "$(cd "$ROOT" && pwd -W 2>/dev/null || pwd):/w" -w /w "$1" sh -c '
    n=0; c=0
    for f in $(find wp-chrono/includes -name "*.php") wp-chrono/wp-chrono.php wp-chrono/uninstall.php wp-chrono/includes/Recovery/ers-template.php.txt wp-chrono/scripts/*.php; do
      c=$((c+1)); out=$(php -l "$f" 2>&1) && echo "PASS  $f" || { echo "FAIL  $out"; n=$((n+1)); }
    done
    exit $n'
}

echo "WP Chrono — autonomous offline suite"
echo "======================================"

echo; echo "Static checks"
run 'TypeScript typecheck'           sh -c "cd '$ROOT/wp-chrono' && npm run --silent typecheck && echo 'PASS  tsc --noEmit'"
run 'Production build'               sh -c "cd '$ROOT/wp-chrono' && npm run --silent build && test -s build/index.js && echo 'PASS  build/index.js'"
run 'PHP 8.3 lint'                   lint wp-chrono-dev:latest
run 'PHP 7.4 lint'                   lint wp-chrono-php74:latest

echo; echo "Fresh offline stack"
docker compose down -v >/dev/null 2>&1
docker compose up -d >/dev/null 2>&1
for _ in $(seq 1 100); do docker compose logs wp 2>&1 | grep -q 'WordPress ready' && break; sleep 3; done
run 'Stack boots (WordPress + MariaDB)' sh -c "curl -s -o /dev/null -w '%{http_code}' http://localhost:8088/ | grep -q 200 && echo 'PASS  http://localhost:8088/ 200'"
run 'Outbound HTTP blocked (offline proof)' sh -c "MSYS_NO_PATHCONV=1 docker compose exec -T wp wp eval 'echo is_wp_error(wp_remote_get(\"https://api.wordpress.org/\")) ? \"PASS  external HTTP blocked\" : \"FAIL  external HTTP allowed\";'"

echo; echo "Phase 6 — licensing (leaves a test Pro license active for later suites)"
run 'license-checks.sh (Ed25519, tiers, server)'  bash tests/license-checks.sh

echo; echo "Phase 2 — reset engine"
run 'rest-checks.php (security, cleaners, reset)' wpx wp eval-file /tests/rest-checks.php --user=chrono
run 'http-checks.sh (cookies, nonces, sessions)' bash tests/http-checks.sh

echo; echo "Phase 3 — snapshots"
run 'snapshot-checks.php (incl. FK restore)'  wpx wp eval-file /tests/snapshot-checks.php --user=chrono
run 'upgrader-checks.php (real bulk_upgrade)' wpx wp eval-file /tests/upgrader-checks.php --user=chrono
run 'stress-checks.php (240k rows, memory)'   wpx wp eval-file /tests/stress-checks.php --user=chrono

echo; echo "Milestone 1 — full update rollback (files + DB)"
run 'rollback-checks.php (modes, tamper, prune)'      wpx wp eval-file /tests/rollback-checks.php --user=chrono
run 'update-rollback-checks.sh (bad update: REST+ERS)' bash tests/update-rollback-checks.sh
run 'safe-update-checks.php (broken update undone by itself)' wpx wp eval-file /tests/safe-update-checks.php --user=chrono
run 'safe-update-woo-checks.sh (WooCommerce checkout, custom URLs, loopback)' bash tests/safe-update-woo-checks.sh
run 'core-update-checks.php (real core update, undo, Safe Updates)' wpx wp eval-file /tests/core-update-checks.php --user=chrono
run 'scheduled-checks.php (WP-Cron schedule, sliced build, retention)' wpx wp eval-file /tests/scheduled-checks.php --user=chrono

echo; echo "Milestone 2 — review-driven safety fixes"
run 'safety-checks.php (backups, verify, quota)'  wpx wp eval-file /tests/safety-checks.php --user=chrono
run 'multisite-checks.sh (wp_2_* never touched)'  bash tests/multisite-checks.sh

echo; echo "Milestone 3 — blueprints & cloud"
run 'blueprint-checks.sh (Reset to Dev Baseline)' bash tests/blueprint-checks.sh
run 'cloud-checks.sh (SigV4, mock S3 push/pull)'  bash tests/cloud-checks.sh

echo; echo "Phase 6 — local .sql.gz import"
run 'import-checks.sh (download → reset → upload back)' bash tests/import-checks.sh
run 'import-validation-checks.php (hostile SQL refused)' wpx wp eval-file /tests/import-validation-checks.php

echo; echo "UI"
run 'ui-e2e-checks.mjs (headless Chrome)'     node tests/ui-e2e-checks.mjs

echo; echo "Phase 4 — emergency recovery"
run 'recovery-checks.sh (WSOD over HTTP)'     bash tests/recovery-checks.sh

echo; echo "WP-CLI"
run 'CLI commands' sh -c '
  W() { MSYS_NO_PATHCONV=1 docker compose exec -T wp wp --user=chrono "$@" 2>&1; }
  X() { MSYS_NO_PATHCONV=1 docker compose exec -T wp "$@" 2>&1; }
  W chrono status | grep -q "Storage protected" && echo "PASS  wp chrono status" || echo "FAIL  wp chrono status"
  W chrono reset partial transients --dry-run | grep -q "Dry run" && echo "PASS  wp chrono reset partial --dry-run" || echo "FAIL  reset partial"
  W chrono snapshot create --label=cli-suite | grep -q "ready and verified" && echo "PASS  wp chrono snapshot create (verified)" || echo "FAIL  snapshot create"
  ID=$(W chrono snapshot list --format=ids | tr " " "\n" | sort -n | tail -1 | tr -d "\r")
  W chrono snapshot verify "$ID" | grep -q "verified" && echo "PASS  wp chrono snapshot verify" || echo "FAIL  snapshot verify"
  W chrono snapshot diff "$ID" | grep -q "tables unchanged" && echo "PASS  wp chrono snapshot diff" || echo "FAIL  snapshot diff"
  W chrono snapshot export "$ID" | grep -q "Exported" && echo "PASS  wp chrono snapshot export" || echo "FAIL  snapshot export"
  W chrono snapshot restore "$ID" --yes | grep -q "atomic swap" && echo "PASS  wp chrono snapshot restore" || echo "FAIL  snapshot restore"
  W chrono snapshot restore "$ID" --mode=full --yes | grep -q "no plugin or theme files" && echo "PASS  wp chrono snapshot restore --mode=full (refuses without archives)" || echo "FAIL  restore --mode=full"
  BP=$(W chrono blueprint create "CLI baseline" --porcelain | tail -1 | tr -d "\r")
  W chrono blueprint list | grep -q "CLI baseline" && echo "PASS  wp chrono blueprint create + list" || echo "FAIL  blueprint create/list"
  W chrono blueprint export "$BP" /tmp/wpc-bp.json | grep -q "Exported" && echo "PASS  wp chrono blueprint export" || echo "FAIL  blueprint export"
  BP2=$(W chrono blueprint import /tmp/wpc-bp.json --porcelain | tail -1 | tr -d "\r")
  [ -n "$BP2" ] && [ "$BP2" != "$BP" ] && echo "PASS  wp chrono blueprint import" || echo "FAIL  blueprint import"
  W chrono blueprint apply "$BP" --no-reset --yes | grep -q "Site rebuilt from blueprint" && echo "PASS  wp chrono blueprint apply" || echo "FAIL  blueprint apply"
  X sh -c "pkill -f \"[m]ock-s3.php\"; rm -rf /tmp/mock-s3; mkdir -p /tmp/mock-s3/data"
  MSYS_NO_PATHCONV=1 docker compose exec -d wp sh -c "php -S 127.0.0.1:9000 /tests/mock-s3.php >/tmp/mock-s3/server.log 2>&1"; sleep 1
  W chrono cloud configure --provider=custom --endpoint=http://127.0.0.1:9000 --region=us-east-1 --bucket=wpc-test-bucket --access-key=MOCKACCESSKEY "--secret-key=mock/Secret+Key/0123456789abcdefghij" | grep -q "stored encrypted" && echo "PASS  wp chrono cloud configure" || echo "FAIL  cloud configure"
  W chrono cloud status | grep -q "Connected to wpc-test-bucket" && echo "PASS  wp chrono cloud status (live test)" || echo "FAIL  cloud status"
  W chrono cloud push "$ID" | grep -q "Pushed snapshot" && echo "PASS  wp chrono cloud push" || echo "FAIL  cloud push"
  KEY=$(W chrono cloud remote --format=keys | head -1 | tr -d "\r")
  NEW=$(W chrono cloud pull "$KEY" --porcelain | tail -1 | tr -d "\r")
  [ "$NEW" -gt "$ID" ] 2>/dev/null && echo "PASS  wp chrono cloud remote + pull (snapshot #$NEW)" || echo "FAIL  cloud pull ($KEY → $NEW)"
  X sh -c "pkill -f \"[m]ock-s3.php\"; true"; W option delete wp_chrono_cloud >/dev/null
  W chrono license status | grep -q "unlocked" && echo "PASS  wp chrono license status (Pro test key active)" || echo "FAIL  license status"
  DUMP=$(W chrono snapshot export "$ID" | sed -n "s/^Success: Exported to \(.*\.sql\.gz\) (.*/\1/p" | tr -d "\r")
  IMP=$(W chrono snapshot import "$DUMP" --porcelain | tail -1 | tr -d "\r")
  [ "$IMP" -gt "$ID" ] 2>/dev/null && echo "PASS  wp chrono snapshot import <file> (snapshot #$IMP)" || echo "FAIL  snapshot import ($DUMP → $IMP)"
  W chrono snapshot scavenge --dry-run | grep -qE "No orphaned|Dry run" && echo "PASS  wp chrono snapshot scavenge" || echo "FAIL  scavenge"
  W chrono recovery generate --porcelain | grep -q "wp-chrono-recovery-" && echo "PASS  wp chrono recovery generate" || echo "FAIL  recovery generate"
  W chrono recovery delete | grep -q "removed" && echo "PASS  wp chrono recovery delete" || echo "FAIL  recovery delete"
  W chrono reset site --yes --keep-theme --no-snapshot | grep -q "Site reset" && echo "PASS  wp chrono reset site" || echo "FAIL  reset site"
'

echo; echo "Release package"
run 'release-checks.sh (zip → fresh install → audit)' bash tests/release-checks.sh --skip-build
run 'update-checks.sh (signed self-update, mock API)' bash tests/update-checks.sh

if [ -n "$SUITES" ] && [ "$SELECTED" -eq 0 ]; then
  echo; echo "SUITES='$SUITES' matched no suite"; TOTAL_FAIL=$((TOTAL_FAIL + 1))
fi

echo
echo "======================================"
[ "$TOTAL_FAIL" -eq 0 ] && echo "ALL SUITES PASSED" || echo "$TOTAL_FAIL FAILURE(S) — see $LOGDIR/"
exit "$TOTAL_FAIL"
