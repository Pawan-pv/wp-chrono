#!/usr/bin/env bash
# Fails when a file that must never be committed is tracked (CI) or staged
# (pre-commit hook): the competitor reference source, signing keys, key
# folders, env/secret files, or anything containing a PEM private key.
#
#   bash .github/ci/forbidden-files.sh            # every tracked file
#   bash .github/ci/forbidden-files.sh --staged   # files in the commit being made
set -uo pipefail
cd "$(git rev-parse --show-toplevel)" || exit 1

if [ "${1:-}" = --staged ]; then
  FILES="$(git diff --cached --name-only --diff-filter=ACMR)"
  content() { git show ":$1" 2>/dev/null; }
else
  FILES="$(git ls-files)"
  content() { cat "$1" 2>/dev/null; }
fi

BAD='(^|/)reference-wp-reset(/|$)|(^|/)\.wp-chrono-keys(/|$)|\.secret$|\.pem$|\.key$|\.p12$|(^|/)id_(rsa|ed25519)|ed25519.*\.(secret|key|bin)$|(^|/)\.dev-test-key\.json$|(^|/)\.dev\.vars|(^|/)\.env(\..*)?$'
FAIL=0
while IFS= read -r f; do
  [ -n "$f" ] || continue
  if echo "$f" | grep -qE "$BAD"; then
    echo "::error file=$f::forbidden file (reference source, key or secret): $f"; FAIL=1
  elif content "$f" | grep -aqE -- '-----BEGIN ([A-Z]+ )?PRIVATE KEY-----'; then
    echo "::error file=$f::contains a private key: $f"; FAIL=1
  fi
done <<< "$FILES"

[ "$FAIL" -eq 0 ] && echo "No forbidden files." || echo "Remove these files from the index (git rm --cached <file>)."
exit "$FAIL"
