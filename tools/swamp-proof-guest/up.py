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
import pwd
import re
import secrets
import subprocess
import sys
import time
from pathlib import Path

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
timings = []


def run(argv, *, input=None, timeout=300, user=None, cwd=None, env=None):
    if user:
        argv = ['/usr/sbin/runuser', '-u', user, '--', *argv]
    result = subprocess.run(argv, input=input, capture_output=True, text=True, timeout=timeout, cwd=cwd, env=env, check=False)
    if result.returncode:
        sys.stderr.write(result.stdout[-4000:] + result.stderr[-4000:])
        raise RuntimeError(f'{Path(argv[0]).name} failed: ' + ' '.join(argv[:6]))
    return result.stdout


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


def native(*argv, timeout=120):
    env = {'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': str(HOME), 'SWAMP_HOME': str(STATE / 'home'),
           'SWAMP_CONFIG_DIR': str(STATE / 'config'), 'SWAMP_API_KEY_FILE': str(HOME / '.config/swamp/club-api-key'),
           'SWAMP_NO_UPDATE_CHECK': '1', 'SWAMP_NO_TELEMETRY': '1'}
    repo = [] if '--server' in argv else ['--repo-dir', str(REPO)]
    output = run([BINARY, *argv, *repo, '--json'], user=ACCOUNT, env=env, timeout=timeout, cwd=REPO)
    for index, char in enumerate(output):
        if char in '{[':
            try:
                value, end = json.JSONDecoder().raw_decode(output[index:])
                if not output[index + end:].strip():
                    return value
            except ValueError:
                pass
    raise RuntimeError('swamp reply was not JSON: ' + argv[0])


def private(path, value):
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    path.write_text(value)
    path.chmod(0o600)
    account = pwd.getpwnam(ACCOUNT)
    os.chown(path, account.pw_uid, account.pw_gid)


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


def grants(manifest):
    result = []
    for entry in manifest['vaults']:
        result.append({'subject': manifest['principal'], 'effect': 'allow', 'actions': entry['actions'],
                       'resource': 'vault:' + entry['vault'], 'condition': 'key.matches(' + json.dumps(entry['key_pattern']) + ')'})
    for entry in manifest['workflows']:
        result.append({'subject': manifest['principal'], 'effect': 'allow', 'actions': entry['actions'], 'resource': 'workflow:' + entry['workflow']})
    # Mirror serve's production grants too: native sensitive-output keys are separate from execution inputs.
    result.extend(json.loads((REPO / 'config/serve-grants.json').read_text())['grants'])
    return {'grants': result}


@step('swamp')
def swamp():
    club = HOME / '.config/swamp/club-api-key'
    if not club.is_file():
        raise RuntimeError('normal provision did not deliver the proof club credential')
    pin = re.search(r'sha256: "([a-f0-9]{64})"', (REPO / 'extensions/behaviors/node-worker/config.ts').read_text())[1]
    with open(BINARY, 'rb') as stream:
        if hashlib.file_digest(stream, 'sha256').hexdigest() != pin:
            raise RuntimeError('the image swamp binary differs from the committed runtime pin')
    STATE.mkdir(parents=True, exist_ok=True, mode=0o700)
    run(['chown', '-R', 'orbit:orbit', str(STATE), str(REPO)])
    # A v3 datastore, as on 209: its commit log feeds the Gateway's shared run reader. Each up starts it empty.
    # A restage stops the old serve first; it would write the old datastore while it is replaced.
    subprocess.run(['systemctl', 'stop', 'orbit-node-worker', 'orbit-swamp-serve'], capture_output=True, check=False)
    run(['rm', '-rf', str(STATE / 'datastore')])
    native('datastore', 'setup', 'filesystem', '--path', str(STATE / 'datastore'), '--format', '3', '--skip-migration')
    for name, entry in json.loads((REPO / 'extensions/models/upstream_extensions.json').read_text()).items():
        native('extension', 'pull', name + '@' + entry['version'], timeout=300)
    manifest = json.loads((REPO / 'config/gateway-workflow-grants.json').read_text())
    (STATE / 'grants.yaml').write_text(json.dumps(grants(manifest)))
    existing = {re.search(r'^name: ["\']?([^"\'\n]+)', path.read_text(), re.MULTILINE)[1].strip()
                for path in (REPO / 'vaults/local_encryption').glob('*.yaml')} if (REPO / 'vaults/local_encryption').is_dir() else set()
    for name in sorted({entry['vault'] for entry in manifest['vaults']} - existing):
        native('vault', 'create', 'local_encryption', name)
    # Every up stages a fresh repository, so its Swamp datastore and these credentials start new.
    for name, principal in (('operator', 'user:proof-operator'), ('gateway', 'user:orbit-gateway'), ('reader', 'user:orbit-gateway-read'),
                            ('worker', 'worker:orbit-node-209')):
        native('access', 'token', 'mint', name, '--principal', principal, '--duration', '24h')
        private(STATE / 'credentials' / name, native('access', 'token', 'reveal', name, '--yes')['token'])
    private(STATE / 'credentials/enrollment', native('worker', 'token', 'create', 'orbit-node-209', '--duration', '24h')['token'])
    # The role's two workers, orbit-swamp-worker@a and @b, read these before the swamp role starts them (version 17).
    for worker in ('a', 'b'):
        name = f'orbit-swamp-worker-{worker}'
        native('access', 'token', 'mint', name, '--principal', f'worker:{name}', '--duration', '24h')
        folder = ROLE_CREDENTIALS / worker
        run(['install', '-d', '-o', 'root', '-g', 'root', '-m', '0700', str(folder)])
        private(folder / 'server.token', native('access', 'token', 'reveal', name, '--yes')['token'])
        private(folder / 'enrollment.token', native('worker', 'token', 'create', name, '--duration', '24h')['token'])
    # A local CA the vault fixtures sign their own sites with; serve itself has no TLS (version 17).
    tls = STATE / 'tls'
    tls.mkdir(exist_ok=True, mode=0o700)
    run(['openssl', 'req', '-x509', '-newkey', 'ed25519', '-nodes', '-days', '2', '-subj', '/CN=swamp-proof CA',
         '-addext', 'basicConstraints=critical,CA:TRUE', '-addext', 'keyUsage=critical,keyCertSign',
         '-keyout', str(tls / 'ca-key.pem'), '-out', str(tls / 'ca.pem')])
    run(['chown', '-R', 'orbit:orbit', str(STATE)])
    # swamp.orbit resolves to this VM's topology address, where Caddy serves the Route (version 17).
    hosts = Path('/etc/hosts')
    lines = [line for line in hosts.read_text().splitlines() if 'swamp.orbit' not in line.split()[1:]]
    hosts.write_text('\n'.join([*lines, f'{TOPOLOGY["network"]["gateway"]} swamp.orbit']) + '\n')
    common = (f'User=orbit\nEnvironment=HOME=/home/orbit SWAMP_HOME={STATE}/home SWAMP_CONFIG_DIR={STATE}/config\n'
              'Environment=SWAMP_NO_UPDATE_CHECK=1 SWAMP_NO_TELEMETRY=1\nUMask=0077\nRestart=on-failure\n')
    units = {
        # 209's serve flags: loopback 9418 behind the swamp.orbit Route, which Caddy terminates (version 17).
        'orbit-swamp-serve': ('[Unit]\nDescription=Proof Swamp serve\n[Service]\n' + common
                              + f'WorkingDirectory={REPO}\n'
                              + f'ExecStart={BINARY} serve --repo-dir {REPO} --port {PORT} --host 127.0.0.1 --auth-mode token '
                              + f'--admins user:proof-operator --club-api-key-file {club} --trusted-hosts swamp.orbit --trust-proxy '
                              + '--dispatch-env-allow=, --remote-only --hot-reload '
                              + f'--grants-file {STATE}/grants.yaml\n'),
        # 209's worker unit: the Node SSH key arrives as the proxy-ssh-identity credential. Production runs two workers of
        # 8 dispatch slots each; one proof worker gets all 16, so nested child runs never queue behind their parent (version 14).
        'orbit-node-worker': ('[Unit]\nDescription=Proof Swamp worker orbit-node-209\nAfter=orbit-swamp-serve.service\n[Service]\n' + common
                              + f'WorkingDirectory={STATE}\nLoadCredential=enrollment:{STATE}/credentials/enrollment\n'
                              + f'LoadCredential=server:{STATE}/credentials/worker\nLoadCredential=proxy-ssh-identity:{IDENTITY}\n'
                              + f'ExecStart={BINARY} worker connect {URL} --token-file=%d/enrollment --server-token-file=%d/server '
                              + f'--cache-dir={STATE}/worker-cache --concurrency 16 --label role=swamp --label node=209\n'),
    }
    for name, contents in units.items():
        Path(f'/etc/systemd/system/{name}.service').write_text(contents)
    # Serve schedules as 209 does, so a Schedule's own workflow fires; the checked-in schedules stay paused,
    # because a proof exercises one kind, not fleet upkeep. A pause is kept in the datastore across restarts.
    for row in native('workflow', 'schedule', 'list')['schedules']:
        native('workflow', 'schedule', 'pause', row['workflowId'], '--reason', 'proof topology')
    run(['systemctl', 'daemon-reload'])
    run(['systemctl', 'restart', *units])
    deadline = time.monotonic() + 180
    while True:
        try:
            workers = native('worker', 'list', '--server', URL, '--token-file', str(STATE / 'credentials/operator'), timeout=15)['workers']
            if any(row.get('tokenName') == 'orbit-node-209' and row.get('status') in ('idle', 'busy') for row in workers):
                break
        except (RuntimeError, KeyError, subprocess.TimeoutExpired):
            pass
        if time.monotonic() > deadline:
            raise RuntimeError('the orbit-node-209 worker did not connect to serve')
        time.sleep(2)


@step('gateway')
def gateway(nodes):
    # API contract generation (post-install bin/build-api) is a build output the proof does not use.
    run(['composer', 'install', '--no-interaction', '--no-progress', '--prefer-dist', '--no-dev', '--no-scripts', '--optimize-autoloader'],
        user=ACCOUNT, cwd=GATEWAY, timeout=900)
    run(['php', 'artisan', 'package:discover'], user=ACCOUNT, cwd=GATEWAY)
    # The Orbit public root CA the Node agent's files carry; only its certificate (topology version 5).
    ca = HOME / '.orbit/ca'
    # Strict TLS clients, such as Python's, refuse a CA without keyUsage; a held VM's older root is replaced (version 8).
    if (ca / 'root.pem').exists() and 'Certificate Sign' not in subprocess.run(
            ['openssl', 'x509', '-in', str(ca / 'root.pem'), '-noout', '-ext', 'keyUsage'], capture_output=True, text=True, check=False).stdout:
        (ca / 'root.pem').unlink()
    if not (ca / 'root.pem').exists():
        ca.mkdir(parents=True, exist_ok=True)
        run(['openssl', 'req', '-x509', '-newkey', 'rsa:4096', '-nodes', '-days', '30', '-subj', '/CN=Orbit proof root CA',
             '-addext', 'basicConstraints=critical,CA:TRUE', '-addext', 'keyUsage=critical,keyCertSign,cRLSign',
             '-keyout', str(ca / 'root.key'), '-out', str(ca / 'root.pem')])
        run(['chown', '-R', f'{ACCOUNT}:{ACCOUNT}', str(HOME / '.orbit/ca')])
    # The pinned agent delivery a Gateway release writes from the frozen manifest (topology version 5).
    run(['install', '-D', '-o', ACCOUNT, '-g', ACCOUNT, '-m', '0644', str(GATEWAY / 'resources/agent/frozen-manifest.json'),
         str(SOURCE / 'apps/agent/delivery/manifest.json')])
    database = STATE / 'gateway.sqlite'
    database.unlink(missing_ok=True)
    database.touch()
    settings = {
        'APP_KEY': '', 'APP_ENV': 'production', 'APP_DEBUG': 'false', 'APP_URL': 'https://gateway.orbit',
        'DB_CONNECTION': 'sqlite', 'DB_DATABASE': str(database), 'QUEUE_CONNECTION': 'sync', 'CACHE_STORE': 'file',
        'ORBIT_GATEWAY_QUEUE_DRIVER': 'database',
        'SESSION_DRIVER': 'file', 'BROADCAST_CONNECTION': 'log', 'LOG_CHANNEL': 'single',
        # swamp.orbit through Caddy with the Orbit root, as production's defaults name it (version 17).
        'ORBIT_SWAMP_RUN_TOKEN': (STATE / 'credentials/gateway').read_text().strip(),
        'ORBIT_SWAMP_READ_TOKEN': (STATE / 'credentials/reader').read_text().strip(),
        'ORBIT_HOME': str(HOME / '.orbit'),
        # The Gateway site and Metrics publication render this checkout, as a release's checkout link (version 12).
        'ORBIT_GATEWAY_CHECKOUT': str(GATEWAY),
    }
    environment = GATEWAY / '.env'
    private(environment, '\n'.join(f'{key}={value}' for key, value in settings.items()) + '\n')
    # The Gateway's PHP user owns ORBIT_HOME, as on a real Gateway: it creates its locks and caches there,
    # such as the Cluster Router lock and the agent-view cache (version 11).
    run(['chown', f'{ACCOUNT}:{ACCOUNT}', str(HOME / '.orbit')])
    run(['install', '-d', '-m', '0700', '-o', ACCOUNT, '-g', ACCOUNT, str(HOME / '.orbit/locks')])
    run(['php', 'artisan', 'key:generate', '--force'], user=ACCOUNT, cwd=GATEWAY)
    run(['chown', 'orbit:orbit', str(database)])
    run(['php', 'artisan', 'migrate', '--force'], user=ACCOUNT, cwd=GATEWAY, timeout=600)
    network = TOPOLOGY['network']
    # Node 209's own agent key; the Gateway records only its verifier, as for every enrolled Node (version 17).
    AGENT.mkdir(parents=True, exist_ok=True, mode=0o700)
    run(['chown', 'root:root', str(AGENT)])
    if not (AGENT / 'secret').exists():
        private(AGENT / 'secret', secrets.token_hex(32))
        run(['chown', 'root:root', str(AGENT / 'secret')])
    facts = {'gateway': network['gateway'], 'gateway_host_key': Path('/etc/ssh/ssh_host_ed25519_key.pub').read_text().split()[1],
             'gateway_agent_secret_hash': hashlib.sha256((AGENT / 'secret').read_text().encode()).hexdigest(),
             'operator': TOPOLOGY['operator']['address'], 'targets': nodes,
             'subnet': str(ipaddress.ip_network(f'{network["gateway"]}/{network["prefix"]}', strict=False))}
    seeded = json.loads(run(['php', str(SOURCE / 'tools/swamp-proof-guest/gateway.php'), 'seed'], input=json.dumps(facts), user=ACCOUNT, cwd=GATEWAY))
    # Steps on 209 reach this VM's own address over SSH like any Node, so the worker key logs in here too (version 12).
    public = ' '.join(IDENTITY.with_suffix('.pub').read_text().split()[:2]) + ' swamp-proof-worker'
    run(['sh', '-c', 'install -d -m 0700 -o orbit -g orbit /home/orbit/.ssh; f=/home/orbit/.ssh/authorized_keys; touch "$f"; '
         'chown orbit:orbit "$f"; chmod 0600 "$f"; grep -qxF "$1" "$f" || printf "%s\\n" "$1" >> "$f"', 'sh', public])
    serve_gateway(seeded['nodes']['gateway'], seeded['swamp_route'])
    # The known-hosts publication starts a Swamp run, which reaches serve through swamp.orbit (version 17).
    run(['php', str(SOURCE / 'tools/swamp-proof-guest/gateway.php'), 'known-hosts'], input='{}', user=ACCOUNT, cwd=GATEWAY)
    observe(seeded['nodes']['gateway'])
    return seeded


def leaf(directory, name, hosts):
    """A certificate from the proof root at the path the role's certificate step writes, as root:caddy 0640."""
    ca = HOME / '.orbit/ca'
    directory.mkdir(exist_ok=True, mode=0o750)
    run(['openssl', 'req', '-new', '-newkey', 'rsa:2048', '-nodes', '-subj', f'/CN={hosts[0]}', '-keyout', str(directory / f'{name}.key'),
         '-out', str(directory / f'{name}.csr')])
    (directory / f'{name}.ext').write_text('basicConstraints=critical,CA:FALSE\nextendedKeyUsage=serverAuth\nsubjectAltName='
                                          + ','.join(('IP:' if host[0].isdigit() else 'DNS:') + host for host in hosts) + '\n')
    run(['openssl', 'x509', '-req', '-in', str(directory / f'{name}.csr'), '-CA', str(ca / 'root.pem'), '-CAkey', str(ca / 'root.key'),
         '-CAcreateserial', '-days', '2', '-extfile', str(directory / f'{name}.ext'), '-out', str(directory / f'{name}.pem')])
    run(['install', '-m', '0640', str(ca / 'root.pem'), str(directory / 'root-ca.pem')])
    for extra in ('csr', 'ext'):
        (directory / f'{name}.{extra}').unlink()
    run(['chown', '-R', 'root:caddy', str(directory)])
    run(['chmod', '0640', str(directory / f'{name}.key'), str(directory / f'{name}.pem')])


def observe(node_id):
    """Node 209's own agent, beside the task VM's observer (version 17).

    The task VM's orbit-agent reports this VM to the fleet. A second unit runs the same binary with the files
    node:converge renders for Node 209, bound over /etc/orbit/agent in its own mount namespace, so Doctor reads
    209's role and firewall facts from the proof Gateway and the outer observer keeps its own configuration.
    """
    rendered = json.loads(run(['php', str(SOURCE / 'tools/swamp-proof-guest/gateway.php'), 'agent'], user=ACCOUNT, cwd=GATEWAY,
                              input=json.dumps({'node': node_id})).strip().splitlines()[-1])
    (AGENT / 'config.toml').write_text(rendered['configuration'])
    (AGENT / 'ca.pem').write_text(rendered['certificate'])
    run(['chown', '-R', 'root:root', str(AGENT)])
    run(['chmod', '0600', str(AGENT / 'secret')])
    delivery = SOURCE.parent / 'agent-delivery/orbit-agent-linux-x86_64'
    binary = '/usr/local/bin/orbit-proof-agent'
    run(['install', '-m', '0755', str(delivery if delivery.is_file() else Path('/usr/local/bin/orbit-agent')), binary])
    unit = rendered['unit'].replace('Description=Orbit Node agent', 'Description=Orbit proof agent for Node 209')
    unit = unit.replace('ExecStart=/usr/local/bin/orbit-agent', f'ExecStart={binary}\nBindPaths={AGENT}:/etc/orbit/agent')
    Path('/etc/systemd/system/orbit-proof-agent.service').write_text(unit)
    run(['systemctl', 'daemon-reload'])
    run(['systemctl', 'enable', 'orbit-proof-agent'])
    run(['systemctl', 'restart', 'orbit-proof-agent'])
    run(['systemctl', 'is-active', '--quiet', 'orbit-agent'])
    deadline = time.monotonic() + 120
    while subprocess.run(['systemctl', 'is-active', '--quiet', 'orbit-proof-agent'], check=False).returncode:
        if time.monotonic() > deadline:
            raise RuntimeError('the Node 209 proof agent did not start')
        time.sleep(2)


def route_leaf(route_id, hosts):
    """A Route's certificate at the path its Caddy site loads: cert.pem and key.pem under its scope (version 17)."""
    directory = Path(f'/etc/caddy/orbit-certificates/route-{route_id}/current')
    directory.parent.mkdir(parents=True, exist_ok=True)
    leaf(directory, 'cert', hosts)
    (directory / 'cert.key').rename(directory / 'key.pem')


def serve_gateway(node_id, swamp_route):
    """The Gateway on Node 209 as production runs it (version 12).

    The gateway and websocket role certificates sit where their certificate steps write them, PHP-FPM serves the
    orbit-gateway socket, and Caddy runs the Node's complete Caddyfile from the Gateway's own Caddy build renderer,
    with its admin endpoint on localhost, so a later Caddy build or certificate reload on this Node works as on a real
    Gateway. A step that signs a CSR calls the Gateway from 209; FastCGI keeps the caller's address, so peer identity holds.
    """
    ca = HOME / '.orbit/ca'
    run(['install', '-m', '0644', str(ca / 'root.pem'), '/usr/local/share/ca-certificates/orbit-managed-root-ca.crt'])
    run(['update-ca-certificates'])
    address = TOPOLOGY['network']['gateway']
    leaf(Path('/etc/caddy/orbit-cert-current'), 'gateway', ['gateway.orbit', address])
    leaf(Path('/etc/caddy/orbit-websocket-cert-current'), 'reverb', ['reverb.orbit'])
    route_leaf(swamp_route, ['swamp.orbit'])
    # Without this line gateway.orbit resolves through fleet private DNS to the production Gateway; a signing
    # step would then call production. up fails unless the name resolves to this VM.
    hosts = Path('/etc/hosts')
    lines = [line for line in hosts.read_text().splitlines() if 'gateway.orbit' not in line.split()[1:]]
    hosts.write_text('\n'.join([*lines, f'{address} gateway.orbit']) + '\n')
    resolved = run(['getent', 'hosts', 'gateway.orbit']).split()
    if not resolved or resolved[0] != address:
        raise RuntimeError(f'gateway.orbit resolves to {resolved[:1]}, not this topology Gateway {address}')
    Path('/etc/php/8.5/fpm/pool.d/proof-gateway.conf').unlink(missing_ok=True)
    Path('/etc/php/8.5/fpm/pool.d/orbit-gateway.conf').write_text(
        f'[orbit-gateway]\nuser = {ACCOUNT}\ngroup = {ACCOUNT}\nlisten = /run/php/orbit-gateway.sock\nlisten.owner = caddy\n'
        'listen.group = caddy\npm = ondemand\npm.max_children = 4\nclear_env = no\n')
    # The fleet's member trust: Nodes reach gateway.orbit and reverb.orbit over the private network (version 11).
    run(['ufw', 'allow', 'in', 'on', TOPOLOGY['network']['name'], 'to', address, 'port', '443', 'proto', 'tcp', 'comment', 'orbit-proof gateway'])
    # Caddy resolves the Gateway web root itself (resolve_root_symlink), so it needs traverse rights down to it.
    path = HOME
    for part in GATEWAY.relative_to(HOME).parts:
        run(['setfacl', '-m', 'u:caddy:x', str(path)])
        path = path / part
    run(['setfacl', '-m', 'u:caddy:x', str(GATEWAY)])
    run(['setfacl', '-m', 'u:caddy:rx', str(GATEWAY / 'public')])
    run(['systemctl', 'enable', 'php8.5-fpm', 'caddy'])
    run(['systemctl', 'restart', 'php8.5-fpm'])
    publish_caddy(node_id)


def publish_caddy(node_id):
    """Write and load the Node's Caddyfile as its Caddy build renders it; a render with problems stops up."""
    rendered = json.loads(run(['php', str(SOURCE / 'tools/swamp-proof-guest/gateway.php'), 'caddyfile'], user=ACCOUNT, cwd=GATEWAY,
                              input=json.dumps({'node': node_id})).strip().splitlines()[-1])
    if rendered['problems']:
        raise RuntimeError('the Gateway Caddyfile has problems: ' + '; '.join(rendered['problems']))
    Path('/etc/caddy/Caddyfile').write_text(rendered['content'])
    run(['caddy', 'validate', '--config', '/etc/caddy/Caddyfile', '--adapter', 'caddyfile'])
    run(['systemctl', 'restart', 'caddy'])


def request(path, body, seconds=600):
    """One real API call as the operator through the Gateway HTTP kernel; an Operation is followed to its end."""
    reply = json.loads(run(['php', str(SOURCE / 'tools/swamp-proof-guest/gateway.php'), 'request'], user=ACCOUNT, cwd=GATEWAY, timeout=seconds + 120,
                           input=json.dumps({'caller': TOPOLOGY['operator']['address'], 'method': 'POST', 'path': path, 'body': body,
                                             'key': 'up-' + path.replace('/', '-'), 'seconds': seconds})).strip().splitlines()[-1])
    if (reply.get('operation') or {}).get('status') != 'succeeded':
        raise RuntimeError(f'{path} did not succeed: ' + json.dumps({k: reply.get(k) for k in ('http_status', 'operation', 'job_errors')})[:1500])


@step('realtime')
def realtime(ids):
    """Reverb, the agent-view subscriber and a running Node agent (version 11).

    The websocket role runs on this VM (Node 209) as its convergence leaves it: the Gateway issues the server
    credentials and renders the .env and unit, and the pinned orbit-reverb release serves behind reverb.orbit.
    The role's own convergence needs a per-Node worker the topology has not. A Node with `agent` converges through
    the real node:converge, which installs and starts orbit-agent; a staged agent delivery then replaces the binary.
    """
    if TOPOLOGY.get('websocket'):
        lines = run(['php', str(SOURCE / 'tools/swamp-proof-guest/gateway.php'), 'fixture'], user=ACCOUNT, cwd=GATEWAY,
                    input=json.dumps({'websocket': ids['gateway']})).strip().splitlines()
        plan = json.loads(lines[-1])['websocket']
        install = Path('/opt/orbit/websocket')
        if not (install / 'artisan').exists():
            run(['install', '-d', '-o', ACCOUNT, '-g', ACCOUNT, str(install)])
            run(['git', 'clone', '-q', plan['repository'], str(install)], user=ACCOUNT, timeout=600)
        run(['git', '-C', str(install), 'checkout', '-q', '--detach', plan['ref']], user=ACCOUNT)
        run(['composer', 'install', '--no-interaction', '--no-progress', '--prefer-dist', '--no-dev', '--optimize-autoloader'],
            user=ACCOUNT, cwd=install, timeout=900)
        private(install / '.env', plan['environment'])
        run(['chown', f'{ACCOUNT}:{ACCOUNT}', str(install / '.env')])
        Path('/etc/systemd/system/orbit-websocket.service').write_text(plan['unit'])
        run(['systemctl', 'daemon-reload'])
        run(['systemctl', 'enable', 'orbit-websocket'])
        run(['systemctl', 'restart', 'orbit-websocket'])
        publish_caddy(ids['gateway'])
    unit = Path('/etc/systemd/system/orbit-proof-agent-view.service')
    unit.write_text('[Unit]\nDescription=Orbit proof agent-view subscriber\nAfter=network.target\n\n[Service]\nType=simple\n'
                    f'User={ACCOUNT}\nGroup={ACCOUNT}\nWorkingDirectory={GATEWAY}\nExecStart=/usr/bin/php {GATEWAY}/artisan orbit:agent-view\n'
                    'Restart=always\nRestartSec=2\n\n[Install]\nWantedBy=multi-user.target\n')
    run(['systemctl', 'daemon-reload'])
    run(['systemctl', 'enable', 'orbit-proof-agent-view'])
    run(['systemctl', 'restart', 'orbit-proof-agent-view'])
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
