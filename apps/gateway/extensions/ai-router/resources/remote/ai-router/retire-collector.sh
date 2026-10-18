# Program: ai-router.retire-collector
# shellcheck shell=bash
# Timeout: 60
# Input: <Process ID> <retire|complete>.
# Result: no data. Refuses symlinks and units without the exact Process ownership marker.
# Removes the retired collector unit before the one-time rename publishes its replacement.

id=$1
[[ "$id" =~ ^[1-9][0-9]*$ ]]
phase=$2
case "$phase" in
    complete)
        rm -rf -- /var/lib/orbit/proxycli /etc/caddy/orbit-proxycli-cert-current
        exit 0
        ;;
    retire) ;;
    *) exit 64 ;;
esac
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
