#!/usr/bin/env python3
"""Bounded phase mechanics. Ordering and failure branches live in native YAML."""
import hashlib
import ipaddress
import json
import os
import re
import secrets
import signal
import subprocess
import sys
import tarfile
import tempfile
import time
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime
from pathlib import Path

import ready
from launcher import unrun_case, write_json


def checked(result, program):
    if result.returncode:
        raise RuntimeError(f'{program} exited {result.returncode}: ' + (result.stderr + result.stdout).decode(errors='replace')[-3000:])
    return result.stdout


def incus(args, *, data=None, timeout=120):
    return checked(subprocess.run(['incus', *map(str, args)], input=data,
                                  capture_output=True, timeout=timeout), 'incus')


def systemctl(args):
    return checked(subprocess.run(['systemctl', *args], capture_output=True, timeout=120), 'systemctl')


# The nested topology's own private network. The outer VM is a roleless member (wg-nested, the Node record `operator`)
# beside the Gateway hub and the targets; its real `orbit` interface and 10.44.0.0/24 are never touched.
WG_DIR = Path('/home/orbit/.orbit/proof-wg')
WG_SUBNET = '10.45.0.0/24'
WG_ADDRESSES = {'gateway': '10.45.0.1', 'app-dev': '10.45.0.2', 'app-prod': '10.45.0.3', 'operator': '10.45.0.10'}
WG_PORT = 51820
WG_OUTER_PORT = 51821
WG_OUTER = 'wg-nested'
NESTED_CLI = '/usr/local/bin/orbit-nested'
NESTED_PROFILE = 'nested'
CLI_ENV = {'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': '/home/orbit', 'ORBIT_HOME': '/home/orbit/.orbit'}

# What the guests write into the generation's source directory while the Gateway is built (`.env`, caches, logs, the
# agent manifest) and the attempt lease. A reset from ready copies carries these into the next generation's fresh export.
CARRIED_STATE = ('.e2e', 'apps/agent/delivery', 'apps/gateway/.env', 'apps/gateway/bootstrap/cache', 'apps/gateway/storage')
# The directories Caddy in the Gateway guest traverses to reach the public directory; the build gave them an ACL for it.
ACL_DIRECTORIES = ('.', 'apps', 'apps/gateway', 'apps/gateway/public')
# A boot is not an observation: the guests need about 50 s from `incus start` to a healthy fleet. The bound is fixed.
READY_WAIT_SECONDS = 150
ACTIONS = ('inventory', 'release-previous', 'snapshot', 'snapshot-ready', 'prepare', 'swamp-state', 'gateway-db',
           'gateway-runtime', 'restore-ready', 'ready-wait', 'agents-online', 'candidate', 'operator', 'transport-readiness',
           'observer-facts', 'readiness', 'ready-check', 'ready-make', 'publish', 'prune-generations', 'failure-cleanup',
           'release-active', 'cases')


def privileged(argv, *, data=None, timeout=60):
    return checked(subprocess.run(['sudo', '-n', *argv], input=data, capture_output=True, timeout=timeout), argv[0])


def orbit_home():
    """The CLI's private home on the outer VM; the CLI refuses a configuration directory others can read."""
    home = Path(CLI_ENV['ORBIT_HOME'])
    home.mkdir(parents=True, exist_ok=True)
    home.chmod(0o700)
    return home


def wg_key(name):
    """The fixed key pair of one member, kept on the outer VM so every reset and every ready copy agree. Never printed."""
    private = WG_DIR / (name + '.key')
    if not private.exists():
        orbit_home()
        WG_DIR.mkdir(exist_ok=True, mode=0o700)
        value = checked(subprocess.run(['wg', 'genkey'], capture_output=True, timeout=30), 'wg')
        descriptor = os.open(private, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(value)
    secret = private.read_bytes()
    return secret.decode().strip(), checked(subprocess.run(['wg', 'pubkey'], input=secret, capture_output=True, timeout=30), 'wg').decode().strip()


def wireguard(topology):
    """The public facts of the nested private network; private keys stay in wg_key() callers."""
    keys = [key for key in topology['nodes'] if key != 'operator']
    return {'subnet': WG_SUBNET, 'endpoint': topology['nodes']['gateway']['incus_address'] + ':' + str(WG_PORT),
            'nodes': {key: {'ip': WG_ADDRESSES[key], 'public_key': wg_key(key)[1]} for key in [*keys, 'operator']}}


def wg_config(key, network, endpoint):
    """orbit.conf for a nested Node (hub on the Gateway), or wg-nested.conf for the outer VM: AllowedIPs only the nested
    subnet, no DNS line and no default route, so the real fleet's tunnel and resolver stay as they are."""
    members = network['nodes']
    lines = ['[Interface]', f"Address = {members[key]['ip']}/24", 'PrivateKey = ' + wg_key(key)[0]]
    if key == 'gateway':
        lines.append(f'ListenPort = {WG_PORT}')
        for peer, member in members.items():
            if peer != 'gateway':
                lines += ['', '[Peer]', 'PublicKey = ' + member['public_key'], f"AllowedIPs = {member['ip']}/32"]
    else:
        if key == 'operator':
            lines.append(f'ListenPort = {WG_OUTER_PORT}')
        lines += ['', '[Peer]', 'PublicKey = ' + members['gateway']['public_key'], 'Endpoint = ' + endpoint,
                  f'AllowedIPs = {WG_SUBNET}', 'PersistentKeepalive = 25']
    return '\n'.join(lines) + '\n'


def bring_up_wireguard(topology, network):
    """Hub first, then the peers and the outer VM's second interface, before anything listens on a nested address."""
    for key in sorted((k for k in topology['nodes'] if k != 'operator'), key=lambda k: k != 'gateway'):
        instance = topology['nodes'][key]['instance']
        incus(['exec', instance, '--', 'sh', '-c', 'umask 077; install -d -m 0700 /etc/wireguard; cat > /etc/wireguard/orbit.conf'],
              data=wg_config(key, network, network['endpoint']).encode())
        incus(['exec', instance, '--', 'systemctl', 'enable', 'wg-quick@orbit'])
        incus(['exec', instance, '--', 'systemctl', 'restart', 'wg-quick@orbit'])
    configuration = wg_config('operator', network, network['endpoint']).encode()
    path = '/etc/wireguard/wg-nested.conf'
    try:
        current = privileged(['cat', path], timeout=30)
    except RuntimeError:
        current = b''  # no configuration yet
    if current != configuration:
        privileged(['sh', '-c', f'umask 077; cat > {path}'], data=configuration)
        privileged(['systemctl', 'enable', f'wg-quick@{WG_OUTER}'])
        privileged(['systemctl', 'restart', f'wg-quick@{WG_OUTER}'])
    else:
        privileged(['systemctl', 'enable', '--now', f'wg-quick@{WG_OUTER}'])
    # A recreated Gateway cannot read the old session; one packet starts the handshake at once.
    subprocess.run(['ping', '-c', '1', '-W', '3', network['nodes']['gateway']['ip']], capture_output=True, timeout=15, check=False)


def force_handshake(address):
    """Make the outer VM's tunnel handshake now. With a deadline, ping keeps sending until the first reply: one blocking
    command, so a Gateway that restarted or sat idle cannot leave the last handshake too old."""
    subprocess.run(['ping', '-c', '1', '-w', '60', address], capture_output=True, timeout=90, check=False)


def tunnel_ready(network, *, seconds=120):
    """The outer VM's latest handshake with the Gateway hub is recent and the real interface is still the fleet's."""
    published = wg_key('gateway')[1]
    rows = privileged(['wg', 'show', WG_OUTER, 'latest-handshakes']).decode().split()
    stamps = dict(zip(rows[0::2], rows[1::2]))
    age = datetime.now().timestamp() - int(stamps.get(published, '0'))
    if age > seconds:
        raise RuntimeError(f'{WG_OUTER} has no handshake with the nested Gateway in the last {seconds} s.')
    return round(age, 1)


def git(candidate, *args, env=None):
    return checked(subprocess.run(['git', '-C', str(candidate), *map(str, args)],
                                  env={**os.environ, 'GIT_OPTIONAL_LOCKS': '0', **(env or {})},
                                  capture_output=True, timeout=120), 'git')


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest() if path.exists() else None


def identity(candidate):
    """Use a throwaway index; never refresh or stage the real candidate index."""
    with tempfile.TemporaryDirectory(prefix='orbit-proof-index-') as temporary:
        env = {'GIT_INDEX_FILE': temporary + '/index'}
        git(candidate, 'read-tree', 'HEAD', env=env)
        git(candidate, 'add', '-A', '--', '.', env=env)
        effective = git(candidate, 'write-tree', env=env).decode().strip()
    index = Path(git(candidate, 'rev-parse', '--path-format=absolute', '--git-path', 'index').decode().strip())
    return {'head': git(candidate, 'rev-parse', 'HEAD').decode().strip(),
            'head_tree': git(candidate, 'rev-parse', 'HEAD^{tree}').decode().strip(),
            'effective_tree': effective, 'index_sha256': digest(index)}


def outer_identity():
    units = json.loads(systemctl(['list-units', '--all', '--type=service', '--output=json']))
    pi_units = sorted(row['unit'] for row in units if row['unit'].endswith('-pi-server.service'))
    if not pi_units:
        raise RuntimeError('Outer Pi service is missing; reset cannot claim preservation.')
    return {'pi': {unit: systemctl(['show', unit, '--property=MainPID', '--property=ActiveState',
                             '--property=ExecMainStartTimestampMonotonic']).decode().strip() for unit in pi_units},
            'hostname': os.uname().nodename,
            'boot_id': Path('/proc/sys/kernel/random/boot_id').read_text().strip(),
            # The fleet's own tunnel: the second interface must leave it exactly as it was.
            'orbit_tunnel': privileged(['wg', 'show', 'orbit', 'public-key']).decode().strip(),
            'observer': systemctl(['show', 'orbit-agent.service', '--property=MainPID',
                             '--property=ActiveState', '--property=ExecMainStartTimestampMonotonic']).decode().strip()}


def inventory():
    # Never persist cloud-init, environment, device secrets, or arbitrary guest configuration.
    guests = json.loads(incus(['list', '--format=json']))
    networks = json.loads(incus(['network', 'list', '--format=json']))
    # Incus reports null config for some unmanaged interfaces; null carries no ownership.
    def metadata(row):
        return {k: v for k, v in (row.get('config') or {}).items() if k.startswith('user.orbit.e2e.')}
    return {'guests': [{'name': row['name'], 'status': row['status'], 'metadata': metadata(row)} for row in guests],
            'networks': [{'name': row['name'], 'metadata': metadata(row)} for row in networks]}


def source_for(request, generation):
    if not isinstance(generation, str) or len(generation) != 32 or any(c not in '0123456789abcdef' for c in generation):
        raise RuntimeError('Invalid generation identity.')
    directory = Path(request['root']) / 'generations' / generation
    marker = directory / 'owner.json'
    expected = {k: request[k] for k in ('candidate', 'group')}
    if directory.is_symlink() or not marker.is_file() or json.loads(marker.read_text()) != expected:
        raise RuntimeError('Generation owner does not match the candidate and group.')
    source = directory / 'source'
    if source.is_symlink() or not source.is_dir():
        raise RuntimeError('Generation source is not a real directory.')
    return source


def active_generation(request):
    active = Path(request['root']) / 'active.json'
    return json.loads(active.read_text())['generation'] if active.exists() else None


def harness(request, action, source, guest=None):
    # Bootstrap the isolated app, but retain the original host-wide lock namespace.
    env = {**os.environ, 'PROOF_REQUEST': os.environ['PROOF_REQUEST']}
    result = subprocess.run(['php', str(Path(request['candidate']) / 'swamp/proof/reset/topology.php'),
                             action, str(source), *([guest] if guest else [])], env=env, capture_output=True, timeout=1800)
    return json.loads(checked(result, 'php'))



def gateway_resources(request, prepared, source):
    """The proof Gateway also hosts Swamp; do not change ordinary harness budgets."""
    construction = prepared['construction']
    name = construction['nodes']['gateway']['instance']
    observed = json.loads(incus(['query', '/1.0/instances/' + name]))['config']
    expected = {'owner': 'orbit-e2e', 'issue': construction['issue'],
                'attempt': construction['attempt_id'], 'operation': prepared['source']['operation_id']}
    if any(observed.get('user.orbit.e2e.' + key) != value for key, value in expected.items()):
        raise RuntimeError('Refusing resource changes on a foreign Gateway.')
    # Resizing live memory kills virtiofsd on this Incus/QEMU version. Size offline.
    incus(['stop', name])
    incus(['config', 'set', name, 'limits.memory=2560MiB', 'limits.cpu=2'])
    incus(['start', name])
    harness(request, 'wait-agent', source, guest=name)
    return {'instance': name, 'memory': '2560MiB', 'cpu': 2, 'ownership': expected}

def release(request, generation, label):
    if generation is None:
        result = {'generation': None, 'resources': {}, 'status': 'absent'}
    else:
        source = source_for(request, generation)
        if not (source / '.e2e/attempt.json').exists():
            observed = inventory()
            if any(row['metadata'].get('user.orbit.e2e.issue') == request['issue'] for rows in observed.values() for row in rows):
                raise RuntimeError('Issue resources exist without the recorded attempt; refusing cleanup.')
            result = {'generation': generation, 'resources': {}, 'status': 'not_prepared'}
        else:
            result = {'generation': generation, 'resources': harness(request, 'release', source), 'status': 'released'}
        if active_generation(request) == generation:
            (Path(request['root']) / 'active.json').unlink()
    stop_operator_tunnel()
    write_json(Path(request['evidence']) / (label + '.json'), result)
    return result


def stop_operator_tunnel():
    """The outer VM's second interface leaves with its fleet; a later reset enables it again."""
    try:
        privileged(['systemctl', 'disable', '--now', f'wg-quick@{WG_OUTER}'])
    except RuntimeError as error:
        if 'does not exist' not in str(error) and 'not loaded' not in str(error):
            raise


def same_lock(first, second):
    return (first / 'composer.lock').is_file() and (first / 'composer.lock').read_bytes() == (second / 'composer.lock').read_bytes()


def snapshot(request, *, activate=True, vendors_from=None):
    before = json.loads((Path(request['evidence']) / 'inventory.json').read_text())
    if identity(request['candidate']) != before['candidate']:
        raise RuntimeError('Candidate changed while reset was starting; start a new independent run.')
    directory = Path(request['root']) / 'generations' / request['generation']
    directory.mkdir(parents=True, mode=0o700)
    write_json(directory / 'owner.json', {k: request[k] for k in ('candidate', 'group')})
    source = directory / 'source'
    checked(subprocess.run(['git', 'clone', '--no-local', '--no-checkout', '--', request['candidate'], str(source)],
                           capture_output=True, timeout=180), 'git')
    git(source, 'reset', '--mixed', 'HEAD')
    archive = git(request['candidate'], 'archive', before['candidate']['effective_tree'])
    with tempfile.TemporaryFile() as stream:
        stream.write(archive)
        stream.seek(0)
        with tarfile.open(fileobj=stream) as exported:
            exported.extractall(source, filter='data')
    for package in ('apps/e2e', 'apps/gateway', 'apps/cli', 'packages/php-sdk'):
        installed = Path(request['candidate']) / package / 'vendor'
        if not (installed / 'autoload.php').is_file():
            raise RuntimeError(f'Missing installed dependencies for {package}; use existing task provisioning.')
        earlier = vendors_from / package if vendors_from else None
        if earlier and (earlier / 'vendor/autoload.php').is_file() and same_lock(earlier, source / package):
            # The previous generation installed the same lock: hard links in scratch space cost nothing and never reach the candidate.
            checked(subprocess.run(['cp', '-al', str(earlier / 'vendor'), str(source / package / 'vendor')],
                                   capture_output=True, timeout=180), 'cp')
            continue
        checked(subprocess.run(['cp', '-a', '--reflink=auto', str(installed), str(source / package / 'vendor')],
                               capture_output=True, timeout=180), 'cp')
    if identity(source)['effective_tree'] != before['candidate']['effective_tree']:
        raise RuntimeError('Exported candidate identity differs from the pinned tree.')
    if activate:
        write_json(Path(request['root']) / 'active.json', {'generation': request['generation']})
    return {'source': str(source), 'candidate': before['candidate']}


def fleet_path(request, generation):
    return Path(request['root']) / 'generations' / generation / 'fleet.json'


def fleet_record(request, generation=None):
    """The record of a published generation (default: the active one): its preparation evidence and the Gateway's Node ids.

    A generation published before the record existed has the same facts in its evidence directory.
    """
    generation = generation or active_generation(request)
    if not generation:
        raise RuntimeError('There is no active generation.')
    path = fleet_path(request, generation)
    if path.is_file():
        return json.loads(path.read_text())
    earlier = Path(request['evidence']).parent / generation
    if all((earlier / name).is_file() for name in ('publish.json', 'prepare.json', 'gateway-db.json')):
        return {'prepare': json.loads((earlier / 'prepare.json').read_text()),
                'nodes': json.loads((earlier / 'gateway-db.json').read_text())['nodes']}
    raise RuntimeError('The generation has no fleet record; it was never published healthy.')


def prepared(request):
    evidence = Path(request['evidence']) / 'prepare.json'
    return json.loads(evidence.read_text()) if evidence.is_file() else fleet_record(request)['prepare']


def node_ids(request):
    evidence = Path(request['evidence']) / 'gateway-db.json'
    return json.loads(evidence.read_text())['nodes'] if evidence.is_file() else fleet_record(request)['nodes']


def started_at(request):
    """When the fleet last started from ready copies or after the copies were made, else None."""
    evidence = Path(request['evidence'])
    for name in ('restore-ready.json', 'ready-make.json'):
        if (evidence / name).is_file():
            return json.loads((evidence / name).read_text())['started_at']
    return None


def fresh_since(request):
    """Facts count as fresh when the Gateway received them after the generation was prepared.

    The agent sends a fact when it changes, so a fleet that starts from ready copies sends none: there the agent's
    presence heartbeat, which ready-wait checks, proves it is alive, and the recorded facts need only exist.
    """
    return 0.0 if started_at(request) is not None else (Path(request['evidence']) / 'prepare.json').stat().st_mtime


def guest(request, action):
    topology = prepared(request)['construction']
    source_for(request, request['generation'])
    gateway = topology['nodes']['gateway']['instance']
    network = wireguard(topology)
    facts = {'group': request['group'], 'generation': request['generation'],
             'nodes': topology['nodes'], 'wireguard': network}
    if action == 'gateway-db':
        # The hub and the peers exist before the Gateway seeds addresses on them and Caddy binds one.
        bring_up_wireguard(topology, network)
    # All credential delivery is the one explicitly approved proof file, never discovered fleet credentials.
    if action == 'swamp-state':
        incus(['exec', gateway, '--', 'install', '-d', '-m', '0700', '-o', 'orbit', '-g', 'orbit', '/home/orbit/.config/swamp'])
        # The orchestration process's HOME is private, so use the approved path supplied by the launcher.
        key = Path(request['key_file'])
        incus(['file', 'push', key, gateway + '/home/orbit/.config/swamp/club-api-key', '--mode=0600', '--uid=1000', '--gid=1000'])
        incus(['file', 'push', '/usr/local/bin/swamp', gateway + '/usr/local/bin/swamp', '--mode=0755'])
    if action == 'gateway-db':
        public = incus(['exec', gateway, '--', 'cat', '/home/orbit/.orbit/ssh/proof_worker_ed25519.pub'])
        targets = []
        for key, node in topology['nodes'].items():
            if key in ('gateway', 'operator'):
                continue
            instance = node['instance']
            secret = secrets.token_hex(32)
            incus(['exec', instance, '--', 'sh', '-c',
                 'install -d -m 0700 /etc/orbit/agent; umask 077; cat > /etc/orbit/agent/secret'], data=secret.encode())
            incus(['exec', instance, '--', 'sh', '-c',
                 'install -d -m 0700 -o orbit -g orbit /home/orbit/.ssh; cat >> /home/orbit/.ssh/authorized_keys; '
                 'chown orbit:orbit /home/orbit/.ssh/authorized_keys; chmod 0600 /home/orbit/.ssh/authorized_keys'], data=public)
            targets.append({'key': key, 'address': node['incus_address'], 'roles': [key],
                            'settings': {'apps': {'path': '/home/orbit/apps'}},
                            'host_key': incus(['exec', instance, '--', 'cat', '/etc/ssh/ssh_host_ed25519_key.pub']).decode().split()[1],
                            'agent_secret_hash': hashlib.sha256(secret.encode()).hexdigest()})
        facts['targets'] = targets
    # Only the Gateway mounts the checkout; the outer VM, the operator, is configured locally below.
    script = '/home/orbit/orbit/swamp/proof/reset/guest.py'
    result = json.loads(incus(['exec', gateway, '--', 'python3', script, action], data=json.dumps(facts).encode(), timeout=1800))
    if action == 'gateway-runtime':
        sys.path.insert(0, str(Path(request['candidate']) / 'tools/swamp-proof-guest'))
        import boot
        import fixture
        address = network['nodes']['gateway']['ip']
        # Canonical product baseline, plus one unmanaged fixture-transport rule.
        for key, commands in result['baseline_commands'].items():
            instance = topology['nodes'][key]['instance']
            for command in commands:
                incus(['exec', instance, '--', *command])
            incus(['exec', instance, '--', 'ufw', 'allow', 'from', str(ipaddress.ip_network(topology['nodes']['gateway']['incus_address'] + '/24', strict=False)), 'comment', 'proof-network'])
            incus(['exec', instance, '--', 'ufw', '--force', 'enable'])
        # The Gateway observer starts during fixture bootstrap, before this baseline is installed.
        # Restart it once so the one-shot native facts step can observe the enabled firewall.
        incus(['exec', topology['nodes']['gateway']['instance'], '--', 'systemctl', 'restart', 'orbit-agent'])
        for key, rendered in result['agents'].items():
            if key == 'gateway':
                continue  # Shared gateway bootstrap already starts its observer.
            instance = topology['nodes'][key]['instance']

            def execute(argv, *, input=None, instance=instance):
                return incus(['exec', instance, '--', *argv], data=None if input is None else input.encode())
            # The boot files come first: the agent must start with its tunnel wait and the Orbit names in /etc/hosts.
            boot.apply(execute, 'node', address)
            secret = incus(['exec', instance, '--', 'cat', '/etc/orbit/agent/secret']).decode()
            fixture.install_agent(None, rendered, secret, execute=execute)
        return {'agents_installed': list(result['agents'])}
    return result


def cli_profiles():
    config = Path(CLI_ENV['ORBIT_HOME']) / 'config.json'
    return list(json.loads(config.read_text()).get('gateways', {})) if config.is_file() else []


def operator(request):
    """The outer VM is the operator: its CLI profile names the nested Gateway by address only, never gateway.orbit."""
    topology = prepared(request)['construction']
    foreign = [name for name in cli_profiles() if name != NESTED_PROFILE]
    if foreign:
        raise RuntimeError('The outer VM CLI already has profile ' + ', '.join(foreign) + '; refusing to switch its default Gateway.')
    address = wireguard(topology)['nodes']['gateway']['ip']
    pinned = orbit_home() / 'nested-ca.pem'
    pinned.write_bytes(incus(['exec', topology['nodes']['gateway']['instance'], '--', 'cat', '/home/orbit/.orbit/ca/root.pem']))
    pinned.chmod(0o644)
    install_cli(request)
    local_cli(request, 'gateway:add', 'https://' + address, '--name=' + NESTED_PROFILE, '--use', '--accept-ca-change', '--ca=' + str(pinned), '--json')
    if cli_profiles() != [NESTED_PROFILE]:
        raise RuntimeError('The outer VM CLI must have exactly one profile, nested; found ' + ', '.join(cli_profiles()))
    return {'cli': NESTED_CLI, 'profile': NESTED_PROFILE, 'gateway': address}


def gateway_state(request):
    node = prepared(request)['construction']['nodes']['gateway']['instance']
    return json.loads(incus(['exec', node, '--', 'sh', '-c',
        'cd /home/orbit/orbit/apps/gateway; exec sudo -u orbit php /home/orbit/orbit/tools/swamp-proof-guest/gateway.php proof-state'], data=b'{}'))


def local_cli(request, *args, allow_failure=False):
    """The candidate CLI on the outer VM as user orbit, with its one profile for the nested Gateway."""
    cli = str(Path(request['candidate']) / 'apps/cli/orbit')
    if os.geteuid() == 0:
        completed = subprocess.run(['runuser', '-u', 'orbit', '--', 'php', cli, *args], env=CLI_ENV, cwd='/', capture_output=True, timeout=120)
    else:
        completed = subprocess.run(['php', cli, *args], env=CLI_ENV, cwd='/', capture_output=True, timeout=120)
    if completed.returncode and not allow_failure:
        raise RuntimeError('Authenticated candidate CLI failed: ' + completed.stdout.decode()[-4000:] + completed.stderr.decode()[-1000:])
    return completed.returncode, json.loads(completed.stdout) if completed.stdout.strip() else None


def install_cli(request):
    """The candidate CLI on the outer VM's PATH under its own name; the real `orbit` there is never replaced."""
    wrapper = f"#!/bin/sh\nexec {Path(request['candidate']) / 'apps/cli/orbit'} \"$@\"\n".encode()
    privileged(['sh', '-c', 'umask 022; cat > "$1"; chmod 0755 "$1"', 'sh', NESTED_CLI], data=wrapper)


def fresh_facts(state, managed_ids, since):
    expected = {(node, kind) for node in managed_ids for kind in ('host', 'ufw', 'units')}
    received = {(fact['node_id'], fact['kind']) for fact in state['facts']
                if datetime.fromisoformat(fact['received_at']).timestamp() >= since
                and (fact['kind'] != 'ufw' or fact.get('enabled') is True)}
    return expected <= received


def case_baseline(request):
    state = gateway_state(request)
    return {'firewall_records': state['firewall_records'],
            'ufw': {key: incus(['exec', node['instance'], '--', 'ufw', 'status', 'numbered']).decode()
                    for key, node in prepared(request)['construction']['nodes'].items() if key != 'operator'}}


def transport_readiness(request):
    lease = prepared(request)
    topology = lease['construction']
    observed = inventory()
    attempt = topology['attempt_id']
    for key, node in topology['nodes'].items():
        if key == 'operator':
            continue  # the operator is the outer VM; a construction that still composes an operator VM leaves it unused
        matches = [g for g in observed['guests'] if g['name'] == node['instance']]
        if len(matches) != 1 or matches[0]['status'] != 'Running' or matches[0]['metadata'] != {
            'user.orbit.e2e.owner': 'orbit-e2e', 'user.orbit.e2e.issue': request['issue'],
            'user.orbit.e2e.attempt': attempt, 'user.orbit.e2e.operation': lease['source']['operation_id']}:
            raise RuntimeError('Guest readiness/ownership mismatch: ' + node['instance'])
        incus(['exec', node['instance'], '--', 'true'])
    gateway = topology['nodes']['gateway']['instance']
    service = incus(['exec', gateway, '--', 'python3', '/home/orbit/orbit/swamp/proof/reset/guest.py', 'readiness'],
                  data=json.dumps({'group': request['group'], 'nodes': topology['nodes'],
                                   'managed': {key: {'address': node['incus_address'], 'host_key': incus(['exec', node['instance'], '--', 'cat', '/etc/ssh/ssh_host_ed25519_key.pub']).decode().split()[1]} for key, node in topology['nodes'].items() if key != 'operator'}}).encode(), timeout=240).decode()
    network = wireguard(topology)
    force_handshake(network['nodes']['gateway']['ip'])
    handshake_age = tunnel_ready(network)
    _, status = local_cli(request, 'gateway:status', '--json')
    # Only an active WireGuard member is told the release state, so this proves the Gateway knows the outer VM by 10.45.0.10.
    if status is None or (status.get('data', status)).get('auto_release') is None:
        raise RuntimeError('The nested Gateway does not recognise the outer VM as a WireGuard member.')
    _, listed = local_cli(request, 'node:list', '--json')
    if {n['name'] for n in listed['nodes']} != {*(k for k in topology['nodes'] if k != 'operator'), 'operator'}:
        raise RuntimeError('Candidate CLI does not see the exact fixture Nodes.')
    return {'attempt_id': attempt, 'control_plane': json.loads(service),
            'wireguard': {'interface': WG_OUTER, 'handshake_age_seconds': handshake_age, 'subnet': WG_SUBNET},
            'candidate_cli': {'command': 'node:list --json', 'nodes': listed['nodes']}}


def observer_facts(request):
    ids = node_ids(request)
    managed = [value for key, value in ids.items() if key != 'operator']
    since = fresh_since(request)
    state = gateway_state(request)
    if not fresh_facts(state, managed, since):
        write_json(Path(request['evidence']) / 'facts-not-ready.json', state)
        raise RuntimeError('Fresh host/ufw/units observer facts missing; see facts-not-ready.json.')
    return {'facts': state['facts']}


def readiness(request):
    evidence = Path(request['evidence'])
    if request.get('mode') == 'ready-make':
        agents_online(request)
    transport = json.loads((evidence / 'transport-readiness.json').read_text())
    facts = json.loads((evidence / 'observer-facts.json').read_text())
    ids = node_ids(request)
    doctors = {}
    for key, value in ids.items():
        if key == 'operator':
            continue
        _, doctors[key] = local_cli(request, 'doctor', '--node=' + str(value), '--family=firewall', '--json')
        if doctors[key].get('healthy') is not True:
            raise RuntimeError('Scoped firewall Doctor baseline is not healthy: ' + key)
    baseline = case_baseline(request)
    write_json(Path(request['evidence']) / 'baseline.json', baseline)
    return {'passed': True, **transport, **facts, 'doctor': doctors}



def generation_guests(record):
    """Role to instance name for the guests ready copies replace."""
    nodes = record['prepare']['construction']['nodes']
    return {role: nodes[role]['instance'] for role in ready.ROLES}


def ready_state(request):
    """Can the ready copies replace the active generation's guests in place? Never raises: the reason is for humans."""
    try:
        generation = active_generation(request)
        if not generation:
            return {'usable': False, 'reason': 'no active generation to restore into'}
        record = fleet_record(request, generation)
        construction = record['prepare']['construction']
        guests = generation_guests(record)
        found = ready.inspect()
        if not found['usable']:
            return found
        network = ready.network_of(guests['gateway'])
        subnet = ready.subnet_of(network)
        if found['subnet'] != subnet:
            return {'usable': False, 'reason': f"the ready copies are for {found['subnet']}, the generation uses {subnet}"}
        for role in ready.ROLES:
            if found['addresses'][role] != construction['nodes'][role]['incus_address']:
                return {'usable': False, 'reason': f'the ready copy of {role} has another address than the generation'}
        return {**found, 'guests': guests, 'network': network}
    except (RuntimeError, ValueError, KeyError, OSError, subprocess.TimeoutExpired) as error:
        return {'usable': False, 'reason': str(error)}


def carry_state(origin, target):
    """Copy the runtime state the guests wrote into one source directory over the fresh export of the next."""
    for relative in CARRIED_STATE:
        source = origin / relative
        if not source.exists():
            continue
        destination = target / relative
        if source.is_dir():
            destination.mkdir(parents=True, exist_ok=True)
            checked(subprocess.run(['cp', '-a', '--reflink=auto', str(source) + '/.', str(destination)], capture_output=True, timeout=180), 'cp')
        else:
            destination.parent.mkdir(parents=True, exist_ok=True)
            checked(subprocess.run(['cp', '-a', '--reflink=auto', str(source), str(destination)], capture_output=True, timeout=180), 'cp')


def carry_acls(origin, target):
    """Give the directories that lead Caddy to the public directory the ACL they have in the previous source."""
    for relative in ACL_DIRECTORIES:
        listing = checked(subprocess.run(['getfacl', '--absolute-names', '--omit-header', str(origin / relative)], capture_output=True, timeout=30), 'getfacl')
        checked(subprocess.run(['setfacl', '--set-file=-', str(target / relative)], input=listing, capture_output=True, timeout=30), 'setfacl')


def snapshot_ready(request):
    """The next generation of a reset from ready copies: a fresh export of the candidate that inherits the fleet's lease.

    The attempt lease and the runtime state move with the guests, so a later release removes the same resources. The
    generation becomes the active one last: a failure earlier leaves the previous generation active and releasable.
    """
    previous = json.loads((Path(request['evidence']) / 'inventory.json').read_text())['previous']
    state = ready_state(request)
    if not previous or not state['usable']:
        raise RuntimeError('Ready copies are not usable: ' + state['reason'])
    origin = source_for(request, previous)
    record = fleet_record(request, previous)
    result = snapshot(request, activate=False, vendors_from=origin)
    source = Path(result['source'])
    carry_state(origin, source)
    carry_acls(origin, source)
    mounts = {role: {**mount, 'source': str(source)} for role, mount in record['prepare'].get('mounts', {}).items()}
    write_json(fleet_path(request, request['generation']),
               {'prepare': {**record['prepare'], 'mounts': mounts}, 'nodes': state['nodes']})
    write_json(Path(request['root']) / 'active.json', {'generation': request['generation']})
    return {**result, 'carried_from': previous, 'ready_set': state['set']}


def restore_ready(request):
    state = ready_state(request)
    if not state['usable']:
        raise RuntimeError('Ready copies are not usable: ' + state['reason'])
    record = fleet_record(request)
    construction = record['prepare']['construction']
    labels = {'owner': 'orbit-e2e', 'issue': construction['issue'], 'attempt': construction['attempt_id'],
              'operation': record['prepare']['source']['operation_id']}
    return ready.restore(state['guests'], state['network'], source_for(request, request['generation']), labels)


CLUB_KEY = '/home/orbit/.config/swamp/club-api-key'


def deliver_key(request, gateway):
    """Put the proof Swamp Club key into a started Gateway and start Swamp with it, as the full build does. Never baked."""
    incus(['wait', gateway, 'agent', '--timeout', str(READY_WAIT_SECONDS)], timeout=READY_WAIT_SECONDS + 30)
    incus(['file', 'push', request['key_file'], gateway + CLUB_KEY, '--mode=0600', '--uid=1000', '--gid=1000'])
    # Serve could not start without the key and may have hit its start limit.
    incus(['exec', gateway, '--', 'sh', '-c', 'systemctl reset-failed orbit-swamp-serve orbit-node-worker; systemctl restart orbit-swamp-serve orbit-node-worker'],
          timeout=READY_WAIT_SECONDS + 30)


def scrub_key(request, gateway):
    """Delete the club key from the running Gateway, prove no file on its disks holds it, then trim the freed blocks.

    The file list names paths only. The fstrim output goes into the receipt.
    """
    incus(['exec', gateway, '--', 'rm', '-f', CLUB_KEY])
    scan = ('umask 077; cat > /run/club-key-scan; find / -xdev -type f -size -20M ! -path /run/club-key-scan '
            '-exec grep -lF -f /run/club-key-scan {} + ; status=$?; rm -f /run/club-key-scan; [ "$status" -le 1 ]')
    found = incus(['exec', gateway, '--', 'sh', '-c', scan], data=Path(request['key_file']).read_bytes(), timeout=600).decode().split()
    if found:
        raise RuntimeError('The proof club key is still on the Gateway disk, nothing copied: ' + ', '.join(sorted(found)))
    # Deleting a file leaves its blocks in the disk image, and every task VM clones the image. Discard the freed blocks now.
    trimmed = incus(['exec', gateway, '--', 'timeout', '300', 'fstrim', '-av'], timeout=330).decode().strip().splitlines()
    return {'scanned': True, 'files_holding_the_key': 0, 'fstrim': trimmed}


def start_units(instance, units):
    """Start units in one guest. systemctl returns when the start jobs finish, and fails if a job fails."""
    incus(['exec', instance, '--', 'systemctl', 'start', *units], timeout=READY_WAIT_SECONDS + 30)


def apply_candidate(request):
    """Apply the candidate in the restored Gateway as soon as it has mounted the new source, while the Nodes still boot."""
    gateway = prepared(request)['construction']['nodes']['gateway']['instance']
    deliver_key(request, gateway)
    # This unit's start waits (at most 60 s) for the new source mount, so the job ends when the mount is there.
    start_units(gateway, ['orbit-proof-operations'])
    incus(['exec', gateway, '--', 'test', '-f', '/home/orbit/orbit/apps/gateway/artisan'], timeout=10)
    return guest(request, 'candidate')


def ready_wait(request):
    """Block on the started guests: guest agents, units, tunnel handshake.

    Each wait is one blocking command with a fixed bound: `incus wait` for the guest agent and `systemctl start` for the
    units, which returns when their start jobs end. The units' own start conditions wait for the Gateway.
    """
    topology = prepared(request)['construction']
    network = wireguard(topology)
    address = network['nodes']['gateway']['ip']
    since = started_at(request)
    if since is None:
        raise RuntimeError('The fleet has no recorded start; restore or make ready copies first.')
    instances = {key: node['instance'] for key, node in topology['nodes'].items() if key != 'operator'}
    for instance in instances.values():
        incus(['wait', instance, 'agent', '--timeout', str(READY_WAIT_SECONDS)], timeout=READY_WAIT_SECONDS + 30)
    start_units(instances['gateway'], ['php8.5-fpm', 'caddy', 'orbit-websocket', 'orbit-swamp-serve', 'orbit-node-worker',
                                       'orbit-proof-agent-view', 'orbit-proof-operations', 'orbit-agent'])
    for key, instance in instances.items():
        if key != 'gateway':
            start_units(instance, ['orbit-agent'])
    force_handshake(address)
    tunnel_ready(network, seconds=max(1.0, time.time() - since))
    return {'seconds_since_start': round(time.time() - since, 1), 'address': address}


def agents_online(request):
    """One observation: every Node agent is online by the Gateway's presence heartbeat, its facts exist, the worker is enrolled.

    It runs at the start of the readiness check, a few seconds after the agent units started: the agents report their
    presence within one heartbeat and no command blocks on that.
    """
    topology = prepared(request)['construction']
    managed = [value for key, value in node_ids(request).items() if key != 'operator']
    state = gateway_state(request)
    agents = state.get('agents')
    if agents is None:
        # A generation built before the Gateway driver reported presence: ask through the candidate CLI.
        agents = {str(node): local_cli(request, 'node:show', str(node), '--json')[1]['agent']['state'] for node in managed}
    silent = [node for node in managed if agents.get(str(node)) != 'online']
    if silent:
        raise RuntimeError('The Node agents are not online: ' + ', '.join(map(str, silent)))
    if not fresh_facts(state, managed, 0.0):
        raise RuntimeError('The Node facts are missing.')
    if request.get('mode') == 'ready':
        # The restored Gateway runs the candidate's guest program, so it can name the worker check itself.
        incus(['exec', topology['nodes']['gateway']['instance'], '--', 'python3', '/home/orbit/orbit/swamp/proof/reset/guest.py', 'worker'],
              data=json.dumps({'group': request['group'], 'generation': request['generation'], 'nodes': topology['nodes'], 'wireguard': wireguard(topology)}).encode(), timeout=60)
    return {'online': managed}


def ready_check(request):
    """The one readiness check of a reset from ready copies: the Gateway knows the operator, Doctor has no finding."""
    agents_online(request)
    ids = {key: value for key, value in node_ids(request).items() if key != 'operator'}
    # The reads are independent, so they run together.
    with ThreadPoolExecutor(max_workers=len(ids) + 2) as pool:
        status = pool.submit(local_cli, request, 'gateway:status', '--json')
        doctors = {key: pool.submit(local_cli, request, 'doctor', '--node=' + str(value), '--family=firewall', '--json') for key, value in ids.items()}
        baseline = pool.submit(case_baseline, request)
        reply = status.result()[1]
        if reply is None or (reply.get('data', reply)).get('auto_release') is None:
            raise RuntimeError('The nested Gateway does not recognise the outer VM as a WireGuard member.')
        reports = {key: future.result()[1] for key, future in doctors.items()}
        for key, report in reports.items():
            if report.get('healthy') is not True:
                raise RuntimeError('Scoped firewall Doctor baseline is not healthy: ' + key)
        write_json(Path(request['evidence']) / 'baseline.json', baseline.result())
    return {'passed': True, 'doctor': reports}


def ready_make(request):
    """Copy the healthy active generation to ready copies, then start it again."""
    record = fleet_record(request)
    construction = record['prepare']['construction']
    guests = generation_guests(record)
    expected = {'user.orbit.e2e.owner': 'orbit-e2e', 'user.orbit.e2e.issue': request['issue'],
                'user.orbit.e2e.attempt': construction['attempt_id'], 'user.orbit.e2e.operation': record['prepare']['source']['operation_id']}
    observed = {row['name']: row for row in inventory()['guests']}
    for instance in guests.values():
        if instance not in observed or observed[instance]['metadata'] != expected:
            raise RuntimeError('Guest ownership mismatch, nothing copied: ' + instance)
    scrubbed = scrub_key(request, guests['gateway'])
    made = ready.make(guests, ready.network_of(guests['gateway']), record['nodes'])
    # The running fleet goes on with its key, as after any start of a Gateway.
    deliver_key(request, guests['gateway'])
    return {**made, 'key': scrubbed}


def prune_generations(request):
    """Keep the active generation and the previous one; delete the other generation source directories of this group.

    A directory is deleted only when it is a generation of this candidate and group and no guest mounts it. The guests
    wrote some of it as root (the agent manifest, Python caches), so the outer user needs sudo.
    """
    previous = json.loads((Path(request['evidence']) / 'inventory.json').read_text())['previous']
    keep = {request['generation'], previous}
    expected = {key: request[key] for key in ('candidate', 'group')}
    mounted = incus(['list', '--format=json']).decode()
    removed = []
    for directory in sorted((Path(request['root']) / 'generations').iterdir()):
        owner = directory / 'owner.json'
        if directory.name in keep or not re.fullmatch('[0-9a-f]{32}', directory.name) or directory.is_symlink() or not owner.is_file():
            continue
        if json.loads(owner.read_text()) != expected or str(directory) in mounted:
            continue
        privileged(['rm', '-rf', '--', str(directory)], timeout=300)
        removed.append(directory.name)
    return {'kept': sorted(name for name in keep if name), 'removed': removed}


def preservation(request):
    before = json.loads((Path(request['evidence']) / 'inventory.json').read_text())
    now = inventory()
    if identity(request['candidate']) != before['candidate'] or outer_identity() != before['outer']:
        raise RuntimeError('Candidate or outer observer identity changed during the reset.')
    # A reset from ready copies keeps the previous generation's guests and network: the new generation inherited them.
    carried = request.get('mode') == 'ready'
    for kind in ('guests', 'networks'):
        previous_owned = before['previous_owned'][kind]
        unrelated = [row for row in before['resources'][kind] if row['name'] not in previous_owned and not row['name'].startswith(ready.PREFIX)]
        for row in unrelated:
            if row not in now[kind]:
                raise RuntimeError('Unrelated fixture changed: ' + row['name'])
        survivors = {row['name'] for row in now[kind] if row['name'] in previous_owned}
        if carried and survivors != set(previous_owned):
            raise RuntimeError('A carried resource of the fleet disappeared: ' + ', '.join(sorted(set(previous_owned) - survivors)))
        if survivors and not carried:
            raise RuntimeError('An old generation resource survived release.')
    return {'passed': True, 'candidate': before['candidate'], 'outer': before['outer'], 'resources': now}


# What a state read or the preservation check can raise; anything else is a defect and stops the phase.
STATE_ERRORS = (RuntimeError, OSError, ValueError, KeyError, subprocess.TimeoutExpired)


def baseline_residue(before, after):
    """Names what a case left behind, by comparing its own pre-case state with the state after it."""
    found = []
    if before['firewall_records'] != after['firewall_records']:
        found.append('firewall database records')
    for key in sorted({*before['ufw'], *after['ufw']}):
        if before['ufw'].get(key) != after['ufw'].get(key):
            found.append('UFW rules on ' + key)
    return found


def run_program(program, source, env, log_path, timeout):
    """Runs one case program in its own process group; returns (exit code or None on timeout, seconds)."""
    started = time.monotonic()
    with log_path.open('w') as log:
        process = subprocess.Popen(['bash', str(program)], cwd=source, env=env, stdout=log,
                                   stderr=subprocess.STDOUT, start_new_session=True)
        try:
            code = process.wait(timeout=timeout)
        except subprocess.TimeoutExpired:
            code = None
        finally:
            if process.poll() is None:
                try:
                    os.killpg(process.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                process.wait()
    return code, round(time.monotonic() - started, 2)


def run_cases(request):
    """Each program in order against one fleet; a case is judged against its own pre-case state."""
    evidence = Path(request['evidence'])
    programs = request.get('cases') or []
    if not programs:
        raise RuntimeError('The request lists no case programs.')
    budget = float(request['budget_seconds'])
    result = json.loads((evidence / 'publish.json').read_text())
    source = source_for(request, request['generation'])
    results = []

    def publish():
        write_json(evidence / 'cases.json', {'generation': request['generation'], 'cases': results})

    write_json(evidence / 'cases-started.json', {'generation': request['generation'], 'programs': programs})
    deadline = time.monotonic() + budget
    exhausted = False
    for index, program in enumerate(programs, 1):
        if exhausted:
            results.append(unrun_case(program, 'an earlier case exceeded the budget'))
            publish()
            continue
        try:
            before = case_baseline(request)
        except STATE_ERRORS as error:
            results.append(unrun_case(program, 'the state before the case could not be read: ' + str(error)[-300:]))
            publish()
            continue
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            exhausted = True
            results.append(unrun_case(program, 'the budget was used up before this case'))
            publish()
            continue
        env = {**os.environ, 'PROOF_RESULT': str(evidence / 'publish.json'), 'PROOF_SOURCE': str(source),
               'PROOF_GENERATION': request['generation'], 'PROOF_EVIDENCE': str(evidence), 'PROOF_CASE_INDEX': str(index)}
        code, seconds = run_program(source / program, source, env, evidence / f'cases-{index}.log', remaining)
        notes = []
        if code is None:
            exhausted = True
            verdict = 'failed'
            notes.append('exceeded the budget')
        else:
            verdict = {0: 'passed', 2: 'unrun'}.get(code, 'failed')
            if code == 2:
                notes.append('the case reported that it could not run (exit 2)')
            elif code:
                notes.append(f'exited {code}')
            try:
                after = case_baseline(request)
                write_json(evidence / f'baseline-after-case-{index}.json', after)
                residue = baseline_residue(before, after)
            except STATE_ERRORS as error:
                residue = []
                verdict = 'failed'
                notes.append('the state after the case could not be read: ' + str(error)[-300:])
            if residue:
                verdict = 'failed'
                notes.append('left residue in ' + ', '.join(residue))
        results.append({'program': program, 'verdict': verdict, 'exit_code': code, 'seconds': seconds,
                        'note': '; '.join(notes) or None})
        publish()
    problems = []
    try:
        preservation(request)
    except STATE_ERRORS as error:
        problems.append('Preservation failed: ' + str(error))
    bad = [f"{case['program']} {case['verdict']}" for case in results if case['verdict'] != 'passed']
    if bad:
        problems.append('Cases not passed: ' + ', '.join(bad) + '; see cases.json and cases-N.log.')
    if problems:
        raise RuntimeError(' '.join(problems))
    return {'generation': result['generation'], 'cases': results}


def execute(request, action):
    if action not in ACTIONS:
        raise RuntimeError('Unknown bounded proof phase: ' + action)
    evidence = Path(request['evidence'])
    if action == 'inventory':
        previous = active_generation(request)
        owned = {'guests': [], 'networks': []}
        if previous:
            source = source_for(request, previous)
            lease = source / '.e2e/attempt.json'
            if lease.exists():
                old = json.loads(lease.read_text())
                resources = inventory()
                # Release does the authoritative exact resource/metadata preflight.
                for kind in owned:
                    owned[kind] = [r['name'] for r in resources[kind] if r['metadata'].get('user.orbit.e2e.issue') == request['issue'] and r['metadata'].get('user.orbit.e2e.attempt') == old['attempt_id']]
        return {'candidate': identity(request['candidate']), 'outer': outer_identity(), 'resources': inventory(),
                'previous': previous, 'previous_owned': owned}
    if action == 'release-previous':
        before = json.loads((evidence / 'inventory.json').read_text())
        return release(request, before['previous'], 'cleanup-previous')
    if action == 'snapshot':
        return snapshot(request)
    if action == 'snapshot-ready':
        return snapshot_ready(request)
    if action == 'restore-ready':
        return restore_ready(request)
    if action == 'candidate':
        return apply_candidate(request)
    if action == 'ready-wait':
        return ready_wait(request)
    if action == 'agents-online':
        return agents_online(request)
    if action == 'ready-make':
        return ready_make(request)
    if action == 'ready-check':
        return ready_check(request)
    if action == 'prune-generations':
        return prune_generations(request)
    if action == 'prepare':
        source = source_for(request, request['generation'])
        value = harness(request, 'prepare', source)
        value['gateway_resources'] = gateway_resources(request, value, source)
        return value
    if action in ('gateway-db', 'swamp-state', 'gateway-runtime'):
        return guest(request, action)
    if action == 'operator':
        return operator(request)
    if action == 'transport-readiness':
        return transport_readiness(request)
    if action == 'observer-facts':
        return observer_facts(request)
    if action == 'readiness':
        return readiness(request)
    if action == 'publish':
        checked_file = evidence / 'readiness.json' if (evidence / 'readiness.json').is_file() else evidence / 'ready-check.json'
        result = {'generation': request['generation'], 'topology': prepared(request),
                  'readiness': json.loads(checked_file.read_text()),
                  'preservation': preservation(request)}
        # The generation is healthy: its record lets a later run copy it to ready copies or restore into it.
        write_json(fleet_path(request, request['generation']), {'prepare': prepared(request), 'nodes': node_ids(request)})
        return result
    if action == 'failure-cleanup':
        try:
            failure_inventory = inventory()
        except (RuntimeError, subprocess.TimeoutExpired) as error:
            failure_inventory = {'error': str(error)}
        write_json(evidence / 'failure-inventory.json', failure_inventory)
        if (evidence / 'prepare.json').is_file():
            statuses = {}
            for key, node in prepared(request)['construction']['nodes'].items():
                try:
                    statuses[key] = incus(['exec', node['instance'], '--', 'journalctl', '-k', '--no-pager', '-n', '40', '--grep=Out of memory|Killed process'], timeout=30).decode()
                except (RuntimeError, subprocess.TimeoutExpired) as error:
                    statuses[key] = str(error)
            write_json(evidence / 'failure-kernel.json', statuses)
        # Never release the previous generation after a refused release or failed snapshot.
        if active_generation(request) == request['generation']:
            return release(request, request['generation'], 'cleanup-failed')
        return {'status': 'not_started', 'generation': request['generation']}
    if action == 'release-active':
        return release(request, active_generation(request), 'cleanup-active')
    if action == 'cases':
        return run_cases(request)
    raise RuntimeError('Unknown bounded proof phase: ' + action)


def main():
    request = json.loads(Path(os.environ['PROOF_REQUEST']).read_text())
    expected = json.loads((Path(request['root']) / 'owner.json').read_text())
    if expected != {k: request[k] for k in ('candidate', 'group')}:
        raise RuntimeError('Request does not match the owned proof root.')
    action = sys.argv[1]
    try:
        result = execute(request, action)
        write_json(Path(request['evidence']) / (action + '.json'), result)
        print(json.dumps(result))
    except Exception as error:
        write_json(Path(request['evidence']) / (action + '-failure.json'), {'phase': action, 'error': str(error)})
        raise


if __name__ == '__main__':
    main()
