#!/usr/bin/env bash
# Milestone 2.4: builds a throwaway WordPress multisite network inside the
# dev container (own database, offline WordPress tarball, current plugin
# code) and proves main-site and subsite snapshots never touch each other.
#
#   bash tests/multisite-checks.sh
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

wpx() { MSYS_NO_PATHCONV=1 docker compose exec -T wp "$@"; }
MS=/var/www/ms
URL=http://ms.test
mswp() { wpx php -d memory_limit=512M /cache/wp-cli.phar --allow-root --path="$MS" "$@"; }
FAILS=0
SUBSNAP=0

echo "== Build a fresh multisite network (offline) =="
wpx sh -c "
  set -e
  mariadb -uroot -e \"DROP DATABASE IF EXISTS wordpress_ms; CREATE DATABASE wordpress_ms; GRANT ALL ON wordpress_ms.* TO 'wp'@'127.0.0.1'; FLUSH PRIVILEGES;\"
  rm -rf $MS && mkdir -p $MS
  tar -xzf \"/cache/${WP_TARBALL:-wordpress.tar.gz}\" -C $MS --strip-components=1
  tar -C /var/www/html/wp-content/plugins -cf - --exclude=node_modules --exclude=src wp-chrono | tar -C $MS/wp-content/plugins -xf -
" || { echo "FAIL  could not prepare the network files"; exit 1; }
mswp config create --dbname=wordpress_ms --dbuser=wp --dbpass=wp --dbhost=127.0.0.1 --skip-check >/dev/null 2>&1
mswp config set WP_HTTP_BLOCK_EXTERNAL true --raw >/dev/null 2>&1
mswp core multisite-install --url="$URL" --title='Chrono Network' --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email >/dev/null 2>&1
mswp plugin activate wp-chrono --network >/dev/null 2>&1
mswp site create --slug=sub --title='Subsite' --email=admin@example.test >/dev/null 2>&1
SITES="$(mswp site list --field=url 2>/dev/null | tr -d '\r' | tr '\n' ' ')"
if [ "$(mswp eval 'echo is_multisite() ? "yes" : "no";' 2>/dev/null | tr -d '\r')" = yes ] && [[ "$SITES" == *"/sub/"* ]]; then
  echo "PASS  multisite network with a subsite is up  ($SITES)"
else
  echo "FAIL  multisite network setup  ($SITES)"; exit 1
fi

phase() { # url, phase, [arg] — prints checks, accumulates FAILS, captures SUBSNAP
  local out
  out="$(mswp --url="$1" --user="${PHASE_USER:-admin}" eval-file /tests/multisite-checks.php "$2" ${3:-} 2>&1)"
  echo
  echo "$out" | grep -vE '^(SUBSNAP=|All checks|[0-9]+ check\(s\) FAILED)' | sed '/^$/d'
  FAILS=$((FAILS + $(echo "$out" | grep -c '^FAIL')))
  local snap
  snap="$(echo "$out" | grep -o '^SUBSNAP=[0-9]*' | cut -d= -f2)"
  [ -n "$snap" ] && SUBSNAP="$snap"
  return 0
}

phase "$URL/sub/" sub-create
phase "$URL/" main "$SUBSNAP"
phase "$URL/sub/" sub-restore "$SUBSNAP"

# A plain administrator of the subsite (manage_options, not a super admin).
mswp user create subadmin subadmin@example.test --role=administrator --url="$URL/sub/" >/dev/null 2>&1
PHASE_USER=subadmin phase "$URL/sub/" sub-caps
phase "$URL/sub/" super-caps

mswp --url="$URL/" eval-file /tests/multisite-checks.php seed-uninstall >/dev/null 2>&1
mswp plugin deactivate wp-chrono --network >/dev/null 2>&1
UNINSTALL="$(mswp plugin uninstall wp-chrono 2>&1)" || echo "$UNINSTALL" | sed 's/^/      /'
phase "$URL/" after-uninstall

wpx sh -c "rm -rf $MS; mariadb -uroot -e 'DROP DATABASE IF EXISTS wordpress_ms;'"
echo; [ "$FAILS" -eq 0 ] && echo "All checks passed" || echo "$FAILS check(s) FAILED"
exit "$FAILS"
