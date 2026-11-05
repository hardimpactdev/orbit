#!/usr/bin/env bash
# Prepares the seed refresh builder for the proof reset (docs/cli/tasks/vm-seed-refresh.mmd). Run as root, in the
# builder, after the source is cloned. Standard input is the proof Swamp Club key.
#
# 1. The key goes to ~/.config/swamp/club-api-key for `orbit`, mode 0600: the reset runs Swamp with it.
# 2. The reset refuses an outer VM that has no Pi service, no `orbit-agent` observer or no `orbit` WireGuard interface,
#    because it proves that it leaves them as it found them. The builder is not a fleet Node, so it has none of them.
#    This adds three throwaway stand-ins: two idle services and an interface with a key that is made here and never
#    used. They live on the builder's root disk and go with the builder.
set -euo pipefail

readonly key=/home/orbit/.config/swamp/club-api-key
# `install -d` makes the parents of a directory as root. The reset changes the mode of ~/.orbit, so `orbit` makes these.
runuser -u orbit -- install -d -m 0700 "$(dirname "$key")"
rm -f -- "$key"
install -m 0600 -o orbit -g orbit /dev/stdin "$key"

for unit in orbit-agent seed-builder-pi-server; do
    printf '[Unit]\nDescription=Seed refresh builder stand-in for %s\n[Service]\nExecStart=/bin/sleep infinity\n[Install]\nWantedBy=multi-user.target\n' \
        "$unit" >"/etc/systemd/system/$unit.service"
done
systemctl daemon-reload
systemctl enable --now orbit-agent.service seed-builder-pi-server.service

if ! ip link show orbit >/dev/null 2>&1; then
    ip link add orbit type wireguard
    wg genkey | wg set orbit private-key /dev/stdin
    ip link set orbit up
fi
wg show orbit public-key >/dev/null
