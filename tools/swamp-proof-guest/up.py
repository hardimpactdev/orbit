#!/usr/bin/python3
"""Bring up the minimal proof topology inside one owned task VM, as root.

This VM runs the Gateway from the staged commit and pinned Swamp serve with one
worker named orbit-node-209, laid out as the swamp role leaves Node 209: serve on loopback
behind the swamp.orbit Route, the node-proxy key and both role workers' tokens (version 17). One nested copy of the seed's node-template plays
the target Node. Prints one JSON line with public facts and step timings.
"""
import hashlib
import ipaddress
import json
import os
import secrets
import subprocess
import sys
import time
from pathlib import Path

# `python3 -I` drops the script directory from sys.path, so name it for the sibling module.
sys.path.insert(0, str(Path(__file__).resolve().parent))
import fixture  # noqa: E402

SOURCE = Path(sys.argv[1]).resolve()
TOPOLOGY = json.loads((SOURCE / 'swamp/proof/topologies' / (sys.argv[2] if len(sys.argv) > 2 else 'minimal') / 'topology.json').read_text())
# --fresh replaces the nested Nodes, for a fixture that needs a clean end state on a held VM.
FRESH = '--fresh' in sys.argv[3:]
ACCOUNT = 'orbit'
HOME = Path('/home/orbit')
STATE = HOME / '.orbit/swamp-proof'
REPO = SOURCE / 'swamp'
GATEWAY = SOURCE / 'apps/gateway'
BINARY = '/usr/local/bin/swamp'
# Serve listens where the swamp role's units put it: loopback port 9418, with Caddy serving swamp.orbit (version 17).
PORT = 9418
URL = f'ws://127.0.0.1:{PORT}'
ROLE_CREDENTIALS = Path('/etc/orbit/swamp-role-worker')
AGENT = STATE / 'agent'
IDENTITY = HOME / '.orbit/ssh/proof_worker_ed25519'
BOOTSTRAP = HOME / '.orbit/ssh/id_ed25519'
FIXTURE = fixture.context(SOURCE, TOPOLOGY)
timings = []


def run(*args, **kwargs):
    return fixture.run(FIXTURE, *args, **kwargs)


def step(name):
    def wrap(function):
        def inner(*args):
            started = time.time()
            value = function(*args)
            timings.append({'step': name, 'seconds': round(time.time() - started, 1)})
            print(f'swamp-proof up: {name} {timings[-1]["seconds"]}s', file=sys.stderr, flush=True)
            return value
        return inner
    return wrap


def native(*args, **kwargs):
    return fixture.native(FIXTURE, *args, **kwargs)


def private(*args, **kwargs):
    return fixture.private(FIXTURE, *args, **kwargs)


@step('target')
def target():
    """Every Node of the topology; the first is the fixtures' target."""
    run(['bin/e2e-topology-host-setup'], cwd=SOURCE)
    network = TOPOLOGY['network']
    if run(['incus', 'network', 'list', '--format', 'csv', '--columns', 'n']).split().count(network['name']) == 0:
        run(['incus', 'network', 'create', network['name'], f'ipv4.address={network["gateway"]}/{network["prefix"]}',
             'ipv4.nat=true', 'ipv6.address=none'])
    if not IDENTITY.exists():
        IDENTITY.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'swamp-proof-worker', '-f', str(IDENTITY)])
        run(['chown', '-R', 'orbit:orbit', str(IDENTITY.parent)])
    # The node-proxy key an operator places before the first swamp role, and the role's identity it installs from it,
    # with the owner and mode the role's own step gives it (ADR 0243, version 17).
    run(['install', '-D', '-o', 'root', '-g', 'root', '-m', '0600', str(IDENTITY), '/etc/orbit/node-proxy/id_ed25519'])
    run(['install', '-D', '-o', 'root', '-g', 'orbit', '-m', '0440', str(IDENTITY), str(ROLE_CREDENTIALS / 'ssh-identity')])
    # The Gateway's bootstrap key, which an enrolled Node accepts beside the worker key (version 6).
    if not BOOTSTRAP.exists():
        BOOTSTRAP.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'swamp-proof-gateway', '-f', str(BOOTSTRAP)])
        run(['chown', '-R', f'{ACCOUNT}:{ACCOUNT}', str(BOOTSTRAP.parent)])
    existing = run(['incus', 'list', '--format', 'csv', '--columns', 'n']).split()
    for node in TOPOLOGY['nodes']:
        if FRESH and node['key'] in existing:
            run(['incus', 'delete', '--force', node['key']])
    return [prepare(node, network) for node in TOPOLOGY['nodes']]


def status(name):
    """The nested Node's Incus status, such as Running or Stopped."""
    return json.loads(run(['incus', 'query', f'/1.0/instances/{name}/state']))['status']


def evidence(name, state):
    """What a failed nested Node shows: its status and the end of its console."""
    console = subprocess.run(['incus', 'console', name, '--show-log'], capture_output=True, text=True, check=False).stdout
    return f'status {state}; console tail:\n' + '\n'.join(console.strip().splitlines()[-20:])


def prepare(node, network):
    # Re-running up on a held VM keeps the target it already made, and starts it again when it stopped.
    if node['key'] not in run(['incus', 'list', '--format', 'csv', '--columns', 'n']).split():
        run(['incus', 'copy', 'node-template', node['key'], '-c', f'limits.memory={node["memory"]}', '-c', 'limits.cpu=1',
             '-c', 'cloud-init.user-data=#cloud-config\nusers: []\n'])
        run(['incus', 'config', 'device', 'add', node['key'], 'eth0', 'nic', f'network={network["name"]}', f'ipv4.address={node["address"]}'])
    if status(node['key']) != 'Running':
        run(['incus', 'start', node['key']])
    deadline = time.monotonic() + 900
    while subprocess.run(['incus', 'exec', node['key'], '--', 'true'], capture_output=True, check=False).returncode:
        # A Node that stopped while booting never answers; say so now instead of at the deadline.
        state = status(node['key'])
        if state in ('Stopped', 'Error') or time.monotonic() > deadline:
            raise RuntimeError(f'{node["key"]} agent did not start: {evidence(node["key"], state)}')
        time.sleep(2)
    # Exit 2 is "done with recoverable errors": the template guest has no Incus datasource.
    waited = subprocess.run(['incus', 'exec', node['key'], '--', 'cloud-init', 'status', '--wait'],
                            capture_output=True, text=True, timeout=600, check=False)
    if waited.returncode not in (0, 2):
        raise RuntimeError(f'{node["key"]} cloud-init did not finish (exit {waited.returncode}: {(waited.stdout + waited.stderr).strip()[-500:]}); '
                           + evidence(node['key'], status(node['key'])))
    run(['incus', 'file', 'push', str(SOURCE / 'apps/e2e/resources/guest/prepare-node.sh'), f'{node["key"]}/root/prepare-node.sh'])
    run(['incus', 'exec', node['key'], '--', 'bash', '/root/prepare-node.sh', 'configure-runtime'])
    public = IDENTITY.with_suffix('.pub').read_text().split()
    run(['incus', 'exec', node['key'], '--', 'bash', '/root/prepare-node.sh', 'gateway-authorize', ' '.join(public[:2])])
    bootstrap = ' '.join(BOOTSTRAP.with_suffix('.pub').read_text().split()[:2]) + ' swamp-proof-gateway'
    run(['incus', 'exec', node['key'], '--', 'sh', '-c', 'grep -qxF "$1" /home/orbit/.ssh/authorized_keys || printf "%s\\n" "$1" >> /home/orbit/.ssh/authorized_keys',
         'sh', bootstrap])
    # Unmanaged SSH allowance and an active backend: the state an enrolled Node has.
    run(['incus', 'exec', node['key'], '--', 'sh', '-c', 'ufw allow 22/tcp comment proof-ssh && ufw --force enable'])
    # The fleet's member trust, exactly as the Gateway's firewall catalog renders it, so doctor finds no drift (version 15).
    run(['incus', 'exec', node['key'], '--', 'ufw', 'allow', 'in', 'on', 'orbit', 'to', node['address'], 'comment', 'orbit:wireguard-members'])
    # The proof network has no `orbit` interface (the guest NIC name varies), so an unmanaged rule lets 209 reach Node ports.
    run(['incus', 'exec', node['key'], '--', 'ufw', 'allow', 'from',
         str(ipaddress.ip_network(f'{network["gateway"]}/{network["prefix"]}', strict=False)),
         'to', node['address'], 'comment', 'proof-network'])
    if 'app-dev' in node.get('roles', []):
        # The web runtime app-dev role convergence leaves: an Orbit-managed Caddyfile, the hibernation log
        # directory its sites name, and active PHP-FPM masters for the shared pools (version 7, for Route kinds).
        run(['incus', 'exec', node['key'], '--', 'sh', '-c', 'set -e; printf "%s\\n" "# Managed by Orbit: Node Caddy build" "{" '
             '"    auto_https disable_certs" "    order abort first" "    metrics {" "        per_host" "    }" "}" > /etc/caddy/Caddyfile; '
             'chown root:caddy /etc/caddy/Caddyfile; chmod 0640 /etc/caddy/Caddyfile; '
             'install -d -o caddy -g caddy -m 0750 /data/caddy/orbit/hibernation; '
             'systemctl enable --now caddy php8.5-fpm php8.4-fpm; systemctl reload-or-restart caddy'])
    host_key = run(['incus', 'exec', node['key'], '--', 'cat', '/etc/ssh/ssh_host_ed25519_key.pub']).split()
    deadline = time.monotonic() + 120
    while subprocess.run(['ssh', '-i', str(IDENTITY), '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=no',
                          '-o', 'UserKnownHostsFile=/dev/null', '-o', 'ConnectTimeout=5', f'orbit@{node["address"]}', 'sudo', '-n', 'true'],
                         capture_output=True, check=False).returncode:
        if time.monotonic() > deadline:
            raise RuntimeError(f'{node["key"]} SSH with sudo is not reachable from the Swamp host')
        time.sleep(2)
    # An enrolled Node's agent key; the Gateway records only its verifier (topology version 5).
    secret = secrets.token_hex(32)
    run(['incus', 'exec', node['key'], '--', 'sh', '-c', 'install -d -m 0700 /etc/orbit/agent && umask 077 && cat > /etc/orbit/agent/secret'],
        input=secret)
    return {'key': node['key'], 'address': node['address'], 'host_key_type': host_key[0], 'host_key': host_key[1],
            'roles': node.get('roles', []), 'settings': node.get('settings', {}),
            'agent_secret_hash': hashlib.sha256(secret.encode()).hexdigest()}


def grants(*args, **kwargs):
    return fixture.grants(FIXTURE, *args, **kwargs)


@step('swamp')
def swamp(*args, **kwargs):
    return fixture.swamp(FIXTURE, *args, **kwargs)


@step('gateway')
def gateway(*args, **kwargs):
    return fixture.gateway(FIXTURE, *args, **kwargs)


def leaf(*args, **kwargs):
    return fixture.leaf(FIXTURE, *args, **kwargs)


def observe(*args, **kwargs):
    return fixture.observe(FIXTURE, *args, **kwargs)


def route_leaf(*args, **kwargs):
    return fixture.route_leaf(FIXTURE, *args, **kwargs)


def serve_gateway(*args, **kwargs):
    return fixture.serve_gateway(FIXTURE, *args, **kwargs)


def publish_caddy(*args, **kwargs):
    return fixture.publish_caddy(FIXTURE, *args, **kwargs)


def request(path, body, seconds=600):
    """One real API call as the operator through the Gateway HTTP kernel; an Operation is followed to its end."""
    reply = json.loads(run(['php', str(SOURCE / 'tools/swamp-proof-guest/gateway.php'), 'request'], user=ACCOUNT, cwd=GATEWAY, timeout=seconds + 120,
                           input=json.dumps({'caller': TOPOLOGY['operator']['address'], 'method': 'POST', 'path': path, 'body': body,
                                             'key': 'up-' + path.replace('/', '-'), 'seconds': seconds})).strip().splitlines()[-1])
    if (reply.get('operation') or {}).get('status') != 'succeeded':
        raise RuntimeError(f'{path} did not succeed: ' + json.dumps({k: reply.get(k) for k in ('http_status', 'operation', 'job_errors')})[:1500])


@step('realtime')
def realtime(ids):
    fixture.realtime(FIXTURE, ids)
    delivery = SOURCE.parent / 'agent-delivery/orbit-agent-linux-x86_64'
    for node in TOPOLOGY['nodes']:
        if not node.get('agent'):
            continue
        request(f'nodes/{ids[node["key"]]}/converge', {})
        if delivery.is_file():
            # A running binary cannot be replaced in place ("text file busy"), so the agent stops first.
            run(['incus', 'exec', node['key'], '--', 'systemctl', 'stop', 'orbit-agent'])
            run(['incus', 'file', 'push', '--mode', '0755', str(delivery), f'{node["key"]}/usr/local/bin/orbit-agent'])
            run(['incus', 'exec', node['key'], '--', 'systemctl', 'start', 'orbit-agent'])
        run(['incus', 'exec', node['key'], '--', 'systemctl', 'is-active', '--quiet', 'orbit-agent'])


def main():
    if os.geteuid() != 0:
        raise RuntimeError('run as root')
    nodes = target()
    swamp()
    seeded = gateway(nodes)
    realtime(seeded['nodes'])
    facts = {'ready': True, 'commit': run(['git', '-C', str(SOURCE), 'rev-parse', 'HEAD'], user=ACCOUNT).strip(),
             'topology': TOPOLOGY['name'], 'topology_version': TOPOLOGY['version'], 'nodes': seeded['nodes'],
             'operator_address': TOPOLOGY['operator']['address'], 'target': nodes[0], 'targets': nodes, 'timings': timings}
    (STATE / 'up.json').write_text(json.dumps(facts))
    print(json.dumps(facts))


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, subprocess.SubprocessError, KeyError, ValueError, TypeError) as error:
        print(json.dumps({'ready': False, 'error': str(error), 'timings': timings}))
        sys.exit(1)
