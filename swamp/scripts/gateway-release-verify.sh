#!/bin/sh
# Checks that the Gateway answers and reports the released version. Argument: sha.
# Run by the step "Verify the Gateway" of swamp/workflows/workflow-gateway-release-activate.yaml.
set -eu
get() { curl -fsS --cacert /etc/caddy/orbit-cert-current/root-ca.pem --max-time 15 --retry 5 --retry-delay 4 --retry-all-errors "https://gateway.orbit$1"; }
get /up >/dev/null || { echo "gateway.release_verify_failed: /up does not answer" >&2; exit 1; }
get /api/v1/gateway/status | jq -e --arg sha "$1" '(.data // .) | .status == "ok" and (.version == $sha or .version == $sha[0:12])' >/dev/null \
  || { echo "gateway.release_verify_failed: status is not ok on $1" >&2; exit 1; }
