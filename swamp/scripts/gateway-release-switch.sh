#!/bin/sh
# Switches the Gateway to a sealed release with its own rollback script. Arguments: release id, sha, Horizon unit.
# Run by the step "Switch the release" of swamp/workflows/workflow-gateway-release-activate.yaml.
set -eu
release=/home/orbit/releases/$1
[ "$(cat "$release/REVISION" 2>/dev/null)" = "$2" ] || { echo "gateway.release_not_prepared: release $1 is not sealed" >&2; exit 1; }
generations=$(runuser -u orbit -- php8.5 "$release/apps/gateway/artisan" gateway:release:cleanup --no-interaction | jq -ce .generations) \
  || { echo "gateway.release_cleanup_unreadable: the release cleanup generations cannot be read" >&2; exit 1; }
"$release/bin/gateway-rollback" "$1" >&2
[ -z "$3" ] || systemctl start "$3"
printf '%s\n' "$generations"
