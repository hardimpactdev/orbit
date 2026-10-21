#!/usr/bin/env bash
# Prepares one Incus host for task VMs. Idempotent. Runs as root.
# Usage: incus-host.sh <project> <network> <cidr> <pool> <image-alias> [<zfs-dataset>]
#   <network> is a bridge under the reserved `orbittask` prefix.
#   <cidr> is the bridge network, for example 10.251.77.0/24. The bridge owns its first usable address.
#   <pool> is a ZFS storage pool. When it is missing, the script creates it on <zfs-dataset>.
#   <image-alias> names the stock Ubuntu cloud image that the base image build starts from.
# Prints one JSON line {"ok":true} on success. Everything else goes to stderr.
set -euo pipefail

# The CI role uses the same host transport. Its reserved project has a separate container boundary.
ci_runner_host() {
    python3 -I - "$1" <<'CI_PY'
import json
import sys
import subprocess

PROJECT = 'orbit-ci'
NETWORK = 'orbitci0'
ACL = 'orbit-ci-egress'
POOL = 'default'
PROJECT_CONFIG = {
    'features.images': 'true', 'features.profiles': 'true', 'features.storage.volumes': 'true',
    'features.storage.buckets': 'true', 'features.networks': 'false',
    'restricted': 'true', 'restricted.networks.access': NETWORK,
    'restricted.containers.nesting': 'block', 'restricted.containers.privilege': 'unprivileged',
    'restricted.virtual-machines.lowlevel': 'block', 'restricted.containers.lowlevel': 'allow',
    'limits.virtual-machines': '0',
}
NETWORK_CONFIG = {
    'ipv4.nat': 'true', 'ipv6.address': 'none', 'security.acls': ACL,
    'security.acls.default.egress.action': 'reject', 'security.acls.default.ingress.action': 'reject',
}
PROFILE = {
    'description': 'Orbit CI shard runner',
    'config': {'boot.autostart': 'true', 'limits.cpu': '6', 'limits.memory': '12GiB',
               'limits.processes': '8192', 'security.nesting': 'false', 'security.privileged': 'false'},
    'devices': {'eth0': {'name': 'eth0', 'network': NETWORK, 'type': 'nic'},
                'root': {'path': '/', 'pool': POOL, 'type': 'disk'}},
}
ACL_RULES = [
    {'action': 'reject', 'destination': '0.0.0.0/8,10.0.0.0/8,100.64.0.0/10,127.0.0.0/8,169.254.0.0/16,172.16.0.0/12,192.0.0.0/24,192.168.0.0/16,198.18.0.0/15,224.0.0.0/3', 'state': 'enabled'},
    {'action': 'reject', 'destination': '::/0', 'state': 'enabled'},
    {'action': 'allow', 'destination': '0.0.0.0/0', 'state': 'enabled'},
]


def incus(*args, document=None):
    # Query rejects --project; query URLs carry their own scope. Other commands override CLI defaults.
    command = ['incus', *args] if args[0] == 'query' else ['incus', '--project', 'default', *args]
    result = subprocess.run(command,
                            input=None if document is None else json.dumps(document),
                            text=True, capture_output=True, check=True, timeout=30)
    return result.stdout


def query(path):
    return json.loads(incus('query', path))


def default_path(resource):
    scope = '?project=default' if resource.split('/', 1)[0] in ('networks', 'network-acls') else ''
    return f'/1.0/{resource}{scope}'


def exists(collection, name):
    return any(path.split('?', 1)[0] == f'/1.0/{collection}/{name}'
               for path in query(default_path(collection)))


def project_path(suffix=''):
    return f'/1.0/{suffix}?project={PROJECT}'


def includes(actual, expected):
    return all(actual.get(key) == value for key, value in expected.items())


def rules(value):
    return [{key: item for key, item in rule.items() if item != ''} for rule in value]


def matches():
    if not all(exists(collection, name) for collection, name in [
        ('projects', PROJECT), ('networks', NETWORK), ('network-acls', ACL), ('storage-pools', POOL),
    ]):
        return False
    project = query(f'/1.0/projects/{PROJECT}')
    network = query(default_path(f'networks/{NETWORK}'))
    acl = query(default_path(f'network-acls/{ACL}'))
    profile = query(project_path('profiles/default'))
    return (includes(project['config'], PROJECT_CONFIG)
            and network['type'] == 'bridge' and network['managed']
            and network['config'].get('ipv4.address') not in (None, '', 'none', 'auto')
            and includes(network['config'], NETWORK_CONFIG)
            and rules(acl['egress']) == ACL_RULES and acl['ingress'] == []
            and profile['config'] == PROFILE['config'] and profile['devices'] == PROFILE['devices'])


def converge():
    if not exists('storage-pools', POOL):
        incus('storage', 'create', POOL, 'dir')
    if not exists('projects', PROJECT):
        incus('project', 'create', PROJECT, *[part for key, value in PROJECT_CONFIG.items()
                                            if key.startswith('features.') for part in ('-c', f'{key}={value}')])
    project = query(f'/1.0/projects/{PROJECT}')
    # Existing setup.sh resources are adopted by their reserved names. Refuse a different isolation model.
    if project['config'].get('features.networks') != 'false':
        raise RuntimeError('orbit-ci must share the default project networks')
    incus('project', 'set', PROJECT, *[f'{key}={value}' for key, value in PROJECT_CONFIG.items()])
    if not exists('network-acls', ACL):
        incus('network', 'acl', 'create', ACL)
    incus('network', 'acl', 'edit', ACL,
          document={'description': 'Orbit CI runner boundary', 'egress': ACL_RULES, 'ingress': [], 'config': {}})
    if not exists('networks', NETWORK):
        incus('network', 'create', NETWORK, '--type=bridge', 'ipv4.address=auto', 'ipv4.nat=true', 'ipv6.address=none')
    network = query(default_path(f'networks/{NETWORK}'))
    if network['type'] != 'bridge' or not network['managed']:
        raise RuntimeError('orbitci0 must be a managed bridge')
    incus('network', 'set', NETWORK, *[f'{key}={value}' for key, value in NETWORK_CONFIG.items()])
    incus('profile', 'edit', 'default', '--project', PROJECT, document=PROFILE)
    if not matches():
        raise RuntimeError('CI host projection did not converge')
    return 'converged', {}


def remove(purge):
    if exists('projects', PROJECT):
        if query(project_path('instances')):
            return 'occupied', {}
        if purge:
            # Incus refuses project deletion while images, volumes, or other profiles use it.
            incus('project', 'delete', PROJECT)
    if not purge:
        return 'retained', {}
    if exists('networks', NETWORK):
        incus('network', 'delete', NETWORK)
    if exists('network-acls', ACL):
        incus('network', 'acl', 'delete', ACL)
    return 'removed', {}


def main(payload):
    action = payload.get('action')
    if action == 'converge':
        return converge()
    if action == 'inspect':
        return ('matches' if matches() else 'drift'), {}
    if action in ('remove', 'purge'):
        return remove(action == 'purge')
    raise ValueError('invalid CI host action')


if __name__ == '__main__':
    outcome, _data = main({'action': sys.argv[1]})
    if outcome == 'occupied':
        print('Remove the CI runner containers and their GitHub registrations before removing the ci-runner role.', file=sys.stderr)
        sys.exit(2)
    if sys.argv[1] == 'inspect':
        print('1' if outcome == 'matches' else '0')
CI_PY
}

fail() {
    printf 'incus-host: %s\n' "$1" >&2
    exit 1
}

exists() {
    "$@" >/dev/null 2>&1
}

# The script arrives on stdin (`bash -s`). Bash reads all of main() before it runs it, so main can close
# stdin and no command can read the rest of the script.
main() {
    exec 3>&1 1>&2 </dev/null

    { [ "$#" -eq 5 ] || [ "$#" -eq 6 ]; } ||
        fail 'usage: incus-host.sh <project> <network> <cidr> <pool> <image-alias> [<zfs-dataset>]'

    local project=$1 network=$2 cidr=$3 pool=$4 image=$5 dataset=${6:-}

    [[ $project =~ ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$ ]] || fail "invalid project [$project]"
    [[ $network =~ ^orbittask[a-z0-9]{1,6}$ ]] || fail "invalid network [$network]"
    [[ $pool =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$ ]] || fail "invalid pool [$pool]"
    [[ $image =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$ ]] || fail "invalid image alias [$image]"
    [ -z "$dataset" ] || [[ $dataset =~ ^[A-Za-z0-9][A-Za-z0-9_.:-]*(/[A-Za-z0-9][A-Za-z0-9_.:-]*)+$ ]] ||
        fail "invalid ZFS dataset [$dataset]"
    [[ $cidr =~ ^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})/([0-9]{1,2})$ ]] ||
        fail "invalid cidr [$cidr]"

    local octets=("${BASH_REMATCH[1]}" "${BASH_REMATCH[2]}" "${BASH_REMATCH[3]}" "${BASH_REMATCH[4]}")
    local prefix=${BASH_REMATCH[5]} numeric=0 octet
    for octet in "${octets[@]}"; do
        [ "$((10#$octet))" -le 255 ] || fail "invalid cidr [$cidr]"
        numeric=$(((numeric << 8) | 10#$octet))
    done
    { [ "$prefix" -ge 16 ] && [ "$prefix" -le 28 ]; } || fail "cidr prefix must be /16 to /28 [$cidr]"
    local mask=$(((0xFFFFFFFF << (32 - prefix)) & 0xFFFFFFFF))
    [ "$((numeric & mask))" -eq "$numeric" ] || fail "cidr is not a network address [$cidr]"
    local bridge=$((numeric + 1))
    local bridge_ip="$((bridge >> 24 & 255)).$((bridge >> 16 & 255)).$((bridge >> 8 & 255)).$((bridge & 255))"
    local acl="$network-egress"

    command -v incus >/dev/null || fail 'incus is not installed'
    command -v ufw >/dev/null || fail 'ufw is not installed'

    # ZFS pool: every VM and the base image volume are clones on it. An existing pool is only checked.
    if ! exists incus storage show "$pool"; then
        [ -n "$dataset" ] || fail "storage pool [$pool] does not exist and no ZFS dataset is set to create it"
        # Incus takes over the dataset it creates a pool on, unmounts it, and destroys it with the pool. Only a
        # dataset that does not exist yet is safe, and Incus creates it.
        command -v zfs >/dev/null || fail 'zfs is not installed'
        if zfs list -H -o name -- "$dataset" >/dev/null 2>&1; then
            fail "ZFS dataset [$dataset] already exists; name a new dataset for storage pool [$pool]"
        fi
        incus storage create "$pool" zfs "source=$dataset"
    fi
    [ "$(incus storage show "$pool" | sed -n 's/^driver: //p')" = zfs ] || fail "storage pool [$pool] is not a zfs pool"
    if [ -n "$dataset" ]; then
        local source
        source=$(incus storage get "$pool" source)
        [ "$source" = "$dataset" ] || fail "storage pool [$pool] uses [$source], expected [$dataset]"
    fi

    # Project: images and profiles are per project; networks and ACLs stay in `default`, named explicitly. The
    # restrictions follow once the bridge and the profile exist.
    if ! exists incus project show "$project"; then
        incus project create "$project" -c features.images=true -c features.profiles=true -c features.networks=false
    fi
    [ "$(incus project get "$project" features.networks)" != true ] || fail "project [$project] has its own networks"

    # Egress ACL. `edit` replaces the whole rule set, so repeated runs converge.
    exists incus network acl show "$acl" --project default || incus network acl create "$acl" --project default
    incus network acl edit "$acl" --project default <<YAML
description: Orbit task VM boundary
egress:
- action: drop
  destination: 10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,169.254.0.0/16,100.64.0.0/10,224.0.0.0/4,240.0.0.0/4
  state: enabled
- action: allow
  state: enabled
ingress:
- action: allow
  source: $bridge_ip/32
  protocol: tcp
  destination_port: "22"
  state: enabled
YAML

    # Bridge with the ACL attached at creation, so no VM ever runs on it without the boundary.
    if ! exists incus network show "$network" --project default; then
        incus network create "$network" --project default --type=bridge \
            "ipv4.address=$bridge_ip/$prefix" ipv4.nat=true ipv6.address=none "security.acls=$acl" \
            security.acls.default.ingress.action=reject security.acls.default.egress.action=reject
    fi
    local current
    current=$(incus network get "$network" ipv4.address --project default)
    [ "$current" = "$bridge_ip/$prefix" ] ||
        fail "network [$network] has address [$current], expected [$bridge_ip/$prefix]"
    incus network set "$network" --project default ipv4.nat=true ipv6.address=none "security.acls=$acl" \
        security.acls.default.ingress.action=reject security.acls.default.egress.action=reject

    # Default profile of the project: root on the pool, NIC on the bridge with L2 port isolation.
    if exists incus profile device get default root pool --project "$project"; then
        incus profile device set default root pool="$pool" path=/ --project "$project"
    else
        incus profile device add default root disk pool="$pool" path=/ --project "$project"
    fi
    if exists incus profile device get default eth0 network --project "$project"; then
        incus profile device set default eth0 network="$network" security.port_isolation=true --project "$project"
    else
        incus profile device add default eth0 nic network="$network" name=eth0 security.port_isolation=true \
            --project "$project"
    fi

    # Restricted project: no privileged or nested containers, only the shared cache host path, and no network other than the
    # task bridge. Low-level VM config stays allowed, because medium and large VMs set `raw.qemu.conf` for free page
    # reporting; only the Gateway sets instance config here, never a guest.
    incus project set "$project" restricted=true "restricted.networks.access=$network" restricted.virtual-machines.lowlevel=allow \
        restricted.devices.disk=allow restricted.devices.disk.paths=/var/lib/orbit/package-cache

    # Stock Ubuntu cloud image, copied once.
    if ! exists incus image show "$image" --project "$project"; then
        incus image copy images:ubuntu/26.04/cloud local: --vm --alias "$image" --target-project "$project"
    fi

    # Routed traffic from every Orbit task bridge. Return traffic uses ufw's ESTABLISHED accept.
    # The rule trusts the prefix: only this script creates `orbittask*` bridges, always with the ACL.
    ufw route allow in on 'orbittask+' comment 'orbit-task-vms'

    printf '{"ok":true}\n' >&3
}

if [ "$#" -eq 2 ] && [ "$1" = --ci-runner ]; then
    ci_runner_host "$2"
else
    main "$@"
fi
