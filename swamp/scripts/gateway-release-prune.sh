#!/bin/bash
# Keeps the newest sealed releases, the live and the previous one. Arguments: keep, release id, previous release id.
# Run by the step "Prune old releases" of swamp/workflows/workflow-gateway-release-activate.yaml.
set -euo pipefail
base=/home/orbit keep=$1 current=$2 previous=$3
live=$(readlink "$base/orbit"); live=${live#releases/}
web=$(readlink "$base/web/current" || true); web=${web#releases/}
# ls -t sorts the sealed releases by age, which is the point.
# shellcheck disable=SC2012
kept=$(ls -1t "$base"/releases/*/REVISION | head -n "$keep" | xargs -r -n1 dirname | xargs -r -n1 basename)
for dir in "$base"/releases/*/; do
  id=$(basename "$dir")
  case " $live $current $previous $web ${kept//$'\n'/ } " in *" $id "*) continue ;; esac
  # An unsealed directory is a stopped build; it goes after an hour.
  [ -f "$dir/REVISION" ] || [ -n "$(find "$dir" -maxdepth 0 -mmin +60)" ] || continue
  chmod -R u+w "$dir" && rm -rf "$dir"
  rm -rf "$base/web/releases/$id"
done
git -C "$base/shared/orbit.git" worktree prune
# A web build whose release is gone, such as one a failed build left, goes too; the served one stays.
for dir in "$base"/web/releases/*/; do
  id=$(basename "$dir")
  [ -d "$base/releases/$id" ] || [ "$id" = "$web" ] || rm -rf "$dir"
done
