#!/usr/bin/python3
"""Bring up the minimal proof topology inside one owned task VM, as root.

This VM runs the Gateway from the staged commit and pinned Swamp serve with one
worker named orbit-node-209. One nested copy of the seed's node-template plays
the target Node. Prints one JSON line with public facts and step timings.
"""
import hashlib
import json
import os
import pwd
import re
import subprocess
import sys
import time
from pathlib import Path

SOURCE = Path(sys.argv[1]).resolve()
TOPOLOGY = json.loads((SOURCE / 'swamp/proof/topologies/minimal/topology.json').read_text())
ACCOUNT = 'orbit'
HOME = Path('/home/orbit')
STATE = HOME / '.orbit/swamp-proof'
REPO = SOURCE / 'swamp'
GATEWAY = SOURCE / 'apps/gateway'
BINARY = '/usr/local/bin/swamp'
PORT = 443
URL = f'wss://swamp.orbit:{PORT}'
IDENTITY = HOME / '.orbit/ssh/proof_worker_ed25519'
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
    run(['bin/e2e-topology-host-setup'], cwd=SOURCE)
    network = TOPOLOGY['network']
    node = TOPOLOGY['nodes'][0]
    if run(['incus', 'network', 'list', '--format', 'csv', '--columns', 'n']).split().count(network['name']) == 0:
        run(['incus', 'network', 'create', network['name'], f'ipv4.address={network["gateway"]}/{network["prefix"]}',
             'ipv4.nat=true', 'ipv6.address=none'])
    # Re-running up on a held VM keeps the target it already made.
    if node['key'] not in run(['incus', 'list', '--format', 'csv', '--columns', 'n']).split():
        run(['incus', 'copy', 'node-template', node['key'], '-c', f'limits.memory={node["memory"]}', '-c', 'limits.cpu=1',
             '-c', 'cloud-init.user-data=#cloud-config\nusers: []\n'])
        run(['incus', 'config', 'device', 'add', node['key'], 'eth0', 'nic', f'network={network["name"]}', f'ipv4.address={node["address"]}'])
        run(['incus', 'start', node['key']])
    deadline = time.monotonic() + 900
    while subprocess.run(['incus', 'exec', node['key'], '--', 'true'], capture_output=True, check=False).returncode:
        if time.monotonic() > deadline:
            raise RuntimeError('target agent did not start')
        time.sleep(2)
    # Exit 2 is "done with recoverable errors": the template guest has no Incus datasource.
    if subprocess.run(['incus', 'exec', node['key'], '--', 'cloud-init', 'status', '--wait'],
                      capture_output=True, timeout=600, check=False).returncode not in (0, 2):
        raise RuntimeError('target cloud-init did not finish')
    run(['incus', 'file', 'push', str(SOURCE / 'apps/e2e/resources/guest/prepare-node.sh'), f'{node["key"]}/root/prepare-node.sh'])
    run(['incus', 'exec', node['key'], '--', 'bash', '/root/prepare-node.sh', 'configure-runtime'])
    if not IDENTITY.exists():
        IDENTITY.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'swamp-proof-worker', '-f', str(IDENTITY)])
        run(['chown', '-R', 'orbit:orbit', str(IDENTITY.parent)])
    public = IDENTITY.with_suffix('.pub').read_text().split()
    run(['incus', 'exec', node['key'], '--', 'bash', '/root/prepare-node.sh', 'gateway-authorize', ' '.join(public[:2])])
    # Unmanaged SSH allowance and an active backend: the state an enrolled Node has.
    run(['incus', 'exec', node['key'], '--', 'sh', '-c', 'ufw allow 22/tcp comment proof-ssh && ufw --force enable'])
    host_key = run(['incus', 'exec', node['key'], '--', 'cat', '/etc/ssh/ssh_host_ed25519_key.pub']).split()
    deadline = time.monotonic() + 120
    while subprocess.run(['ssh', '-i', str(IDENTITY), '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=no',
                          '-o', 'UserKnownHostsFile=/dev/null', '-o', 'ConnectTimeout=5', f'orbit@{node["address"]}', 'sudo', '-n', 'true'],
                         capture_output=True, check=False).returncode:
        if time.monotonic() > deadline:
            raise RuntimeError('target SSH with sudo is not reachable from the Swamp host')
        time.sleep(2)
    return {'key': node['key'], 'address': node['address'], 'host_key_type': host_key[0], 'host_key': host_key[1],
            'roles': node.get('roles', []), 'settings': node.get('settings', {})}


def grants(manifest):
    result = []
    for entry in manifest['vaults']:
        result.append({'subject': manifest['principal'], 'effect': 'allow', 'actions': entry['actions'],
                       'resource': 'vault:' + entry['vault'], 'condition': 'key.matches(' + json.dumps(entry['key_pattern']) + ')'})
    for entry in manifest['workflows']:
        result.append({'subject': manifest['principal'], 'effect': 'allow', 'actions': entry['actions'], 'resource': 'workflow:' + entry['workflow']})
    # The Gateway's separate reader, as in gateway-environment.md: read on workflow:* and data:*, so terminal
    # projection sees step outputs.
    for resource in ('workflow:*', 'data:*'):
        result.append({'subject': 'user:orbit-gateway-read', 'effect': 'allow', 'actions': ['read'], 'resource': resource})
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
    # Serve refuses a self-signed CA:TRUE server certificate: a local CA signs a CA:FALSE leaf.
    tls = STATE / 'tls'
    tls.mkdir(exist_ok=True, mode=0o700)
    run(['openssl', 'req', '-x509', '-newkey', 'ed25519', '-nodes', '-days', '2', '-subj', '/CN=swamp-proof CA',
         '-addext', 'basicConstraints=critical,CA:TRUE', '-addext', 'keyUsage=critical,keyCertSign',
         '-keyout', str(tls / 'ca-key.pem'), '-out', str(tls / 'ca.pem')])
    run(['openssl', 'req', '-new', '-newkey', 'ed25519', '-nodes', '-subj', '/CN=swamp.orbit',
         '-keyout', str(tls / 'key.pem'), '-out', str(tls / 'leaf.csr')])
    (tls / 'leaf.ext').write_text('basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\n'
                                  'extendedKeyUsage=serverAuth\nsubjectAltName=DNS:swamp.orbit,IP:127.0.0.1\n')
    run(['openssl', 'x509', '-req', '-in', str(tls / 'leaf.csr'), '-CA', str(tls / 'ca.pem'), '-CAkey', str(tls / 'ca-key.pem'),
         '-CAcreateserial', '-days', '2', '-extfile', str(tls / 'leaf.ext'), '-out', str(tls / 'cert.pem')])
    run(['chown', '-R', 'orbit:orbit', str(STATE)])
    hosts = Path('/etc/hosts')
    if 'swamp.orbit' not in hosts.read_text():
        hosts.write_text(hosts.read_text() + '127.0.0.1 swamp.orbit\n')
    common = (f'User=orbit\nEnvironment=HOME=/home/orbit SWAMP_HOME={STATE}/home SWAMP_CONFIG_DIR={STATE}/config\n'
              'Environment=SWAMP_NO_UPDATE_CHECK=1 SWAMP_NO_TELEMETRY=1\nUMask=0077\nRestart=on-failure\n')
    units = {
        # 209's serve flags, with TLS terminated by serve itself instead of the swamp role's Caddy.
        'orbit-swamp-serve': ('[Unit]\nDescription=Proof Swamp serve\n[Service]\n' + common
                              + f'WorkingDirectory={REPO}\nAmbientCapabilities=CAP_NET_BIND_SERVICE\n'
                              + f'ExecStart={BINARY} serve --repo-dir {REPO} --port {PORT} --host 127.0.0.1 --auth-mode token '
                              + f'--admins user:proof-operator --club-api-key-file {club} --trusted-hosts swamp.orbit,swamp.orbit:{PORT} '
                              + '--dispatch-env-allow=, --remote-only --hot-reload --no-schedule '
                              + f'--cert-file {tls}/cert.pem --key-file {tls}/key.pem --grants-file {STATE}/grants.yaml\n'),
        # 209's worker unit: the Node SSH key arrives as the proxy-ssh-identity credential.
        'orbit-node-worker': ('[Unit]\nDescription=Proof Swamp worker orbit-node-209\nAfter=orbit-swamp-serve.service\n[Service]\n' + common
                              + f'WorkingDirectory={STATE}\nLoadCredential=enrollment:{STATE}/credentials/enrollment\n'
                              + f'LoadCredential=server:{STATE}/credentials/worker\nLoadCredential=proxy-ssh-identity:{IDENTITY}\n'
                              + f'ExecStart={BINARY} worker connect {URL} --token-file=%d/enrollment --server-token-file=%d/server '
                              + f'--ca-cert={tls}/ca.pem --cache-dir={STATE}/worker-cache --label role=swamp --label node=209\n'),
    }
    for name, contents in units.items():
        Path(f'/etc/systemd/system/{name}.service').write_text(contents)
    run(['systemctl', 'daemon-reload'])
    run(['systemctl', 'restart', *units])
    deadline = time.monotonic() + 180
    while True:
        try:
            workers = native('worker', 'list', '--server', URL, '--token-file', str(STATE / 'credentials/operator'),
                             '--ca-cert', str(tls / 'ca.pem'), timeout=15)['workers']
            if any(row.get('tokenName') == 'orbit-node-209' and row.get('status') in ('idle', 'busy') for row in workers):
                break
        except (RuntimeError, KeyError, subprocess.TimeoutExpired):
            pass
        if time.monotonic() > deadline:
            raise RuntimeError('the orbit-node-209 worker did not connect to serve')
        time.sleep(2)


@step('gateway')
def gateway(node):
    # API contract generation (post-install bin/build-api) is a build output the proof does not use.
    run(['composer', 'install', '--no-interaction', '--no-progress', '--prefer-dist', '--no-dev', '--no-scripts', '--optimize-autoloader'],
        user=ACCOUNT, cwd=GATEWAY, timeout=900)
    run(['php', 'artisan', 'package:discover'], user=ACCOUNT, cwd=GATEWAY)
    database = STATE / 'gateway.sqlite'
    database.unlink(missing_ok=True)
    database.touch()
    settings = {
        'APP_KEY': '', 'APP_ENV': 'production', 'APP_DEBUG': 'false', 'APP_URL': 'https://gateway.orbit',
        'DB_CONNECTION': 'sqlite', 'DB_DATABASE': str(database), 'QUEUE_CONNECTION': 'sync', 'CACHE_STORE': 'file',
        'ORBIT_GATEWAY_QUEUE_DRIVER': 'database',
        'SESSION_DRIVER': 'file', 'BROADCAST_CONNECTION': 'log', 'LOG_CHANNEL': 'single',
        'ORBIT_SWAMP_ADDRESS': '127.0.0.1', 'ORBIT_SWAMP_CA_PATH': str(STATE / 'tls/ca.pem'),
        'ORBIT_SWAMP_RUN_TOKEN': (STATE / 'credentials/gateway').read_text().strip(),
        'ORBIT_SWAMP_READ_TOKEN': (STATE / 'credentials/reader').read_text().strip(),
    }
    environment = GATEWAY / '.env'
    private(environment, '\n'.join(f'{key}={value}' for key, value in settings.items()) + '\n')
    run(['php', 'artisan', 'key:generate', '--force'], user=ACCOUNT, cwd=GATEWAY)
    run(['chown', 'orbit:orbit', str(database)])
    run(['php', 'artisan', 'migrate', '--force'], user=ACCOUNT, cwd=GATEWAY, timeout=600)
    facts = {'gateway': TOPOLOGY['network']['gateway'], 'gateway_host_key': Path('/etc/ssh/ssh_host_ed25519_key.pub').read_text().split()[1],
             'operator': TOPOLOGY['operator']['address'], 'target': node}
    return json.loads(run(['php', str(SOURCE / 'tools/swamp-proof-guest/gateway.php'), 'seed'], input=json.dumps(facts), user=ACCOUNT, cwd=GATEWAY))


def main():
    if os.geteuid() != 0:
        raise RuntimeError('run as root')
    node = target()
    swamp()
    seeded = gateway(node)
    facts = {'ready': True, 'commit': run(['git', '-C', str(SOURCE), 'rev-parse', 'HEAD'], user=ACCOUNT).strip(),
             'topology': TOPOLOGY['name'], 'topology_version': TOPOLOGY['version'], 'nodes': seeded['nodes'],
             'operator_address': TOPOLOGY['operator']['address'], 'target': node, 'timings': timings}
    (STATE / 'up.json').write_text(json.dumps(facts))
    print(json.dumps(facts))


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, subprocess.SubprocessError, KeyError, ValueError, TypeError) as error:
        print(json.dumps({'ready': False, 'error': str(error), 'timings': timings}))
        sys.exit(1)
