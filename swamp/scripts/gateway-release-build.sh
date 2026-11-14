#!/bin/bash
# Adds the worktree of a fetched commit, installs its dependencies, seals it with REVISION and a cached configuration, and prints the release's Swamp grants.
# Arguments: sha, force, agent manifest. The commit is in the shared repository already (the step "Fetch the commit").
# Run by the step "Build the release" of swamp/workflows/workflow-operation-gateway-release.yaml, from the commit it builds.
# ORBIT_RELEASE_BASE moves the base for tests only.
set -euo pipefail
sha=$1 force=$2 manifest=$3
id=${sha:0:12} base=${ORBIT_RELEASE_BASE:-/home/orbit}
repo=$base/shared/orbit.git release=$base/releases/$id app=$base/releases/$id/apps/gateway
fail() { echo "$1: $2" >&2; exit 1; }
current=$(readlink "$base/orbit") || fail gateway.release_not_adopted "$base/orbit is no release link"
current=${current#releases/}
current_sha=$(cat "$base/releases/$current/REVISION") || fail gateway.release_current_incomplete "release $current has no REVISION"
# As the legacy guard, for a reused release too: a release moves forward unless forced.
if [ "$force" != true ] && [ "$sha" != "$current_sha" ]; then
  git -C "$repo" merge-base --is-ancestor "$current_sha" "$sha" || fail gateway.release_downgrade "$sha does not descend from the current $current_sha"
fi
cache() {
  candidate="$app/bootstrap/cache/config.next-$RANDOM$RANDOM.php"
  APP_CONFIG_CACHE=$candidate ORBIT_AGENT_DELIVERY=$release/apps/agent/delivery/manifest.json \
    timeout 120 php8.5 "$app/artisan" config:cache --no-interaction --quiet || { rm -f "$candidate"; return 1; }
  chmod 0600 "$candidate" && mv "$candidate" "$app/bootstrap/cache/config.php"
}
if [ "$(cat "$release/REVISION" 2>/dev/null || true)" = "$sha" ]; then
  # A sealed release is reused; its configuration is cached again from the shared env file, as each release does.
  cache || fail gateway.release_configuration_failed "config:cache failed"
else
  [ "$current" != "$id" ] || fail gateway.release_current_incomplete "the current release $id is not sealed"
  # The PHP code is in single quotes on purpose.
  # shellcheck disable=SC2016
  database=$(php8.5 -r '$c = require $argv[1]; echo $c["database"]["connections"]["sqlite"]["database"];' "$base/releases/$current/apps/gateway/bootstrap/cache/config.php")
  # Before anything is written: 1 GiB for the build and a copy of the database for the snapshot, above the floor; the snapshot script owns that rule.
  check=$(git -C "$repo" show "$sha:swamp/scripts/gateway-release-snapshot.sh")
  bash -c "$check" gateway-release-snapshot --check "$database" 1073741824
  install -d -m 0710 -g caddy "$base/releases"
  if [ -e "$release" ]; then chmod -R u+w "$release"; rm -rf "$release"; fi
  git -C "$repo" worktree prune
  git -C "$repo" worktree add --quiet --detach --force "$release" "$sha" || fail gateway.release_worktree_failed "git worktree add failed"
  chmod 0700 "$app/bootstrap/cache"
  for dir in app/private app/public framework/cache/data framework/sessions framework/views logs; do
    install -d -m 0750 "$base/shared/gateway-storage/$dir"
  done
  ln -s "$base/shared/gateway.env" "$app/.env"
  git -C "$release" ls-files -z -- apps/gateway/storage | xargs -0 -r git -C "$release" update-index --skip-worktree --
  rm -rf "$app/storage"
  grep -qxF /apps/gateway/storage "$repo/info/exclude" 2>/dev/null || { install -d -m 0755 "$repo/info"; echo /apps/gateway/storage >> "$repo/info/exclude"; }
  ln -s "$base/shared/gateway-storage" "$app/storage"
  log=$(mktemp)
  for project in apps/cli apps/gateway; do
    timeout 900 composer --working-dir="$release/$project" install --no-dev --prefer-dist --no-interaction --no-progress --no-ansi > "$log" 2>&1 \
      || { tail -n 20 "$log" >&2; fail gateway.release_dependencies_failed "composer install failed in $project"; }
    timeout 120 composer --working-dir="$release/$project" check-platform-reqs --no-dev --no-interaction --no-ansi > "$log" 2>&1 \
      || { tail -n 20 "$log" >&2; fail gateway.release_dependencies_failed "platform requirements failed in $project"; }
  done
  rm -f "$log"
  install -d -m 0755 "$release/apps/agent/delivery"
  printf '%s' "$manifest" > "$release/apps/agent/delivery/manifest.json"
  chmod 0444 "$release/apps/agent/delivery/manifest.json"
  # Caddy reaches the application through the group; the env file stays private.
  chgrp caddy "$release" "$release/apps" "$app" && chmod 0710 "$release" "$release/apps" "$app"
  chmod 0600 "$base/shared/gateway.env"
  find "$release" -path "$app/bootstrap/cache" -prune -o -path "$release/apps/cli/storage" -prune -o \
    \( -type f -o -type d \) ! -type l -exec chmod a-w {} +
  chmod u+w "$release"
  printf '%s\n' "$sha" > "$release/.REVISION.next" && chmod 0644 "$release/.REVISION.next" && mv "$release/.REVISION.next" "$release/REVISION"
  cache || { rm -f "$release/REVISION"; fail gateway.release_configuration_failed "config:cache failed"; }
  chmod u-w "$release"
fi
# The new code renders its own grants; the load step splits the two files at the separator line.
# shellcheck disable=SC2016
php8.5 -r 'require $argv[1]."/vendor/autoload.php"; $g = (new App\Infrastructure\GatewayReleases\SwampGrantRenderer)->render($argv[2]);
  echo $g["grants/orbit-gateway.yaml"], "---\n", $g["grants/orbit-serve.yaml"];' "$app" "$release/swamp"
