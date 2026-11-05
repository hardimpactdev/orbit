"""Shared proof fixture bootstrap. Call only in the explicitly owned fixture host.

Context fixes every source/state/topology path. No topology acquisition occurs here.
The legacy up entrypoint and task-local nested reset use these same functions.
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
from types import SimpleNamespace


def context(source, topology, *, home=Path('/home/orbit'), state=None, repo=None, nested=False):
    source, home = Path(source), Path(home)
    state = Path(state) if state else home / '.orbit/swamp-proof'
    return SimpleNamespace(SOURCE=source, TOPOLOGY=topology, ACCOUNT='orbit', HOME=home,
        STATE=state, REPO=Path(repo) if repo else source / 'swamp', GATEWAY=source / 'apps/gateway',
        BINARY='/usr/local/bin/swamp', PORT=9418, URL='ws://127.0.0.1:9418',
        ROLE_CREDENTIALS=Path('/etc/orbit/swamp-role-worker'), AGENT=state / 'agent',
        IDENTITY=home / '.orbit/ssh/proof_worker_ed25519', BOOTSTRAP=home / '.orbit/ssh/id_ed25519', nested=nested)

def member_address(ctx):
    """The Gateway's address on the topology's private network: its WireGuard hub address, else the Incus address."""
    wireguard = ctx.TOPOLOGY.get('wireguard')
    return wireguard['nodes']['gateway']['ip'] if wireguard else ctx.TOPOLOGY['network']['gateway']

def run(ctx, argv, *, input=None, timeout=300, user=None, cwd=None, env=None):
    if user:
        argv = ['/usr/sbin/runuser', '-u', user, '--', *argv]
    result = subprocess.run(argv, input=input, capture_output=True, text=True, timeout=timeout, cwd=cwd, env=env, check=False)
    if result.returncode:
        sys.stderr.write(result.stdout[-4000:] + result.stderr[-4000:])
        raise RuntimeError(f'{Path(argv[0]).name} failed: ' + ' '.join(argv[:6]))
    return result.stdout

def native(ctx, *argv, timeout=120):
    env = {'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': str(ctx.HOME), 'SWAMP_HOME': str(ctx.STATE / 'home'), 'SWAMP_CONFIG_DIR': str(ctx.STATE / 'config'), 'SWAMP_API_KEY_FILE': str(ctx.HOME / '.config/swamp/club-api-key'), 'SWAMP_NO_UPDATE_CHECK': '1', 'SWAMP_NO_TELEMETRY': '1'}
    repo = [] if '--server' in argv else ['--repo-dir', str(ctx.REPO)]
    output = run(ctx, [ctx.BINARY, *argv, *repo, '--json'], user=ctx.ACCOUNT, env=env, timeout=timeout, cwd=ctx.REPO)
    for index, char in enumerate(output):
        if char in '{[':
            try:
                value, end = json.JSONDecoder().raw_decode(output[index:])
                if not output[index + end:].strip():
                    return value
            except ValueError:
                pass
    raise RuntimeError('swamp reply was not JSON: ' + argv[0])

def private(ctx, path, value):
    path.parent.mkdir(parents=True, exist_ok=True, mode=448)
    path.write_text(value)
    path.chmod(384)
    account = pwd.getpwnam(ctx.ACCOUNT)
    os.chown(path, account.pw_uid, account.pw_gid)

def grants(ctx, manifest):
    result = []
    for entry in manifest['vaults']:
        result.append({'subject': manifest['principal'], 'effect': 'allow', 'actions': entry['actions'], 'resource': 'vault:' + entry['vault'], 'condition': 'key.matches(' + json.dumps(entry['key_pattern']) + ')'})
    for entry in manifest['workflows']:
        result.append({'subject': manifest['principal'], 'effect': 'allow', 'actions': entry['actions'], 'resource': 'workflow:' + entry['workflow']})
    result.extend(json.loads((ctx.REPO / 'config/serve-grants.json').read_text())['grants'])
    return {'grants': result}

def swamp(ctx):
    club = ctx.HOME / '.config/swamp/club-api-key'
    if not club.is_file():
        raise RuntimeError('normal provision did not deliver the proof club credential')
    pin = re.search('sha256: "([a-f0-9]{64})"', (ctx.REPO / 'extensions/behaviors/node-worker/config.ts').read_text())[1]
    with open(ctx.BINARY, 'rb') as stream:
        if hashlib.file_digest(stream, 'sha256').hexdigest() != pin:
            raise RuntimeError('the image swamp binary differs from the committed runtime pin')
    ctx.STATE.mkdir(parents=True, exist_ok=True, mode=448)
    run(ctx, ['chown', '-R', 'orbit:orbit', str(ctx.STATE), str(ctx.REPO)])
    subprocess.run(['systemctl', 'stop', 'orbit-node-worker', 'orbit-swamp-serve'], capture_output=True, check=False)
    run(ctx, ['rm', '-rf', str(ctx.STATE / 'datastore')])
    native(ctx, 'datastore', 'setup', 'filesystem', '--path', str(ctx.STATE / 'datastore'), '--format', '3', '--skip-migration')
    for name, entry in json.loads((ctx.REPO / 'extensions/models/upstream_extensions.json').read_text()).items():
        native(ctx, 'extension', 'pull', name + '@' + entry['version'], timeout=300)
    manifest = json.loads((ctx.REPO / 'config/gateway-workflow-grants.json').read_text())
    (ctx.STATE / 'grants.yaml').write_text(json.dumps(grants(ctx, manifest)))
    existing = {re.search('^name: ["\\\']?([^"\\\'\\n]+)', path.read_text(), re.MULTILINE)[1].strip() for path in (ctx.REPO / 'vaults/local_encryption').glob('*.yaml')} if (ctx.REPO / 'vaults/local_encryption').is_dir() else set()
    for name in sorted({entry['vault'] for entry in manifest['vaults']} - existing):
        native(ctx, 'vault', 'create', 'local_encryption', name)
    for name, principal in (('operator', 'user:proof-operator'), ('gateway', 'user:orbit-gateway'), ('reader', 'user:orbit-gateway-read'), ('worker', 'worker:orbit-node-209')):
        native(ctx, 'access', 'token', 'mint', name, '--principal', principal, '--duration', '24h')
        private(ctx, ctx.STATE / 'credentials' / name, native(ctx, 'access', 'token', 'reveal', name, '--yes')['token'])
    private(ctx, ctx.STATE / 'credentials/enrollment', native(ctx, 'worker', 'token', 'create', 'orbit-node-209', '--duration', '24h')['token'])
    for worker in ('a', 'b'):
        name = f'orbit-swamp-worker-{worker}'
        native(ctx, 'access', 'token', 'mint', f'{name}-server', '--principal', f'worker:{name}', '--duration', '24h')
        folder = ctx.ROLE_CREDENTIALS / worker
        run(ctx, ['install', '-d', '-o', 'root', '-g', 'root', '-m', '0700', str(folder)])
        private(ctx, folder / 'server.token', native(ctx, 'access', 'token', 'reveal', f'{name}-server', '--yes')['token'])
        private(ctx, folder / 'enrollment.token', native(ctx, 'worker', 'token', 'create', name, '--duration', '24h')['token'])
        run(ctx, ['chown', 'root:root', str(folder / 'server.token'), str(folder / 'enrollment.token')])
    tls = ctx.STATE / 'tls'
    tls.mkdir(exist_ok=True, mode=448)
    run(ctx, ['openssl', 'req', '-x509', '-newkey', 'ed25519', '-nodes', '-days', '2', '-subj', '/CN=swamp-proof CA', '-addext', 'basicConstraints=critical,CA:TRUE', '-addext', 'keyUsage=critical,keyCertSign', '-keyout', str(tls / 'ca-key.pem'), '-out', str(tls / 'ca.pem')])
    run(ctx, ['chown', '-R', 'orbit:orbit', str(ctx.STATE)])
    hosts = Path('/etc/hosts')
    lines = [line for line in hosts.read_text().splitlines() if 'swamp.orbit' not in line.split()[1:]]
    hosts.write_text('\n'.join([*lines, f"{member_address(ctx)} swamp.orbit"]) + '\n')
    common = f'User=orbit\nEnvironment=HOME=/home/orbit SWAMP_HOME={ctx.STATE}/home SWAMP_CONFIG_DIR={ctx.STATE}/config\nEnvironment=SWAMP_NO_UPDATE_CHECK=1 SWAMP_NO_TELEMETRY=1\nUMask=0077\nRestart=on-failure\n'
    units = {'orbit-swamp-serve': '[Unit]\nDescription=Proof Swamp serve\n[Service]\n' + common + f'WorkingDirectory={ctx.REPO}\n' + f'ExecStart={ctx.BINARY} serve --repo-dir {ctx.REPO} --port {ctx.PORT} --host 127.0.0.1 --auth-mode token ' + f'--admins user:proof-operator --club-api-key-file {club} --trusted-hosts swamp.orbit --trust-proxy ' + '--dispatch-env-allow=, --remote-only --hot-reload ' + f'--grants-file {ctx.STATE}/grants.yaml\n', 'orbit-node-worker': '[Unit]\nDescription=Proof Swamp worker orbit-node-209\nAfter=orbit-swamp-serve.service\n[Service]\n' + common + f'WorkingDirectory={ctx.STATE}\nLoadCredential=enrollment:{ctx.STATE}/credentials/enrollment\n' + f'LoadCredential=server:{ctx.STATE}/credentials/worker\nLoadCredential=proxy-ssh-identity:{ctx.IDENTITY}\n' + f'ExecStart={ctx.BINARY} worker connect {ctx.URL} --token-file=%d/enrollment --server-token-file=%d/server ' + f'--cache-dir={ctx.STATE}/worker-cache --concurrency 16 --label role=swamp --label node=209\n'}
    for name, contents in units.items():
        Path(f'/etc/systemd/system/{name}.service').write_text(contents)
    for row in native(ctx, 'workflow', 'schedule', 'list')['schedules']:
        native(ctx, 'workflow', 'schedule', 'pause', row['workflowId'], '--reason', 'proof topology')
    run(ctx, ['systemctl', 'daemon-reload'])
    run(ctx, ['systemctl', 'restart', *units])
    if ctx.nested:
        return  # The native queued SSH execution establishes worker readiness.
    deadline = time.monotonic() + 180
    while True:
        try:
            workers = native(ctx, 'worker', 'list', '--server', ctx.URL, '--token-file', str(ctx.STATE / 'credentials/operator'), timeout=15)['workers']
            if any((row.get('tokenName') == 'orbit-node-209' and row.get('status') in ('idle', 'busy') for row in workers)):
                break
        except (RuntimeError, KeyError, subprocess.TimeoutExpired):
            pass
        if time.monotonic() > deadline:
            raise RuntimeError('the orbit-node-209 worker did not connect to serve')
        time.sleep(2)

def gateway(ctx, nodes):
    if not ctx.nested:
        run(ctx, ['composer', 'install', '--no-interaction', '--no-progress', '--prefer-dist', '--no-dev', '--no-scripts', '--optimize-autoloader'], user=ctx.ACCOUNT, cwd=ctx.GATEWAY, timeout=900)
    run(ctx, ['php', 'artisan', 'package:discover'], user=ctx.ACCOUNT, cwd=ctx.GATEWAY)
    ca = ctx.HOME / '.orbit/ca'
    if (ca / 'root.pem').exists() and 'Certificate Sign' not in subprocess.run(['openssl', 'x509', '-in', str(ca / 'root.pem'), '-noout', '-ext', 'keyUsage'], capture_output=True, text=True, check=False).stdout:
        (ca / 'root.pem').unlink()
    if not (ca / 'root.pem').exists():
        ca.mkdir(parents=True, exist_ok=True)
        run(ctx, ['openssl', 'req', '-x509', '-newkey', 'rsa:4096', '-nodes', '-days', '30', '-subj', '/CN=Orbit proof root CA', '-addext', 'basicConstraints=critical,CA:TRUE', '-addext', 'keyUsage=critical,keyCertSign,cRLSign', '-keyout', str(ca / 'root.key'), '-out', str(ca / 'root.pem')])
        run(ctx, ['chown', '-R', f'{ctx.ACCOUNT}:{ctx.ACCOUNT}', str(ctx.HOME / '.orbit/ca')])
    run(ctx, ['install', '-D', '-o', ctx.ACCOUNT, '-g', ctx.ACCOUNT, '-m', '0644', str(ctx.GATEWAY / 'resources/agent/frozen-manifest.json'), str(ctx.SOURCE / 'apps/agent/delivery/manifest.json')])
    database = ctx.STATE / 'gateway.sqlite'
    database.unlink(missing_ok=True)
    database.touch()
    settings = {'APP_KEY': '', 'APP_ENV': 'production', 'APP_DEBUG': 'false', 'APP_URL': 'https://gateway.orbit', 'DB_CONNECTION': 'sqlite', 'DB_DATABASE': str(database), 'QUEUE_CONNECTION': 'sync', 'CACHE_STORE': 'file', 'ORBIT_GATEWAY_QUEUE_DRIVER': 'database', 'SESSION_DRIVER': 'file', 'BROADCAST_CONNECTION': 'log', 'LOG_CHANNEL': 'single', 'ORBIT_SWAMP_RUN_TOKEN': (ctx.STATE / 'credentials/gateway').read_text().strip(), 'ORBIT_SWAMP_READ_TOKEN': (ctx.STATE / 'credentials/reader').read_text().strip(), 'ORBIT_HOME': str(ctx.HOME / '.orbit'), 'ORBIT_SWAMP_SSH_PUBLIC_KEY': '"' + ' '.join(ctx.IDENTITY.with_suffix('.pub').read_text().split()[:2]) + '"', 'ORBIT_GATEWAY_CHECKOUT': str(ctx.GATEWAY)}
    environment = ctx.GATEWAY / '.env'
    private(ctx, environment, '\n'.join((f'{key}={value}' for key, value in settings.items())) + '\n')
    run(ctx, ['chown', f'{ctx.ACCOUNT}:{ctx.ACCOUNT}', str(ctx.HOME / '.orbit')])
    run(ctx, ['install', '-d', '-m', '0700', '-o', ctx.ACCOUNT, '-g', ctx.ACCOUNT, str(ctx.HOME / '.orbit/locks')])
    run(ctx, ['php', 'artisan', 'key:generate', '--force'], user=ctx.ACCOUNT, cwd=ctx.GATEWAY)
    run(ctx, ['chown', 'orbit:orbit', str(database)])
    run(ctx, ['php', 'artisan', 'migrate', '--force'], user=ctx.ACCOUNT, cwd=ctx.GATEWAY, timeout=600)
    network = ctx.TOPOLOGY['network']
    ctx.AGENT.mkdir(parents=True, exist_ok=True, mode=448)
    run(ctx, ['chown', 'root:root', str(ctx.AGENT)])
    if not (ctx.AGENT / 'secret').exists():
        private(ctx, ctx.AGENT / 'secret', secrets.token_hex(32))
        run(ctx, ['chown', 'root:root', str(ctx.AGENT / 'secret')])
    wireguard = ctx.TOPOLOGY.get('wireguard')
    if wireguard:
        nodes = [{**node, 'wireguard_ip': wireguard['nodes'][node['key']]['ip']} for node in nodes]
    facts = {'gateway': network['gateway'], 'gateway_host_key': Path('/etc/ssh/ssh_host_ed25519_key.pub').read_text().split()[1], 'gateway_agent_secret_hash': hashlib.sha256((ctx.AGENT / 'secret').read_text().encode()).hexdigest(), 'operator': ctx.TOPOLOGY['operator']['address'], 'targets': nodes,
             'subnet': wireguard['subnet'] if wireguard else str(ipaddress.ip_network(f"{network['gateway']}/{network['prefix']}", strict=False))}
    if wireguard:
        facts['wireguard'] = wireguard
    seeded = json.loads(run(ctx, ['php', str(ctx.SOURCE / 'tools/swamp-proof-guest/gateway.php'), 'seed'], input=json.dumps(facts), user=ctx.ACCOUNT, cwd=ctx.GATEWAY))
    public = ' '.join(ctx.IDENTITY.with_suffix('.pub').read_text().split()[:2]) + ' swamp-proof-worker'
    run(ctx, ['sh', '-c', 'install -d -m 0700 -o orbit -g orbit /home/orbit/.ssh; f=/home/orbit/.ssh/authorized_keys; touch "$f"; chown orbit:orbit "$f"; chmod 0600 "$f"; grep -qxF "$1" "$f" || printf "%s\\n" "$1" >> "$f"', 'sh', public])
    for command in seeded['gateway_firewall']:
        run(ctx, command)
    serve_gateway(ctx, seeded['nodes']['gateway'], seeded['swamp_route'])
    run(ctx, ['php', str(ctx.SOURCE / 'tools/swamp-proof-guest/gateway.php'), 'known-hosts'], input='{}', user=ctx.ACCOUNT, cwd=ctx.GATEWAY)
    observe(ctx, seeded['nodes']['gateway'])
    return seeded

def leaf(ctx, directory, name, hosts):
    """A certificate from the proof root at the path the role's certificate step writes, as root:caddy 0640."""
    ca = ctx.HOME / '.orbit/ca'
    directory.mkdir(exist_ok=True, mode=488)
    run(ctx, ['openssl', 'req', '-new', '-newkey', 'rsa:2048', '-nodes', '-subj', f'/CN={hosts[0]}', '-keyout', str(directory / f'{name}.key'), '-out', str(directory / f'{name}.csr')])
    (directory / f'{name}.ext').write_text('basicConstraints=critical,CA:FALSE\nextendedKeyUsage=serverAuth\nsubjectAltName=' + ','.join((('IP:' if host[0].isdigit() else 'DNS:') + host for host in hosts)) + '\n')
    run(ctx, ['openssl', 'x509', '-req', '-in', str(directory / f'{name}.csr'), '-CA', str(ca / 'root.pem'), '-CAkey', str(ca / 'root.key'), '-CAcreateserial', '-days', '2', '-extfile', str(directory / f'{name}.ext'), '-out', str(directory / f'{name}.pem')])
    run(ctx, ['install', '-m', '0640', str(ca / 'root.pem'), str(directory / 'root-ca.pem')])
    for extra in ('csr', 'ext'):
        (directory / f'{name}.{extra}').unlink()
    run(ctx, ['chown', '-R', 'root:caddy', str(directory)])
    run(ctx, ['chmod', '0640', str(directory / f'{name}.key'), str(directory / f'{name}.pem')])

def observe(ctx, node_id):
    """Node 209's own agent, beside the task VM's observer (version 17).

    The task VM's orbit-agent reports this VM to the fleet. Root's user manager runs a second orbit-agent.service with
    the files node:converge renders for Node 209, bound over /etc/orbit/agent in its own mount namespace, so Doctor
    reads 209's role and firewall facts from the proof Gateway and the outer observer keeps its own configuration.
    """
    rendered = json.loads(run(ctx, ['php', str(ctx.SOURCE / 'tools/swamp-proof-guest/gateway.php'), 'agent'], user=ctx.ACCOUNT, cwd=ctx.GATEWAY, input=json.dumps({'node': node_id})).strip().splitlines()[-1])
    if ctx.nested:
        install_agent(ctx, rendered, (ctx.AGENT / 'secret').read_text())
        return
    (ctx.AGENT / 'config.toml').write_text(rendered['configuration'])
    (ctx.AGENT / 'ca.pem').write_text(rendered['certificate'])
    run(ctx, ['chown', '-R', 'root:root', str(ctx.AGENT)])
    run(ctx, ['chmod', '0600', str(ctx.AGENT / 'secret')])
    delivery = ctx.SOURCE.parent / 'agent-delivery/orbit-agent-linux-x86_64'
    binary = '/usr/local/bin/orbit-proof-agent'
    run(ctx, ['install', '-m', '0755', str(delivery if delivery.is_file() else Path('/usr/local/bin/orbit-agent')), binary])
    unit = '\n'.join((line for line in rendered['unit'].splitlines() if not line.startswith(('User=', 'Group='))))
    unit = unit.replace('Description=Orbit Node agent', 'Description=Orbit proof agent for Node 209')
    unit = unit.replace('ExecStart=/usr/local/bin/orbit-agent', f'ExecStart={binary}\nBindPaths={ctx.AGENT}:/etc/orbit/agent')
    unit = unit.replace('WantedBy=multi-user.target', 'WantedBy=default.target') + '\n'
    Path('/etc/systemd/user').mkdir(parents=True, exist_ok=True)
    Path('/etc/systemd/user/orbit-agent.service').write_text(unit)
    run(ctx, ['loginctl', 'enable-linger', 'root'])
    run(ctx, ['systemctl', 'start', 'user@0.service'])
    manager = ['systemctl', '--user', '-M', 'root@']
    run(ctx, [*manager, 'daemon-reload'])
    run(ctx, [*manager, 'enable', 'orbit-agent'])
    run(ctx, [*manager, 'restart', 'orbit-agent'])
    run(ctx, ['systemctl', 'is-active', '--quiet', 'orbit-agent'])
    deadline = time.monotonic() + 120
    while subprocess.run([*manager, 'is-active', '--quiet', 'orbit-agent'], check=False).returncode:
        if time.monotonic() > deadline:
            raise RuntimeError('the Node 209 proof agent did not start')
        time.sleep(2)

def route_leaf(ctx, route_id, hosts):
    """A Route's certificate at the path its Caddy site loads: cert.pem and key.pem under its scope (version 17)."""
    directory = Path(f'/etc/caddy/orbit-certificates/route-{route_id}/current')
    directory.parent.mkdir(parents=True, exist_ok=True)
    leaf(ctx, directory, 'cert', hosts)
    (directory / 'cert.key').rename(directory / 'key.pem')

def caddy_access(ctx):
    """Caddy can reach the Gateway's public directory through the directories that lead to it."""
    path = ctx.HOME
    for part in ctx.GATEWAY.relative_to(ctx.HOME).parts:
        run(ctx, ['setfacl', '-m', 'u:caddy:x', str(path)])
        path = path / part
    run(ctx, ['setfacl', '-m', 'u:caddy:x', str(ctx.GATEWAY)])
    run(ctx, ['setfacl', '-m', 'u:caddy:rx', str(ctx.GATEWAY / 'public')])

def serve_gateway(ctx, node_id, swamp_route):
    """The Gateway on Node 209 as production runs it (version 12).

    The gateway and websocket role certificates sit where their certificate steps write them, PHP-FPM serves the
    orbit-gateway socket, and Caddy runs the Node's complete Caddyfile from the Gateway's own Caddy build renderer,
    with its admin endpoint on localhost, so a later Caddy build or certificate reload on this Node works as on a real
    Gateway. A step that signs a CSR calls the Gateway from 209; FastCGI keeps the caller's address, so peer identity holds.
    """
    ca = ctx.HOME / '.orbit/ca'
    run(ctx, ['install', '-m', '0644', str(ca / 'root.pem'), '/usr/local/share/ca-certificates/orbit-managed-root-ca.crt'])
    run(ctx, ['update-ca-certificates'])
    address = member_address(ctx)
    # The outer VM reaches the Gateway as https://<address>, never by name, so the leaf carries the address as an IP SAN.
    leaf(ctx, Path('/etc/caddy/orbit-cert-current'), 'gateway', ['gateway.orbit', address])
    leaf(ctx, Path('/etc/caddy/orbit-websocket-cert-current'), 'reverb', ['reverb.orbit'])
    route_leaf(ctx, swamp_route, ['swamp.orbit'])
    hosts = Path('/etc/hosts')
    lines = [line for line in hosts.read_text().splitlines() if 'gateway.orbit' not in line.split()[1:]]
    hosts.write_text('\n'.join([*lines, f'{address} gateway.orbit']) + '\n')
    resolved = run(ctx, ['getent', 'hosts', 'gateway.orbit']).split()
    if not resolved or resolved[0] != address:
        raise RuntimeError(f'gateway.orbit resolves to {resolved[:1]}, not this topology Gateway {address}')
    Path('/etc/php/8.5/fpm/pool.d/proof-gateway.conf').unlink(missing_ok=True)
    Path('/etc/php/8.5/fpm/pool.d/orbit-gateway.conf').write_text(f'[orbit-gateway]\nuser = {ctx.ACCOUNT}\ngroup = {ctx.ACCOUNT}\nlisten = /run/php/orbit-gateway.sock\nlisten.owner = caddy\nlisten.group = caddy\npm = ondemand\npm.max_children = 4\nclear_env = no\n')
    if ctx.TOPOLOGY.get('wireguard'):
        # Caddy listens on the hub address, so it starts after the hub; HTTPS arrives on the orbit interface under the
        # product's own gateway rule, and the handshakes arrive from the bridge under the proof-network rule.
        run(ctx, ['install', '-d', '/etc/systemd/system/caddy.service.d'])
        Path('/etc/systemd/system/caddy.service.d/orbit-wireguard.conf').write_text('[Unit]\nAfter=wg-quick@orbit.service\nWants=wg-quick@orbit.service\n')
        run(ctx, ['systemctl', 'daemon-reload'])
    else:
        # As on the nested Nodes, proof traffic crosses the isolated bridge, not the fleet's orbit interface (version 19).
        network = ctx.TOPOLOGY['network']
        run(ctx, ['ufw', 'allow', 'in', 'on', network['name'], 'from', str(ipaddress.ip_network(f'{address}/{network["prefix"]}', strict=False)), 'to', address, 'comment', 'proof-network'])
        run(ctx, ['ufw', '--force', 'delete', 'allow', 'in', 'on', network['name'], 'to', address, 'port', '443', 'proto', 'tcp', 'comment', 'orbit-proof gateway'])
    caddy_access(ctx)
    run(ctx, ['systemctl', 'enable', 'php8.5-fpm', 'caddy'])
    run(ctx, ['systemctl', 'restart', 'php8.5-fpm'])
    publish_caddy(ctx, node_id)

def publish_caddy(ctx, node_id):
    """Write and load the Node's Caddyfile as its Caddy build renders it; a render with problems stops up."""
    rendered = json.loads(run(ctx, ['php', str(ctx.SOURCE / 'tools/swamp-proof-guest/gateway.php'), 'caddyfile'], user=ctx.ACCOUNT, cwd=ctx.GATEWAY, input=json.dumps({'node': node_id})).strip().splitlines()[-1])
    if rendered['problems']:
        raise RuntimeError('the Gateway Caddyfile has problems: ' + '; '.join(rendered['problems']))
    Path('/etc/caddy/Caddyfile').write_text(rendered['content'])
    run(ctx, ['caddy', 'validate', '--config', '/etc/caddy/Caddyfile', '--adapter', 'caddyfile'])
    run(ctx, ['systemctl', 'restart', 'caddy'])


def install_agent(ctx, rendered, secret, *, execute=None):
    """Install the Gateway-rendered frozen observer on an owned fixture Node."""
    execute = execute or (lambda argv, **kwargs: run(ctx, argv, **kwargs))
    execute(['sh', '-c', 'getent group orbit-env >/dev/null || groupadd --system orbit-env'])
    execute(['install', '-d', '-m', '0700', '/etc/orbit/agent'])
    files = {'/etc/orbit/agent/secret': secret, '/etc/orbit/agent/config.toml': rendered['configuration'],
             '/etc/orbit/agent/ca.pem': rendered['certificate'],
             '/etc/systemd/system/orbit-agent.service': rendered['unit']}
    for path, content in files.items():
        execute(['sh', '-c', 'umask 077; cat > "$1"', 'sh', path], input=content)
    execute(['systemctl', 'daemon-reload'])
    execute(['systemctl', 'enable', '--now', 'orbit-agent'])
    execute(['systemctl', 'restart', 'orbit-agent'])


def realtime(ctx, ids):
    """Reverb, the agent-view subscriber and a running Node agent (version 11).

    The websocket role runs on this VM (Node 209) as its convergence leaves it: the Gateway issues the server
    credentials and renders the .env and unit, and the pinned orbit-reverb release serves behind reverb.orbit.
    The role's own convergence needs a per-Node worker the topology has not. A Node with `agent` converges through
    the real node:converge, which installs and starts orbit-agent; a staged agent delivery then replaces the binary.
    """
    if ctx.TOPOLOGY.get('websocket'):
        lines = run(ctx, ['php', str(ctx.SOURCE / 'tools/swamp-proof-guest/gateway.php'), 'fixture'], user=ctx.ACCOUNT, cwd=ctx.GATEWAY, input=json.dumps({'websocket': ids['gateway']})).strip().splitlines()
        plan = json.loads(lines[-1])['websocket']
        install = Path('/opt/orbit/websocket')
        if not (install / 'artisan').exists():
            run(ctx, ['install', '-d', '-o', ctx.ACCOUNT, '-g', ctx.ACCOUNT, str(install)])
            run(ctx, ['git', 'clone', '-q', plan['repository'], str(install)], user=ctx.ACCOUNT, timeout=600)
        run(ctx, ['git', '-C', str(install), 'checkout', '-q', '--detach', plan['ref']], user=ctx.ACCOUNT)
        run(ctx, ['composer', 'install', '--no-interaction', '--no-progress', '--prefer-dist', '--no-dev', '--optimize-autoloader'], user=ctx.ACCOUNT, cwd=install, timeout=900)
        private(ctx, install / '.env', plan['environment'])
        run(ctx, ['chown', f'{ctx.ACCOUNT}:{ctx.ACCOUNT}', str(install / '.env')])
        Path('/etc/systemd/system/orbit-websocket.service').write_text(plan['unit'])
        run(ctx, ['systemctl', 'daemon-reload'])
        run(ctx, ['systemctl', 'enable', 'orbit-websocket'])
        run(ctx, ['systemctl', 'restart', 'orbit-websocket'])
        publish_caddy(ctx, ids['gateway'])
    unit = Path('/etc/systemd/system/orbit-proof-agent-view.service')
    unit.write_text(f'[Unit]\nDescription=Orbit proof agent-view subscriber\nAfter=network.target\n\n[Service]\nType=simple\nUser={ctx.ACCOUNT}\nGroup={ctx.ACCOUNT}\nWorkingDirectory={ctx.GATEWAY}\nExecStart=/usr/bin/php {ctx.GATEWAY}/artisan orbit:agent-view\nRestart=always\nRestartSec=2\n\n[Install]\nWantedBy=multi-user.target\n')
    run(ctx, ['systemctl', 'daemon-reload'])
    run(ctx, ['systemctl', 'enable', 'orbit-proof-agent-view'])
    run(ctx, ['systemctl', 'restart', 'orbit-proof-agent-view'])


def projection_queue(ctx):
    """Run the real Gateway operations worker against the isolated fixture DB."""
    # Reuse the proven Documents tmpfiles layout, including its stable private lock.
    # /run is volatile; systemd restores this before workers start on later boots.
    runtime = Path('/etc/tmpfiles.d/orbit-proof-documents.conf')
    runtime.write_text('d /run/orbit 0755 root root -\n'
                       f'd /run/orbit/project-documents 0700 {ctx.ACCOUNT} {ctx.ACCOUNT} -\n'
                       f'f /run/orbit/project-documents/execution.lock 0600 {ctx.ACCOUNT} {ctx.ACCOUNT} -\n')
    run(ctx, ['systemd-tmpfiles', '--create', str(runtime)])
    unit = Path('/etc/systemd/system/orbit-proof-operations.service')
    unit.write_text(f"""[Unit]
Description=Proof Gateway operations queue
After=network.target

[Service]
User={ctx.ACCOUNT}
Group={ctx.ACCOUNT}
WorkingDirectory={ctx.GATEWAY}
ExecStart=/usr/bin/php {ctx.GATEWAY}/artisan queue:work gateway --queue=operations --sleep=1 --tries=1 --timeout=1700
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
""")
    run(ctx, ['systemctl', 'daemon-reload'])
    run(ctx, ['systemctl', 'enable', '--now', 'orbit-proof-operations'])
