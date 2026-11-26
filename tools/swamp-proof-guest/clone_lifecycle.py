"""Disposable clone fixtures and the bounded receipts read by the shared runner."""
import hashlib
import json
import os
import re
import shlex
import shutil
import subprocess
import tarfile
import tempfile
import time
import tomllib
from datetime import datetime
from pathlib import Path

CHECKOUT = Path(__file__).resolve().parents[2]
STAGED = CHECKOUT / '.orbit-artifacts' / 'proof-clones'
GATEWAY_DB = '/home/orbit/.orbit/gateway.sqlite'
GUEST_STATE = '/run/orbit-proof-clones'
EXECUTOR = '/usr/local/libexec/orbit-executor'
EXECUTOR_UNIT = 'orbit-executor.service'
ENGINE_UNIT = 'orbit-gateway-engine.service'
NATS_UNIT = 'nats-server.service'
POOL = 'orbit-instances'
TERMINAL = {'succeeded', 'failed', 'cancelled', 'interrupted'}
CREATE_STEPS = {'clone', 'fetch', 'prepare', 'start', 'checkout', 't3'}
APP_SERVICES = ('php8.5-fpm', 'orbit-gateway-horizon', 'orbit-gateway-schedule')
RUSTUP = 'https://static.rust-lang.org/rustup/archive/1.29.3/x86_64-unknown-linux-gnu/rustup-init'
RUSTUP_SHA256 = 'b1c8cb14e30889e7e2999e6b69e62d9e9b9ffc0f4df8180564d306d9680e6c55'
PROOF_RUST = '1.100.0'
WORK_CA = '/usr/local/share/ca-certificates/orbit-managed-root-ca.crt'
WORK_SAMPLES = 12


class ProofFailure(Exception):
    pass


class ProofInfrastructure(ProofFailure):
    pass


def require(condition, message):
    if not condition:
        raise ProofFailure(message)


def git(*args):
    return subprocess.check_output(['git', *args], cwd=CHECKOUT, text=True).strip()


def digest(path):
    with open(path, 'rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def download(url, sha256, path):
    fetched = subprocess.run(['curl', '--silent', '--show-error', '--fail', '--location', '--max-time', '90', '--output', str(path), url],
                             capture_output=True, timeout=95)
    require(fetched.returncode == 0 and digest(path) == sha256, f'could not fetch the pinned {path.name} artifact')


def cargo_env(directory):
    if shutil.which('cargo') and shutil.which('rustc'):
        return os.environ.copy()
    installer = directory / 'rustup-init'
    download(RUSTUP, RUSTUP_SHA256, installer)
    installer.chmod(0o700)
    cargo = directory / 'cargo'
    environment = {**os.environ, 'CARGO_HOME': str(cargo), 'RUSTUP_HOME': str(directory / 'rustup'),
                   'PATH': str(cargo / 'bin') + os.pathsep + os.environ['PATH']}
    result = subprocess.run([str(installer), '-y', '--profile', 'minimal', '--no-modify-path', '--default-toolchain', PROOF_RUST],
                            env=environment, capture_output=True, timeout=150)
    require(result.returncode == 0, f'could not install the pinned Rust {PROOF_RUST} toolchain in the proof copy')
    return environment


def stage_executor(log=False):
    require(not git('status', '--porcelain', '--', 'apps/agent'), 'stage from a clean apps/agent tree')
    head = git('rev-parse', 'HEAD')
    source = git('rev-parse', 'HEAD:apps/agent')
    STAGED.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='orbit-proof-build-') as build:
        environment = {**cargo_env(Path(build)), 'CARGO_TARGET_DIR': str(Path(build) / 'target')}
        result = subprocess.run(['cargo', 'build', '--locked', '--release', '-p', 'orbit-executor'], cwd=CHECKOUT / 'apps/agent',
                                env=environment, capture_output=not log, timeout=180)
        require(result.returncode == 0, 'the locked executor build failed; run python3 tools/swamp-proof-guest/clone_lifecycle.py for build diagnostics')
        require(git('rev-parse', 'HEAD') == head and not git('status', '--porcelain', '--', 'apps/agent'),
                'the executor source changed during its build')
        staged = STAGED / 'orbit-executor'
        shutil.copyfile(Path(build) / 'target' / 'release' / 'orbit-executor', staged)
        compiler = subprocess.check_output(['rustc', '--version'], text=True, env=environment, timeout=15).strip()
    version = tomllib.loads((CHECKOUT / 'apps/agent/crates/executor/Cargo.toml').read_text())['package']['version']
    manifest = {'build_head': head, 'agent_tree': source, 'executor_sha256': digest(staged), 'executor_version': version,
                'rustc': compiler}
    (STAGED / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    return manifest


def wait_for(read, message, seconds, interval=0.25):
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        value = read()
        if value:
            return value
        time.sleep(interval)
    raise ProofFailure(message)


class CloneLane:
    def __init__(self, run, fleet_contract):
        self.run = run
        self.gateway_address = fleet_contract.NESTED_GATEWAY
        self.node_checkout = fleet_contract.NODE_CHECKOUT
        self.gateway_app = self.node_checkout + '/apps/gateway'
        self.guest_helper = self.node_checkout + '/tools/swamp-proof-guest/clone-lifecycle.php'
        self.ca_path = fleet_contract.outer_ca()
        self.node = 'app-dev'
        self.node_id = int(run.fleet.nodes[self.node]['node_id'])
        self.node_ip = '10.45.0.2'
        self.prefix = 'proof-clones-' + os.urandom(4).hex()
        self.project = None
        self.default = None
        self.clones = {}
        self.capability = None
        self.installed = False
        self.closed = False
        self.firewall_added = False

    def record(self, name, data):
        self.run.evidence_text(name + '.json', json.dumps(data, indent=2) + '\n')

    def guest(self, node, *argv, stdin=None, timeout=45):
        result = self.run.fleet.sh(node, shlex.join(map(str, argv)), timeout=timeout, stdin_text=stdin)
        if result.returncode != 0:
            raise ProofInfrastructure(f'{node}: {argv[0]} {argv[1] if len(argv) > 1 else ""} failed ({result.returncode})')
        return result.stdout.strip()

    def push(self, node, source, path):
        guest = self.run.fleet.nodes[node]['instance']
        result = subprocess.run(['incus', 'file', 'push', '--uid=0', '--gid=0', '--mode=0755', str(source), guest + path],
                                capture_output=True, timeout=45)
        require(result.returncode == 0, f'{node}: could not install {Path(path).name}')

    def db(self, sql):
        return json.loads(self.guest('gateway', 'runuser', '-u', 'orbit', '--', 'sqlite3', '-cmd', '.timeout 3000', '-json', GATEWAY_DB, sql) or '[]')

    def helper(self, *args):
        return json.loads(self.guest('gateway', 'runuser', '-u', 'orbit', '--', 'php', self.guest_helper, *args))

    def gateway_php(self, *args, timeout=45):
        self.guest('gateway', 'runuser', '-u', 'orbit', '--', 'php', self.gateway_app + '/artisan', *args, timeout=timeout)

    def systemctl(self, node, *args):
        return self.guest(node, 'systemctl', *args, timeout=75)

    def append_env(self, content):
        self.guest('gateway', 'sh', '-eu', '-c', 'printf "\\n" >> "$1"; cat >> "$1"', 'sh', self.gateway_app + '/.env', stdin=content)

    def put(self, node, path, content):
        self.guest(node, 'sh', '-eu', '-c', 'umask 022; cat > "$1"', 'sh', path, stdin=content)

    def setup(self, repository, branch):
        require(self.run.target == self.node and all(self.run.fleet.nodes[role]['instance'] == 'nested-' + role for role in ('gateway', self.node)),
                'the clone proof only runs on the disposable nested Gateway and app-dev')
        head = git('rev-parse', 'HEAD')
        require(not git('status', '--porcelain', '--', 'apps/agent', 'apps/gateway'), 'the proof needs a clean Gateway and agent tree')
        gateway_head = self.guest('gateway', 'git', '-c', f'safe.directory={self.node_checkout}', '-C', self.node_checkout, 'rev-parse', 'HEAD')
        require(gateway_head == head, 'the nested Gateway does not mount the tested checkout')
        for role, address in (('gateway', self.gateway_address), (self.node, self.node_ip)):
            require(f'inet {address}/' in self.guest(role, 'ip', '-4', 'address', 'show', 'dev', 'orbit'),
                    f'{role}: not the nested proof WireGuard address')
        staged, manifest = STAGED / 'orbit-executor', STAGED / 'manifest.json'
        artifact = json.loads(manifest.read_text()) if manifest.is_file() else {}
        if artifact.get('agent_tree') != git('rev-parse', 'HEAD:apps/agent') or not staged.is_file() or digest(staged) != artifact.get('executor_sha256'):
            artifact = stage_executor()
        self.install(staged)
        live = self.set_capability(True)
        require(live['version'] == artifact['executor_version'], 'the advertised executor version does not match the build')
        gateway_cwd = self.systemctl('gateway', 'show', '-p', 'WorkingDirectory', '--value', ENGINE_UNIT)
        require(gateway_cwd == self.gateway_app, 'the engine service does not run the mounted Gateway source')
        running = self.systemctl(self.node, 'show', '-p', 'MainPID', '--value', EXECUTOR_UNIT)
        executor_hashes = [line.split()[0] for line in self.guest(self.node, 'sha256sum', EXECUTOR, f'/proc/{int(running)}/exe').splitlines()]
        require(executor_hashes == [artifact['executor_sha256']] * 2, 'the staged, on-disk and running executor differ')
        self.record('identity', {**artifact, 'head': head, 'gateway_head': gateway_head, 'gateway_working_directory': gateway_cwd,
                                'gateway_tree': git('rev-parse', 'HEAD:apps/gateway'), 'base': git('merge-base', 'HEAD', 'origin/main'),
                                'node': self.node_id, 'installed_executor_sha256': executor_hashes[0], 'running_executor_sha256': executor_hashes[1],
                                'nats': self.nats_identity, 'routes': self.routes, 'advertisement': live})
        code, project, error = self.api('POST', 'projects', {'slug': self.prefix, 'type': 'laravel-package',
                                                             'repository_url': repository, 'default_branch': branch, 'root': '.'})
        require(code == 201, f'disposable project admission: HTTP {code} {error}')
        self.project = int(project['id'])
        self.default = self.create('default', 'swamp', seed=True)['instance']
        runtime = self.runtime(self.default)
        require(runtime['seed_commit'], 'the disposable default did not take a seed')
        default_refusal = self.api('DELETE', f'instances/{self.default}', {'force': True}, 'protected-default')
        require(default_refusal[0] == 503 and default_refusal[2] == 'operation.kind_unavailable'
                and self.runtime(self.default) == runtime, 'the protected default did not refuse destroy unchanged')
        self.record('seed', {'project': self.project, 'instance': self.default, 'commit': runtime['seed_commit'],
                             'source': repository, 'branch': branch, 'pool': POOL,
                             'default_refusal': default_refusal[2],
                             'driver': json.loads(self.incus('query', '/1.0/storage-pools/' + POOL))['driver']})

    def install(self, staged):
        gateway_paths = (GUEST_STATE, '/etc/nats/nats.conf', '/usr/local/bin/nats-server', '/var/lib/nats',
                         '/etc/systemd/system/' + NATS_UNIT, '/etc/systemd/system/' + ENGINE_UNIT)
        node_paths = ('/etc/orbit/executor', '/var/lib/orbit/executor', EXECUTOR, '/etc/systemd/system/' + EXECUTOR_UNIT)
        for node, paths, user in (('gateway', gateway_paths, 'nats'), (self.node, node_paths, 'orbit-executor-relay')):
            self.guest(node, 'sh', '-eu', '-c', 'user=$1; shift; if getent passwd "$user" >/dev/null; then exit 1; fi; for path; do test ! -e "$path"; done',
                       'sh', user, *paths)
        self.guest(self.node, 'sh', '-c', 'if nft list table inet orbit_bus_guard >/dev/null 2>&1; then exit 1; fi')
        self.guest('gateway', 'sh', '-eu', '-c', '''
            if grep -qE '^ORBIT_(NATS_SEED|ENGINE_KINDS|EXECUTOR_(PUBLIC_KEYS|SIGNING_KEY)|INSTANCES_DEFAULT_DESTROY_PROJECTS)=' "$1"; then exit 1; fi
            install -d -m 0711 "$2"
            install -m 0600 "$1" "$2/gateway-env"
        ''', 'sh', self.gateway_app + '/.env', GUEST_STATE)
        self.installed = True
        self.guest('gateway', 'sh', '-eu', '-c', '''
            cd "$1"
            printf '\n' >> .env
            runuser -u orbit -- php artisan orbit:engine-keys >> .env
            php -r '$pair = sodium_crypto_sign_keypair(); printf("ORBIT_EXECUTOR_PUBLIC_KEYS=%s\\nORBIT_EXECUTOR_SIGNING_KEY=%s\\n", base64_encode(sodium_crypto_sign_publickey($pair)), base64_encode(sodium_crypto_sign_secretkey($pair)));' >> .env
            printf 'ORBIT_ENGINE_KINDS=instance.create,instance.destroy\n' >> .env
        ''', 'sh', self.gateway_app)
        self.gateway_php('config:clear')
        settings = self.helper('configuration')
        self.routes = settings['routes']
        require(set(self.routes) == {'instance.create', 'instance.destroy'}, 'incorrect proof engine routes')
        for node, user in (('gateway', 'nats'), (self.node, 'orbit-executor-relay')):
            self.guest(node, 'useradd', '--system', '--home-dir=/nonexistent', '--shell=/usr/sbin/nologin', user)
        with tempfile.TemporaryDirectory(prefix='orbit-proof-nats-') as directory:
            archive = Path(directory) / 'nats.tar.gz'
            artifact = settings['nats_artifact']
            download(artifact['urls'][0], artifact['sha256'], archive)
            server = Path(directory) / 'nats-server'
            with tarfile.open(archive) as release:
                member = f'nats-server-v{artifact["version"]}-linux-amd64/nats-server'
                with release.extractfile(member) as source, server.open('wb') as target:
                    shutil.copyfileobj(source, target)
            self.push('gateway', server, '/usr/local/bin/nats-server')
            self.nats_identity = {'version': self.guest('gateway', '/usr/local/bin/nats-server', '--version'),
                                  'binary_sha256': digest(server), 'archive_sha256': artifact['sha256']}
        self.push(self.node, staged, EXECUTOR)
        self.guest('gateway', 'install', '-d', '/etc/nats')
        self.guest(self.node, 'install', '-d', '/etc/orbit/executor')
        for node, directory, files in (
            ('gateway', '/etc/nats', {'nats.conf': settings['nats']}),
            ('gateway', '/etc/systemd/system', {NATS_UNIT: settings['nats_unit'], ENGINE_UNIT: settings['engine_unit']}),
            (self.node, '/etc/systemd/system', {EXECUTOR_UNIT: settings['executor_unit']}),
            (self.node, '/etc/orbit/executor', {'gateway.pub': settings['executor_keys'], 'node': str(self.node_id) + '\n',
             'nats.json': settings['executor_bus'], 'bus-guard.nft': settings['executor_guard'], 'restore-threads': '32\n'}),
        ):
            for name, content in files.items():
                self.put(node, directory + '/' + name, content)
        firewall = self.guest('gateway', 'ufw', 'allow', 'in', 'on', 'orbit', 'from', self.node_ip, 'to', self.gateway_address,
                              'port', 4222, 'proto', 'tcp', 'comment', self.prefix)
        self.firewall_added = 'Rule added' in firewall
        require(self.firewall_added, 'the proof Gateway NATS rule was already present')
        self.systemctl('gateway', 'daemon-reload')
        self.systemctl(self.node, 'daemon-reload')
        self.systemctl('gateway', 'start', NATS_UNIT)
        self.gateway_php('orbit:engine-setup')
        self.systemctl('gateway', 'start', ENGINE_UNIT)
        self.systemctl('gateway', 'restart', *APP_SERVICES)

    def set_capability(self, enabled):
        if self.capability is not enabled:
            self.put(self.node, '/etc/orbit/executor/enabled', ('instance\n' if enabled else '') + 'power\nproof\n')
            self.systemctl(self.node, 'restart', EXECUTOR_UNIT)
            self.capability = enabled

        def advertised():
            found = self.helper('capabilities', self.node_id)
            actions = found['actions']
            return found if 'proof.noop' in actions and ('instance.clone' in actions) is enabled and (enabled or 'instance.destroy' not in actions) else None

        return wait_for(advertised, f'executor did not advertise the expected capability ({enabled})', 15)

    def api(self, method, path, body=None, key=None):
        argv = ['curl', '--noproxy', '*', '--silent', '--show-error', '--max-time', '45', '--cacert', self.ca_path,
                '--request', method, '--write-out', '\n%{http_code}', 'https://' + self.gateway_address + '/api/v1/' + path]
        if body is not None:
            argv += ['--header', 'Content-Type: application/json', '--data', json.dumps(body)]
        if key is not None:
            argv += ['--header', 'Idempotency-Key: ' + self.prefix + '-' + key]
        result = subprocess.run(argv, capture_output=True, text=True, timeout=50)
        require(result.returncode == 0, f'{method} {path}: curl failed ({result.returncode})')
        payload, code = result.stdout.rsplit('\n', 1)
        answer = json.loads(payload)
        return int(code), answer.get('data'), (answer.get('error') or {}).get('code')

    def admitted(self, method, path, body, key):
        code, row, error = self.api(method, path, body, key)
        require(code == 202, f'{method} {path}: expected admission, got HTTP {code} {error}')
        return row

    def terminal(self, operation, expect='succeeded', seconds=140):
        def terminal_row():
            code, row, error = self.api('GET', f'operations/{operation}')
            require(code == 200, f'Operation {operation}: HTTP {code} {error}')
            return row if row['status'] in TERMINAL else None

        row = wait_for(terminal_row, f'Operation {operation} did not reach {expect}', seconds)
        require(row['status'] == expect, f'Operation {operation}: {row["status"]} ({row.get("error_code")}), expected {expect}')
        return row

    def runtime(self, instance):
        found = self.db(f'''SELECT i.status, r.container_name, r.t3_port, r.seed_commit, r.inner_volume,
            r.incus_project, r.clone_source_instance_id FROM instances i JOIN instance_runtimes r ON i.id=r.instance_id
            WHERE i.id={instance}''')
        return found[0] if found else None

    def steps(self, operation):
        return self.db(f'''SELECT name,action,target,status,sends,first_sent_ms,first_order_expires_ms,done_ms,error_code
            FROM operation_engine_steps WHERE operation_id={operation} ORDER BY id''')

    def journal(self, operation):
        code = '''import json, sys
found = []
for line in open('/var/lib/orbit/executor/journal'):
    try:
        row = json.loads(line)
    except ValueError:
        continue
    if str(row.get('operation', '')).startswith(sys.argv[1] + ':'):
        found.append({key: row.get(key) for key in ('record', 'operation', 'action', 'target', 'at_ms')}
                     | {'outcome': {key: row.get('outcome', {}).get(key) for key in ('status', 'code')}})
print(json.dumps(found))'''
        return json.loads(self.guest(self.node, 'python3', '-c', code, operation))

    def operation(self, operation, via):
        summary = self.db(f'''SELECT id,kind,target_id,status,request_id,lock_key,created_at,started_at,finished_at,error_code,
            json_extract(state,'$.swamp_run_id') AS run,
            json_extract(state,'$.engine.started_ms') AS engine_started_ms,
            json_extract(state,'$.engine.finished_ms') AS engine_finished_ms
            FROM operations WHERE id={operation}''')[0]
        engine = summary['run'] == f'engine-{operation}'
        require(summary['run'] and engine == (via == 'engine'), f'Operation {operation} did not run through {via}')
        summary['steps'] = self.steps(operation)
        require(engine or not summary['steps'], f'Swamp Operation {operation} recorded engine steps')
        if engine:
            summary['journal'] = self.journal(operation)
        else:
            summary['dispatch_timing'] = self.swamp_timing(operation)
            summary['native_steps'] = self.db(f'''SELECT json_extract(value,'$.name') AS name, json_extract(value,'$.status') AS status,
                json_extract(value,'$.started_at') AS started_at, json_extract(value,'$.finished_at') AS finished_at
                FROM json_each((SELECT json_extract(state,'$.native_progress.steps') FROM operations WHERE id={operation}))''')
        return summary

    def swamp_timing(self, operation):
        code = '''import json, pathlib, sys
for path in sorted(pathlib.Path(sys.argv[1]).glob('laravel*.log'), reverse=True)[:2]:
    with path.open() as logfile:
        logfile.seek(max(0, path.stat().st_size - 1024 * 1024))
        entries = [line.partition('Swamp direct start ')[2].strip() for line in logfile
                   if 'Swamp direct start {"operation_id":' + sys.argv[2] + ',' in line]
    if entries:
        row = json.loads(entries[-1])
        print(json.dumps({key: value for key, value in row.items() if key.endswith('_ms')}))
        break'''
        answer = self.guest('gateway', 'runuser', '-u', 'orbit', '--', 'python3', '-c', code,
                            self.gateway_app + '/storage/logs', operation)
        return json.loads(answer or '{}')

    def create(self, name, via, seed=False):
        require(re.fullmatch('[a-z][a-z0-9-]{0,20}', name) and via in ('engine', 'swamp'), 'invalid clone case')
        advertised = self.set_capability(via == 'engine' or seed)
        started = time.monotonic()
        admitted = self.admitted('POST', 'instances', {'project_id': self.project, 'node_id': self.node_id, 'name': name}, name + '-create')
        operation, instance = int(admitted['id']), int(admitted['target_id'])
        if seed:
            self.default = instance
        else:
            self.clones[name] = {'instance': instance, 'create': operation}
        row = self.terminal(operation)
        create_ms = round((time.monotonic() - started) * 1000)
        completion = (row.get('result') or {}).get('completion_operation_id')
        completion_status = None
        if completion:
            completion_status = self.terminal(int(completion))['status']
        require(self.api('GET', f'instances/{instance}')[1]['status'] == 'active', f'Instance {instance} is not active')
        if not seed:
            require(self.reachable(instance), f'Instance {instance} T3 is not reachable')
        ready_ms = round((time.monotonic() - started) * 1000)
        summary = self.operation(operation, via)
        if via == 'engine':
            relevant = [step for step in summary['steps'] if step['name'] != 'stop']
            require({step['name'] for step in relevant} == CREATE_STEPS and all(step['status'] == 'done' for step in relevant),
                    f'Create {operation} did not finish the expected engine steps')
            done = {(entry['operation'], entry['action'], entry['target']) for entry in summary['journal'] if entry['record'] == 'done'
                    and entry['outcome']['status'] in ('done', 'applied', 'output')}
            require(all((f'{operation}:{step["name"]}', step['action'], step['target']) in done for step in relevant),
                    f'Create {operation} lacks successful signed journal records')
        result = {'instance': instance, 'create': summary, 'completion': completion, 'completion_status': completion_status, 'advertisement': advertised,
                  'request_through_create_ms': create_ms, 'request_through_ready_ms': ready_ms, 'runtime': self.runtime(instance)}
        self.record(name + '-create', result)
        if not seed:
            self.clones[name]['create_result'] = result
        return result

    def incus(self, *args):
        return self.guest(self.node, 'incus', *args)

    def reachable(self, instance):
        runtime = self.runtime(instance)
        if not runtime or runtime['status'] != 'active' or not runtime['t3_port']:
            return False
        response = subprocess.run(['curl', '--noproxy', '*', '--silent', '--output', '/dev/null', '--write-out', '%{http_code}',
                                   '--max-time', '5', f'http://{self.node_ip}:{int(runtime["t3_port"])}/'], capture_output=True, text=True, timeout=6)
        return response.returncode == 0 and response.stdout == '200'

    def artifacts(self, instance):
        return (f'/var/lib/orbit/hibernation/instances/{instance}', f'/var/lib/orbit/executor/power/{instance}',
                f'/data/caddy/orbit/hibernation/app-instance-{instance}.asleep', f'/var/lib/orbit/instance-repositories/{instance}.git')

    def add_artifacts(self, instance):
        runtime = self.runtime(instance)
        require(runtime and runtime['status'] == 'active' and runtime['clone_source_instance_id'] == self.default and runtime['incus_project'] == POOL,
                'only a disposable active clone of the fixture default may receive artifacts')
        inner, container = f'inner-{instance}', f'instance-{instance}'
        self.incus('storage', 'volume', 'create', POOL, inner, '--project', POOL)
        self.incus('storage', 'volume', 'snapshot', 'create', POOL, inner, 'proof', '--project', POOL)
        self.incus('config', 'device', 'add', container, 'proof-inner', 'disk', 'pool=' + POOL, 'source=' + inner,
                   'path=/mnt/orbit-proof', '--project', POOL)
        self.db(f"UPDATE instance_runtimes SET inner_volume='{inner}' WHERE instance_id={instance} AND clone_source_instance_id={self.default}")
        legacy, signed, marker, mirror = self.artifacts(instance)
        self.guest(self.node, 'install', '-d', legacy, signed, str(Path(marker).parent))
        self.guest(self.node, 'touch', legacy + '/plan', signed + '/plan.json', marker)
        self.guest(self.node, 'sh', '-eu', '-c', 'if test ! -e "$1"; then git init --bare --quiet "$1"; fi', 'sh', mirror)

    def resources(self, instance):
        container, inner = f'instance-{instance}', f'inner-{instance}'
        running = [row['status'] for row in json.loads(self.incus('list', '--project', POOL, '--format=json')) if row['name'] == container]
        volumes = json.loads(self.incus('storage', 'volume', 'list', POOL, '--project', POOL, '--format=json'))
        owned = [f'{row["type"]}:{row["name"]}' for row in volumes if row['name'] in (container, inner)]
        snapshots = json.loads(self.incus('storage', 'volume', 'snapshot', 'list', POOL, inner, '--project', POOL, '--format=json')) if f'custom:{inner}' in owned else []
        acl = json.loads(self.incus('network', 'acl', 'list', '--project=default', '--format=json'))
        rules = self.guest(self.node, 'ufw', 'show', 'added').splitlines()
        paths = self.guest(self.node, 'sh', '-c', 'for path; do if test -e "$path"; then printf "%s\\n" "$path"; fi; done', 'sh', *self.artifacts(instance)).splitlines()
        return {'incus': running, 'volumes': sorted(owned), 'snapshots': [row['name'].removeprefix(inner + '/') for row in snapshots],
                'acl': [row['name'] for row in acl if row['name'] == container],
                'firewall': [f'{container}-t3' for rule in rules if f'comment \'orbit:{container}-' in rule], 'artifact_paths': paths}

    def removal(self, operation):
        return self.db(f'''SELECT r.id AS removal_id,r.status AS removal_status,o.lock_key,
            json_extract(o.input,'$.container_destroy.instance_id') AS instance,
            json_extract(o.input,'$.container_destroy.project') AS project,
            json_extract(o.input,'$.container_destroy.name') AS name,
            json_extract(o.input,'$.container_destroy.pool') AS pool,
            json_extract(o.input,'$.container_destroy.acl') AS acl,
            json_extract(o.input,'$.container_destroy.inner_volume') AS inner_volume,
            json_array_length(o.input,'$.direct_destroy.routes') AS routes,
            json_array_length(o.input,'$.direct_destroy.runtime.processes') AS processes,
            json_array_length(o.input,'$.direct_destroy.runtime.schedules') AS schedules,
            json_array_length(o.input,'$.direct_destroy.teardown[0].steps') AS teardown,
            json_array_length(o.input,'$.direct_destroy.database.connection_ids') AS connections,
            json_array_length(o.input,'$.direct_destroy.database.servers') AS servers,
            json_array_length(o.input,'$.direct_destroy.database.sqlite') AS sqlite
            FROM operations o JOIN instance_removals r ON r.id=json_extract(o.input,'$.removal_id') WHERE o.id={operation}''')[0]

    def discard_receipt(self, instance, key):
        path = f'instances/{instance}'
        self.systemctl(self.node, 'kill', '--kill-whom=main', '--signal=STOP', EXECUTOR_UNIT)
        try:
            admitted = self.admitted('DELETE', path, {'force': True}, key)
            operation = int(admitted['id'])

            def published():
                rows = self.steps(operation)
                return rows if len(rows) == 1 and rows[0]['first_order_expires_ms'] else None

            dispatch = wait_for(published, f'Destroy {operation} did not publish its order', 8)
            queued = wait_for(lambda: self.helper('pending-order', self.node_id, operation, instance),
                              f'Destroy {operation} had no signed order on JetStream', 5)
            self.systemctl('gateway', 'stop', ENGINE_UNIT)
            self.systemctl(self.node, 'kill', '--kill-whom=main', '--signal=CONT', EXECUTOR_UNIT)
            dropped = wait_for(lambda: self.helper('drop-receipt', self.node_id, operation, instance),
                               f'Destroy {operation} did not leave the expected queued receipt', 20)
            held = self.removal(operation)
            runtime = self.runtime(instance)
            resources = self.resources(instance)
            denied = self.api('POST', path + '/wake', {})
            pending_replay = self.admitted('DELETE', path, {'force': True}, key)
            require(not any(resources.values()) and runtime and runtime['status'] == 'removing'
                    and held['lock_key'] == f'instance:{instance}' and held['removal_status'] == 'removing'
                    and denied[2] == 'instance.lifecycle_busy' and pending_replay['id'] == operation
                    and pending_replay['status'] not in TERMINAL and self.steps(operation)[0]['status'] != 'done',
                    f'Destroy {operation} did not keep its Gateway fence after Node deletion without a receipt')
            self.record('discarded-receipt', {'dispatch': dispatch, 'queued_signed_order': queued, 'dropped': dropped, 'removal': held, 'runtime': runtime,
                                             'resources': resources, 'wake_refusal': denied[2],
                                             'http_replay': {'id': pending_replay['id'], 'status': pending_replay['status']}})
            return admitted
        finally:
            self.systemctl(self.node, 'kill', '--kill-whom=main', '--signal=CONT', EXECUTOR_UNIT)
            self.systemctl('gateway', 'start', ENGINE_UNIT)

    def held_volume(self, name, instance, key):
        profile, inner = self.prefix + '-hold', f'inner-{instance}'
        self.incus('profile', 'create', profile, '--project', POOL)
        try:
            self.incus('profile', 'device', 'add', profile, 'hold', 'disk', 'pool=' + POOL, 'source=' + inner,
                       'path=/mnt/orbit-proof-hold', '--project', POOL)
            row = self.admitted('DELETE', f'instances/{instance}', {'force': True}, key + '-held')
            operation = int(row['id'])
            self.terminal(operation, expect='failed')
            summary, removal = self.operation(operation, 'engine'), self.removal(operation)
            resources, runtime = self.resources(instance), self.runtime(instance)
            require(summary['error_code'] == 'instance.removal_incomplete' and summary['steps'][0]['status'] == 'failed'
                    and any(item['record'] == 'done' and item['outcome']['status'] == 'failed' for item in summary['journal'])
                    and removal['removal_status'] == 'failed' and runtime['status'] == 'removing' and not resources['incus']
                    and resources['volumes'] == [f'custom:{inner}'] and resources['snapshots'] == ['proof'],
                    f'Destroy {operation} did not stop after deleting the container with an owned volume still held')
            self.record(name + '-after-delete', {'operation': summary, 'removal': removal, 'resources': resources, 'runtime': runtime})
            return removal
        finally:
            self.incus('profile', 'delete', profile, '--project', POOL)

    def destroy(self, name, via, sibling, fault=None):
        require(via in ('engine', 'swamp') and (fault is None or (via == 'engine' and fault in ('receipt', 'held-volume'))), 'invalid destroy case')
        instance, live_sibling = self.clones[name]['instance'], self.clones[sibling]['instance']
        require(self.reachable(live_sibling) and instance != live_sibling, 'the live sibling is not reachable before removal')
        sibling_resources = self.resources(live_sibling)
        advertised = self.set_capability(via == 'engine')
        self.add_artifacts(instance)
        before = self.resources(instance)
        self.record(name + '-before-destroy', before)
        require(before['incus'] == ['Running'] and f'container:instance-{instance}' in before['volumes']
                and f'custom:inner-{instance}' in before['volumes'] and before['snapshots'] == ['proof'] and before['acl'] and before['firewall']
                and set(before['artifact_paths']) == set(self.artifacts(instance)), f'Instance {instance} resource fixture is incomplete')
        path, key = f'instances/{instance}', name + '-destroy'
        count = self.db(f"SELECT count(*) AS total FROM operations WHERE kind='instance.destroy' AND target_id={instance}")
        refusal = self.api('DELETE', path, {}, name + '-no-force')
        require(refusal[0] == 409 and refusal[2] == 'instance.container_destroy_force_required'
                and count == self.db(f"SELECT count(*) AS total FROM operations WHERE kind='instance.destroy' AND target_id={instance}")
                and self.resources(instance) == before, 'destroy without force did not refuse without effects')
        blocked = self.held_volume(name, instance, key) if fault == 'held-volume' else None
        started = time.monotonic()
        admitted = self.discard_receipt(instance, key) if fault == 'receipt' else self.admitted('DELETE', path, {'force': True}, key)
        operation = int(admitted['id'])
        terminal = self.terminal(operation)
        elapsed = round((time.monotonic() - started) * 1000)
        summary = self.operation(operation, via)
        removal, after = self.removal(operation), self.resources(instance)
        require(all(removal[key] == 0 for key in ('routes', 'processes', 'schedules', 'teardown', 'connections', 'servers', 'sqlite'))
                and (removal['instance'], removal['project'], removal['name'], removal['pool'], removal['acl'], removal['inner_volume'])
                == (instance, POOL, f'instance-{instance}', POOL, f'instance-{instance}', f'inner-{instance}'), 'admitted removal identity or scope changed')
        if blocked:
            require({field: value for field, value in removal.items() if field not in ('lock_key', 'removal_status')}
                    == {field: value for field, value in blocked.items() if field not in ('lock_key', 'removal_status')},
                    f'Destroy {operation} did not reuse the saved removal identity')
        require(terminal['result']['removed'] and removal['removal_status'] == 'completed' and removal['lock_key'] is None
                and not self.runtime(instance) and not any(after.values()) and self.reachable(live_sibling)
                and self.resources(live_sibling) == sibling_resources,
                f'Destroy {operation} did not remove only the target and release its fence')
        replay = self.admitted('DELETE', path, {'force': True}, key)
        require(replay['id'] == operation and replay['status'] == 'succeeded', 'same admission key did not return the terminal destroy after deletion')
        fresh = self.api('DELETE', path, {'force': True}, key + '-new-after-removal')
        require(fresh[0] == 404, 'a fresh admission of the deleted Instance did not refuse')
        if via == 'engine':
            require(len(summary['steps']) == 1 and summary['steps'][0]['name'] == 'remove' and summary['steps'][0]['status'] == 'done',
                    f'Destroy {operation} did not record its terminal engine receipt')
            journal = summary['journal']
            require(sum(item['record'] == 'open' for item in journal) == 1
                    and [item['outcome']['status'] for item in journal if item['record'] == 'done'] == ['done'],
                    f'Destroy {operation} did not execute once in the Node journal')
            if fault == 'receipt':
                require(summary['steps'][0]['sends'] >= 2 and sum(item['record'] == 'seen' for item in journal) >= 2,
                        f'Destroy {operation} did not redeliver a new signed order')
        else:
            native_done = {step['name'] for step in summary['native_steps'] if step['status'] == 'succeeded'}
            require({'Stop the container', 'Delete the container', 'Clean container files'} <= native_done,
                    f'Destroy {operation} did not complete the Swamp container removal phases')
        result = {'instance': instance, 'sibling': live_sibling, 'sibling_http_before_after': [200, 200],
                  'force_refusal': refusal[2], 'advertisement': advertised, 'before': before, 'after': after,
                  'operation': summary, 'removal': removal, 'http_replay': {'id': replay['id'], 'status': replay['status']},
                  'fresh_http_after_removal': {'status': fresh[0], 'code': fresh[2]},
                  'request_through_terminal_ms': elapsed}
        self.clones[name]['destroy_result'] = result
        self.record(name + '-destroy', result)

    def compare(self, engine, swamp):
        comparison = {key: {'create': self.clones[name]['create_result']['request_through_create_ms'],
                            'ready': self.clones[name]['create_result']['request_through_ready_ms'],
                            'destroy': self.clones[name]['destroy_result']['request_through_terminal_ms']}
                      for key, name in (('engine', engine), ('swamp', swamp))}
        comparison['swamp_minus_engine_ms'] = {phase: comparison['swamp'][phase] - comparison['engine'][phase] for phase in comparison['engine']}
        signed = self.clones[engine]['destroy_result']['operation']
        legacy = self.clones[swamp]['destroy_result']['operation']
        step = signed['steps'][0]
        node_start_ms = next(entry['at_ms'] for entry in signed['journal'] if entry['record'] == 'done')
        comparison['signed_destroy_ms'] = {'send_to_node_start': node_start_ms - step['first_sent_ms'],
                                           'send_to_gateway_done': step['done_ms'] - step['first_sent_ms']}
        comparison['swamp_gateway_start_ms'] = legacy['dispatch_timing']
        named = {'Prepare container removal', 'Stop the container', 'Delete the container', 'Read container removal', 'Clean container files'}
        comparison['swamp_destroy_steps_ms'] = {
            row['name']: round((datetime.fromisoformat(row['finished_at']) - datetime.fromisoformat(row['started_at'])).total_seconds() * 1000)
            for row in legacy['native_steps'] if row['name'] in named and row['started_at'] and row['finished_at']}
        self.record('comparison', comparison)

    def conn(self, method, path, body=None, key=None):
        """A Conn call from the owning Node (app-dev), as Conn's Remove makes it; the answer, its HTTP code and its wall time."""
        argv = ['curl', '--noproxy', '*', '--silent', '--show-error', '--max-time', '20', '--cacert', WORK_CA, '--request', method,
                '--write-out', '\n%{http_code} %{time_total}', f'https://{self.gateway_address}/api/v1/conn/{path}']
        if body is not None:
            argv += ['--header', 'Content-Type: application/json', '--data', json.dumps(body)]
        if key is not None:
            argv += ['--header', 'Idempotency-Key: ' + self.prefix + '-' + key]
        payload, status = self.guest(self.node, *argv, timeout=25).rsplit('\n', 1)
        code, seconds = status.split()
        answer = json.loads(payload)
        return int(code), answer.get('data'), answer.get('error') or {}, round(float(seconds) * 1000)

    def workspace(self, instance, script):
        """Changes the clone's workspace as its user would: Git as `agent` inside the container."""
        self.incus('exec', f'instance-{instance}', '--project', POOL, '--', 'runuser', '-u', 'agent', '--', 'sh', '-eu', '-c',
                   'cd /srv/workspace; ' + script)

    def ssh_logins(self, since):
        lines = self.guest(self.node, 'journalctl', '-u', 'ssh', '-o', 'cat', '--no-pager', f'--since=@{since}')
        return sum(1 for line in lines.splitlines() if line.startswith(f'Accepted publickey for orbit from {self.gateway_address} '))

    def work_read(self, instance, via):
        """One GET .../work on the path `via` names; the answer, its wall time and the SSH logins it caused on the Node."""
        self.set_capability(via == 'engine')
        since = int(time.time()) - 1
        time.sleep(1)
        code, answer, error, wall = self.conn('GET', f'instances/{instance}/work')
        require(code == 200 and answer['instance_id'] == instance, f'work read of {instance} via {via}: HTTP {code} {error.get("code")}')
        time.sleep(1)
        return answer, wall, self.ssh_logins(since)

    def work(self, name, quiet):
        """The workspace work read (docs/cli/instance/container/work-read-engine.mmd) on running, dirty, stopped and asleep clones
        through Conn's own calls: each state read by the signed executor read and by today's SSH reader, which must agree."""
        instance, clean = self.clones[name]['instance'], self.clones[quiet]['instance']
        states, reads = [], {'engine': [], 'ssh': []}

        def both(label, expect, reason=None):
            row = {'state': label}
            for via in ('engine', 'ssh'):
                answer, wall, logins = self.work_read(instance, via)
                row[via] = {'answer': answer, 'wall_ms': wall, 'ssh_logins': logins}
            engine, ssh = ({key: value for key, value in row[via]['answer'].items() if key not in ('read_at', 'duration_ms')} for via in ('engine', 'ssh'))
            require(engine == ssh, f'{label}: the executor and SSH readers disagree')
            require(engine['state'] == expect and engine['reason'] == reason, f'{label}: read {engine["state"]}/{engine["reason"]}, expected {expect}/{reason}')
            require(row['engine']['ssh_logins'] == 0 and row['ssh']['ssh_logins'] >= 1, f'{label}: the read did not take the path it names')
            states.append(row)
            return engine

        both('clean', 'clean')
        for via in ('engine', 'ssh'):
            self.set_capability(via == 'engine')
            for _ in range(WORK_SAMPLES):
                code, answer, _error, wall = self.conn('GET', f'instances/{instance}/work')
                require(code == 200 and answer['state'] == 'clean', f'latency sample via {via} was not clean')
                reads[via].append({'wall_ms': wall, 'duration_ms': answer['duration_ms']})
        edits = {
            'tracked edit': ('f=$(git ls-files | head -n 1); printf "proof\\n" >> "$f"', 'git checkout -- "$(git ls-files | head -n 1)"'),
            'untracked file': ('printf "proof\\n" > orbit-proof-untracked.txt', 'rm orbit-proof-untracked.txt'),
            'stash': ('f=$(git ls-files | head -n 1); printf "proof\\n" >> "$f"; git stash push --quiet', 'git stash drop --quiet'),
            'new branch': (f'git branch orbit-proof; git -c user.name=proof -c user.email=proof@invalid commit-tree -m proof "$(git rev-parse HEAD^{{tree}})" -p HEAD'
                           ' | xargs git update-ref refs/heads/orbit-proof', 'git branch -D --quiet orbit-proof'),
        }
        for label, (change, undo) in edits.items():
            self.workspace(instance, change)
            both(label, 'dirty')
            self.workspace(instance, undo)
            both(label + ' undone', 'clean')
        self.workspace(instance, edits['untracked file'][0])
        dirty = both('dirty, running', 'dirty')
        self.set_capability(True)
        held = self.db(f"SELECT count(*) AS total FROM operations WHERE kind='instance.destroy' AND target_id={instance}")
        refusal = self.conn('POST', f'instances/{instance}/destroy', {'acknowledge_unsaved': False}, name + '-unacknowledged')
        require(refusal[0] == 409 and refusal[2].get('code') == 'instance.unsaved_work' and refusal[2]['details']['state'] == 'dirty'
                and held == self.db(f"SELECT count(*) AS total FROM operations WHERE kind='instance.destroy' AND target_id={instance}")
                and self.runtime(instance)['status'] == 'active', 'the unacknowledged destroy of a dirty clone did not refuse without effect')

        # A read that the executor cannot answer is `unknown` `timeout`, never a guess: the reader is the executor, not SSH.
        self.systemctl(self.node, 'kill', '--kill-whom=main', '--signal=STOP', EXECUTOR_UNIT)
        try:
            since = int(time.time()) - 1
            time.sleep(1)
            code, stalled, error, wall = self.conn('GET', f'instances/{instance}/work')
            time.sleep(1)
            stalled_logins = self.ssh_logins(since)
        finally:
            self.systemctl(self.node, 'kill', '--kill-whom=main', '--signal=CONT', EXECUTOR_UNIT)
        require(code == 200 and (stalled['state'], stalled['reason']) == ('unknown', 'timeout') and stalled_logins == 0 and wall < 6000,
                'a stalled executor did not give unknown/timeout within the read budget')

        self.incus('stop', f'instance-{instance}', '--project', POOL)
        both('stopped', 'unknown', 'stopped')
        self.incus('start', f'instance-{instance}', '--project', POOL)
        wait_for(lambda: self.reachable(instance), f'Instance {instance} did not come back after its start', 90, 1)
        both('started again', 'dirty')
        self.set_capability(True)
        slept = self.admitted('POST', f'instances/{instance}/sleep', {}, name + '-sleep')
        sleep_row = self.terminal(int(slept['id']))
        asleep = both('asleep', 'unknown', 'asleep')
        container = [row['status'] for row in json.loads(self.incus('list', '--project', POOL, '--format=json')) if row['name'] == f'instance-{instance}']
        require(container == ['Stopped'], 'reading an asleep clone woke it')

        self.set_capability(True)
        quiet_read = self.conn('GET', f'instances/{clean}/work')
        require(quiet_read[1]['state'] == 'clean', 'the quiet clone is not clean')
        removals = {}
        for label, target, body in (('clean, unacknowledged', clean, {'acknowledge_unsaved': False}),
                                    ('asleep dirty, acknowledged', instance, {'acknowledge_unsaved': True})):
            code, row, error, _wall = self.conn('POST', f'instances/{target}/destroy', body, f'{target}-destroy')
            require(code == 202, f'{label} destroy: HTTP {code} {error.get("code")}')
            removals[label] = self.terminal(int(row['id']))
            require(self.runtime(target) is None, f'{label} destroy left the Instance')

        def p50(values):
            ordered = sorted(values)
            return ordered[len(ordered) // 2] if len(ordered) % 2 else (ordered[len(ordered) // 2 - 1] + ordered[len(ordered) // 2]) / 2

        latency = {via: {'samples': len(rows), 'p50_wall_ms': p50([row['wall_ms'] for row in rows]),
                         'p50_read_ms': p50([row['duration_ms'] for row in rows]), 'reads': rows} for via, rows in reads.items()}
        self.record('work-read', {'instance': instance, 'quiet': clean, 'states': states, 'latency': latency,
                                  'unacknowledged_dirty_refusal': {'code': refusal[0], 'error': refusal[2]},
                                  'dirty_details': dirty, 'stalled_executor': {'answer': stalled, 'wall_ms': wall, 'ssh_logins': stalled_logins},
                                  'sleep': {'operation': sleep_row['id'], 'status': sleep_row['status'], 'answer': asleep, 'container': container},
                                  'removals': {label: {'operation': row['id'], 'status': row['status']} for label, row in removals.items()}})

    def cleanup(self):
        if not self.installed or self.closed:
            return
        if self.project is not None:
            self.set_capability(True)
            for name, clone in self.clones.items():
                instance = clone['instance']
                if self.runtime(instance) is not None:
                    admitted = self.admitted('DELETE', f'instances/{instance}', {'force': True}, name + '-cleanup')
                    self.terminal(int(admitted['id']))
                require(not any(self.resources(instance).values()), f'fixture clone {instance} still owns Node resources')
            if self.default is not None and self.runtime(self.default) is not None:
                self.append_env(f'ORBIT_INSTANCES_DEFAULT_DESTROY_PROJECTS=[{self.project}]\n')
                self.systemctl('gateway', 'restart', *APP_SERVICES)
                admitted = self.admitted('DELETE', f'instances/{self.default}', {'force': True}, 'seed-cleanup')
                self.terminal(int(admitted['id']))
            code, _data, error = self.api('DELETE', f'projects/{self.project}')
            require(code in (200, 204), f'disposable project cleanup: HTTP {code} {error}')
        self.uninstall()
        self.closed = True
        self.record('cleanup', {'project_deleted': self.project, 'seed_deleted': self.default,
                                'clones_absent': [clone['instance'] for clone in self.clones.values()], 'temporary_services_removed': True})

    def uninstall(self):
        self.guest(self.node, 'sh', '-c', '''
            systemctl stop orbit-executor.service 2>/dev/null || :
            nft delete table inet orbit_bus_guard 2>/dev/null || :
            rm -rf /etc/orbit/executor /var/lib/orbit/executor /usr/local/libexec/orbit-executor /etc/systemd/system/orbit-executor.service
            userdel orbit-executor-relay 2>/dev/null || :
            systemctl daemon-reload
        ''')
        if self.firewall_added:
            self.guest('gateway', 'ufw', '--force', 'delete', 'allow', 'in', 'on', 'orbit', 'from', self.node_ip,
                       'to', self.gateway_address, 'port', 4222, 'proto', 'tcp')
        self.guest('gateway', 'sh', '-eu', '-c', '''
            systemctl stop orbit-gateway-engine.service nats-server.service 2>/dev/null || :
            cat "$1/gateway-env" > "$2/.env"
            rm -rf "$1" /etc/nats/nats.conf /var/lib/nats /usr/local/bin/nats-server /etc/systemd/system/nats-server.service /etc/systemd/system/orbit-gateway-engine.service
            userdel nats 2>/dev/null || :
            systemctl daemon-reload
        ''', 'sh', GUEST_STATE, self.gateway_app)
        self.systemctl('gateway', 'restart', *APP_SERVICES)


if __name__ == '__main__':
    print(json.dumps(stage_executor(log=True)))
