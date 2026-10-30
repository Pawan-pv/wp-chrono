#!/usr/bin/env bash
# Copies the test environment (../dev-env, the copy you edit and run locally)
# into this repo's dev-env/ so GitHub Actions (.github/workflows/tests.yml)
# runs the same suites. Never shipped: the release zip is built from a
# whitelist (scripts/build-release.sh) and dev-env is export-ignored.
#
#   bash scripts/sync-dev-env.sh     # then commit dev-env/
set -euo pipefail
cd "$(dirname "$0")/.."
SRC=../dev-env
[ -d "$SRC/tests" ] || { echo "No $SRC/tests next to the plugin"; exit 1; }
rm -rf dev-env
mkdir -p dev-env
# Downloads (cache/), run output (tests/artifacts/) and scratch files stay local.
tar -C "$SRC" --exclude=./cache --exclude=./tests/artifacts --exclude=./tests/.p -cf - . | tar -C dev-env -xf -
mkdir -p dev-env/cache
echo "dev-env synced: $(find dev-env -type f | wc -l) files"
