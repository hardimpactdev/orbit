#!/usr/bin/env bash
# Turns the nested topology that the proof reset built into the ready copies of the topology seed
# (docs/cli/tasks/vm-seed-refresh.mmd). Run as root, in the builder, after `bin/proof-fleet reset`.
#
# The seed's pool then holds the stopped VMs `node-template`, `ready-gateway`, `ready-app-dev` and `ready-app-prod`, and
# nothing else. The interface for the reset from ready copies (docs/reference/compute-drivers.md, The topology seed):
# - A ready copy has no NIC. A NIC with a static MAC or address cannot sit on a second instance (Incus refuses a VM whose
#   MAC or address another instance holds, even a stopped one), and a NIC on the build's network would make Incus refuse
#   to adopt the pool in a VM that does not have that network. The NIC's values are in the keys `user.orbit.ready.mac`,
#   `user.orbit.ready.ipv4` and `user.orbit.ready.network`, and the role in `user.orbit.ready.role`. A copy for a running
#   VM adds the NIC with `incus config device add NAME eth0 nic network=... hwaddr=... ipv4.address=...`.
# - The disk `orbit-source` of a ready copy points at READY_SOURCE, a fixed path, so a task VM that adopts the pool needs
#   one directory and not the build's generation path.
# - It carries no `user.orbit.e2e.*` label: those mark a generation's resources for release, and a release must never
#   delete a ready copy.
# - A ready copy has never been started since its copy.
# - The ready Gateway and app-dev run the code of the build from a directory of the outer VM that the build wrote into:
#   its `.env` with the application key that the baked database needs, `storage`, `bootstrap/cache`, and the ACLs of the
#   web server. That directory is not in the seed, so a copy of the build's whole tree, with its ACLs and owners, is kept in
#   the pool as the custom volume `ready-source`. A task VM restores it into READY_SOURCE before it starts the copies.
# Everything but the last line goes to standard error. The last line is the JSON list of the ready copy names.
set -euo pipefail
exec 3>&1 1>&2

readonly READY_SOURCE=/home/orbit/.orbit/ready/source
readonly roles=(gateway app-dev app-prod)

# The generation's VM of a role: the one whose name ends in `-<role>`, never a ready copy.
generation_vm() {
    incus list --format csv --columns n | grep -E -- "-$1\$" | grep -v '^ready-' | head -n 1
}

readonly SOURCE_VOLUME=ready-source
readonly SOURCE_COPY=/var/lib/incus/storage-pools/topology-seed/custom/default_$SOURCE_VOLUME

install -d -m 0755 -o orbit -g orbit "$READY_SOURCE"

# Everything but the template is the generation or an earlier ready copy. Stop it all, then copy and delete.
mapfile -t running < <(incus list --format csv --columns n | grep -v -x node-template || true)
((${#running[@]} == 0)) || incus stop --timeout 120 "${running[@]}" || true

# The build's tree, from the Gateway's `orbit-source` disk, before the copies change that device.
build_source=$(incus config device get "$(generation_vm gateway)" orbit-source source)
[[ -d $build_source ]] || {
    echo "ready-copies: the build's source $build_source is no directory" >&2
    exit 1
}
if incus storage volume show topology-seed "$SOURCE_VOLUME" >/dev/null 2>&1; then incus storage volume delete topology-seed "$SOURCE_VOLUME"; fi
incus storage volume create topology-seed "$SOURCE_VOLUME" --type filesystem
tar --acls --xattrs --numeric-owner -C "$build_source" -cf - . | tar --acls --xattrs --numeric-owner --same-owner -C "$SOURCE_COPY" -xf -

for role in "${roles[@]}"; do
    vm=$(generation_vm "$role")
    [[ -n $vm ]] || {
        echo "ready-copies: no VM for the role $role" >&2
        exit 1
    }
    mac=$(incus config device get "$vm" eth0 hwaddr)
    ipv4=$(incus config device get "$vm" eth0 ipv4.address)
    network=$(incus config device get "$vm" eth0 network)
    if incus info "ready-$role" >/dev/null 2>&1; then incus delete --force "ready-$role"; fi
    incus copy "$vm" "ready-$role"
    incus config device remove "ready-$role" eth0
    if incus config device get "ready-$role" orbit-source source >/dev/null 2>&1; then
        incus config device set "ready-$role" orbit-source source="$READY_SOURCE"
    fi
    for label in $(incus config show "ready-$role" | sed -n 's/^  \(user\.orbit\.e2e\.[a-z]*\):.*/\1/p'); do
        incus config unset "ready-$role" "$label"
    done
    incus config set "ready-$role" user.orbit.ready.role="$role" user.orbit.ready.mac="$mac" \
        user.orbit.ready.ipv4="$ipv4" user.orbit.ready.network="$network"
done

# The generation is done: its VMs, and the operator VM that the harness still makes, leave the pool.
mapfile -t generation < <(incus list --format csv --columns n | grep -v -x node-template | grep -v '^ready-' || true)
((${#generation[@]} == 0)) || incus delete --force "${generation[@]}"

observed=$(incus list --format csv --columns ns | sort | paste -sd ' ')
expected='node-template,STOPPED ready-app-dev,STOPPED ready-app-prod,STOPPED ready-gateway,STOPPED'
[[ $observed == "$expected" ]] || {
    echo "ready-copies: the pool holds: $observed" >&2
    exit 1
}
sync
incus list --format json --columns n | python3 -c 'import json, sys; print(json.dumps(sorted(row["name"] for row in json.load(sys.stdin) if row["name"].startswith("ready-"))))' >&3
