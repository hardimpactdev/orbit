#!/usr/bin/env bash
# Promotes the seed that a seed refresh built (docs/cli/tasks/vm-seed-refresh.mmd). Run as root on the task VM host, after the
# builder stopped cleanly. The Gateway puts this file in the workflow intent, and the workflow runs it as
# `bash -c <this file> bash PROJECT POOL CURRENT BUILDING PREVIOUS BUILDER`.
#
# CURRENT is the seed every claim clones, BUILDING its clone that the builder filled, PREVIOUS the name the replaced seed keeps.
# The old seed stays as PREVIOUS, the rollback copy, until the next successful refresh deletes it.
# - One host lock (the one the claim's seed clone takes) covers the whole script, so no claim clones between the renames.
# - The renames ignore HUP, INT and TERM. A KILL between them leaves CURRENT missing and PREVIOUS present;
#   cleanup-builder.sh repairs that state.
# Standard output is one JSON line. Everything else goes to standard error.
set -euo pipefail
exec 3>&1 1>&2

project=${1:?} pool=${2:?} current=${3:?} building=${4:?} previous=${5:?} builder=${6:?}
lock=${ORBIT_SEED_LOCK:-/run/lock/orbit-task-vm-seed.lock}
volume() { incus --project "$project" storage volume "$@"; }
exists() { volume show "$pool" "$1" >/dev/null 2>&1; }

exec 9>"$lock"
flock --wait 300 9 || {
    echo "promote-seed: the host seed lock is held for more than 300 s" >&2
    exit 1
}

exists "$current" || {
    echo "promote-seed: the host has no seed $current" >&2
    exit 1
}
exists "$building" || {
    echo "promote-seed: the host has no built seed $building" >&2
    exit 1
}
# Incus keeps a custom volume when its VM goes. The clone must leave the builder before it can be renamed.
if incus --project "$project" info "$builder" >/dev/null 2>&1; then incus --project "$project" delete --force "$builder"; fi

dataset=$(incus storage get "$pool" zfs.pool_name)/custom
written=$(zfs get -Hp -o value used "$dataset/${project}_$building")
# The clone now owns the blocks it shares with the old seed, so the new seed has no origin and refreshes build no chain.
zfs promote "$dataset/${project}_$building"
# The rollback copy of the refresh before this one goes now: this refresh succeeded.
if exists "$previous"; then volume delete "$pool" "$previous"; fi

trap '' HUP INT TERM
volume rename "$pool" "$current" "$previous"
if ! volume rename "$pool" "$building" "$current"; then
    volume rename "$pool" "$previous" "$current"
    trap - HUP INT TERM
    exit 1
fi
trap - HUP INT TERM

referenced=$(zfs get -Hp -o value referenced "$dataset/${project}_$current")
printf '{"seed":"%s","previous":"%s","referenced_bytes":%s,"written_bytes":%s}\n' "$current" "$previous" "$referenced" "$written" >&3
