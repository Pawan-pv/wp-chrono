#!/usr/bin/env bash
# After a failed run: adds container-side logs to dev-env/tests/artifacts/logs/stack/
# so the uploaded artifact has everything (suite logs are already there).
#
#   bash wp-chrono/.github/ci/collect-logs.sh      # from the workspace
set -uo pipefail
cd dev-env || exit 0
OUT=tests/artifacts/logs/stack
mkdir -p "$OUT"
docker compose ps -a                                   >"$OUT/compose-ps.txt" 2>&1
docker compose logs --no-color --timestamps            >"$OUT/compose.log" 2>&1
docker compose exec -T wp sh -c 'cat /var/www/html/wp-content/debug.log' >"$OUT/wp-debug.log" 2>&1
docker compose exec -T wp sh -c 'cat /var/log/mariadb.log'               >"$OUT/mariadb.log" 2>&1
docker compose exec -T wp sh -c 'cat /var/log/nginx/error.log /var/log/php83/error.log 2>/dev/null' >"$OUT/web-error.log" 2>&1
# Keep the artifact small: last 2 MB of each log.
for f in "$OUT"/*; do [ "$(stat -c%s "$f")" -gt 2097152 ] && tail -c 2097152 "$f" >"$f.tmp" && mv "$f.tmp" "$f"; done
ls -la "$OUT"
