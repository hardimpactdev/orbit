"""The nested fleet as the runner sees it: shell on a Node and the real `orbit` CLI. Tests replace this class."""
import json
import os
import subprocess
import time
from pathlib import Path

ORBIT = '/usr/local/bin/orbit-nested'
CLI_ENV = {'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': '/home/orbit', 'ORBIT_HOME': '/home/orbit/.orbit'}
ROLES = ('gateway', 'app-dev', 'app-prod')
SKIPPED_INTERFACES = ('lo', 'orbit', 'docker0')


class FleetError(Exception):
    pass


class Fleet:
    """`nodes` maps a role to `{instance, node_id, bridge}`: the Incus guest, the Node ID in the nested Gateway
    and the address the other Nodes use to reach it."""

    def __init__(self, nodes):
        self.nodes = nodes

    def sh(self, node, command, timeout=60, stdin_text=None):
        try:
            return subprocess.run(['incus', 'exec', self.nodes[node]['instance'], '--', 'sh', '-c', command], capture_output=True, text=True,
                                  timeout=timeout, input=stdin_text, stdin=None if stdin_text is not None else subprocess.DEVNULL)
        except subprocess.TimeoutExpired as error:
            return subprocess.CompletedProcess(['incus', 'exec', node], 124, error.stdout or '', 'timeout')

    def cli(self, args, timeout=240):
        started = time.time()
        completed = subprocess.run([ORBIT, *args, '--json'], capture_output=True, text=True, timeout=timeout, env=CLI_ENV, cwd='/',
                                   stdin=subprocess.DEVNULL)
        try:
            parsed = json.loads(completed.stdout)
        except ValueError:
            parsed = None
        return {'rc': completed.returncode, 'json': parsed, 'stdout': completed.stdout, 'stderr': completed.stderr,
                'secs': round(time.time() - started, 2), 'args': ['orbit', *args, '--json']}


def incus_guests():
    listed = subprocess.run(['incus', 'list', '--format', 'json'], capture_output=True, text=True, timeout=60, stdin=subprocess.DEVNULL)
    if listed.returncode:
        raise FleetError('incus list failed: ' + listed.stderr.strip()[-200:])
    return json.loads(listed.stdout)


def bridge_address(guest):
    for name, interface in ((guest.get('state') or {}).get('network') or {}).items():
        if name in SKIPPED_INTERFACES:
            continue
        for address in interface.get('addresses', []):
            if address.get('family') == 'inet' and address.get('scope') == 'global':
                return address['address']
    return None


def discover(prefix=None, guests=None, node_ids=None):
    """Find the three nested guests. `prefix` is `orbit-e2e-task-<group>-<id>`; without it exactly one complete set must run."""
    guests = incus_guests() if guests is None else guests
    running = {guest['name']: guest for guest in guests if guest.get('status') == 'Running'}
    prefixes = sorted({name[:-len(role) - 1] for name in running for role in ROLES if name.endswith('-' + role) and name.startswith('orbit-e2e-task-')})
    complete = [item for item in prefixes if all(f'{item}-{role}' in running for role in ROLES)]
    if prefix is None:
        if len(complete) != 1:
            raise FleetError(f'expected one complete nested fleet, found {complete}; set PROOF_PREFIX')
        prefix = complete[0]
    nodes = {}
    ids = node_ids if node_ids is not None else listed_node_ids()
    for role in ROLES:
        guest = running.get(f'{prefix}-{role}')
        if guest is None or role not in ids:
            raise FleetError(f'nested node missing: {role}')
        bridge = bridge_address(guest)
        if bridge is None:
            raise FleetError(f'{role}: no bridge address')
        nodes[role] = {'instance': guest['name'], 'node_id': ids[role], 'bridge': bridge}
    return Fleet(nodes)


def listed_node_ids():
    listed = Fleet({}).cli(['node:list'])
    if listed['rc'] or not listed['json']:
        raise FleetError('node:list failed: ' + str(listed['rc']))
    return {node['name']: node['id'] for node in listed['json']['nodes']}


def from_environment(env=os.environ):
    return discover(env.get('PROOF_PREFIX'))


def evidence_root(env=os.environ):
    return Path(env.get('PROOF_EVIDENCE') or Path.cwd() / 'proof-evidence')
