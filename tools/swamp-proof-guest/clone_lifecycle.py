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
import threading
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


def parsed(source, text):
    # A reply that is not JSON names its source and size only: the text itself may hold what a scan found, so it never reaches a receipt.
    try:
        return json.loads(text)
    except json.JSONDecodeError:
        raise ProofInfrastructure(f'{source}: not JSON ({len(text)} bytes)') from None


def wait_for(read, message, seconds, interval=0.25):
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        value = read()
        if value:
            return value
        time.sleep(interval)
    raise ProofFailure(message)


class CloneLane:
    KINDS = ('instance.create', 'instance.destroy')

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
            # Inside a container, the command run there and its first word; never an argument that could carry content.
            inner = [str(word) for word in argv[argv.index('--') + 1:] if str(word) not in ('runuser', '-u', 'agent', '--')][:2] if '--' in argv else []
            raise ProofInfrastructure(f'{node}: {argv[0]} {argv[1] if len(argv) > 1 else ""} {" ".join(inner)} failed ({result.returncode})'.replace('  ', ' '))
        return result.stdout.strip()

    def push(self, node, source, path):
        guest = self.run.fleet.nodes[node]['instance']
        result = subprocess.run(['incus', 'file', 'push', '--uid=0', '--gid=0', '--mode=0755', str(source), guest + path],
                                capture_output=True, timeout=45)
        require(result.returncode == 0, f'{node}: could not install {Path(path).name}')

    def db(self, sql):
        return parsed('sqlite3', self.guest('gateway', 'runuser', '-u', 'orbit', '--', 'sqlite3', '-cmd', '.timeout 3000', '-json', GATEWAY_DB, sql) or '[]')

    def helper(self, *args):
        return parsed('helper ' + args[0], self.guest('gateway', 'runuser', '-u', 'orbit', '--', 'php', self.guest_helper, *args))

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
            printf 'ORBIT_ENGINE_KINDS=%s\n' "$2" >> .env
        ''', 'sh', self.gateway_app, ','.join(self.KINDS))
        self.gateway_php('config:clear')
        settings = self.helper('configuration')
        self.routes = settings['routes']
        require(set(self.routes) == set(self.KINDS), 'incorrect proof engine routes')
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
        answer = parsed(f'{method} {path} HTTP {code}', payload)
        error = answer.get('error') or {}
        fields = sorted((error.get('details') or {}).items()) if error.get('code') == 'validation.failed' else None
        return int(code), answer.get('data'), f'{error["code"]} {fields}' if fields else error.get('code')

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
        return parsed('executor journal', self.guest(self.node, 'python3', '-c', code, operation))

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

    def create(self, name, via, seed=False, attempt=''):
        require(re.fullmatch('[a-z][a-z0-9-]{0,20}', name) and via in ('engine', 'swamp'), 'invalid clone case')
        advertised = self.set_capability(via == 'engine' or seed)
        started = time.monotonic()
        admitted = self.admitted('POST', 'instances', {'project_id': self.project, 'node_id': self.node_id, 'name': name}, name + '-create' + attempt)
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


SYNC_STEPS = ('fetch', 'sync', 'clear')
SYNC_FIELDS = {'default_branch', 'default_commit', 'branch', 'branch_commit', 'behind_by', 'start', 'work'}
CREDENTIAL = ('x-access-token', 'ghs_', 'github_pat_')
# The scan builds its patterns from pieces, so neither its own argv nor its script text holds one and matches itself. It prints only
# where a credential form is and how often, never a value: a process (environment or argv), a file anywhere in the copy's checkout and
# its home and temporary folders, or Git configuration.
CREDENTIAL_SCAN = '''
a="x-access-"'token'; b="ghs"'_'; c="github_"'pat_'
for path in /proc/[0-9]*/environ /proc/[0-9]*/cmdline; do
  n=$(tr '\\0' '\\n' < "$path" 2>/dev/null | grep -a -c -e "$a" -e "$b" -e "$c" || :)
  if [ "${n:-0}" -gt 0 ]; then echo "$path $n"; fi
done
grep -r -I -c -s -e "$a" -e "$b" -e "$c" /srv/workspace /home /root /etc /tmp /var/tmp /run 2>/dev/null | grep -v ':0$' || :
n=$(git -C /srv/workspace config --list 2>/dev/null | grep -c -e "$a" -e "$b" -e "$c" -e extraheader -e 'credential\\.' || :)
if [ "${n:-0}" -gt 0 ]; then echo "git-config $n"; fi
'''
# A credential form the scan must find in a worktree file: built from pieces, written for the self-check and removed at once.
SENTINEL = 'x-access-' + 'token:proof-sentinel@example.invalid'


class SyncLane(CloneLane):
    """Task-copy syncs of one ready clone through the signed executor and through Swamp, read back inside the copy."""
    KINDS = ('instance.create', 'instance.destroy', 'tasks.sync')

    def counts(self):
        containers = [row['name'] for row in parsed('incus list', self.incus('list', '--project', POOL, '--format=json'))]
        volumes = [row['name'] for row in parsed('incus volumes', self.incus('storage', 'volume', 'list', POOL, '--project', POOL, '--format=json'))]
        return {'containers': sorted(containers), 'volumes': sorted(volumes), 'instances': self.db('SELECT count(*) AS total FROM instances')[0]['total']}

    def create(self, name, via, seed=False, attempt=''):
        try:
            return super().create(name, via, seed, attempt)
        except ProofFailure as error:
            # The Swamp container phase can lose its mktemp file in the new container's /tmp while it boots
            # (instance-container-phase.yml, "Move the app account to www-data"). Not this group's code: the fixture's default retries once.
            failed = self.db(f"SELECT id, json_extract(state,'$.native_failure.detail') AS detail FROM operations WHERE kind='instance.create' "
                             f"AND status='failed' ORDER BY id DESC LIMIT 1")
            if not seed or attempt or not failed or "cannot stat '/tmp/" not in (failed[0]['detail'] or ''):
                raise
            self.record('default-create-boot-race', {'operation': failed[0]['id'], 'error': str(error)})
            wait_for(lambda: not self.db(f"SELECT id FROM instances WHERE project_id={self.project}"), 'the failed default was not removed', 180, 2)
            return super().create(name, via, seed, '-retry')

    def setup(self, repository, branch):
        self.start_counts = None
        self.branch = branch
        self.groups = {}
        self.syncs = {}
        self.extensions = None
        self.start_counts = self.counts()
        self.record('start-counts', self.start_counts)
        super().setup(repository, branch)
        # task:start and task:sync need the tasks extension. Fixture only: the nested fleet has no AI router, so the helper sets the stored
        # state directly and cleanup restores exactly what it read; Conn is read and left alone.
        self.extensions = {name: self.helper('extension', name, 'read')['enabled'] for name in ('tasks', 'conn')}
        if not self.extensions['tasks']:
            require(self.helper('extension', 'tasks', 'on')['enabled'], 'the tasks extension did not turn on')
        self.record('extensions-before', self.extensions)

    def cleanup(self):
        # Groups first: a discarding cancel ends each group and removes its copy, so the Project can go. The extension state goes back
        # while the guest helper still runs, then the base cleanup, then the fleet must be back at the counts the run started from.
        try:
            if self.installed and not self.closed:
                self.set_capability(True)
                for name, group in list(self.groups.items()):
                    # The copy is read from the group, not from what start returned: a lost start answer still leaves a copy to remove.
                    owned = lambda: [row['id'] for row in self.db(f'SELECT id FROM instances WHERE task_workspace_owner_id={group}')]
                    copies = owned()
                    group_row = self.db(f'SELECT status FROM tasks WHERE id={group}')
                    if group_row and group_row[0]['status'] not in ('cancelled', 'completed'):
                        admitted = self.admitted('POST', f'tasks/{group}/cancel', {'discard': True}, name + '-cancel')
                        self.terminal(int(admitted['id']), seconds=400)
                    wait_for(lambda: not owned(), f'the copy of group {group} outlived its cancelled group', 300, 2)
                    for instance in copies:
                        wait_for(lambda: self.runtime(instance) is None, f'copy {instance} outlived its cancelled group', 300, 2)
                        require(not any(self.resources(instance).values()), f'copy {instance} left Node resources after its cancelled group')
                    self.clones.pop(name, None)
                    del self.groups[name]
        finally:
            try:
                if self.extensions is not None:
                    after = {name: self.helper('extension', name, 'off' if name == 'tasks' and not self.extensions[name] else 'read')['enabled']
                             for name in self.extensions}
                    require(after == self.extensions, 'the extension state was not restored')
                    self.record('extensions-after', after)
                    self.extensions = None
            finally:
                super().cleanup()
        if self.start_counts is not None:
            # Every destroy ends before the next run: the Node and Gateway are back at the counts this run started from.
            back = wait_for(lambda: (lambda now: now if now == self.start_counts else None)(self.counts()),
                            'the fleet did not return to its start counts', 300, 3)
            self.record('end-counts', back)
            self.start_counts = None

    def copy(self, name, sibling):
        self.create(sibling, 'engine')
        self.set_capability(True)
        code, group, error = self.api('POST', 'tasks', {'project_id': self.project, 'title': 'Sync proof ' + name, 'brief': 'Task-copy sync proof.',
                                                        'status': 'todo', 'orchestration': 'external',
                                                        'deliverables': [{'id': 'sync', 'type': 'file', 'description': 'Sync proof.', 'path': 'proof-sync.txt', 'change': 'created'}]})
        require(code == 201, f'task group admission: HTTP {code} {error}')
        group = int(group['id'])
        self.groups[name] = group
        started = time.monotonic()
        operation = int(self.admitted('POST', f'tasks/{group}/start', {}, name + '-start')['id'])
        self.terminal(operation, seconds=600)
        found = self.db(f"""SELECT i.id, i.branch, i.starting_commit, i.task_workspace_owner_id, r.clone_source_instance_id FROM tasks t
            JOIN instances i ON i.id=t.taskable_id JOIN instance_runtimes r ON r.instance_id=i.id WHERE t.id={group}""")
        require(found and found[0]['task_workspace_owner_id'] == group and found[0]['branch'] == f'task-{group}'
                and found[0]['clone_source_instance_id'] == self.default, f'task:start {operation} did not leave the group its own copy')
        instance = int(found[0]['id'])
        self.clones[name] = {'instance': instance, 'create': operation}
        wait_for(lambda: self.api('GET', f'instances/{instance}')[1]['status'] == 'active', f'copy {instance} is not active', 120, 2)
        start_ms = round((time.monotonic() - started) * 1000)
        local = f'task-{group}'
        require(self.work(instance, 'symbolic-ref', 'HEAD') == 'refs/heads/' + local, f'copy {instance} is not on {local}')
        self.tip = self.work(instance, 'rev-parse', 'HEAD')
        # The copy goes back three commits and loses every ref and reflog entry that still reaches its old head, so the head the mirror
        # holds is missing here and each sync imports it (`bundled`). The work below is made on the older commit.
        self.work(instance, 'reset', '--quiet', '--hard', 'HEAD~3')
        self.work(instance, 'update-ref', f'refs/remotes/origin/{self.branch}', 'HEAD')
        for line in self.work(instance, 'for-each-ref', '--contains', self.tip, '--format=%(refname)', 'refs/heads').splitlines():
            self.work(instance, 'update-ref', line, 'HEAD')
        self.work(instance, 'branch', 'proof-local', 'HEAD~1')
        tracked = self.work(instance, 'ls-files').splitlines()[0]
        self.workfile(instance, 'proof-stashed.txt', 'stashed\n')
        self.work(instance, '-c', 'user.name=proof', '-c', 'user.email=proof@orbit.invalid', 'stash', 'push', '--quiet', '--include-untracked', '-m', 'proof')
        self.workfile(instance, 'proof-staged.txt', 'staged\n')
        self.work(instance, 'add', 'proof-staged.txt')
        self.workfile(instance, tracked, 'unstaged\n', append=True)
        self.workfile(instance, 'proof-untracked.txt', 'untracked\n')
        self.strip(instance)
        state = self.state(instance)
        require(state['stash'] and state['untracked'] and state['staged'] != state['unstaged'], 'the copy work fixture is incomplete')
        self.record(name + '-copy', {'instance': instance, 'group': group, 'start_operation': self.operation_row(operation),
                                     'request_through_start_ms': start_ms, 'copy': found[0], 'state': state,
                                     'sibling': self.clones[sibling]['instance']})

    def operation_row(self, operation):
        return self.db(f"SELECT id,kind,status,error_code,json_extract(state,'$.swamp_run_id') AS run FROM operations WHERE id={operation}")[0]

    def container(self, instance, *argv, user='agent', stdin=None):
        prefix = ['runuser', '-u', user, '--'] if user else []
        return self.guest(self.node, 'incus', 'exec', f'instance-{instance}', '--project', POOL, '--cwd', '/srv/workspace', '--',
                          *prefix, *argv, stdin=stdin, timeout=60)

    def work(self, instance, *args):
        return self.container(instance, 'git', *args)

    def workfile(self, instance, path, content, append=False):
        self.container(instance, 'sh', '-eu', '-c', 'cat >> "$1"' if append else 'cat > "$1"', 'sh', path, stdin=content)

    def strip(self, instance):
        # Puts origin/<default> back on HEAD and prunes every object only the mirror head reached; the stash reflog is kept.
        ref = f'refs/remotes/origin/{self.branch}'
        self.work(instance, 'update-ref', ref, self.work(instance, 'rev-parse', 'HEAD'))
        # A pruned head has no ref left that reaches it, and `--contains` refuses a missing object.
        reaching = self.work(instance, 'for-each-ref', '--contains', self.tip, '--format=%(refname)') if self.holds(instance, self.tip) else ''
        for line in reaching.splitlines():
            require(not line.startswith(('refs/heads/', 'refs/stash')), f'copy {instance}: {line} still reaches the mirror head')
            self.work(instance, 'update-ref', '-d', line)
        logs = [line for line in self.work(instance, 'for-each-ref', '--format=%(refname)').splitlines() if line != 'refs/stash']
        self.work(instance, 'reflog', 'expire', '--expire=now', '--expire-unreachable=now', 'HEAD', *logs)
        self.work(instance, 'gc', '--quiet', '--prune=now')
        require(not self.holds(instance, self.tip), f'copy {instance} still holds the mirror head after the strip')

    def holds(self, instance, commit):
        return self.container(instance, 'sh', '-c', 'git cat-file -e "$1^{commit}" 2>/dev/null && echo yes || echo no', 'sh', commit) == 'yes'

    def imported_owners(self, instance, since):
        # Owners of the object files written since the sync started: the import must leave them agent's.
        return sorted(set(self.container(instance, 'find', '/srv/workspace/.git/objects', '-type', 'f', '-newermt', f'@{since}',
                                         '-printf', '%u\\n', user=None).split()))

    def state(self, instance):
        def hashed(*args):
            return hashlib.sha256(self.work(instance, *args).encode()).hexdigest()

        return {'head': self.work(instance, 'symbolic-ref', 'HEAD'), 'head_commit': self.work(instance, 'rev-parse', 'HEAD'),
                'refs': self.work(instance, 'for-each-ref', '--format=%(refname) %(objectname)', 'refs/heads', 'refs/stash').splitlines(),
                'stash': self.work(instance, 'stash', 'list', '--format=%H %gs').splitlines(),
                'staged': hashed('diff', '--cached', '--binary'), 'unstaged': hashed('diff', '--binary'),
                'untracked': self.container(instance, 'sh', '-c', 'git ls-files -z -o --exclude-standard | xargs -0 -r sha256sum').splitlines(),
                'origin': self.work(instance, 'rev-parse', f'refs/remotes/origin/{self.branch}'), 'holds_tip': self.holds(instance, self.tip),
                'root_owned': self.container(instance, 'find', '/srv/workspace', '-xdev', '-user', 'root', '-print', '-quit', user=None),
                'owners': self.container(instance, 'stat', '-c', '%U:%G', '/srv/workspace/.git', '/srv/workspace/.git/objects', user=None).splitlines()}

    def credential_hits(self, instance, operation=None):
        # Locations and counts only. The Operation record is searched here, in Python, and reports its row and count.
        found = self.container(instance, 'sh', '-c', CREDENTIAL_SCAN, user=None).splitlines()
        if operation is not None:
            row = json.dumps(self.db(f'SELECT input,state,result FROM operations WHERE id={operation}'))
            count = sum(row.count(token) for token in CREDENTIAL)
            found += [f'operation {operation} {count}'] if count else []
        return found

    def scan_check(self, instance):
        # The scan must see a credential form in an ordinary worktree file before its silence counts.
        self.workfile(instance, 'proof-sentinel.txt', SENTINEL + '\\n')
        try:
            seen = self.credential_hits(instance)
        finally:
            self.container(instance, 'rm', '-f', 'proof-sentinel.txt')
        require(seen == ['/srv/workspace/proof-sentinel.txt:1'], f'the credential scan did not find exactly its worktree sentinel ({len(seen)} places)')
        require(not self.credential_hits(instance), 'the credential scan found a credential form before any sync')
        return seen

    def watched(self, instance, act):
        # Scans the copy again and again while `act` runs, then once more after it.
        hits, stop = [], threading.Event()

        def watch():
            while not stop.is_set():
                hits.extend(self.credential_hits(instance))

        watcher = threading.Thread(target=watch, daemon=True)
        watcher.start()
        try:
            value = act()
        finally:
            stop.set()
            watcher.join(60)
        return value, sorted(set(hits))

    def bundled(self, operation):
        output = self.db(f"SELECT output FROM operation_engine_steps WHERE operation_id={operation} AND name='sync'")[0]['output']
        receipt = parsed('sync receipt', output) if isinstance(output, str) else output
        receipt = parsed('sync receipt stdout', receipt['stdout']) if isinstance(receipt, dict) and 'stdout' in receipt else receipt
        return receipt.get('bundled')

    def credential_digest(self, operation):
        # What the run minted, as its digest: an anonymous read mints the empty line, so no credential exists to leak.
        found = self.db(f"SELECT json_extract(state,'$.engine_credential.sha256') AS sha, json_extract(state,'$.engine_credential.source_access') AS access "
                        f"FROM operations WHERE id={operation}")[0]
        return {**found, 'empty': found['sha'] == hashlib.sha256(b'').hexdigest()}

    def mirror_head(self, instance):
        return self.guest(self.node, 'git', '--git-dir', f'/var/lib/orbit/instance-repositories/{instance}.git', 'rev-parse', f'refs/heads/{self.branch}')

    def scratch(self, instance):
        # The exact per-copy paths: the Node's base folder and the mirror's seeding repository, the mirror pin, and the copy's bundle folder.
        mirror = f'/var/lib/orbit/instance-repositories/{instance}.git'
        node = self.guest(self.node, 'sh', '-c', 'for path in "$1" "$2"; do test ! -e "$path" || echo "$path"; done; '
                          'git --git-dir "$3" for-each-ref --format="%(refname)" "$4"', 'sh',
                          f'/var/tmp/orbit-task-base-{instance}', mirror + '.seeding', mirror, f'refs/orbit/task-base-{instance}').splitlines()
        guest = self.container(instance, 'sh', '-c', 'test ! -e "$1" || echo "$1"', 'sh', f'/tmp/orbit-task-base-{instance}', user=None).splitlines()
        return node + guest

    def drop_receipt(self, group, instance, key):
        target, path = f'{self.node_id}:fetch-{instance}', f'tasks/{group}/sync'
        self.systemctl(self.node, 'kill', '--kill-whom=main', '--signal=STOP', EXECUTOR_UNIT)
        try:
            operation = int(self.admitted('POST', path, {}, key)['id'])

            def published():
                rows = [row for row in self.steps(operation) if row['name'] == 'fetch']
                return rows if rows and rows[0]['first_order_expires_ms'] else None

            dispatch = wait_for(published, f'Sync {operation} did not publish its fetch', 15)
            queued = wait_for(lambda: self.helper('pending-order', self.node_id, operation, target, 'fetch', 'instance.fetch_branch'),
                              f'Sync {operation} had no signed fetch order on JetStream', 5)
            self.systemctl('gateway', 'stop', ENGINE_UNIT)
            self.systemctl(self.node, 'kill', '--kill-whom=main', '--signal=CONT', EXECUTOR_UNIT)
            dropped = wait_for(lambda: self.helper('drop-receipt', self.node_id, operation, target, 'fetch', 'instance.fetch_branch'),
                               f'Sync {operation} did not leave the expected fetch receipt', 90)
            held = self.db(f'SELECT status,lock_key FROM operations WHERE id={operation}')[0]
            replay = self.admitted('POST', path, {}, key)
            beside = self.api('POST', path, {}, key + '-beside')
            require(held['status'] not in TERMINAL and held['lock_key'] == f'instance:{instance}' and replay['id'] == operation
                    and beside[:3:2] == (409, 'operation.target_busy')
                    and [row['status'] for row in self.steps(operation) if row['name'] == 'fetch'] != ['done'],
                    f'Sync {operation} did not stay fenced without its fetch receipt')
            self.record('dropped-receipt', {'dispatch': dispatch, 'queued_signed_order': queued, 'dropped': dropped, 'held': held,
                                            'http_replay': {'id': replay['id'], 'status': replay['status']},
                                            'beside': {'status': beside[0], 'code': beside[2]}})
            return operation
        finally:
            self.systemctl(self.node, 'kill', '--kill-whom=main', '--signal=CONT', EXECUTOR_UNIT)
            self.systemctl('gateway', 'start', ENGINE_UNIT)

    def held_ref(self, name, instance, group, key, before):
        lock = f'/srv/workspace/.git/refs/remotes/origin/{self.branch}.lock'
        self.workfile(instance, lock, '')
        try:
            failed, hits = self.watched(instance, lambda: self.held_sync(group, key))
        finally:
            self.container(instance, 'rm', '-f', lock)
        summary, after = self.operation(failed, 'engine'), self.state(instance)
        statuses = {step['name']: step['status'] for step in summary['steps']}
        hits += self.credential_hits(instance, failed)
        left = self.scratch(instance)
        require(statuses.get('fetch') == 'done' and statuses.get('sync') == 'failed' and statuses.get('clear_after_failure') == 'done'
                and {field: value for field, value in after.items() if field != 'holds_tip'} == {field: value for field, value in before.items() if field != 'holds_tip'}
                and not left and not hits, f'Sync {failed} did not fail at the import and leave the copy unchanged '
                f'({len(left)} scratch paths, {len(hits)} credential places)')
        self.record(name + '-failed-import', {'operation': summary, 'state': after, 'scratch': left, 'credential_hits': hits})

    def held_sync(self, group, key):
        failed = int(self.admitted('POST', f'tasks/{group}/sync', {}, key + '-held')['id'])
        self.terminal(failed, expect='failed', seconds=400)
        return failed

    def sync(self, name, via, sibling, fault=None, key=None):
        require(via in ('engine', 'swamp') and (fault is None or (via == 'engine' and fault in ('receipt', 'held-ref'))), 'invalid sync case')
        key = key or name
        instance, group, other = self.clones[name]['instance'], self.groups[name], self.clones[sibling]['instance']
        advertised = self.set_capability(via == 'engine')
        self.strip(instance)
        if not self.syncs and fault is None:
            self.record('credential-scan-check', {'found_sentinel': self.scan_check(instance)})
        before, other_before = self.state(instance), (self.state(other), self.resources(other))
        if fault == 'held-ref':
            # The failed import may already have brought the objects in; the retry starts from the stripped copy again.
            self.held_ref(name, instance, group, key, before)
            self.strip(instance)
            require(self.state(instance) == before, 'the copy changed between the failed import and its retry')
        since = int(time.time()) - 1
        started = time.monotonic()

        def act():
            operation = self.drop_receipt(group, instance, key) if fault == 'receipt' else int(self.admitted('POST', f'tasks/{group}/sync', {}, key)['id'])
            return operation, self.terminal(operation, seconds=400), round((time.monotonic() - started) * 1000)

        (operation, row, elapsed), during = self.watched(instance, act)
        summary, after = self.operation(operation, via), self.state(instance)
        result = row.get('result') or {}
        behind = int(self.work(instance, 'rev-list', '--count', f'{after["head"]}..refs/remotes/origin/{self.branch}'))
        owners = self.imported_owners(instance, since)
        bundled = self.bundled(operation) if via == 'engine' else None
        require(not before['holds_tip'] and after['holds_tip'] and owners == ['agent'] and bundled in (True, None),
                f'Sync {operation} did not import the missing head as agent ({owners}, bundled {bundled})')
        require(before['origin'] != after['origin'] == self.mirror_head(instance) == self.tip == result.get('default_commit')
                and result.get('behind_by') == behind and result.get('branch_commit') == after['head_commit'] and SYNC_FIELDS <= set(result),
                f'Sync {operation} did not advance origin/{self.branch} to the mirror head with its result')
        require({field: value for field, value in after.items() if field not in ('origin', 'holds_tip')}
                == {field: value for field, value in before.items() if field not in ('origin', 'holds_tip')}
                and not after['root_owned'], f'Sync {operation} changed HEAD, a branch, the index, work, a stash or ownership')
        require(self.reachable(other) and (self.state(other), self.resources(other)) == other_before, f'Sync {operation} changed the sibling')
        hits = sorted(set(during + self.credential_hits(instance, operation)))
        left = self.scratch(instance)
        require(not hits and not left, f'Sync {operation} left {len(hits)} credential places and {len(left)} scratch paths')
        replay = self.admitted('POST', f'tasks/{group}/sync', {}, key)
        require(replay['id'] == operation and replay['status'] == 'succeeded' and self.state(instance) == after,
                'the same admission key did not return the terminal sync unchanged')
        if via == 'engine':
            done = {step['name']: step for step in summary['steps'] if step['status'] == 'done'}
            journal = summary['journal']
            require(set(SYNC_STEPS) <= set(done) and all(sum(item['record'] == 'open' and item['operation'] == f'{operation}:{step}' for item in journal) == 1
                                                         for step in SYNC_STEPS), f'Sync {operation} did not execute each step once on the Node')
            if fault == 'receipt':
                require(done['fetch']['sends'] >= 2 and sum(item['record'] == 'seen' and item['operation'] == f'{operation}:fetch' for item in journal) >= 2,
                        f'Sync {operation} did not resend its fetch')
        outcome = {'instance': instance, 'group': group, 'sibling': other, 'advertisement': advertised, 'before': before, 'after': after,
                   'operation': summary, 'result': result, 'credential_hits': hits, 'bundled': bundled, 'imported_owners': owners,
                   'credential': self.credential_digest(operation), 'http_replay': {'id': replay['id'], 'status': replay['status']},
                   'request_through_terminal_ms': elapsed}
        self.syncs[key] = outcome
        self.record(key + '-sync', outcome)

    def sync_compare(self, engine, swamp):
        signed, legacy = self.syncs[engine], self.syncs[swamp]
        comparison = {'engine_ms': signed['request_through_terminal_ms'], 'swamp_ms': legacy['request_through_terminal_ms'],
                      'swamp_production_median_ms': 24500, 'same_copy': signed['instance'] == legacy['instance'],
                      'engine_steps_ms': {step['name']: step['done_ms'] - step['first_sent_ms'] for step in signed['operation']['steps'] if step['done_ms']},
                      'swamp_gateway_start_ms': legacy['operation'].get('dispatch_timing'),
                      'same_result': signed['result'] == legacy['result'],
                      'bundled_engine': signed['bundled'], 'both_imported': not signed['before']['holds_tip'] and not legacy['before']['holds_tip']}
        differs = sorted(field for field in set(signed['result']) | set(legacy['result']) if signed['result'].get(field) != legacy['result'].get(field))
        require(comparison['same_copy'] and comparison['same_result'] and comparison['both_imported'],
                f'the engine and Swamp syncs of the same copy state differ in {differs}')
        self.record('sync-comparison', comparison)


if __name__ == '__main__':
    print(json.dumps(stage_executor(log=True)))
