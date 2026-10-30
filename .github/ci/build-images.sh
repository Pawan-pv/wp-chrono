#!/usr/bin/env bash
# Builds the three local test images exactly as dev-env/README.md describes
# (docker run + apk + docker commit) and saves them to one zstd tarball that
# actions/cache keeps. The cache key hashes this file, so editing the recipe
# rebuilds; the key also rotates monthly to pick up Alpine security updates.
#
#   bash .github/ci/build-images.sh <out.tar.zst>
set -euo pipefail
OUT="$1"

docker run --name wpc-build redis:7-alpine sh -c 'apk add --no-cache bash curl less mariadb mariadb-client \
  php83 php83-mysqli php83-pdo_mysql php83-mbstring php83-xml php83-curl php83-zip php83-gd php83-phar \
  php83-openssl php83-tokenizer php83-ctype php83-fileinfo php83-dom php83-simplexml php83-xmlreader \
  php83-xmlwriter php83-intl php83-iconv php83-session php83-exif php83-sodium php83-json php83-zlib php83-posix \
  && ln -sf /usr/bin/php83 /usr/bin/php'
docker commit wpc-build wp-chrono-dev:latest && docker rm wpc-build
docker run --name wpc-prod-build wp-chrono-dev:latest sh -c 'apk add --no-cache nginx php83-fpm php83-opcache su-exec'
docker commit wpc-prod-build wp-chrono-prod:latest && docker rm wpc-prod-build
docker run --name wpc-74 alpine:3.15 sh -c 'apk add --no-cache php7 php7-tokenizer && ln -sf /usr/bin/php7 /usr/bin/php'
docker commit wpc-74 wp-chrono-php74:latest && docker rm wpc-74

mkdir -p "$(dirname "$OUT")"
docker save wp-chrono-dev:latest wp-chrono-prod:latest wp-chrono-php74:latest | zstd -T0 -3 -q -o "$OUT"
ls -la "$OUT"
