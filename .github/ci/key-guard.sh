#!/usr/bin/env bash
# Signing-key guard for CI. Run from the workspace (wp-chrono/ and dev-env/ side by side).
#
#   bash wp-chrono/.github/ci/key-guard.sh pre    # before the suites
#   bash wp-chrono/.github/ci/key-guard.sh post   # after the suites, stack still up
#
# pre:  refuses a ~/.wp-chrono-keys folder, generates a throwaway Ed25519 pair
#       in $RUNNER_TEMP (exported as WPC_LICENSE_SECRET_FILE, so scripts/license-sign.php
#       can never fall back to the real key), checks it is not a production key,
#       mints + verifies a license with it, and fails if a production public key
#       appears anywhere in the test code.
# post: fails if a production public key was trusted as a TEST key inside the
#       stack (mu-plugins, the update test's env.json) or if the test license
#       secret the suites used belongs to a production public key.
# The production public keys are read from the plugin source (LicenseService::PUBLIC_KEYS,
# Updater::RELEASE_KEYS); they are compared by SHA-256 fingerprint.
set -euo pipefail
MODE="${1:?pre|post}"
PLUGIN=wp-chrono
php_() { docker run --rm --pull=never -i -e PUB -v "$PWD/$PLUGIN:/p:ro" -v "${RUNNER_TEMP:-/tmp}:/k" wp-chrono-dev:latest php "$@"; }
fp() { echo "$1" | base64 -d | sha256sum | cut -c1-64; }

mapfile -t PROD < <(grep -hoE "'[0-9]{4}-[0-9]{2}' => '[A-Za-z0-9+/]{43}='" \
  "$PLUGIN/includes/Services/LicenseService.php" "$PLUGIN/includes/Services/Updater.php" | sed -E "s/.*=> '([^']+)'/\1/")
[ "${#PROD[@]}" -ge 2 ] || { echo "::error::could not read the production public keys from the plugin source"; exit 1; }
declare -A PROD_FP
for k in "${PROD[@]}"; do PROD_FP[$(fp "$k")]=1; echo "production public key fingerprint: sha256:$(fp "$k" | cut -c1-16)…"; done

is_prod() { [ -n "${PROD_FP[$(fp "$1")]:-}" ]; }

if [ "$MODE" = pre ]; then
  if [ -e "$HOME/.wp-chrono-keys" ]; then echo "::error::$HOME/.wp-chrono-keys exists on the runner; CI must never see the real keys"; exit 1; fi

  KEYDIR="${RUNNER_TEMP:-/tmp}/wpc-ci-keys"; mkdir -p "$KEYDIR"; chmod 700 "$KEYDIR"
  PAIR="$(php_ -r '$p = sodium_crypto_sign_keypair();
    echo base64_encode(sodium_crypto_sign_secretkey($p)), " ", base64_encode(sodium_crypto_sign_publickey($p));')"
  ( umask 077; echo "${PAIR% *}" > "$KEYDIR/license-ed25519.secret" )
  PUB="${PAIR#* }"; unset PAIR
  if is_prod "$PUB"; then echo "::error::the generated test key equals a production key"; exit 1; fi
  echo "throwaway test key fingerprint:      sha256:$(fp "$PUB" | cut -c1-16)…"
  if [ -n "${GITHUB_ENV:-}" ]; then
    echo "WPC_LICENSE_SECRET_FILE=$KEYDIR/license-ed25519.secret" >> "$GITHUB_ENV"
    echo "WPC_TEST_PUBLIC_KEY=$PUB" >> "$GITHUB_ENV"
  fi

  # The vendor minting tool, driven by the throwaway key only.
  CODE="$(docker run --rm --pull=never -v "$PWD/$PLUGIN:/p:ro" -v "$KEYDIR:/k:ro" -e WPC_LICENSE_SECRET_FILE=/k/license-ed25519.secret -e HOME=/nonexistent \
    wp-chrono-dev:latest php /p/scripts/license-sign.php --plan=pro --sites=1 --source=ci | tail -1 | cut -d, -f1)"
  echo "$CODE" | PUB="$PUB" php_ -r '$k = trim(stream_get_contents(STDIN)); $p = explode(".", $k);
    $d = function ($s) { return base64_decode(strtr($s, "-_", "+/")); };
    exit(3 === count($p) && "WPC1" === $p[0] && sodium_crypto_sign_verify_detached($d($p[2]), "WPC1." . $p[1], base64_decode(getenv("PUB"))) ? 0 : 1);' \
    || { echo "::error::license-sign.php did not produce a key that verifies with the throwaway public key"; exit 1; }
  echo "license-sign.php minted a key that verifies with the throwaway key"

  HITS="$(grep -rlF $(printf -- '-e %s ' "${PROD[@]}") dev-env/tests "$PLUGIN/.github" "$PLUGIN/tests" 2>/dev/null || true)"
  if [ -n "$HITS" ]; then echo "::error::production public key used in test code:"; echo "$HITS"; exit 1; fi
  echo "OK: no production key in test code; throwaway key ready"
  exit 0
fi

# post
cd dev-env
PATTERNS=()
for k in "${PROD[@]}"; do PATTERNS+=(-e "$k" -e "${k//\//\\/}"); done   # also the JSON-escaped form
HITS="$(docker compose exec -T wp sh -c 'grep -rlF "$@" /var/www/*/wp-content/mu-plugins /tmp/wpc-update/env.json /tmp/wpc-update/mu.php 2>/dev/null; true' sh "${PATTERNS[@]}")"
if [ -n "$HITS" ]; then echo "::error::a production public key was trusted as a test key in:"; echo "$HITS"; exit 1; fi
TEST_PUB="$(docker compose exec -T wp php -r '$s = @file_get_contents("/tmp/wpc-test-license.secret");
  if ($s) { echo base64_encode(sodium_crypto_sign_publickey_from_secretkey(base64_decode(trim($s)))); }' | tr -d '\r')"
if [ -n "$TEST_PUB" ]; then
  if is_prod "$TEST_PUB"; then echo "::error::the suites signed test licenses with a production key"; exit 1; fi
  echo "test license key in the stack:       sha256:$(fp "$TEST_PUB" | cut -c1-16)… (not production)"
fi
echo "OK: no production key was used by the suites"
