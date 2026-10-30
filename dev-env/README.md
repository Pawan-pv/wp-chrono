# WP Chrono dev stack

A throwaway WordPress for testing the plugin. Everything is destructive by design.

## One-time setup

Docker Hub pulls hang on this network, so the image is assembled from a cached
Alpine image with `apk` and committed locally:

```bash
docker run --name wpc-build redis:7-alpine sh -c 'apk add --no-cache bash curl less mariadb mariadb-client \
  php83 php83-mysqli php83-pdo_mysql php83-mbstring php83-xml php83-curl php83-zip php83-gd php83-phar \
  php83-openssl php83-tokenizer php83-ctype php83-fileinfo php83-dom php83-simplexml php83-xmlreader \
  php83-xmlwriter php83-intl php83-iconv php83-session php83-exif php83-sodium php83-json php83-zlib php83-posix \
  && ln -sf /usr/bin/php83 /usr/bin/php'
docker commit wpc-build wp-chrono-dev:latest && docker rm wpc-build
```

`cache/` must contain `wordpress.tar.gz` (WordPress 6.7.x) and `wp-cli.phar`.

## Run everything (offline, ~20 min)

```bash
bash tests/autonomous-full-suite.sh   # fresh stack, lint, all suites, result matrix
```

Variants (any combination):

```bash
WP_TARBALL=wordpress-7.1.3.tar.gz bash tests/autonomous-full-suite.sh      # another WordPress release from cache/
COMPOSE_FILE=prod/docker-compose.yml bash tests/autonomous-full-suite.sh   # nginx + PHP-FPM + OPcache, PHP as non-root,
                                                                           # 1 MB body limit, 256 MB / 30 s, pretty permalinks
```

The production-like image is the dev image plus four packages:
`docker run --name wpc-prod-build wp-chrono-dev:latest sh -c 'apk add --no-cache nginx php83-fpm php83-opcache su-exec'`,
then `docker commit wpc-prod-build wp-chrono-prod:latest`. Stop one stack before starting the other (both use port 8088).
Drop `plugin-check.zip` (from wordpress.org) into `cache/` and `release-checks.sh` also runs the official Plugin Check.

Also needs a one-time PHP 7.4 lint image (`wp-chrono-php74:latest`, same commit trick with
Alpine 3.15's `php7`) and a local Chrome/Edge for `tests/ui-e2e-checks.mjs`. Screenshots and
per-suite logs land in `tests/artifacts/`.

## Use

```bash
docker compose up -d                       # http://localhost:8088, log in as chrono / chrono-pass
docker compose exec wp wp chrono status
docker compose exec wp wp eval-file /tests/rest-checks.php --user=chrono      # Phase 2: security, cleaners, full reset
docker compose exec wp wp eval-file /tests/snapshot-checks.php --user=chrono  # Phase 3: snapshots, diff, restore, export, scavenger
bash tests/http-checks.sh
docker compose exec wp wp eval-file /tests/upgrader-checks.php --user=chrono  # real Plugin_Upgrader bulk update, offline
docker compose exec wp wp eval-file /tests/stress-checks.php --user=chrono    # 240k rows / 70 MB, memory bounds
docker compose exec wp wp eval-file /tests/rollback-checks.php --user=chrono  # update rollback: files + DB, modes, tamper, prune
bash tests/update-rollback-checks.sh                                          # bad v2.0 update undone over REST and from the ERS
docker compose exec wp wp eval-file /tests/safety-checks.php --user=chrono    # backup folders, verification, storage warning
bash tests/multisite-checks.sh                                                # throwaway multisite: wp_2_* never touched
bash tests/blueprint-checks.sh                                                # blueprints + Reset to Dev Baseline + HTTP upload
bash tests/cloud-checks.sh                                                    # SigV4 vectors + push/pull against a mock S3
bash tests/license-checks.sh                                                  # Ed25519 keys, Free/Pro gating, mock license server
bash tests/import-checks.sh                                                   # download .sql.gz → reset → chunked upload → restore
bash tests/release-checks.sh                                                  # build dist zip → install on a fresh site → audit → uninstall
node tests/ui-e2e-checks.mjs                                                  # headless Chrome E2E (no npm deps)
bash tests/recovery-checks.sh                                                 # WSOD → Emergency Recovery Script
docker compose down -v                     # reset the stack completely
```
