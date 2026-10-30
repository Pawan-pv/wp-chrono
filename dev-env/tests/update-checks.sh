#!/usr/bin/env bash
# Plugin updates (and, against the Worker, licensing) through the WP Chrono API.
# Installs dist/wp-chrono-<v>.zip on a throwaway WordPress (/var/www/upd, own
# database), offers it a signed <v+1> release, and checks that tampered or
# wrongly signed packages are refused and the real one installs — with WP
# Chrono's own pre-update snapshot. The mounted plugin source is never updated.
#
#   bash tests/update-checks.sh                     # offline: mock API in the container (mock-update.php)
#   bash tests/update-checks.sh --worker [URL]      # the local Worker, default http://host.docker.internal:5173
#                                                   # (cd ../cloud && CF_DEV_HOST=0.0.0.0 npx cf dev)
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1
MODE=mock; API=http://localhost:9101
if [ "${1:-}" = --worker ]; then MODE=worker; API="${2:-http://host.docker.internal:5173}"; fi
HOST_API="${API/host.docker.internal/localhost}"
FAILS=0; PASSES=0
check() { if [ "$2" -eq 0 ]; then echo "PASS  $1${3:+  ($3)}"; PASSES=$((PASSES + 1)); else echo "FAIL  $1${3:+  ($3)}"; FAILS=$((FAILS + 1)); fi; }
wpx() { MSYS_NO_PATHCONV=1 docker compose exec -T wp "$@"; }
UPD=/var/www/upd
WPCLI="php -d memory_limit=512M /cache/wp-cli.phar --allow-root --path=$UPD --url=http://upd.test"
# Own WP-CLI cache: WP-CLI caches downloaded plugin zips by slug + version and
# would hand a cached (e.g. tampered) package to every later attempt.
updwp() { MSYS_NO_PATHCONV=1 docker compose exec -T -e WP_CLI_CACHE_DIR=/tmp/wpc-update/cli-cache ${HOME_AS:+-e WPC_FAKE_HOME=$HOME_AS} wp $WPCLI "$@" 2>&1; }
updq() { wpx $WPCLI db query "$1" --skip-column-names 2>/dev/null | tr -d '\r'; }

VERSION="$(sed -n "s/^define( 'WP_CHRONO_VERSION', '\([^']*\)' );/\1/p" ../wp-chrono/wp-chrono.php)"
NEXT="$(echo "$VERSION" | awk -F. '{ print $1 "." $2 "." $3 + 1 }')"
ZIP="../wp-chrono/dist/wp-chrono-$VERSION.zip"
[ -s "$ZIP" ] || { echo "Missing $ZIP: run bash ../wp-chrono/scripts/build-release.sh"; exit 1; }
echo "== Fixtures ($MODE mode, API $API): $VERSION installed, $NEXT offered =="

wpx sh -c 'rm -rf /tmp/wpc-update && mkdir -p /tmp/wpc-update'
wpx sh -c 'cat > /tmp/wpc-update/base.zip' < "$ZIP"
wpx sh -c 'cat > /tmp/wpc-update/mu.php' < tests/update-test-mu.php
LICENSE_SECRET=""; LICENSE_PUB=""
if [ "$MODE" = worker ]; then
  LICENSE_SECRET="$(node -p 'require("../cloud/.dev-test-key.json").secret')"
  LICENSE_PUB="$(node -p 'require("../cloud/.dev-test-key.json").publicKey')"
fi
# Builds the next release zip, a tampered copy, a test release key, a rogue key, good.json and rogue.json.
wpx env NEXT="$NEXT" API="$API" LICENSE_PUB="$LICENSE_PUB" php -r '
  $d = "/tmp/wpc-update"; $next = getenv("NEXT");
  $z = new ZipArchive(); $z->open("$d/base.zip"); $z->extractTo("$d/src"); $z->close();
  $main = file_get_contents("$d/src/wp-chrono/wp-chrono.php");
  $main = preg_replace("/(\* Version:\s+)\S+/", "\${1}$next", $main);
  $main = preg_replace("/(WP_CHRONO_VERSION.,\s*.)[^\x27]+/", "\${1}$next", $main);
  file_put_contents("$d/src/wp-chrono/wp-chrono.php", $main);
  $readme = preg_replace("/^Stable tag: .*/m", "Stable tag: $next", file_get_contents("$d/src/wp-chrono/readme.txt"));
  file_put_contents("$d/src/wp-chrono/readme.txt", $readme);
  passthru("php /var/www/html/wp-content/plugins/wp-chrono/scripts/make-zip.php $d/src/wp-chrono $d/wp-chrono-$next.zip wp-chrono >/dev/null");
  copy("$d/wp-chrono-$next.zip", "$d/tampered.zip"); file_put_contents("$d/tampered.zip", "x", FILE_APPEND);
  $key = sodium_crypto_sign_keypair(); $rogue = sodium_crypto_sign_keypair();
  $sha = hash_file("sha256", "$d/wp-chrono-$next.zip");
  $b64u = function ($s) { return rtrim(strtr(base64_encode($s), "+/", "-_"), "="); };
  $meta = array("slug" => "wp-chrono", "name" => "WP Chrono", "version" => $next, "file" => "wp-chrono-$next.zip",
    "size" => filesize("$d/wp-chrono-$next.zip"), "sha256" => $sha, "requires" => "6.3", "requires_php" => "7.4", "tested" => "6.7",
    "released" => gmdate("c"), "description" => "<p>WP Chrono</p>", "changelog" => "<h4>$next</h4><ul><li>Update test</li></ul>");
  $msg = "WPCU1.wp-chrono.$next.$sha";
  file_put_contents("$d/good.json", json_encode($meta + array("signature" => $b64u(sodium_crypto_sign_detached($msg, sodium_crypto_sign_secretkey($key))))));
  file_put_contents("$d/rogue.json", json_encode($meta + array("signature" => $b64u(sodium_crypto_sign_detached($msg, sodium_crypto_sign_secretkey($rogue))))));
  file_put_contents("$d/env.json", json_encode(array("api" => getenv("API"), "release_pub" => base64_encode(sodium_crypto_sign_publickey($key)), "license_pub" => getenv("LICENSE_PUB"))));
'
wpx test -s "/tmp/wpc-update/wp-chrono-$NEXT.zip"; check "built a signed $NEXT release + tampered copy" $?

publish() { # json-file: put latest.json (+ the zip) where the API serves it
  if [ "$MODE" = mock ]; then wpx cp "/tmp/wpc-update/$1" /tmp/wpc-update/latest.json; return; fi
  local tmp; tmp="$(mktemp -d)"
  wpx cat "/tmp/wpc-update/wp-chrono-$NEXT.zip" > "$tmp/next.zip"; wpx cat "/tmp/wpc-update/$1" > "$tmp/latest.json"
  local base="$HOST_API/cdn-cgi/local/explorer/api/r2/buckets/wp-chrono-releases/objects"
  curl -sf -X PUT -H 'Content-Type: application/zip' --data-binary @"$tmp/next.zip" "$base/wp-chrono%2Freleases%2Fwp-chrono-$NEXT.zip" >/dev/null &&
  curl -sf -X PUT -H 'Content-Type: application/json' --data-binary @"$tmp/latest.json" "$base/wp-chrono%2Flatest.json" >/dev/null
  local code=$?; rm -rf "$tmp"; return $code
}
if [ "$MODE" = mock ]; then
  wpx sh -c 'pkill -f "[m]ock-update.php"; true'
  MSYS_NO_PATHCONV=1 docker compose exec -d wp sh -c 'php -S 127.0.0.1:9101 /tests/mock-update.php >/tmp/wpc-update/server.log 2>&1'
  sleep 1
fi
publish good.json; check "release published to the $MODE API" $?

echo; echo "== Fresh WordPress with WP Chrono $VERSION =="
wpx sh -c "
  set -e
  mariadb -uroot -e \"DROP DATABASE IF EXISTS wordpress_upd; CREATE DATABASE wordpress_upd; GRANT ALL ON wordpress_upd.* TO 'wp'@'127.0.0.1'; FLUSH PRIVILEGES;\"
  rm -rf $UPD && mkdir -p $UPD/wp-content/mu-plugins && tar -xzf \"/cache/\${WP_TARBALL:-wordpress.tar.gz}\" -C $UPD --strip-components=1
  cp /tmp/wpc-update/mu.php $UPD/wp-content/mu-plugins/wpc-update-test.php
" || check 'fresh WordPress unpacked' 1
updwp config create --dbname=wordpress_upd --dbuser=wp --dbpass=wp --dbhost=127.0.0.1 --skip-check >/dev/null
updwp config set WP_HTTP_BLOCK_EXTERNAL true --raw >/dev/null
[ "$MODE" = worker ] && updwp config set WP_ACCESSIBLE_HOSTS host.docker.internal >/dev/null
updwp core install --url=http://upd.test --title='Update Test' --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email >/dev/null
OUT="$(updwp plugin install /tmp/wpc-update/base.zip --activate)"; echo "$OUT" | grep -q "Plugin 'wp-chrono' activated"; check "installed $VERSION from the release zip" $? "$(echo "$OUT" | tail -1)"
OUT="$(updwp eval 'echo \WPChrono\Services\Updater::update_uri(), "|", \WPChrono\Services\Updater::api(), "|", \WPChrono\Services\LicenseService::server();')"
echo "$OUT" | grep -q "^https://.*|$API|$API/v1/license$"; check 'Update URI header read; WP_CHRONO_API and the default license server follow it' $? "$OUT"

FLUSH='delete_site_transient("update_plugins"); delete_site_transient("wp_chrono_update_info");'
offered() { updwp eval "$FLUSH"' wp_update_plugins(); $u = get_site_transient("update_plugins"); $r = $u->response["wp-chrono/wp-chrono.php"] ?? null; echo $r ? $r->new_version . " " . $r->package : "none";'; }
update() { wpx rm -rf /tmp/wpc-update/cli-cache; updwp eval "$FLUSH"' wp_update_plugins();' >/dev/null; updwp plugin update wp-chrono; }
installed() { updwp plugin get wp-chrono --field=version | tr -d '\r'; }

echo; echo "== Update check =="
OUT="$(offered)"; [ "$OUT" = "$NEXT $API/v1/download/wp-chrono/wp-chrono-$NEXT.zip" ]; check "WordPress offers $NEXT through the Update URI updater" $? "$OUT"
OUT="$(updwp plugin list --update=available --field=name)"; echo "$OUT" | grep -qx wp-chrono; check 'wp plugin list --update=available lists wp-chrono' $?
OUT="$(updwp eval 'require_once ABSPATH . "wp-admin/includes/plugin-install.php"; $i = plugins_api("plugin_information", array("slug" => "wp-chrono")); echo is_wp_error($i) ? $i->get_error_message() : $i->version . "|" . $i->sections["changelog"];')"
echo "$OUT" | grep -q "^$NEXT|<h4>$NEXT</h4>"; check '"View details" modal gets version + changelog' $? "$(echo "$OUT" | cut -c1-60)"
if [ "$MODE" = mock ]; then
  LOG="$(wpx cat /tmp/wpc-update/requests.log | grep '/v1/update/')"
  echo "$LOG" | grep -q "GET /v1/update/wp-chrono?version=$VERSION" && ! echo "$LOG" | grep -qi 'upd.test\|key\|site='; check 'update check sends only the installed version (no site URL, no key)' $? "$(echo "$LOG" | tail -1)"
fi

if [ "$MODE" = mock ]; then
  echo; echo "== Refused packages =="
  wpx sh -c 'echo tamper > /tmp/wpc-update/mode'
  OUT="$(update)"; echo "$OUT" | grep -q 'SHA-256 mismatch' && [ "$(installed)" = "$VERSION" ]; check 'tampered zip refused (hash), still on the old version' $? "$(echo "$OUT" | grep -m1 -i 'error\|warning' | cut -c1-90)"
  wpx sh -c 'echo ok > /tmp/wpc-update/mode'
  publish rogue.json
  OUT="$(update)"; echo "$OUT" | grep -q 'not signed by the WP Chrono release key' && [ "$(installed)" = "$VERSION" ]; check 'zip signed with an unknown key refused, still on the old version' $? "$(echo "$OUT" | grep -m1 -i 'error\|warning' | cut -c1-90)"
  publish good.json
  [ -z "$(wpx sh -c 'ls /tmp/wpc-update/ | grep -E "^wp-chrono-.*tmp$"; ls /tmp | grep -iE "^wp-chrono-[0-9.]+.*\.tmp$"')" ]; check 'refused downloads were deleted' $?
fi

echo; echo "== Update =="
BEFORE="$(updq 'SELECT COUNT(*) FROM wp_wpchrono_snapshots')"
OUT="$(update)"; echo "$OUT" | grep -q 'Updated 1 of 1\|Success: Updated'; check "wp plugin update wp-chrono installs the signed $NEXT" $? "$(echo "$OUT" | tail -1)"
[ "$(installed)" = "$NEXT" ] && [ "$(updwp plugin get wp-chrono --field=status | tr -d '\r')" = active ]; check "now $NEXT and still active" $? "$(installed)"
AFTER="$(updq 'SELECT COUNT(*) FROM wp_wpchrono_snapshots')"; LAST="$(updq 'SELECT trigger_type FROM wp_wpchrono_snapshots ORDER BY id DESC LIMIT 1')"
[ "$AFTER" -gt "$BEFORE" ] && [ "$LAST" = auto ]; check 'WP Chrono snapshotted the site before updating itself' $? "$BEFORE → $AFTER, trigger $LAST"
OUT="$(offered)"; [ "$OUT" = none ]; check 'no further update offered once current' $? "$OUT"

if [ "$MODE" = mock ]; then
  echo; echo "== API down =="
  wpx sh -c 'echo down > /tmp/wpc-update/mode'
  OUT="$(offered)"; [ "$OUT" = none ]; check 'API unreachable → no update offered, no error' $? "$OUT"
  OUT="$(updwp eval '$c = get_site_transient("wp_chrono_update_info"); echo is_array($c) && "" === $c["version"] ? "cached-failure" : "no-cache";')"
  [ "$OUT" = cached-failure ]; check 'failure cached (no request storm while the API is down)' $? "$OUT"
  wpx sh -c 'pkill -f "[m]ock-update.php"; true'
fi

if [ "$MODE" = worker ]; then
  echo; echo "== Licensing against the Worker =="
  LID="e2e-$(date +%s)"
  KEY="$(wpx env SECRET="$LICENSE_SECRET" LID="$LID" php -r '
    $b64u = function ($s) { return rtrim(strtr(base64_encode($s), "+/", "-_"), "="); };
    $body = $b64u(json_encode(array("lid" => getenv("LID"), "plan" => "pro", "sites" => 1, "source" => "test", "issued" => gmdate("Y-m-d"), "expires" => null)));
    echo "WPC1.$body." . $b64u(sodium_crypto_sign_detached("WPC1.$body", base64_decode(getenv("SECRET"))));')"
  ACT='$r = \WPChrono\Services\LicenseService::activate(getenv("KEY")); echo is_wp_error($r) ? "ERR " . $r->get_error_message() : ($r["active"] ? "ACTIVE " : "INACTIVE ") . json_encode($r["remote"]);'
  OUT="$(HOME_AS=https://shop-one.example.com updwp eval "putenv('KEY=$KEY'); $ACT")"
  echo "$OUT" | grep -q '^ACTIVE .*"reachable":true.*"status":"active"'; check 'first production site: Worker confirms (signed "active")' $? "$(echo "$OUT" | cut -c1-90)"
  OUT="$(HOME_AS=https://shop-two.example.com updwp eval "putenv('KEY=$KEY'); $ACT")"
  echo "$OUT" | grep -q '^ERR .*site_limit.*maximum number of sites'; check 'second production site refused: site_limit with a clear message' $? "$(echo "$OUT" | cut -c1-110)"
  OUT="$(HOME_AS=https://staging.shop-two.example.com updwp eval "putenv('KEY=$KEY'); $ACT")"
  echo "$OUT" | grep -q '^ACTIVE '; check 'staging copy does not use a seat' $? "$(echo "$OUT" | cut -c1-60)"
  (cd ../cloud && node -e "import('./scripts/local.mjs').then(l => l.queryLocal('$HOST_API', \"UPDATE licenses SET status = 'revoked' WHERE lid = ?\", ['$LID']))")
  OUT="$(HOME_AS=https://shop-one.example.com updwp eval '\WPChrono\Services\LicenseService::maybe_check(true); $s = \WPChrono\Services\LicenseService::status(); echo ($s["active"] ? "ACTIVE" : "OFF") . " " . $s["error"];')"
  echo "$OUT" | grep -q '^OFF This license was revoked'; check 'revoked in D1 → daily check switches Pro off' $? "$OUT"
  OUT="$(HOME_AS=https://shop-one.example.com updwp chrono license status --user=admin)"; echo "$OUT" | grep -q 'locked (Pro)'; check 'wp chrono license status: Pro locked, restore features still free' $?
fi

echo; [ "$FAILS" -eq 0 ] && echo "All checks passed ($PASSES passed)" || echo "$FAILS check(s) FAILED ($PASSES passed)"
exit "$FAILS"
