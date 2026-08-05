#!/usr/bin/env bash
# Fail-closed registry absence check for one-time npm bootstrap.
#
# Only a confirmed npm E404 may treat the package/version as absent and allow
# bootstrap to continue. Successful npm view means the target already exists.
# Any other non-zero registry/DNS/TLS/rate/auth error must stop.
#
# Usage: npm-bootstrap-registry-absent.sh <package-or-spec>
# Exit 0: confirmed absent (E404)
# Exit 1: exists, or non-E404 failure, or invalid usage

set -euo pipefail

spec="${1:-}"

if [ -z "$spec" ]; then
  echo "Usage: $0 <package-or-spec>" >&2
  exit 1
fi

set +e
output="$(npm view "$spec" version 2>&1)"
status=$?
set -e

if [ "$status" -eq 0 ]; then
  echo "Package [$spec] already exists on the registry; bootstrap is one-time only." >&2
  exit 1
fi

# Match modern and legacy npm E404 markers only. Do not treat ENOTFOUND,
# ETIMEDOUT, E401, E403, E429, TLS, or other failures as absence.
if printf '%s\n' "$output" | grep -Eq '(^|[^A-Za-z0-9_])code E404([^A-Za-z0-9_]|$)'; then
  exit 0
fi

echo "Registry lookup for [$spec] failed with a non-E404 error; bootstrap refuses to proceed." >&2
if [ -n "$output" ]; then
  printf '%s\n' "$output" >&2
fi
exit 1
