#!/usr/bin/env bash
# Phase 6: local .sql.gz import — the "hostage backup" scenario competitors'
# 1★ reviews describe: download a backup, reset the site, then upload the file
# back and restore it. Over real HTTP with a chunked upload (64 KB chunks, far
# below PHP's upload limit, to prove any size works).
#
#   bash tests/import-checks.sh [base_url]
#
# DESTRUCTIVE (full reset). Only run against dev-env.
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

BASE="${1:-http://localhost:8088}"
WORK="$(mktemp -d)"; JAR="$WORK/jar"; BODY="$WORK/body"
FAILS=0; PASSES=0
trap 'rm -rf "$WORK"' EXIT

check() { if [ "$2" -eq 0 ]; then echo "PASS  $1${3:+  ($3)}"; PASSES=$((PASSES + 1)); else echo "FAIL  $1${3:+  ($3)}"; FAILS=$((FAILS + 1)); fi; }
wpx() { MSYS_NO_PATHCONV=1 docker compose exec -T wp "$@"; }
W()   { wpx wp --user=chrono "$@" 2>/dev/null; }
json() { grep -o "\"$1\":\"\?[^,\"}]*" "$BODY" | head -1 | sed 's/^"[^"]*":"\{0,1\}//'; }
login() {
  rm -f "$JAR"
  curl -s -c "$JAR" -b 'wordpress_test_cookie=WP%20Cookie%20check' -o /dev/null --data-urlencode log=chrono --data-urlencode pwd=chrono-pass -d testcookie=1 "$BASE/wp-login.php"
  PAGE="$(curl -s -b "$JAR" -c "$JAR" "$BASE/wp-admin/admin.php?page=wp-chrono")"
  REST="$(echo "$PAGE" | grep -o '"restNonce":"[^"]*"' | cut -d'"' -f4)"
  CHRONO="$(echo "$PAGE" | grep -o '"chronoNonce":"[^"]*"' | cut -d'"' -f4)"
}
api() { # method route [json]
  curl -s -o "$BODY" -w '%{http_code}' -b "$JAR" -c "$JAR" -X "$1" -H "X-WP-Nonce: $REST" -H "X-WP-Chrono-Nonce: $CHRONO" \
    -H 'Content-Type: application/json' ${3:+--data "$3"} "$BASE/?rest_route=/wp-chrono/v1/$2"
}
chunk() { # file upload offset final name
  curl -s -o "$BODY" -w '%{http_code}' -b "$JAR" -c "$JAR" -H "X-WP-Nonce: $REST" -H "X-WP-Chrono-Nonce: $CHRONO" \
    -F "chunk=@$1" -F "upload=$2" -F "offset=$3" -F "final=$4" -F "name=$5" "$BASE/?rest_route=/wp-chrono/v1/snapshots/import/chunk"
}
# Uploads a whole file in 64 KB chunks. Sets CODE (last HTTP code) and CHUNKS; last response in $BODY.
CODE=''; CHUNKS=0
upload() { # file name
  local parts upload='' offset=0 n i=0
  rm -f "$WORK"/part.*; split -b 65536 -d -a 4 "$1" "$WORK/part."
  parts=("$WORK"/part.*); n=${#parts[@]}
  for p in "${parts[@]}"; do
    i=$((i + 1))
    CODE="$(chunk "$p" "$upload" "$offset" "$([ "$i" -eq "$n" ] && echo 1 || echo 0)" "$2")"
    [ "$CODE" = 200 ] || break
    [ -z "$upload" ] && upload="$(json upload)"
    offset=$((offset + $(wc -c < "$p")))
  done
  CHUNKS=$i
}
# Runs import ticks until done. Sets CODE; final tick in $BODY.
import_all() {
  local job
  job="$(json job)"; CODE=200
  for _ in $(seq 1 300); do
    grep -q '"done":true' "$BODY" && break
    CODE="$(api POST snapshots/import/tick "{\"job\":\"$job\"}")"; [ "$CODE" = 200 ] || break
  done
}

echo "== Download a backup, lose the site, upload the file back =="
login
W option update wpc_import_marker 'before-reset' >/dev/null
# ~1 MB of content so the upload really spans many chunks.
W db query "DELETE FROM wp_posts WHERE post_title LIKE 'wpc import post %'" >/dev/null
W eval 'global $wpdb; $v = array(); for ( $i = 0; $i < 1500; $i++ ) { $v[] = $wpdb->prepare( "(2, NOW(), NOW(), %s, %s, %s, \"publish\", \"post\", \"\", \"\", \"\", NOW(), NOW(), \"\")", wp_generate_password( 640, false ), "wpc import post $i", "" ); } $wpdb->query( "INSERT INTO {$wpdb->posts} (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, post_type, to_ping, pinged, post_content_filtered, post_modified, post_modified_gmt, guid) VALUES " . implode( ",", $v ) );' >/dev/null
SNAP="$(W chrono snapshot create --label='Downloaded backup' >/dev/null; W chrono snapshot list --format=ids | tr ' ' '\n' | sort -n | tail -1 | tr -d '\r')"
api POST "snapshots/$SNAP/export" '{"job":""}' >/dev/null
for _ in $(seq 1 50); do grep -q '"done":true' "$BODY" && break; api POST "snapshots/$SNAP/export" "{\"job\":\"$(json job)\"}" >/dev/null; done
URL="$(json download_url | sed 's#\\/#/#g')"
[[ "$URL" == *"&file="* && "$URL" != *"&amp;"* ]]; check 'download URL is a raw URL (no &amp;), usable as an href' $? "${URL:0:70}…"
DUMP="$WORK/snapshot-download.sql.gz"
C="$(curl -s -o "$DUMP" -w '%{http_code}' -b "$JAR" "$URL")"
[ "$C" = 200 ] && [ "$(head -c2 "$DUMP" | od -An -tx1 | tr -d ' ')" = 1f8b ]; check 'exported .sql.gz downloaded to "my computer" over HTTP' $? "$(wc -c < "$DUMP" | tr -d ' ') bytes"
W chrono snapshot delete "$SNAP" --yes >/dev/null
W chrono reset site --yes --keep-theme --no-snapshot >/dev/null
[ -z "$(W option get wpc_import_marker)" ]; check 'snapshot deleted + site reset: the downloaded file is the only copy left' $?

login
upload "$DUMP" snapshot-download.sql.gz; C="$CODE"
[ "$C" = 200 ] && [ "$CHUNKS" -gt 5 ] && grep -q '"phase":"import"' "$BODY" && grep -q 'Validated a WP Chrono export' "$BODY"; check "chunked upload (${CHUNKS} × 64 KB) validated as a WP Chrono export" $? "HTTP $C"
import_all; C="$CODE"
NEW="$(json snapshot_id)"
[ "$C" = 200 ] && grep -q '"done":true' "$BODY" && grep -q ', verified' "$BODY"; check 'imported as a new verified snapshot' $? "#$NEW"
grep -q '"trigger_type":"import"' "$BODY" && grep -q 'Imported: Downloaded backup' "$BODY"; check 'snapshot labelled "Imported", trigger import' $?
api POST "snapshots/$NEW/restore" '{"confirm":"RESTORE"}' >/dev/null; JOB="$(json job)"
for _ in $(seq 1 100); do grep -q '"done":true' "$BODY" && break; api POST snapshots/restore/tick "{\"job\":\"$JOB\",\"confirm\":\"RESTORE\"}" >/dev/null; done
REST="$(json rest)"; CHRONO="$(json chrono)"
POSTS="$(W db query "SELECT COUNT(*) FROM wp_posts WHERE post_title LIKE 'wpc import post %'" --skip-column-names | tr -d '\r ')"
[ "$(W option get wpc_import_marker | tr -d '\r')" = before-reset ] && [ "$POSTS" = 1500 ]; check 'atomic restore of the imported snapshot brings the site back' $? "marker + $POSTS/1500 posts"
C="$(api GET snapshots)"; grep -q "\"exports\":\[{\"file\":\"snapshot-" "$BODY"; check 'uploaded dump kept as the snapshot export (downloadable again)' $? "HTTP $C"

echo; echo "== Only genuine, intact WP Chrono exports are accepted =="
wpx php -r '
  file_put_contents("/tmp/wpc-not-chrono.sql.gz", gzencode("-- MySQL dump 10.13\nCREATE TABLE x (id int);\n"));
  $h = "-- WP Chrono export of snapshot #9 (Evil)\n-- Created 2026-10-26T00:00:00Z, exported 2026-10-26T00:00:00Z\n-- Site: http://evil.test\n\n";
  file_put_contents("/tmp/wpc-drop.sql.gz", gzencode($h . "CREATE TABLE `wp_x` (\n  `id` int NOT NULL\n) ENGINE=InnoDB;\nDROP TABLE `wp_users`;\n-- End of WP Chrono export\n"));
  file_put_contents("/tmp/wpc-prefix.sql.gz", gzencode($h . "CREATE TABLE `other_users` (\n  `id` int NOT NULL\n) ENGINE=InnoDB;\n-- End of WP Chrono export\n"));
  // Storage-engine smuggling: MySQL takes ENGINE without "=", lets the last ENGINE win and skips comments.
  $t = "CREATE TABLE `wp_wpc_evil` (\n  `id` int NOT NULL\n)";
  file_put_contents("/tmp/wpc-engine-noeq.sql.gz", gzencode($h . "$t ENGINE FEDERATED COMMENT=\x27mysql://u@127.0.0.1:3306/wordpress/wp_users\x27;\n-- End of WP Chrono export\n"));
  file_put_contents("/tmp/wpc-engine-twice.sql.gz", gzencode($h . "$t ENGINE=InnoDB ENGINE=FEDERATED COMMENT=\x27mysql://u@127.0.0.1:3306/wordpress/wp_users\x27;\n-- End of WP Chrono export\n"));
  file_put_contents("/tmp/wpc-engine-comment.sql.gz", gzencode($h . "$t ENGINE=InnoDB /* \x27 */ ENGINE=FEDERATED /* \x27 */;\n-- End of WP Chrono export\n"));'
for f in not-chrono drop prefix engine-noeq engine-twice engine-comment; do wpx cat "/tmp/wpc-$f.sql.gz" > "$WORK/$f.sql.gz"; done
head -c "$(( $(wc -c < "$DUMP") / 2 ))" "$DUMP" > "$WORK/truncated.sql.gz"
USERS_BEFORE="$(W db query "SELECT COUNT(*) FROM wp_users" --skip-column-names | tr -d '\r ')"
COUNT_BEFORE="$(W chrono snapshot list --format=ids | wc -w)"

upload "$WORK/not-chrono.sql.gz" not-chrono.sql.gz; C="$CODE"
[ "$C" = 400 ] && grep -q 'not exported by WP Chrono' "$BODY"; check 'plain mysqldump rejected (no WP Chrono header)' $? "HTTP $C"
upload "$WORK/truncated.sql.gz" truncated.sql.gz; C="$CODE"
[ "$C" = 422 ] && grep -qE 'corrupt|incomplete' "$BODY"; check 'truncated / corrupt download rejected (HTTP 422, not a server error)' $? "HTTP $C: $(json message | cut -c1-60)"
upload "$WORK/drop.sql.gz" drop.sql.gz; C="$CODE"; [ "$C" = 200 ] && { import_all; C="$CODE"; }
[ "$C" = 422 ] && grep -q 'does not import' "$BODY" && [ "$(W db query "SELECT COUNT(*) FROM wp_users" --skip-column-names | tr -d '\r ')" = "$USERS_BEFORE" ]; check 'dump with DROP TABLE wp_users refused, nothing executed' $? "HTTP $C"
upload "$WORK/prefix.sql.gz" prefix.sql.gz; C="$CODE"; [ "$C" = 200 ] && { import_all; C="$CODE"; }
[ "$C" = 422 ] && grep -q 'does not belong to this site' "$BODY"; check 'dump for another table prefix refused' $? "HTTP $C"
for f in engine-noeq engine-twice engine-comment; do
  upload "$WORK/$f.sql.gz" "$f.sql.gz"; C="$CODE"; [ "$C" = 200 ] && { import_all; C="$CODE"; }
  [ "$C" = 422 ] && grep -qE 'FEDERATED is not allowed|comments are not allowed' "$BODY"; check "storage-engine smuggling refused ($f)" $? "HTTP $C: $(json message | cut -c1-70)"
done
EVIL="$(W db query "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND (TABLE_NAME LIKE '%wpc_evil%' OR ENGINE NOT IN ('InnoDB','MyISAM','Aria','MEMORY'))" --skip-column-names | tr -d '\r ')"
[ "$EVIL" = 0 ]; check 'no table from a refused dump exists, every table on an allowed engine' $? "$EVIL found"
[ "$(W chrono snapshot list --format=ids | wc -w)" = "$COUNT_BEFORE" ]; check 'failed imports leave no half-built snapshot' $?
C="$(chunk "$DUMP" '' 512 0 x.sql.gz)"; [ "$C" = 409 ]; check 'chunk at the wrong offset rejected' $? "HTTP $C"
C="$(chunk "$DUMP" '' 0 1 backup.zip)"; [ "$C" = 400 ]; check 'non-.sql.gz file name rejected' $? "HTTP $C"
dd if=/dev/zero of="$WORK/oversized-chunk.bin" bs=1M count=9 status=none
# 9 MB is also over post_max_size (8M) on the dev stack, where PHP drops the whole body: still a 413, the
# answer the SPA shrinks its chunk on (nginx on the prod stack answers 413 itself).
C="$(chunk "$WORK/oversized-chunk.bin" '' 0 0 oversized.sql.gz)"; [ "$C" = 413 ]; check 'oversized upload chunk rejected before it is stored (HTTP 413)' $? "HTTP $C"
C="$(curl -s -o /dev/null -w '%{http_code}' -F "chunk=@$DUMP" "$BASE/?rest_route=/wp-chrono/v1/snapshots/import/chunk")"
[ "$C" = 401 ] || [ "$C" = 403 ]; check 'anonymous upload rejected' $? "HTTP $C"

echo; echo "== WP-CLI =="
wpx sh -c 'cat > /tmp/wpc-cli-import.sql.gz' < "$DUMP"
ID="$(W chrono snapshot import /tmp/wpc-cli-import.sql.gz --porcelain | tail -1 | tr -d '\r')"
[ -n "$ID" ] && [ "$ID" -gt "$NEW" ] 2>/dev/null; check 'wp chrono snapshot import <file>' $? "#$ID"
OUT="$(wpx wp --user=chrono chrono snapshot import /tmp/wpc-not-chrono.sql.gz 2>&1)"   # exits 1 by design
echo "$OUT" | grep -q 'not exported by WP Chrono'; check 'CLI rejects a non-WP-Chrono dump' $?

W option delete wpc_import_marker >/dev/null
W db query "DELETE FROM wp_posts WHERE post_title LIKE 'wpc import post %'" >/dev/null
echo; [ "$FAILS" -eq 0 ] && echo "All checks passed ($PASSES passed)" || echo "$FAILS check(s) FAILED ($PASSES passed)"
exit "$FAILS"
