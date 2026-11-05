#!/usr/bin/env bash
# Cleans up after a seed refresh (docs/cli/tasks/vm-seed-refresh.mmd). Run as root on the task VM host, first thing in a run
# and again at the end, after success, failure and cancellation. The Gateway puts this file in the workflow intent, and the
# workflow runs it as `bash -c <this file> bash PROJECT POOL BUILDER BUILDING PREVIOUS CURRENT`.
#
# 1. It deletes the builder VM, which holds the clone while it exists.
# 2. It repairs a promotion that died between its two renames: CURRENT is missing and PREVIOUS holds the old seed, so
#    PREVIOUS goes back to CURRENT. This comes before anything else is deleted.
# 3. It deletes the unpromoted clone BUILDING. A promoted clone has the name CURRENT by now.
# The rollback copy PREVIOUS stays. Standard output lists the VMs and the volumes, for the end check.
set -euo pipefail

project=${1:?} pool=${2:?} builder=${3:?} building=${4:?} previous=${5:?} current=${6:?}
lock=${ORBIT_SEED_LOCK:-/run/lock/orbit-task-vm-seed.lock}
volume() { incus --project "$project" storage volume "$@"; }
exists() { volume show "$pool" "$1" >/dev/null 2>&1; }

exec 9>"$lock"
flock --wait 300 9 || {
    echo "cleanup-builder: the host seed lock is held for more than 300 s" >&2
    exit 1
}

if incus --project "$project" info "$builder" >/dev/null 2>&1; then incus --project "$project" delete --force "$builder"; fi
if ! exists "$current" && exists "$previous"; then volume rename "$pool" "$previous" "$current"; fi
if exists "$building"; then volume delete "$pool" "$building"; fi

incus --project "$project" list --format csv --columns n
volume list "$pool" --format csv --columns tn
