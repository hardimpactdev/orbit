#!/usr/bin/env bash
# Keeps what a fresh task VM needs next to the ready copies of a seed refresh (docs/cli/tasks/vm-seed-refresh.mmd). Run as
# root, in the builder, after the step that makes the ready copies. Argument: the proof group.
#
# That step made `fleet-ready-<role>` (the contract is in swamp/proof/README.md, "Ready copies: the contract").
# 1. It checks the pool holds `node-template` and the three complete, stopped ready copies, and nothing else: the guests of the
#    build are gone. It checks the keys `user.ready.set`, `complete`, `minted`, `attempt` and `inputs`, and that no copy has a
#    NIC, a source mount or a generation label.
# 2. The Gateway's database and `.env` key belong to the source tree of the build, which is a directory of the outer VM and not
#    part of the seed. It copies that tree, with its `.env`, `storage`, `bootstrap/cache`, agent manifest, lease and ACLs and
#    owners, into the pool as the custom volume `ready-source`. A task VM restores it before it restores the copies.
# Everything but the last line goes to standard error. The last line is one JSON object: the copies and their set identity.
set -euo pipefail
exec 3>&1 1>&2

group=${1:?}
readonly volume=ready-source
readonly copy=/var/lib/incus/storage-pools/topology-seed/custom/default_$volume
readonly state=/home/orbit/.local/state/orbit-proof-$group/generations

incus list --format json | python3 -c '
import json, sys

rows = {row["name"]: row for row in json.load(sys.stdin)}
roles = ("gateway", "app-dev", "app-prod")
expected = {"node-template", *("fleet-ready-" + role for role in roles)}
if set(rows) != expected:
    sys.exit("ready-state: the pool holds " + ", ".join(sorted(rows)) + ", not " + ", ".join(sorted(expected)))
sets = set()
for role in roles:
    row = rows["fleet-ready-" + role]
    config = row.get("config") or {}
    if row["status"] != "Stopped" or config.get("user.ready.role") != role:
        sys.exit("ready-state: fleet-ready-" + role + " is not a stopped copy of its role")
    if not config.get("user.ready.set") or config.get("user.ready.complete") != config["user.ready.set"]:
        sys.exit("ready-state: fleet-ready-" + role + " is not part of a complete set")
    for key in ("user.ready.minted", "user.ready.attempt", "user.ready.inputs"):
        if not config.get(key):
            sys.exit("ready-state: fleet-ready-" + role + " has no " + key)
    if any(key.startswith("user.orbit.e2e.") for key in config) or {"eth0", "orbit-source"} & set(row.get("devices") or {}):
        sys.exit("ready-state: fleet-ready-" + role + " carries a generation label or device")
    sets.add(config["user.ready.set"])
if len(sets) != 1:
    sys.exit("ready-state: the ready copies are not one set")
'

# The generation that the build published last holds the runtime state of the Gateway.
generation=$(find "$state" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' | sort -n | tail -n 1 | cut -d' ' -f2-)/
[[ -d ${generation}source ]] || {
    echo "ready-state: the build left no source tree in $state" >&2
    exit 1
}
if incus storage volume show topology-seed "$volume" >/dev/null 2>&1; then incus storage volume delete topology-seed "$volume"; fi
incus storage volume create topology-seed "$volume" --type filesystem
tar --acls --xattrs --numeric-owner -C "$generation" -cf - . | tar --acls --xattrs --numeric-owner --same-owner -C "$copy" -xf -
basename "$generation" >"$copy/GENERATION"
sync

incus list --format json | python3 -c '
import json, sys

rows = {row["name"]: row["config"] for row in json.load(sys.stdin)}
gateway = rows["fleet-ready-gateway"]
print(json.dumps({"copies": sorted(name for name in rows if name.startswith("fleet-ready-")), "set": gateway["user.ready.set"],
                  "minted": gateway["user.ready.minted"], "attempt": gateway["user.ready.attempt"], "inputs": gateway["user.ready.inputs"]}))
' >&3
