#!/usr/bin/env bash
# Milestone 3.2: starts the offline mock S3 endpoint inside the container,
# runs cloud-checks.php against it, then stops it.
#
#   bash tests/cloud-checks.sh
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1
wpx() { MSYS_NO_PATHCONV=1 docker compose exec -T wp "$@"; }

# The mock runs as the user that owns WordPress (root on the dev stack, the PHP-FPM
# user on prod/), so the checks can tamper with bucket objects it wrote.
OWNER="$(wpx stat -c %U /var/www/html/wp-content | tr -d '\r')"
AS=''; [ "$OWNER" != root ] && AS="su-exec $OWNER"
wpx sh -c "pkill -f '[m]ock-s3.php' 2>/dev/null; rm -rf /tmp/mock-s3; mkdir -p /tmp/mock-s3/data; chown -R $OWNER /tmp/mock-s3"
MSYS_NO_PATHCONV=1 docker compose exec -d wp sh -c "$AS php -S 127.0.0.1:9000 /tests/mock-s3.php >/tmp/mock-s3/server.log 2>&1"
for _ in $(seq 1 50); do wpx sh -c 'curl -s -o /dev/null http://127.0.0.1:9000/' && break; sleep 0.2; done
echo "Mock S3 endpoint: http://127.0.0.1:9000 (SigV4-verifying, offline)"
echo

wpx wp eval-file /tests/cloud-checks.php --user=chrono 2>&1
CODE=$?
REQS="$(wpx sh -c 'wc -l < /tmp/mock-s3/requests.log' | tr -d '\r ')"
echo "($REQS requests served by the mock endpoint)"
wpx sh -c 'pkill -f "[m]ock-s3.php" 2>/dev/null; true'
exit $CODE
