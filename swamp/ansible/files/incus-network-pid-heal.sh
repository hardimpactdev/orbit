#!/bin/sh
# Before Incus starts on a fleet Node, drop each managed network's dnsmasq pid file that no live dnsmasq of that network owns
# (docs/reference/development-containers.md#no-stale-network-pid-file). Its `ExecStartPre` drop-in runs it, whatever boots the Node.
#
#   incus-network-pid-heal.sh [<networks folder> [<proc folder>]]
#
# Incus reads `<networks>/<network>/dnsmasq.pid` at the network's start to stop the old dnsmasq. A file that is not YAML, such as an
# empty one from a copy of a running Node, fails the network with `Could not read pid file`. A file stays when its `pid:` names a
# running process whose command line holds `--interface=<network>`: a daemon restart keeps that dnsmasq and its file. Every other
# file goes. It prints `removed <network>` for each and never fails the start.
networks=${1:-/var/lib/incus/networks}
proc=${2:-/proc}
for file in "$networks"/*/dnsmasq.pid; do
  [ -e "$file" ] || [ -L "$file" ] || continue
  network=${file%/dnsmasq.pid}
  network=${network##*/}
  pid=$(sed -n 's/^pid: *\([0-9][0-9]*\) *$/\1/p' "$file" 2>/dev/null | head -n 1)
  if [ -n "$pid" ] && [ -r "$proc/$pid/cmdline" ] && tr '\0' '\n' < "$proc/$pid/cmdline" | grep -qx -- "--interface=$network"; then
    continue
  fi
  rm -f -- "$file" && echo "removed $network"
done
exit 0
