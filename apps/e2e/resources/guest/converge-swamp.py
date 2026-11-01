#!/usr/bin/python3
"""Prepare the official native runtime in an owned topology Gateway VM only."""
import hashlib
import json
import os
import pwd
import re
import shutil
import ssl
import subprocess
import sys
import time
import urllib.request
from pathlib import Path

SOURCE = Path('/home/orbit/orbit/swamp')
ROOT = Path('/home/orbit/.orbit/swamp')
REPO = ROOT / 'repository'
BINARY = Path('/usr/local/bin/swamp')
URL = 'wss://localhost:19470'
ACCOUNT = 'orbit'


def run(argv, *, input=None, timeout=120):
    result = subprocess.run(argv, input=input, capture_output=True, text=True,
                            timeout=timeout, check=False)
    if result.returncode:
        raise RuntimeError('e2e.swamp_command_failed')
    return result.stdout


def native(*argv, timeout=120):
    try:
        output = run(['runuser', '-u', ACCOUNT, '--', 'env', '-i',
                      'PATH=/usr/local/bin:/usr/bin:/bin',
                      'HOME=/home/orbit', f'SWAMP_HOME={ROOT}/home',
                      f'SWAMP_CONFIG_DIR={ROOT}/config',
                      f'SWAMP_API_KEY_FILE={ROOT}/credentials/club',
                      'SWAMP_NO_UPDATE_CHECK=1', 'SWAMP_NO_TELEMETRY=1',
                      str(BINARY), *argv, *([] if argv[0] == 'init' or '--server' in argv else ['--repo-dir', str(REPO)]), '--json'],
                     timeout=timeout)
    except RuntimeError:
        raise RuntimeError('e2e.swamp_' + argv[0] + '_failed') from None
    # Native first-use events may precede its final JSON reply.
    for index, char in enumerate(output):
        if char not in '{[':
            continue
        try:
            value, end = json.JSONDecoder().raw_decode(output[index:])
            if not output[index + end:].strip():
                return value
        except ValueError:
            pass
    raise RuntimeError('e2e.swamp_native_reply_invalid')


def private_file(path, value):
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    temporary = path.with_suffix('.new')
    with temporary.open('w', opener=lambda p, flags: os.open(p, flags, 0o600)) as stream:
        stream.write(value)
    temporary.chmod(0o600)
    os.chown(temporary, pwd.getpwnam(ACCOUNT).pw_uid, pwd.getpwnam(ACCOUNT).pw_gid)
    temporary.replace(path)


def runtime_pin():
    source = (SOURCE / 'extensions/behaviors/node-worker/config.ts').read_text()
    version = re.search(r'version: "([0-9.]+-sha\.[a-f0-9]+)"', source)
    digest = re.search(r'sha256: "([a-f0-9]{64})"', source)
    if not version or not digest:
        raise RuntimeError('e2e.swamp_runtime_pin_invalid')
    return version[1], digest[1]


def install_binary():
    version, digest = runtime_pin()
    if BINARY.is_file():
        with BINARY.open('rb') as stream:
            if hashlib.file_digest(stream, 'sha256').hexdigest() == digest:
                return
    temporary = BINARY.with_suffix('.new')
    try:
        url = f'https://github.com/swamp-club/swamp/releases/download/v{version}/swamp-linux-x86_64'
        with urllib.request.urlopen(url, timeout=120) as response, temporary.open('wb') as stream:
            shutil.copyfileobj(response, stream)
        with temporary.open('rb') as stream:
            if hashlib.file_digest(stream, 'sha256').hexdigest() != digest:
                raise RuntimeError('e2e.swamp_runtime_digest_mismatch')
        temporary.chmod(0o755)
        temporary.replace(BINARY)
    finally:
        temporary.unlink(missing_ok=True)


def grants(manifest):
    result = []
    for entry in manifest['vaults']:
        result.append({'subject': manifest['principal'], 'effect': 'allow',
                       'actions': entry['actions'], 'resource': 'vault:' + entry['vault'],
                       'condition': 'key.matches(' + json.dumps(entry['key_pattern']) + ')'})
    for entry in manifest['workflows']:
        result.append({'subject': manifest['principal'], 'effect': 'allow',
                       'actions': entry['actions'], 'resource': 'workflow:' + entry['workflow']})
        result.append({'subject': 'user:orbit-proof-reader', 'effect': 'allow',
                       'actions': ['read'], 'resource': 'workflow:' + entry['workflow']})
    return {'grants': result}


def load_source():
    # Native runs create models too. Replace only source definitions, never their generated models.
    inventory = ROOT / 'source-files.json'
    previous = set(json.loads(inventory.read_text())) if inventory.exists() else set()
    current = {}
    for name in ('extensions', 'models', 'workflows'):
        for source in (SOURCE / name).rglob('*'):
            if source.is_file() and '__pycache__' not in source.parts:
                current[str(source.relative_to(SOURCE))] = source
    for relative in previous - set(current):
        (REPO / relative).unlink(missing_ok=True)
    for relative, source in current.items():
        destination = REPO / relative
        destination.parent.mkdir(parents=True, exist_ok=True)
        temporary = destination.with_suffix(destination.suffix + '.source-new')
        shutil.copyfile(source, temporary)
        temporary.replace(destination)
    inventory.write_text(json.dumps(sorted(current)))
    manifest = json.loads((SOURCE / 'config/gateway-workflow-grants.json').read_text())
    (ROOT / 'grants.yaml').write_text(json.dumps(grants(manifest)))
    run(['chown', '-R', 'orbit:orbit', str(ROOT)])
    existing = set()
    for path in (REPO / 'vaults/local_encryption').glob('*.yaml'):
        match = re.search(r'^name: [\"\']?([^\"\'\n]+)', path.read_text(), re.MULTILINE)
        if match:
            existing.add(match[1].strip())
    for name in sorted({entry['vault'] for entry in manifest['vaults']} - existing):
        native('vault', 'create', 'local_encryption', name)
    run(['chown', '-R', 'orbit:orbit', str(ROOT)])


def token(name, principal):
    native('access', 'token', 'mint', name, '--principal', principal, '--duration', '24h')
    private_file(ROOT / 'credentials' / name,
                 native('access', 'token', 'reveal', name, '--yes')['token'])


def configure_gateway():
    identity = Path('/home/orbit/.orbit/ssh/id_ed25519')
    identity.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    if not identity.exists():
        run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(identity)])
    run(['chown', '-R', 'orbit:orbit', str(identity.parent)])
    settings = {
        'ORBIT_SWAMP_ADDRESS': '127.0.0.1', 'ORBIT_SWAMP_PORT': '19470',
        'ORBIT_SWAMP_CA_PATH': str(ROOT / 'tls/cert.pem'),
        'ORBIT_SWAMP_RUN_TOKEN_FILE': str(ROOT / 'credentials/gateway'),
        'ORBIT_SWAMP_READ_TOKEN_FILE': str(ROOT / 'credentials/reader'),
        'ORBIT_SWAMP_SSH_PUBLIC_KEY': "'" + run(['ssh-keygen', '-y', '-f', str(identity)]).strip() + "'",
    }
    environment = SOURCE.parent / 'apps/gateway/.env'
    contents = environment.read_text()
    keys = {*settings, 'ORBIT_SWAMP_RUN_TOKEN', 'ORBIT_SWAMP_READ_TOKEN'}
    lines = [line for line in contents.splitlines() if line.split('=', 1)[0] not in keys]
    # Only public settings/file paths enter the mount; token bytes remain VM-local.
    environment.write_text('\n'.join(lines + [key + '=' + value for key, value in settings.items()]) + '\n')


def units():
    common = (f'User=orbit\nWorkingDirectory={REPO}\n'
              f'Environment=HOME=/home/orbit SWAMP_HOME={ROOT}/home SWAMP_CONFIG_DIR={ROOT}/config\n'
              'Environment=SWAMP_NO_UPDATE_CHECK=1 SWAMP_NO_TELEMETRY=1\nUMask=0077\n')
    serve = ('[Unit]\nDescription=Topology Swamp serve\nAfter=network-online.target\n[Service]\n'
             + common + f'LoadCredential=club:{ROOT}/credentials/club\n'
             + f'Environment=SWAMP_API_KEY_FILE=%d/club\nExecStart={BINARY} serve '
             + f'--repo-dir {REPO} --host 127.0.0.1 --port 19470 --auth-mode token '
             + '--admins user:topology-operator --trusted-hosts swamp.orbit:19470 '
             + '--remote-only --hot-reload --no-schedule '
             + f'--cert-file {ROOT}/tls/cert.pem --key-file {ROOT}/tls/key.pem '
             + f'--grants-file {ROOT}/grants.yaml --grant-reload auto --dispatch-env-allow=, '
             + '--max-run-duration 16m\nRestart=on-failure\n[Install]\nWantedBy=multi-user.target\n')
    worker = ('[Unit]\nDescription=Topology Swamp worker orbit-node-209\nAfter=orbit-e2e-swamp.service\n'
              + '[Service]\n' + common
              + f'LoadCredential=enrollment:{ROOT}/credentials/enrollment\n'
              + f'LoadCredential=server:{ROOT}/credentials/worker\n'
              + 'LoadCredential=proxy-ssh-identity:/home/orbit/.orbit/ssh/id_ed25519\n'
              + f'ExecStart={BINARY} worker connect {URL} --token-file %d/enrollment '
              + f'--server-token-file %d/server --ca-cert {ROOT}/tls/cert.pem '
              + f'--cache-dir {ROOT}/worker-cache --label node=209\nRestart=on-failure\n'
              + '[Install]\nWantedBy=multi-user.target\n')
    for name, contents in [('orbit-e2e-swamp', serve), ('orbit-node-worker', worker)]:
        Path('/etc/systemd/system/' + name + '.service').write_text(contents)
    run(['systemctl', 'daemon-reload'])


def prepare():
    credential = sys.stdin.read(16_385).strip()
    if not credential or len(credential) > 16_384 or re.search(r'\s', credential):
        raise RuntimeError('e2e.swamp_club_credential_invalid')
    ROOT.mkdir(parents=True, exist_ok=True, mode=0o700)
    private_file(ROOT / 'credentials/club', credential)
    run(['chown', '-R', 'orbit:orbit', str(ROOT)])
    install_binary()
    REPO.mkdir(exist_ok=True)
    run(['chown', 'orbit:orbit', str(REPO)])
    native('init', str(REPO), '--tool', 'none')
    native('extension', 'trust', 'add', 'nckrtl')
    for name, version in json.loads((SOURCE / 'config/official-extensions.json').read_text()).items():
        native('extension', 'pull', name + '@' + version)
    load_source()
    token('operator', 'user:topology-operator')
    token('gateway', 'user:orbit-gateway')
    token('reader', 'user:orbit-proof-reader')
    token('worker', 'worker:orbit-node-209')
    private_file(ROOT / 'credentials/enrollment',
                 native('worker', 'token', 'create', 'orbit-node-209', '--duration', '24h')['token'])
    tls = ROOT / 'tls'
    tls.mkdir(mode=0o700)
    run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '2',
         '-subj', '/CN=swamp.orbit', '-addext', 'subjectAltName=DNS:swamp.orbit,DNS:localhost,IP:127.0.0.1',
         '-keyout', str(tls / 'key.pem'), '-out', str(tls / 'cert.pem')])
    run(['chown', '-R', 'orbit:orbit', str(ROOT)])
    configure_gateway()
    units()
    run(['systemctl', 'enable', '--now', 'orbit-e2e-swamp.service', 'orbit-node-worker.service'])
    wait_ready()


def verify():
    for name in ('orbit-e2e-swamp.service', 'orbit-node-worker.service'):
        run(['systemctl', 'is-active', '--quiet', name])
    context = ssl.create_default_context(cafile=str(ROOT / 'tls/cert.pem'))
    with urllib.request.urlopen('https://localhost:19470/ready', context=context, timeout=3) as response:
        if response.status != 200:
            raise RuntimeError('e2e.swamp_not_ready')
    workers = native('worker', 'list', '--server', URL, '--token-file', str(ROOT / 'credentials/operator'),
                     '--ca-cert', str(ROOT / 'tls/cert.pem'), timeout=5)['workers']
    if len(workers) != 1 or workers[0].get('tokenName') != 'orbit-node-209' \
            or workers[0].get('status') not in ('idle', 'busy') \
            or workers[0].get('labels', {}).get('node') != '209':
        raise RuntimeError('e2e.swamp_worker_not_ready')


def wait_ready():
    deadline = time.monotonic() + 120
    while True:
        try:
            verify()
            return
        except (OSError, ValueError, KeyError, RuntimeError, subprocess.SubprocessError):
            if time.monotonic() >= deadline:
                raise RuntimeError('e2e.swamp_startup_timeout') from None
            time.sleep(0.25)


def main():
    if os.geteuid() != 0 or len(sys.argv) != 2:
        raise RuntimeError('e2e.swamp_arguments_invalid')
    if sys.argv[1] == 'prepare':
        prepare()
    elif sys.argv[1] == 'refresh':
        verify()
        load_source()
        reply = native('serve', 'reload', '--server', URL,
                       '--token-file', str(ROOT / 'credentials/operator'),
                       '--ca-cert', str(ROOT / 'tls/cert.pem'))
        if reply.get('success') is not True:
            raise RuntimeError('e2e.swamp_refresh_failed')
    elif sys.argv[1] == 'verify':
        verify()
    else:
        raise RuntimeError('e2e.swamp_arguments_invalid')
    print(json.dumps({'ready': True, 'worker': 'orbit-node-209'}))


if __name__ == '__main__':
    os.umask(0o077)
    try:
        main()
    except RuntimeError as error:
        code = str(error)
        print(code if re.fullmatch(r'e2e\.swamp_[a-z_]+', code) else 'e2e.swamp_setup_failed', file=sys.stderr)
        sys.exit(1)
    except (OSError, ValueError, KeyError, subprocess.SubprocessError):
        # No native command text, stderr or credential appears in harness evidence.
        print('e2e.swamp_setup_failed', file=sys.stderr)
        sys.exit(1)
