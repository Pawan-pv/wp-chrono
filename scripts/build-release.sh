#!/usr/bin/env bash
# Builds the distributable plugin zip: dist/wp-chrono-<version>.zip
#
#   bash scripts/build-release.sh [--skip-build]
#
# 1. npm run build (type-check + production bundle)
# 2. PHP lint on 8.3 and 7.4 (the dev-env Docker images; see dev-env/README.md)
# 3. copy runtime files only: wp-chrono.php, uninstall.php, readme.txt,
#    includes/, build/, languages/  (never src/, node_modules/, scripts/, tests …)
# 4. zip with forward-slash paths under a wp-chrono/ folder (PHP ZipArchive)
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$(pwd)"
WIN_ROOT="$(pwd -W 2>/dev/null || pwd)"
VERSION="$(sed -n "s/^define( 'WP_CHRONO_VERSION', '\([^']*\)' );/\1/p" wp-chrono.php)"
STABLE="$(sed -n 's/^Stable tag: *//p' readme.txt | tr -d '\r')"
[ -n "$VERSION" ] || { echo "Cannot read WP_CHRONO_VERSION"; exit 1; }
[ "$VERSION" = "$STABLE" ] || { echo "Version mismatch: wp-chrono.php $VERSION vs readme Stable tag $STABLE"; exit 1; }
DIST="dist/wp-chrono"
ZIP="dist/wp-chrono-$VERSION.zip"
echo "Building WP Chrono $VERSION"
grep -q "Update URI: .*CHANGE-ME" wp-chrono.php && echo "  (warning: placeholder Update URI; cloud/scripts/publish-release.mjs will refuse this build)"

if [ "${1:-}" != "--skip-build" ]; then
  echo "• npm run build"
  npm run --silent build >/dev/null
fi
[ -s build/index.js ] && [ -s build/index.css ] || { echo "build/ is missing; run npm run build"; exit 1; }
[ -s languages/wp-chrono.pot ] || { echo "languages/wp-chrono.pot is missing"; exit 1; }

lint() { # image
  MSYS_NO_PATHCONV=1 docker run --rm --pull=never -v "$WIN_ROOT:/w" -w /w "$1" sh -c '
    n=0
    for f in $(find includes -name "*.php") wp-chrono.php uninstall.php includes/Recovery/ers-template.php.txt; do
      php -l "$f" >/dev/null 2>&1 || { php -l "$f"; n=$((n+1)); }
    done
    exit $n'
}
echo "• PHP 8.3 lint"; lint wp-chrono-dev:latest
echo "• PHP 7.4 lint"; lint wp-chrono-php74:latest

echo "• assembling $DIST"
rm -rf dist && mkdir -p "$DIST"
cp -r wp-chrono.php uninstall.php readme.txt includes build languages "$DIST/"
find "$DIST" -name '*.map' -delete

echo "• zipping"
MSYS_NO_PATHCONV=1 docker run --rm --pull=never -v "$WIN_ROOT:/w" -w /w wp-chrono-dev:latest php scripts/make-zip.php "$DIST" "$ZIP" wp-chrono
rm -rf "$DIST"

SHA="$(sha256sum "$ZIP" | cut -d' ' -f1)"
echo "$SHA  wp-chrono-$VERSION.zip" > "$ZIP.sha256"
echo "Done: $ZIP ($(du -h "$ZIP" | cut -f1)), sha256 $SHA"
