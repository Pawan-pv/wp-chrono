#!/usr/bin/env bash
# Fills dev-env/cache with the packages the suites install offline, each pinned
# by SHA-256 (the hashes of the copies the local suite was verified with).
# Files already present (actions/cache) are only re-verified.
#
#   bash .github/ci/fetch-downloads.sh <dev-env/cache>
set -euo pipefail
mkdir -p "$1" && cd "$1"

get() { # file, url, sha256
  [ -s "$1" ] || curl -fsSL --retry 3 -o "$1" "$2"
  echo "$3  $1" | sha256sum -c --quiet - || { echo "::error::$1 does not match its pinned SHA-256"; rm -f "$1"; exit 1; }
}
get wordpress.tar.gz       https://wordpress.org/wordpress-6.7.2.tar.gz                              cfd9c83cfa9dda0351898ea9b73f58995c817994a5424be18777f83e886a3cf6
get wordpress-7.1.3.tar.gz https://wordpress.org/wordpress-7.1.3.tar.gz                              d2a09acb6a15e3b9c471d72557753c266d6f41a79ed19bfe04cbfe49e283b2a5
get woocommerce-9.9.7.zip  https://downloads.wordpress.org/plugin/woocommerce.9.9.7.zip              96facedd12e32b6e0b120dcaa4a62b9c6b1ae12159d32510ea416c8e9b44f4dd
get plugin-check.zip       https://downloads.wordpress.org/plugin/plugin-check.2.1.0.zip             6ff4bd2145f3befcf907df158cc466b1649dafed5686de8369907403c3013fc4
get wp-cli.phar            https://github.com/wp-cli/wp-cli/releases/download/v2.11.0/wp-cli-2.11.0.phar a39021ac809530ea607580dbf93afbc46ba02f86b6cffd03de4b126ca53079f6
chmod +x wp-cli.phar
