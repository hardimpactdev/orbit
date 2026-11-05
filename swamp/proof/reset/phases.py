#!/usr/bin/env python3
"""Bounded phase mechanics. Ordering and failure branches live in native YAML."""
import hashlib
import ipaddress
import json
import os
import secrets
import signal
import subprocess
import sys
import tarfile
import tempfile
import time
from datetime import datetime
from pathlib import Path

from launcher import parse_budget, unrun_case, write_json


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


def snapshot(request):
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
        checked(subprocess.run(['cp', '-a', '--reflink=auto', str(installed), str(source / package / 'vendor')],
                               capture_output=True, timeout=180), 'cp')
    if identity(source)['effective_tree'] != before['candidate']['effective_tree']:
        raise RuntimeError('Exported candidate identity differs from the pinned tree.')
    write_json(Path(request['root']) / 'active.json', {'generation': request['generation']})
    return {'source': str(source), 'candidate': before['candidate']}


def prepared(request):
    return json.loads((Path(request['evidence']) / 'prepare.json').read_text())


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
    ids = json.loads((Path(request['evidence']) / 'gateway-db.json').read_text())['nodes']
    managed = [value for key, value in ids.items() if key != 'operator']
    since = (Path(request['evidence']) / 'prepare.json').stat().st_mtime
    state = gateway_state(request)
    if not fresh_facts(state, managed, since):
        write_json(Path(request['evidence']) / 'facts-not-ready.json', state)
        raise RuntimeError('Fresh host/ufw/units observer facts missing; see facts-not-ready.json.')
    return {'facts': state['facts']}


def readiness(request):
    evidence = Path(request['evidence'])
    transport = json.loads((evidence / 'transport-readiness.json').read_text())
    facts = json.loads((evidence / 'observer-facts.json').read_text())
    ids = json.loads((evidence / 'gateway-db.json').read_text())['nodes']
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



def preservation(request):
    before = json.loads((Path(request['evidence']) / 'inventory.json').read_text())
    now = inventory()
    if identity(request['candidate']) != before['candidate'] or outer_identity() != before['outer']:
        raise RuntimeError('Candidate or outer observer identity changed during the reset.')
    for kind in ('guests', 'networks'):
        previous_owned = before['previous_owned'][kind]
        unrelated = [row for row in before['resources'][kind] if row['name'] not in previous_owned]
        for row in unrelated:
            if row not in now[kind]:
                raise RuntimeError('Unrelated fixture changed: ' + row['name'])
        if any(row['name'] in previous_owned for row in now[kind]):
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
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            process.wait()
            code = None
    return code, round(time.monotonic() - started, 2)


def run_cases(request):
    """Each program in order against one fleet; a case is judged against its own pre-case state."""
    evidence = Path(request['evidence'])
    programs = request.get('cases') or []
    if not programs:
        raise RuntimeError('The request lists no case programs.')
    budget = request.get('budget_seconds')
    budget = float(budget) if budget else parse_budget(os.environ.get('PROOF_BUDGET_SECONDS'))
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
        return {'generation': request['generation'], 'topology': prepared(request),
                'readiness': json.loads((evidence / 'readiness.json').read_text()),
                'preservation': preservation(request)}
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
