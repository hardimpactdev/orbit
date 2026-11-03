"""Disposable private Git origin and real production account, FPM and Process proof."""
import base64
import importlib.util
import json
import os
import secrets
import signal
import subprocess
import sys
import time
from pathlib import Path

SOURCE = Path(__file__).resolve().parents[3]
STATE = Path('/home/orbit/.orbit/swamp-proof/production-deploy')
DATA = STATE / 'state.json'
HOME = '/home/orbit-app-9002'
SERVICE = 'orbit-orbit-app-9002-php8.5-fpm.service'
spec = importlib.util.spec_from_file_location('github', Path(__file__).resolve().parents[1] / 'tasks.publish/github_fixture.py')
github = importlib.util.module_from_spec(spec)
spec.loader.exec_module(github)
github.STATE, github.DATA = STATE, DATA
github.LISTEN_HOST = '10.231.0.3'


def command(argv, **kwargs):
    return subprocess.run(argv, capture_output=True, text=True, timeout=180, check=True, **kwargs).stdout.strip()


def peer(*argv, **kwargs):
    return command(['incus', 'exec', 'peer', '--', *argv], **kwargs)


def php(code):
    bootstrap = r"require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); "
    return command(['runuser', '-u', 'orbit', '--', 'php', '-r', bootstrap + code], cwd=SOURCE / 'apps/gateway')


def setup(node, run, case):
    STATE.mkdir(mode=0o700, exist_ok=True)
    if (STATE / 'pid').exists():
        try:
            os.kill(int((STATE / 'pid').read_text()), signal.SIGTERM)
        except ProcessLookupError:
            pass
        time.sleep(.2)
    origin, remote = STATE / 'origin', STATE / 'git/hardimpactdev/orbit.git'
    for directory in [origin, STATE / 'git']:
        command(['rm', '-rf', '--', str(directory)])
    command(['git', 'init', '-qb', 'main', str(origin)])
    for key, value in [('user.email', 'proof@example.invalid'), ('user.name', 'Proof')]:
        command(['git', '-C', str(origin), 'config', key, value])
    (origin / 'public').mkdir()
    (origin / 'public/index.php').write_text('<?php echo "production-proof";\n')
    (origin / 'storage/app').mkdir(parents=True)
    (origin / 'storage/app/seed').write_text('initial seed\n')
    (origin / 'package.json').write_text(json.dumps({'private': True, 'devDependencies': {'vite-plus': '1.4.0'}, 'packageManager': 'pnpm@12.11.2', 'engines': {'node': '24.22.1'}}))
    (origin / '.node-version').write_text('24.22.1\n')
    (origin / 'pnpm-lock.yaml').write_text("lockfileVersion: '9.0'\nimporters:\n  .:\n    devDependencies:\n      vite-plus: {specifier: 1.4.0, version: 1.4.0}\npackages:\n  vite-plus@1.4.0: {engines: {node: '>=24'}}\n")
    (origin / '.env.example').write_text('APP_ENV=production\nAPP_KEY=\n')
    command(['git', '-C', str(origin), 'add', '.'])
    command(['git', '-C', str(origin), 'commit', '-qm', 'Initial'])
    first = command(['git', '-C', str(origin), 'rev-parse', 'HEAD'])
    command(['git', '-C', str(origin), 'checkout', '-qb', 'side'])
    (origin / 'side').write_text('not on main\n')
    command(['git', '-C', str(origin), 'add', '.'])
    command(['git', '-C', str(origin), 'commit', '-qm', 'Side'])
    side = command(['git', '-C', str(origin), 'rev-parse', 'HEAD'])
    command(['git', '-C', str(origin), 'checkout', '-q', 'main'])
    (origin / 'version').write_text('two\n')
    command(['git', '-C', str(origin), 'add', '.'])
    command(['git', '-C', str(origin), 'commit', '-qm', 'Second'])
    head = command(['git', '-C', str(origin), 'rev-parse', 'HEAD'])
    command(['git', 'init', '-q', '--bare', '--initial-branch=main', str(remote)])
    command(['git', '-C', str(origin), 'push', '-q', str(remote), 'main', 'side'])
    tls = STATE / 'tls'
    tls.mkdir(exist_ok=True)
    ca = Path('/home/orbit/.orbit/ca')
    command(['openssl', 'req', '-new', '-newkey', 'rsa:2048', '-nodes', '-subj', '/CN=github.com', '-keyout', str(tls / 'key.pem'), '-out', str(tls / 'request.pem')])
    (tls / 'extensions').write_text('subjectAltName=DNS:github.com,DNS:api.github.com\nkeyUsage=digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n')
    command(['openssl', 'x509', '-req', '-in', str(tls / 'request.pem'), '-CA', str(ca / 'root.pem'), '-CAkey', str(ca / 'root.key'), '-CAcreateserial', '-days', '1', '-extfile', str(tls / 'extensions'), '-out', str(tls / 'cert.pem')])
    hosts = Path('/etc/hosts')
    hosts.write_text('\n'.join(line for line in hosts.read_text().splitlines() if not line.startswith(('127.0.0.2 ', '10.231.0.3 '))) + '\n10.231.0.3 github.com api.github.com\n')
    # The shared Nodes are VMs. A fixture-only host address serves their local HTTPS origin.
    command(['ip', 'address', 'replace', '10.231.0.3/32', 'dev', 'lo'])
    command(['ufw', 'allow', 'in', 'on', 'oeproof', 'from', '10.231.0.12', 'to', '10.231.0.3', 'port', '443', 'proto', 'tcp', 'comment', 'proof-private-git'])
    command(['incus', 'file', 'push', str(ca / 'root.pem'), 'peer/usr/local/share/ca-certificates/orbit-proof-fixture.crt'])
    peer('update-ca-certificates')
    peer('sh', '-c', "sed -i '/github.com/d' /etc/hosts; printf '\n10.231.0.3 github.com\n' >> /etc/hosts")
    peer('sh', '-c', 'if ! test -x /usr/sbin/php-fpm8.5 || ! command -v setfacl >/dev/null; then apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -q php8.5-fpm acl >/dev/null; fi')
    peer('sh', '-c', 'id caddy >/dev/null 2>&1 || useradd -r -M -s /usr/sbin/nologin caddy; id orbit-app-9002 >/dev/null 2>&1 || useradd -r -m -s /usr/sbin/nologin orbit-app-9002')
    peer('sh', '-c', "systemctl stop 'orbit-process-*-proof-deploy.service' 2>/dev/null || true; systemctl stop " + SERVICE + ' 2>/dev/null || true')
    peer('rm', '-rf', '--', HOME)
    peer('install', '-d', '-o', 'orbit-app-9002', '-g', 'orbit-app-9002', '-m', '0700', HOME)
    peer('install', '-d', '-o', 'orbit-app-9002', '-g', 'orbit-app-9002', '-m', '0755', HOME + '/releases')
    for release in ['initial', 'r1', 'r2', 'r3']:
        peer('install', '-d', '-o', 'orbit-app-9002', '-g', 'orbit-app-9002', HOME + '/releases/' + release + '/public')
    peer('sh', '-c', "printf 'APP_ENV=production\n' > " + HOME + '/.env; chmod 600 ' + HOME + '/.env; chown orbit-app-9002:orbit-app-9002 ' + HOME + '/.env')
    # The initial clone marker stays byte-for-byte unchanged through deployment.
    marker = 'https://github.com/hardimpactdev/orbit.git\0orbit-app-9002\0' + HOME + '\0initial\0'
    peer('install', '-d', '-m', '0700', '/var/lib/orbit/app-instance-sources/9002')
    peer('python3', '-c', 'import pathlib,sys;p=pathlib.Path(sys.argv[1]);p.write_bytes(bytes.fromhex(sys.argv[2]));p.chmod(0o600)', '/var/lib/orbit/app-instance-sources/9002/release-layout', marker.encode().hex())
    peer('setfacl', '-m', 'u:caddy:--x', HOME, HOME + '/releases')
    if case == 'first':
        peer('rm', '-rf', '--', '/etc/orbit/php-fpm/orbit-app-9002', '/etc/systemd/system/' + SERVICE)
        peer('systemctl', 'daemon-reload')
    else:
        peer('runuser', '-u', 'orbit-app-9002', '--', 'ln', '-s', 'releases/r3', HOME + '/current')
        peer('systemctl', 'start', SERVICE)
        peer('sh', '-c', "systemctl start 'orbit-process-*-proof-deploy.service' 2>/dev/null || true")
    token, key = secrets.token_urlsafe(32), secrets.token_urlsafe(32) + "'\n$HOME $(literal) `literal`"
    data = {'case': case, 'token': token, 'key': key, 'head': head, 'first': first, 'side': side, 'calls': [], 'minted': 0, 'revoked': 0, 'permissions': {'contents': 'read'}}
    DATA.write_text(json.dumps(data))
    DATA.chmod(0o600)
    (STATE / 'ready').unlink(missing_ok=True)
    with (STATE / 'server.log').open('w') as log:
        process = subprocess.Popen(['python3', '-I', str(Path(__file__).resolve()), 'serve'], stdin=subprocess.DEVNULL, stdout=log, stderr=log, start_new_session=True)
    (STATE / 'pid').write_text(str(process.pid))
    for _ in range(100):
        if (STATE / 'ready').exists():
            break
        time.sleep(.05)
    assert (STATE / 'ready').exists()
    command(['runuser', '-u', 'orbit', '--', 'php', str(Path(__file__).with_name('prepare.php'))], cwd=SOURCE / 'apps/gateway', input=json.dumps({'case': case, 'node': int(node), 'previous': first}))
    print(json.dumps({'secrets': {'key': key, 'commit': side if case == 'off-branch' else head}}))


def parity():
    data = json.loads(DATA.read_text())
    operation = json.loads(Path(os.environ['PROOF_OPERATION_FILE']).read_text())
    public = json.dumps(operation) + Path(os.environ['PROOF_OUTPUTS']).read_text()
    assert data['token'] not in public and data['key'] not in public
    assert data['key'].split("'", 1)[0] not in public
    assert base64.b64encode(('x-access-token:' + data['token']).encode()).decode() not in public
    assert data['minted'] == 1
    result, case = operation['result'], data['case']
    success = case in ['first', 'success', 'vp']
    assert operation['status'] == ('succeeded' if success else 'failed'), operation
    release = result['release']
    selected = 'releases/' + (release if success or case == 'after-failure' else 'r3')
    assert peer('readlink', HOME + '/current') == selected
    assert peer('stat', '-c', '%U', HOME + '/releases/' + release) == 'orbit-app-9002'
    marker = 'https://github.com/hardimpactdev/orbit.git\0orbit-app-9002\0' + HOME + '\0initial\0'
    assert peer('sha256sum', '/var/lib/orbit/app-instance-sources/9002/release-layout').split()[0] == __import__('hashlib').sha256(marker.encode()).hexdigest()
    assert not peer('find', '/tmp', '-maxdepth', '1', '-name', 'orbit-deploy-operation-*')
    if success:
        assert result['selected_release'] == release and result['commit'] == data['head'], result
        assert peer('git', '-c', 'safe.directory=*', '-C', HOME + '/' + selected, 'rev-parse', 'HEAD') == data['head']
        assert peer('stat', '-c', '%a %U', HOME + '/.env') == '600 orbit-app-9002'
        assert peer('readlink', HOME + '/' + selected + '/.env') == '../../.env'
        assert peer('readlink', HOME + '/' + selected + '/storage') == HOME + '/storage'
        assert peer('cat', HOME + '/before-ran').splitlines()[:3] == ['orbit-app-9002', data['head'], HOME + '/' + selected]
        assert peer('cat', HOME + '/env-digest').split()[0] == __import__('hashlib').sha256(data['key'].encode()).hexdigest()
        assert peer('cat', HOME + '/after-ran') == selected
        assert peer('systemctl', 'is-active', SERVICE) == 'active'
        peer('test', '-S', '/run/php/orbit-app-9002.sock')
        if case == 'success' and result.get('warnings', 0) == 0:
            assert not peer('find', HOME + '/releases', '-maxdepth', '1', '-name', 'r1')
    if case == 'vp':
        assert peer('cat', HOME + '/vp-version') in ['1.4.0', 'vp 1.4.0']
        assert peer('cat', HOME + '/node-version') == 'v24.22.1'
        assert peer('cat', HOME + '/pnpm-version') == '12.11.2'
        assert peer('stat', '-c', '%U', HOME + '/.local/share/vite-plus') == 'orbit-app-9002'
    if not success:
        assert operation['error_code'] == ('deployment.commit_not_on_branch' if case == 'off-branch' else 'deployment.step_failed')
    state = json.loads(php("echo json_encode(App\\Models\\Instance::findOrFail(9002)->only(['checkout_path','production_php_service','environment_owned_keys']));"))
    assert state['checkout_path'] == HOME + '/' + selected
    if success:
        assert state['production_php_service'] == SERVICE and sorted(state['environment_owned_keys']) == ['APP_ENV', 'APP_KEY']
        processes = json.loads(php("echo App\\Models\\Process::where('owner_id',9002)->get(['id','name','status'])->toJson();"))
        assert len(processes) == 1 and processes[0]['status'] == 'active', processes
        unit = 'orbit-process-' + str(processes[0]['id']) + '-proof-deploy.service'
        assert peer('systemctl', 'show', unit, '--property=ActiveState', '--value') == ('inactive' if case == 'first' else 'active')
        schedules = json.loads(php("echo App\\Models\\Schedule::where('target_id',9002)->get(['id','status'])->toJson();"))
        assert len(schedules) == 1 and schedules[0]['status'] == 'active', schedules
        assert peer('systemctl', 'show', 'orbit-schedule-' + schedules[0]['id'] + '.timer', '--property=LoadState', '--value') == 'loaded'
    print('Private Git over verified TLS; exact release and selection; preserved clone marker; real PHP-FPM and Process; scoped credential removed; private commands passed only on stdin; terminal projection checked.')


if __name__ == '__main__':
    if sys.argv[1] == 'serve':
        github.serve()
    elif sys.argv[1] == 'setup':
        setup(*sys.argv[2:])
    else:
        parity()
