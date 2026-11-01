#!/bin/sh
# Keep the process bound in coreutils and identify an expired Router phase.
phase=$1
seconds=$2
shift 2
/usr/bin/timeout "$seconds" "$@"
status=$?
if [ "$status" -eq 124 ]; then
    printf '%s exceeded %s-second budget\n' "$phase" "$seconds" >&2
fi
exit "$status"
