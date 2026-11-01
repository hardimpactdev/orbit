"""Disposable-VM driver. Never imports an operator identity or contacts production serve."""
import hashlib
import json
import os
import secrets
import shlex
import shutil
import subprocess
import sys
import time
import urllib.request
from pathlib import Path

from swamp_proof import ProofError, evaluate, native_step, relative_file

BASE = Path('/opt/orbit-proof')
SOURCE = BASE / 'repo'
RUNTIME = BASE / 'runtime'
PRIVATE = BASE / 'private'
BINARY = BASE / 'swamp'
SERVER = 'ws://127.0.0.1:9090'
PROJECT = 'swamp-proof'
TARGET = 'proof-target'
USER = 'proof'


def call(argv, source=None, timeout=60, env=None, check=True, cwd=None):
    try:
        result = subprocess.run(argv, input=source, capture_output=True, timeout=timeout, env=env, cwd=cwd, check=False)
    except (OSError, subprocess.TimeoutExpired):
        raise ProofError('Disposable proof command unavailable or timed out.') from None
    if check and result.returncode:
        # Never return raw subprocess output: it can contain the throwaway secret.
        raise ProofError('Disposable proof command failed; diagnostics stay on the VM.')
    return result


def environment():
    return {'PATH': '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'HOME': str(PRIVATE / 'home'), 'SWAMP_HOME': str(PRIVATE / 'home'),
            'SWAMP_CONFIG_DIR': str(PRIVATE / 'config'), 'SWAMP_NO_UPDATE_CHECK': '1',
            'SWAMP_NO_TELEMETRY': '1', 'TMPDIR': str(PRIVATE / 'tmp')}


def cli(*args, source=None, remote=False, check=True, timeout=60):
    argv = [str(BINARY), *args, '--json']
    if remote:
        argv += ['--server', SERVER]
    result = call(argv, source, timeout, environment(), check=False, cwd=RUNTIME)
    if check and result.returncode:
        # Report known official authentication refusal without disclosing diagnostics.
        code = 'auth_gate_blocked' if b'auth_gate_blocked' in result.stdout + result.stderr else 'native_command_failed'
        raise ProofError('Isolated Swamp refused: ' + code)
    try:
        data = json.loads(result.stdout)
    except ValueError:
        raise ProofError('Isolated Swamp returned no JSON result.') from None
    return data


def private_file(path, data):
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    with path.open('wb', opener=lambda name, flags: os.open(name, flags, 0o600)) as stream:
        stream.write(data)
    path.chmod(0o600)


def container(*args, source=None, timeout=120):
    return call(['incus', 'exec', TARGET, '--project', PROJECT, '--', *args], source, timeout).stdout


def install(pin):
    for name in ('home', 'config', 'tmp'):
        (PRIVATE / name).mkdir(parents=True, mode=0o700)
    url = f'https://github.com/swamp-club/swamp/releases/download/v{pin["version"]}/swamp-linux-x86_64'
    digest, size = hashlib.sha256(), 0
    with urllib.request.urlopen(url, timeout=60) as response, BINARY.open('wb') as destination:
        while block := response.read(1024 * 1024):
            size += len(block)
            if size > pin['bytes']:
                raise ProofError('Official Swamp download exceeds the pinned size.')
            digest.update(block)
            destination.write(block)
    if size != pin['bytes'] or digest.hexdigest() != pin['sha256']:
        BINARY.unlink()
        raise ProofError('Official Swamp checksum differs from the repository pin.')
    BINARY.chmod(0o755)
    RUNTIME.mkdir()
    cli('init', str(RUNTIME), '--tool', 'none', timeout=120)
    for name, expected in pin['extensions'].items():
        cli('extension', 'pull', name + '@' + expected['version'], '--yes', timeout=180)
        installed = json.loads((RUNTIME / 'extensions/models/upstream_extensions.json').read_text())[name]
        if any(installed.get(field) != expected.get(field) for field in ('version', 'checksum', 'filesChecksum')):
            raise ProofError('Official extension installation differs from the repository pin.')
    shutil.copytree(SOURCE / 'swamp', RUNTIME, dirs_exist_ok=True)
    private_file(PRIVATE / 'pin.json', json.dumps(pin).encode())


def prepare(payload):
    install(payload['pin'])
    if not shutil.which('incus'):
        raise ProofError('Normal Task VM has no Incus container runtime; target bootstrap is blocked.')
    # All Incus state here is INSIDE the disposable VM, never on Shark's host.
    call(['incus', 'storage', 'create', 'proof', 'dir'])
    call(['incus', 'project', 'create', PROJECT, '-c', 'features.images=false', '-c', 'features.profiles=false',
          '-c', 'features.networks=true'])
    call(['incus', 'network', 'create', 'proof0', '--project', PROJECT, 'ipv4.address=10.239.255.1/24',
          'ipv4.nat=true', 'ipv6.address=none'])
    image = payload['topology']['image']
    reference = payload['topology']['pilot_image']
    if image['fingerprint']:
        call(['incus', 'image', 'import', *payload['image_files'], '--alias', image['alias']], timeout=300)
        rows = json.loads(call(['incus', 'image', 'list', image['alias'], '--format', 'json']).stdout)
        if len(rows) != 1 or rows[0]['fingerprint'] != image['fingerprint'] or rows[0]['type'] != 'container':
            raise ProofError('Imported topology image differs from its fingerprint/type.')
        reference = image['fingerprint']
    call(['incus', 'launch', reference, TARGET, '--project', PROJECT, '--storage', 'proof',
          '--network', 'proof0'], timeout=300)
    # Canonical Incus readiness, not an inferred ready flag from a successful launch.
    boot = call(['incus', 'exec', TARGET, '--project', PROJECT, '--', 'systemctl', 'is-system-running', '--wait'],
                timeout=120, check=False)
    if boot.stdout.strip() not in (b'running', b'degraded'):
        raise ProofError('Target container systemd did not finish booting.')
    container('env', 'DEBIAN_FRONTEND=noninteractive', 'apt-get', 'update', timeout=180)
    container('env', 'DEBIAN_FRONTEND=noninteractive', 'apt-get', 'install', '-y', 'openssh-server', 'sudo', 'python3', 'ufw', timeout=300)
    container('useradd', '--create-home', '--shell', '/bin/bash', USER)
    container('sh', '-c', 'cat > /etc/sudoers.d/proof; chmod 440 /etc/sudoers.d/proof', source=b'proof ALL=(ALL) NOPASSWD:ALL\n')
    container('systemctl', 'enable', '--now', 'ssh')
    identity = PRIVATE / 'id_ed25519'
    call(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(identity)])
    public = identity.with_suffix('.pub').read_text().strip()
    container('install', '-d', '-m', '700', '-o', USER, '-g', USER, '/home/proof/.ssh')
    container('sh', '-c', 'cat > /home/proof/.ssh/authorized_keys; chown proof:proof /home/proof/.ssh/authorized_keys; chmod 600 /home/proof/.ssh/authorized_keys', source=(public + '\n').encode())
    host_key = container('cat', '/etc/ssh/ssh_host_ed25519_key.pub').decode().strip()
    info = json.loads(call(['incus', 'list', TARGET, '--project', PROJECT, '--format', 'json']).stdout)
    addresses = [row['address'] for network in info[0]['state']['network'].values()
                 for row in network['addresses'] if row['family'] == 'inet' and row['scope'] == 'global']
    if len(addresses) != 1:
        raise ProofError('Target container has no unique VM-internal IPv4 address.')
    target = {'name': TARGET, 'host': addresses[0], 'user': USER, 'port': 22, 'host_keys': [host_key]}
    private_file(PRIVATE / 'known_hosts', (addresses[0] + ' ' + host_key + '\n').encode())
    private_file(PRIVATE / 'target.json', json.dumps(target).encode())
    if image['fingerprint']:
        script = relative_file(SOURCE / 'swamp/proof/topologies' / payload['topology']['name'],
                               payload['topology']['targets']['default']['setup']).read_bytes()
        call([*ssh_prefix(target), 'sudo -n bash -s'], source=script, timeout=300)
    return {'identity_file': str(identity), 'public_key': public, 'target': target}


def ssh_prefix(target):
    return ['ssh', '-i', str(PRIVATE / 'id_ed25519'), '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes',
            '-o', 'StrictHostKeyChecking=yes', '-o', 'UserKnownHostsFile=' + str(PRIVATE / 'known_hosts'),
            '-o', 'ConnectTimeout=10', '-p', str(target['port']), target['user'] + '@' + target['host']]


def seed(fixture, created, seeded):
    directory = '/tmp/swamp-proof/' + fixture['kind']
    container('install', '-d', '-m', '700', '-o', USER, '-g', USER, directory, directory + '/secrets')
    helpers = SOURCE / 'swamp/proof' / fixture['kind']
    if helpers.is_dir():
        # incus file push does not preserve owners; extraction as target user does.
        archive = call(['tar', '-cf', '-', '-C', str(helpers), '.']).stdout
        container('sudo', '-u', USER, 'tar', '-xf', '-', '-C', directory, source=archive)
    for secret in fixture['secrets']:
        if secret['vault'] not in created:
            cli('vault', 'create', 'local_encryption', secret['vault'])
            created.add(secret['vault'])
        identity = (secret['vault'], secret['key'])
        if identity in seeded:
            material = seeded[identity]
            if secret.get('stage_to_target'):
                container('sudo', '-u', USER, 'sh', '-c', 'umask 077; cat > "$1"', '--', directory + '/secrets/' + secret['key'], source=material)
            continue
        value = secret['value']
        if value == 'generate:hex32':
            material = secrets.token_hex(32).encode()
        elif value == 'generate:ed25519':
            path = PRIVATE / ('key-' + secrets.token_hex(8))
            call(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(path)])
            material = path.read_bytes()
        else:
            material = relative_file(helpers, value[5:]).read_bytes()
        cli('vault', 'put', secret['vault'], secret['key'], source=material)
        seeded[identity] = material
        if secret.get('stage_to_target'):
            container('sudo', '-u', USER, 'sh', '-c', 'umask 077; cat > "$1"', '--', directory + '/secrets/' + secret['key'], source=material)


def target_setup(fixture, target):
    if fixture.get('target_setup'):
        script = relative_file(SOURCE / 'swamp/proof', fixture['target_setup']).read_bytes()
        call([*ssh_prefix(target), shlex.join(['sudo', '-n', 'env', 'PROOF_KIND_DIR=/tmp/swamp-proof/' + fixture['kind'], 'bash', '-s'])],
             source=script, timeout=300)
    # A real authenticated SSH round trip, even when target_setup is absent.
    call([*ssh_prefix(target), '/usr/bin/true'])


def parity_outputs(history, fixture):
    selector = fixture['expect'].get('outputs')
    if selector is None:
        raise ProofError('Parity needs expect.outputs to select the public terminal step.')
    return native_step(history, selector['job'], selector['step']).get('outputs', {})


def ready(process, url):
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        if process.poll() is not None:
            raise ProofError('Isolated serve exited before readiness.')
        try:
            with urllib.request.urlopen(url, timeout=1) as response:
                if response.status == 200:
                    return
        except OSError:
            pass
        time.sleep(0.2)
    raise ProofError('Isolated serve readiness deadline exceeded.')


def run(payload):
    requests = payload['proofs']
    target = json.loads((PRIVATE / 'target.json').read_text())
    created, seeded = set(), {}
    for request in requests:
        seed(request['fixture'], created, seeded)
        cli('workflow', 'validate', request['fixture']['workflow'])
    ceiling = max(request['fixture']['budget_seconds'] for request in requests)
    lifetime = sum(request['fixture']['budget_seconds'] + 180 for request in requests)
    enrollment = cli('worker', 'token', 'create', 'orbit-node-209', '--duration', str(lifetime) + 's', '--max-enrollments', '1')
    token = PRIVATE / 'worker.token'
    private_file(token, enrollment['token'].encode())
    serve = worker = None
    with (PRIVATE / 'serve.log').open('wb') as server_log, (PRIVATE / 'worker.log').open('wb') as worker_log:
        try:
            serve = subprocess.Popen([str(BINARY), 'serve', '--host', '127.0.0.1', '--port', '9090', '--no-schedule',
                                      '--remote-only', '--max-run-duration', str(ceiling) + 's'],
                                     cwd=RUNTIME, env=environment(), stdout=server_log, stderr=server_log)
            ready(serve, 'http://127.0.0.1:9090/ready')
            worker = subprocess.Popen([str(BINARY), 'worker', 'connect', SERVER, '--token-file', str(token),
                                       '--label', 'node=209', '--concurrency', '1', '--no-reconnect'],
                                      cwd=RUNTIME, env=environment(), stdout=worker_log, stderr=worker_log)
            # Native roster readiness before the measured workflow, with no per-kind warm-up run.
            deadline = time.monotonic() + 30
            while True:
                if worker.poll() is not None:
                    raise ProofError('Isolated worker exited before enrollment.')
                roster = cli('worker', 'list', remote=True)
                if any(row.get('tokenName') == 'orbit-node-209' and row.get('status') == 'idle' for row in roster['workers']):
                    break
                if time.monotonic() >= deadline:
                    raise ProofError('Isolated worker enrollment deadline exceeded.')
                time.sleep(0.2)
            results = []
            for request in requests:
                result = run_kind(request, target, ceiling)
                results.append(result)
                private_file(PRIVATE / 'results.json', json.dumps(results).encode())
            pin = json.loads((PRIVATE / 'pin.json').read_text())
            return {'proofs': results, 'swamp_commit': payload['swamp_commit'], 'swamp_version': pin['version'],
                    'status': 'succeeded' if all(result['expect_pass'] and result['budget_pass'] and result['parity']['passed'] for result in results) else 'failed',
                    'expect_pass': all(result['expect_pass'] for result in results),
                    'budget_pass': all(result['budget_pass'] for result in results),
                    'parity': {'passed': all(result['parity']['passed'] for result in results)}}
        finally:
            for process in (worker, serve):
                if process is None:
                    continue
                process.terminate()
                try:
                    process.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=5)


def run_kind(request, target, ceiling):
    fixture = request['fixture']
    target_setup(fixture, target)
    started = cli('workflow', 'run', fixture['workflow'], '--stdin', '--detach',
                  source=json.dumps(request['envelope']).encode(), remote=True)
    run_id = started['id']
    private_file(PRIVATE / 'run-id', run_id.encode())
    deadline = time.monotonic() + ceiling + 30
    while True:
        history = cli('workflow', 'history', 'get', run_id, remote=True)
        if history['status'] in {'succeeded', 'failed', 'cancelled', 'interrupted'}:
            break
        if time.monotonic() >= deadline:
            raise ProofError('Native run remains nonterminal after its configured serve ceiling.')
        time.sleep(0.5)
    output_file = PRIVATE / 'outputs.json'
    private_file(output_file, json.dumps(parity_outputs(history, fixture)).encode())
    env = dict(environment(), PROOF_RUN_ID=run_id, PROOF_OUTPUTS=str(output_file),
               PROOF_TARGET_SSH=shlex.join(ssh_prefix(target)))
    parity = call(fixture['parity']['command'], timeout=120, env=env, cwd=SOURCE, check=False)
    return dict(evaluate(history, fixture, parity.returncode), kind=fixture['kind'], workflow=fixture['workflow'])


def main():
    os.umask(0o077)
    try:
        payload = json.load(sys.stdin)
        result = {'prepare': prepare, 'run': run}[sys.argv[1]](payload)
    except (ProofError, OSError, ValueError, KeyError) as error:
        result = {'error': str(error) if isinstance(error, ProofError) else 'Disposable proof bootstrap or native observation unavailable.'}
        # Retain public native identity and completed siblings even if observation/parity fails.
        if (PRIVATE / 'run-id').is_file():
            result['native_run_id'] = (PRIVATE / 'run-id').read_text()
        if (PRIVATE / 'results.json').is_file():
            result['proofs'] = json.loads((PRIVATE / 'results.json').read_text())
    print(json.dumps(result))


if __name__ == '__main__':
    main()
