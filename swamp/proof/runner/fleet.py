"""The nested fleet as the runner sees it: shell on a Node and the real `orbit` CLI. Tests replace this class."""
import json
import os
import select
import subprocess
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
# The prompt driver and the ufw shim loop by nature, so they live with the guest programs, outside the Swamp-shape
# rules for step code.
sys.path.insert(0, str(Path(__file__).resolve().parents[3] / 'tools/swamp-proof-guest'))
import terminal  # noqa: E402

# The candidate CLI of the checkout this runner runs from, with the profile `nested` that the fleet start adds on the outer VM.
ORBIT = ['php', str(Path(__file__).resolve().parents[3] / 'apps/cli/orbit')]
CLI_ENV = {'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': '/home/orbit', 'ORBIT_HOME': '/home/orbit/.orbit'}
NODE_HOME = '/home/orbit'
NODE_USER = '1000'   # the managed user `orbit` of a Node
ROLES = ('gateway', 'app-dev', 'app-prod')
SKIPPED_INTERFACES = ('lo', 'orbit', 'docker0')
GUEST_PREFIX = 'nested-'


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

    def cli(self, args, timeout=240, form='json', node=None):
        """Run the CLI without a terminal. The human form leaves out `--json`. With `node` the call is the `orbit` installed on that
        Node, run as its managed user: the Gateway sees that Node as the caller."""
        started = time.time()
        words = [*args, '--json'] if form == 'json' else list(args)
        if node:
            command = ['incus', 'exec', self.nodes[node]['instance'], '--user', NODE_USER, '--cwd', NODE_HOME, '--env', 'HOME=' + NODE_HOME, '--', 'orbit', *words]
            completed = subprocess.run(command, capture_output=True, text=True, timeout=timeout, stdin=subprocess.DEVNULL)
        else:
            completed = subprocess.run([*ORBIT, *words], capture_output=True, text=True, timeout=timeout, env=CLI_ENV, cwd='/',
                                       stdin=subprocess.DEVNULL)
        try:
            parsed = json.loads(completed.stdout) if form == 'json' else None
        except ValueError:
            parsed = None
        return {'rc': completed.returncode, 'json': parsed, 'stdout': completed.stdout, 'stderr': completed.stderr,
                'secs': round(time.time() - started, 2), 'args': ['orbit', *words]}

    def cli_background(self, args):
        """Start the CLI in its own session and return a handle. The Gateway keeps the Operation when the handle stops the CLI."""
        process = subprocess.Popen([*ORBIT, *args], stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True,
                                   env=CLI_ENV, cwd='/', start_new_session=True)
        return Background(process)

    def cli_tty(self, args, script, timeout=120):
        """Run the CLI in a terminal and answer its prompts (see terminal.py)."""
        started = time.time()
        done = terminal.drive([*ORBIT, *args], script, {**CLI_ENV, 'TERM': 'xterm-256color'}, timeout)
        return {'rc': done['rc'], 'json': None, 'stdout': done['text'], 'stderr': '', 'secs': round(time.time() - started, 2), 'args': ['orbit', *args]}


class Background:
    """A CLI call that runs while a case goes on. `started` waits for its first line: the CLI prints it when the Gateway has admitted the
    request, so the Operation exists and holds its target."""

    def __init__(self, process):
        self.process = process

    def started(self, seconds):
        ready, _, _ = select.select([self.process.stdout], [], [], seconds)
        return bool(ready) and bool(self.process.stdout.readline())

    def finish(self, seconds):
        """Wait for the CLI to end on its own; stop it if it does not end in time."""
        try:
            self.process.wait(seconds)
        except subprocess.TimeoutExpired:
            self.process.terminate()
            self.process.wait()
        self.process.stdout.close()


def incus_guests():
    listed = subprocess.run(['incus', 'list', '--format', 'json'], capture_output=True, text=True, timeout=60, stdin=subprocess.DEVNULL)
    if listed.returncode:
        raise FleetError('incus list failed: ' + listed.stderr.strip()[-200:])
    return json.loads(listed.stdout)


def bridge_address(guest, family='inet'):
    for name, interface in ((guest.get('state') or {}).get('network') or {}).items():
        if name in SKIPPED_INTERFACES:
            continue
        for address in interface.get('addresses', []):
            if address.get('family') == family and address.get('scope') == 'global':
                return address['address']
    return None


def discover(guests=None, node_ids=None):
    """Find the three nested guests by the names `task-nested-fleet` gives them: `nested-<role>`."""
    guests = incus_guests() if guests is None else guests
    running = {guest['name']: guest for guest in guests if guest.get('status') == 'Running'}
    nodes = {}
    ids = node_ids if node_ids is not None else listed_node_ids()
    for role in ROLES:
        guest = running.get(GUEST_PREFIX + role)
        if guest is None or role not in ids:
            raise FleetError(f'nested node missing: {role}')
        bridge = bridge_address(guest)
        if bridge is None:
            raise FleetError(f'{role}: no bridge address')
        nodes[role] = {'instance': guest['name'], 'node_id': ids[role], 'bridge': bridge}
        if bridge6 := bridge_address(guest, 'inet6'):  # set by the batch setup `ipv6_addresses`, which runs in another process
            nodes[role]['bridge6'] = bridge6
    return Fleet(nodes)


def listed_node_ids():
    listed = Fleet({}).cli(['node:list'])
    if listed['rc'] or not listed['json']:
        raise FleetError('node:list failed: ' + str(listed['rc']))
    return {node['name']: node['id'] for node in listed['json']['nodes']}


def from_environment():
    return discover()


def evidence_root(env=os.environ):
    return Path(env.get('PROOF_EVIDENCE') or Path.cwd() / 'proof-evidence')
