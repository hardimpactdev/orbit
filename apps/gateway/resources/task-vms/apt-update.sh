#!/usr/bin/env bash
set -euo pipefail

# APT's DPkg::Lock::Timeout does not cover update's lists lock (F_SETLK).
# Retry only that contention; preserve every other error and its exit status.
wait_seconds=$1
shift
export LC_ALL=C
errors=$(mktemp)
trap 'rm -f -- "$errors"' EXIT
deadline=$((SECONDS + wait_seconds))
while true; do
    if apt-get "$@" update 2>"$errors"; then
        cat "$errors" >&2
        exit 0
    else
        status=$?
    fi
    cat "$errors" >&2
    if ! grep -Eq 'Could not get lock .*/lists/lock|Unable to lock directory .*/lists/' "$errors" || ((SECONDS >= deadline)); then
        exit "$status"
    fi
    sleep 1
done
