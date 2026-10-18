# Program: ai-router.retire-collector
# shellcheck shell=bash
# Timeout: 60
# Input: <Process ID>.
# Result: no data. Refuses symlinks and units without the exact Process ownership marker.
# Removes the retired collector unit before the one-time rename publishes its replacement.

id=$1
[[ "$id" =~ ^[1-9][0-9]*$ ]]
unit=orbit-process-${id}-cli-proxy-api-collector.service
path=/etc/systemd/system/$unit
test ! -L "$path"
if test -e "$path"; then
    grep -Fxq "X-Orbit-Process-ID=$id" "$path"
    systemctl disable --now "$unit"
    rm -f -- "$path"
    systemctl daemon-reload
    systemctl reset-failed "$unit" 2>/dev/null || true
fi
