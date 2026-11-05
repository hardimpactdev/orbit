"""Ready copies of the nested fleet: stopped copies of a healthy gateway, app-dev and app-prod.

A reset from ready copies replaces the three guests of the active generation in place: delete them, copy the stopped
copies back under the generation's names, give each copy its generation again, start. A copy is reflinked, so this takes
seconds; the guests then boot with all their Orbit state already on disk.

A ready copy carries nothing that belongs to one generation, so it never blocks release or cleanup:

- no ``user.orbit.e2e.*`` label (the inventory and the harness release see only the guests of an attempt);
- no ``eth0`` device (a stopped NIC would keep the network in use, and Incus refuses a second VM that holds the same
  static MAC or IP); the MAC, the IP and the subnet are in ``user.ready.*`` keys;
- no ``orbit-source`` device (it names one generation's source directory); the mount path is in ``user.ready.mounts``.

``user.ready.complete`` is written last, on every copy, with the set identity. A set without it is half made and unused.
"""
import json
import secrets
import subprocess
import time
from datetime import datetime, timezone

PREFIX = 'fleet-ready-'
ROLES = ('gateway', 'app-dev', 'app-prod')
LABELS = ('owner', 'issue', 'attempt', 'operation')
# Devices that belong to one generation: the NIC (network, MAC, IP) and the source mount.
NIC = 'eth0'
MOUNT = 'orbit-source'
# The swamp and agent tokens minted by a build last 24 h, and the Gateway leaf certificates 2 days. The age counts from
# the mint, not from the copy: a copy of an old fleet is as old as the fleet.
MAX_AGE_SECONDS = 20 * 3600


def incus(args, *, data=None, timeout=120):
    result = subprocess.run(['incus', *map(str, args)], input=data, capture_output=True, timeout=timeout)
    if result.returncode:
        raise RuntimeError(f'incus exited {result.returncode}: ' + (result.stderr + result.stdout).decode(errors='replace')[-3000:])
    return result.stdout


def name(role):
    return PREFIX + role


def stamp():
    return datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


def instances():
    return {row['name']: row for row in json.loads(incus(['list', '--format=json']))}


def copies():
    return {key: row for key, row in instances().items() if key.startswith(PREFIX)}


def network_of(instance):
    """The network of the NIC of one instance."""
    devices = json.loads(incus(['query', '/1.0/instances/' + instance]))['devices']
    if NIC not in devices or 'network' not in devices[NIC]:
        raise RuntimeError(f'{instance} has no managed NIC {NIC}.')
    return devices[NIC]['network']


def subnet_of(network):
    return incus(['network', 'get', network, 'ipv4.address']).decode().strip()


def inspect(rows=None, *, now=None):
    """The ready set, or the reason there is none. Never raises for a missing or half made set."""
    rows = copies() if rows is None else rows
    missing = [name(role) for role in ROLES if name(role) not in rows]
    if missing:
        return {'usable': False, 'reason': 'ready copies missing: ' + ', '.join(missing)}
    configs = {role: rows[name(role)].get('config') or {} for role in ROLES}
    sets = {config.get('user.ready.set') for config in configs.values()}
    completed = {config.get('user.ready.complete') for config in configs.values()}
    if len(sets) != 1 or None in sets or completed != sets:
        return {'usable': False, 'reason': 'the ready copies are not one complete set'}
    for role in ROLES:
        row, config = rows[name(role)], configs[role]
        if row.get('status') != 'Stopped':
            return {'usable': False, 'reason': f'{name(role)} is not stopped'}
        if config.get('user.ready.role') != role:
            return {'usable': False, 'reason': f'{name(role)} is a copy of another role'}
        if any(key.startswith('user.orbit.e2e.') for key in config):
            return {'usable': False, 'reason': f'{name(role)} still carries generation labels'}
        if {NIC, MOUNT} & set(row.get('devices') or {}):
            return {'usable': False, 'reason': f'{name(role)} still carries a generation device'}
    made = configs['gateway'].get('user.ready.made', '')
    minted = configs['gateway'].get('user.ready.minted', '')
    try:
        age = ((now or datetime.now(timezone.utc)) - datetime.strptime(minted, '%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=timezone.utc)).total_seconds()
    except ValueError:
        return {'usable': False, 'reason': 'the ready copies do not record when their credentials were minted'}
    if age > MAX_AGE_SECONDS:
        return {'usable': False, 'reason': f'the credentials of the ready copies are {int(age // 3600)} h old; their tokens and certificates expire'}
    try:
        nodes = json.loads(configs['gateway'].get('user.ready.nodes', ''))
    except ValueError:
        return {'usable': False, 'reason': 'the Gateway copy does not record the Node ids'}
    return {'usable': True, 'reason': 'ready', 'set': sets.pop(), 'made': made, 'minted': minted, 'nodes': nodes,
            'attempt': configs['gateway'].get('user.ready.attempt'), 'inputs': configs['gateway'].get('user.ready.inputs'),
            'subnet': configs['gateway'].get('user.ready.subnet'),
            'addresses': {role: configs[role].get('user.ready.ipv4') for role in ROLES}}


def stamp_of(epoch):
    return datetime.fromtimestamp(int(epoch), timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


def make(guests, network, nodes, *, minted, attempt, inputs):
    """Stop the running guests and copy them to ready copies; start the originals again. The set is not complete yet.

    ``guests`` maps each role to the instance name, ``network`` is the generation's network and ``nodes`` the Gateway's
    Node ids. ``minted`` is the epoch second the fleet's credentials were minted, ``attempt`` the attempt of the fleet and
    ``inputs`` the digest of the files baked into the guests. The originals are started again even when a copy fails.
    The caller calls ``complete`` after the fleet passed its checks.
    """
    subnet = subnet_of(network)
    details = {role: json.loads(incus(['query', '/1.0/instances/' + guests[role]])) for role in ROLES}
    saved = {}
    for role in ROLES:
        devices = details[role].get('devices') or {}
        nic = devices.get(NIC) or {}
        if nic.get('network') != network or not nic.get('hwaddr') or not nic.get('ipv4.address'):
            raise RuntimeError(f'{guests[role]} must have a static MAC and IP on {network} to be copied.')
        saved[role] = {'hwaddr': nic['hwaddr'], 'ipv4': nic['ipv4.address'],
                       'mounts': {key: device['path'] for key, device in devices.items()
                                  if key == MOUNT and device.get('type') == 'disk'}}
    running = [guests[role] for role in ROLES if details[role].get('status') == 'Running']
    identity = secrets.token_hex(8)
    made = stamp()
    failure = None
    try:
        if running:
            incus(['stop', *running], timeout=300)
        for role in ROLES:
            if json.loads(incus(['query', '/1.0/instances/' + guests[role]]))['status'] != 'Stopped':
                raise RuntimeError(f'{guests[role]} is not stopped; nothing copied.')
        for existing in copies():
            incus(['delete', '--force', existing])
        for role in ROLES:
            target = name(role)
            incus(['copy', guests[role], target])
            settings = {'user.ready.set': identity, 'user.ready.role': role, 'user.ready.made': made,
                        'user.ready.minted': stamp_of(minted), 'user.ready.attempt': attempt, 'user.ready.inputs': inputs,
                        'user.ready.hwaddr': saved[role]['hwaddr'], 'user.ready.ipv4': saved[role]['ipv4'],
                        'user.ready.subnet': subnet, 'user.ready.mounts': json.dumps(saved[role]['mounts'])}
            if role == 'gateway':
                settings['user.ready.nodes'] = json.dumps(nodes)
            incus(['config', 'set', target, *(f'{key}={value}' for key, value in settings.items())])
            if 'user.ready.complete' in (details[role].get('config') or {}):
                incus(['config', 'unset', target, 'user.ready.complete'])
            for label in LABELS:
                incus(['config', 'unset', target, 'user.orbit.e2e.' + label])
            for device in (NIC, MOUNT):
                if device in (details[role].get('devices') or {}):
                    incus(['config', 'device', 'remove', target, device])
    except Exception as error:
        failure = error
    if failure is not None:
        # A half-made copy would still hold the original's MAC and IP and keep it from starting.
        for existing in copies():
            incus(['delete', '--force', existing])
    # The fleet runs again whether or not the copies succeeded. The first error is the one reported.
    started = time.time()
    try:
        incus(['start', *(guests[role] for role in ROLES)], timeout=300)
    except Exception as error:
        raise (failure or error) from (error if failure else None)
    if failure is not None:
        raise failure
    return {'set': identity, 'made': made, 'subnet': subnet, 'copies': [name(role) for role in ROLES], 'started_at': started}


def complete(identity):
    """Mark the set usable. Only the last step of `ready` calls this, after the fleet it was copied from passed its checks."""
    rows = copies()
    for role in ROLES:
        if (rows.get(name(role)) or {}).get('config', {}).get('user.ready.set') != identity:
            raise RuntimeError(f'{name(role)} is not part of set {identity}; nothing completed.')
    for role in ROLES:
        incus(['config', 'set', name(role), 'user.ready.complete=' + identity])


def discard_incomplete():
    """Delete the copies that are not part of a complete set: what a failed `ready` leaves."""
    removed = []
    for existing, row in copies().items():
        config = row.get('config') or {}
        if not config.get('user.ready.complete') or config.get('user.ready.complete') != config.get('user.ready.set'):
            incus(['delete', '--force', existing])
            removed.append(existing)
    return removed


def drop():
    """Delete every ready copy: a full reset builds a new fleet, so the old copies are stale."""
    removed = list(copies())
    if removed:
        incus(['delete', '--force', *removed], timeout=300)
    return removed


def restore(guests, network, source, labels):
    """Replace the guests of the active generation with their ready copies, and start them.

    ``labels`` maps each of LABELS to the generation's value and ``source`` is the generation's source directory.
    Returns the time just before the start, which later freshness checks measure from.
    """
    found = inspect()
    if not found['usable']:
        raise RuntimeError('Ready copies are not usable: ' + found['reason'])
    rows = instances()
    present = [guests[role] for role in ROLES if guests[role] in rows]
    if present:
        incus(['delete', '--force', *present], timeout=300)
    for role in ROLES:
        target, config = guests[role], rows[name(role)].get('config') or {}
        incus(['copy', name(role), target])
        incus(['config', 'set', target, *(f'user.orbit.e2e.{label}={labels[label]}' for label in LABELS)])
        incus(['config', 'device', 'add', target, NIC, 'nic', 'network=' + network,
               'hwaddr=' + config['user.ready.hwaddr'], 'ipv4.address=' + config['user.ready.ipv4']])
        for device, path in json.loads(config.get('user.ready.mounts') or '{}').items():
            incus(['config', 'device', 'add', target, device, 'disk', 'source=' + str(source), 'path=' + path])
    started = time.time()
    incus(['start', *(guests[role] for role in ROLES)], timeout=300)
    return {'set': found['set'], 'started_at': started, 'guests': [guests[role] for role in ROLES]}
