"""The production Gateway release layout on the proof VM, a target commit and a disposable GitHub for it.

setup <gateway node id>: as root, before the Gateway request.
  - The proof Gateway moves into the release layout production runs: /home/orbit/orbit links to releases/<id> of the
    staged commit, a worktree of shared/orbit.git with the shared env and storage, and the web directory beside it.
  - A target commit, one empty commit on the staged one, exists only at the disposable origin.
  - The disposable GitHub, behind the proof CA, serves that origin over Git HTTP, its CI web build and the real
    published agent release of the frozen pin (downloaded once from github.com), so the Sigstore check stays real.
serve: the GitHub server process.
parity: checks the switched layout and the Gateway's own version through Caddy, with PROOF_OPERATION_FILE.
"""
import base64
import hashlib
import io
import json
import os
import pwd
import secrets
import shutil
import signal
import ssl
import subprocess
import sys
import threading
import time
import urllib.request
import zipfile
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlsplit

SOURCE = Path(__file__).resolve().parents[3]
HOME = Path('/home/orbit')
STATE = HOME / '.orbit/swamp-proof/gateway-release'
DATA = STATE / 'state.json'
ASSETS = STATE / 'assets'
ORIGIN = STATE / 'git/hardimpactdev/orbit.git'
LISTEN = '127.0.0.2'
ARTIFACT, RUN = 4242, 777
# The root server reads the orbit-owned origin.
TRUSTED = {'GIT_CONFIG_COUNT': '1', 'GIT_CONFIG_KEY_0': 'safe.directory', 'GIT_CONFIG_VALUE_0': '*'}
account = pwd.getpwnam('orbit')


def command(argv, user=None, **kwargs):
    if user:
        argv = ['runuser', '-u', user, '--', *argv]
    result = subprocess.run(argv, capture_output=True, text=True, timeout=900, check=False, **kwargs)
    if result.returncode:
        sys.stderr.write(result.stdout[-3000:] + result.stderr[-3000:])
        result.check_returncode()
    return result.stdout.strip()


def orbit(*argv, **kwargs):
    return command(list(argv), user='orbit', **kwargs)


def link(path, target):
    if path.is_symlink() or path.exists():
        path.unlink()
    path.symlink_to(target)
    os.chown(path, account.pw_uid, account.pw_gid, follow_symlinks=False)


def pinned_assets():
    """The real published release of the frozen agent pin, fetched before github.com points at this fixture."""
    pin = json.loads((SOURCE / 'apps/gateway/resources/agent/frozen-manifest.json').read_text())
    base = pin['artifacts']['linux-x86_64']['url'].rsplit('/', 1)[0]
    tag = base.rsplit('/', 1)[1]
    ASSETS.mkdir(parents=True, exist_ok=True)
    for name in ('manifest.json', 'SHA256SUMS', 'provenance.sigstore.json', 'orbit-agent-linux-x86_64'):
        path = ASSETS / tag / name
        if not path.is_file():
            path.parent.mkdir(parents=True, exist_ok=True)
            with urllib.request.urlopen(base + '/' + name, timeout=120) as response:
                path.write_bytes(response.read())
    return tag


def web_build(sha):
    """The CI web artifact of the target commit: an archive with index.html and version.json at its root."""
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, 'w') as archive:
        archive.writestr('index.html', f'<!doctype html><title>proof {sha[:12]}</title>\n')
        archive.writestr('version.json', json.dumps({'version': sha}) + '\n')
    (STATE / 'web.zip').write_bytes(buffer.getvalue())
    return hashlib.sha256(buffer.getvalue()).hexdigest(), len(buffer.getvalue())


def layout(gateway_node):
    """The release layout: shared/, releases/<id> of the staged commit, the orbit link and the web directory."""
    staged = orbit('git', '-C', str(SOURCE), 'rev-parse', 'HEAD')
    current = staged[:12]
    shared = HOME / 'shared'
    # The Gateway's private runtime directory, which the scheduler's document cleanup gate needs.
    command(['install', '-d', '-m', '0755', '-o', 'orbit', '-g', 'orbit', '/run/orbit'])
    for path in [shared, HOME / 'releases', HOME / 'web/releases']:
        path.mkdir(parents=True, exist_ok=True)
        os.chown(path, account.pw_uid, account.pw_gid)
    command(['chgrp', 'caddy', str(HOME / 'releases'), str(HOME / 'web'), str(HOME / 'web/releases')])
    command(['chmod', '0710', str(HOME / 'releases')])
    command(['chmod', '0750', str(HOME / 'web'), str(HOME / 'web/releases')])
    repository = shared / 'orbit.git'
    if not repository.exists():
        orbit('git', 'clone', '--quiet', '--bare', str(SOURCE), str(repository))
    orbit('git', '-C', str(repository), 'fetch', '--quiet', str(SOURCE), staged)
    orbit('git', '-C', str(repository), 'remote', 'set-url', 'origin', 'https://github.com/hardimpactdev/orbit.git')
    # One env file and one storage for the staged checkout and the releases: the fixtures' artisan and FPM agree.
    link(shared / 'gateway.env', SOURCE / 'apps/gateway/.env')
    link(shared / 'gateway-storage', SOURCE / 'apps/gateway/storage')
    release = HOME / 'releases' / current
    if not (release / 'REVISION').is_file():
        if release.exists():
            command(['chmod', '-R', 'u+w', str(release)])
            shutil.rmtree(release)
        orbit('git', '-C', str(repository), 'worktree', 'prune')
        orbit('git', '-C', str(repository), 'worktree', 'add', '--quiet', '--detach', '--force', str(release), staged)
        app = release / 'apps/gateway'
        shutil.rmtree(app / 'storage')
        link(app / 'storage', shared / 'gateway-storage')
        link(app / '.env', shared / 'gateway.env')
        orbit('composer', 'install', '--no-interaction', '--no-progress', '--prefer-dist', '--no-dev', '--no-scripts', '--optimize-autoloader', '--quiet', cwd=app)
        orbit('php', 'artisan', 'package:discover', '--quiet', cwd=app)
        (release / 'REVISION').write_text(staged + '\n')
        os.chown(release / 'REVISION', account.pw_uid, account.pw_gid)
        command(['chgrp', 'caddy', str(release), str(release / 'apps'), str(app)])
        command(['chmod', '0710', str(release), str(release / 'apps'), str(app)])
    web = HOME / 'web/releases' / current
    web.mkdir(exist_ok=True)
    (web / 'index.html').write_text(f'<!doctype html><title>proof {current}</title>\n')
    command(['chown', '-R', 'orbit:caddy', str(web)])
    link(HOME / 'web/current', Path('releases') / current)
    link(HOME / 'orbit', Path('releases') / current)
    # The Gateway now names its application by the stable link, as production does; Caddy serves through it.
    env = SOURCE / 'apps/gateway/.env'
    lines = [line for line in env.read_text().splitlines() if not line.startswith(('ORBIT_GATEWAY_CHECKOUT=', 'ORBIT_GATEWAY_WEB='))]
    env.write_text('\n'.join([*lines, f'ORBIT_GATEWAY_CHECKOUT={HOME}/orbit/apps/gateway', f'ORBIT_GATEWAY_WEB={HOME}/web']) + '\n')
    rendered = json.loads(orbit('php', str(SOURCE / 'tools/swamp-proof-guest/gateway.php'), 'caddyfile', cwd=SOURCE / 'apps/gateway',
                                input=json.dumps({'node': gateway_node})).splitlines()[-1])
    if rendered['problems']:
        raise RuntimeError('the Gateway Caddyfile has problems: ' + '; '.join(rendered['problems']))
    Path('/etc/caddy/Caddyfile').write_text(rendered['content'])
    command(['caddy', 'validate', '--config', '/etc/caddy/Caddyfile', '--adapter', 'caddyfile'])
    command(['systemctl', 'restart', 'caddy', 'php8.5-fpm'])
    return staged


def setup(gateway_node):
    STATE.mkdir(parents=True, mode=0o700, exist_ok=True)
    # The orbit account builds the target commit and its origin; this root fixture reads them.
    os.chown(STATE, account.pw_uid, account.pw_gid)
    if (STATE / 'pid').exists():
        try:
            os.kill(int((STATE / 'pid').read_text()), signal.SIGTERM)
        except ProcessLookupError:
            pass
        time.sleep(.2)
    hosts = Path('/etc/hosts')
    hosts.write_text('\n'.join(line for line in hosts.read_text().splitlines() if not line.startswith(LISTEN + ' ')) + '\n')
    tag = pinned_assets()
    staged = layout(gateway_node)
    # The serve checkout, its loopback API and grants paths, exactly as the load proof prepares them. After the layout:
    # its Caddy site joins the Caddyfile the layout rendered.
    command(['python3', '-I', str(SOURCE / 'swamp/proof/gateway.swamp_load/prepare.py')], cwd=SOURCE)
    work = STATE / 'work'
    shutil.rmtree(work, ignore_errors=True)
    orbit('git', 'clone', '--quiet', str(SOURCE), str(work))
    orbit('git', '-C', str(work), '-c', 'user.name=Proof', '-c', 'user.email=proof@example.invalid', 'commit', '--quiet', '--allow-empty', '-m', 'Proof release')
    target = orbit('git', '-C', str(work), 'rev-parse', 'HEAD')
    shutil.rmtree(STATE / 'git', ignore_errors=True)
    orbit('git', 'init', '--quiet', '--bare', '--initial-branch=main', str(ORIGIN))
    orbit('git', '-C', str(work), 'push', '--quiet', str(ORIGIN), 'HEAD:refs/heads/main')
    # The serve checkout's origin is the load proof's local mirror; it needs the target commit too.
    mirror = SOURCE.parent / 'release-origin.git'
    orbit('git', '-C', str(work), 'push', '--quiet', str(mirror), 'HEAD:refs/heads/release')
    digest, size = web_build(target)
    tls = STATE / 'tls'
    tls.mkdir(exist_ok=True)
    ca = HOME / '.orbit/ca'
    command(['openssl', 'req', '-new', '-newkey', 'rsa:2048', '-nodes', '-subj', '/CN=github.com', '-keyout', str(tls / 'key.pem'), '-out', str(tls / 'request.pem')])
    (tls / 'extensions').write_text('subjectAltName=DNS:github.com,DNS:api.github.com\nkeyUsage=digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n')
    command(['openssl', 'x509', '-req', '-in', str(tls / 'request.pem'), '-CA', str(ca / 'root.pem'), '-CAkey', str(ca / 'root.key'), '-CAcreateserial', '-days', '1',
             '-extfile', str(tls / 'extensions'), '-out', str(tls / 'cert.pem')])
    hosts.write_text(hosts.read_text() + f'{LISTEN} github.com api.github.com\n')
    DATA.write_text(json.dumps({'staged': staged, 'target': target, 'tag': tag, 'digest': digest, 'size': size, 'token': None, 'calls': [], 'minted': []}))
    DATA.chmod(0o600)
    (STATE / 'ready').unlink(missing_ok=True)
    with (STATE / 'server.log').open('w') as log:
        process = subprocess.Popen(['python3', '-I', str(Path(__file__).resolve()), 'serve'], stdin=subprocess.DEVNULL, stdout=log, stderr=log, start_new_session=True)
    (STATE / 'pid').write_text(str(process.pid))
    for _ in range(100):
        if (STATE / 'ready').exists():
            break
        time.sleep(.05)
    assert (STATE / 'ready').exists(), 'the GitHub proof server did not bind'
    print(json.dumps({'bindings': {'target': target, 'staged': staged}}))


def serve():
    lock = threading.Lock()

    class Handler(BaseHTTPRequestHandler):
        def log_message(self, *_args):
            pass

        def do_GET(self):
            self.handle_request()

        def do_POST(self):
            self.handle_request()

        def do_DELETE(self):
            self.handle_request()

        def handle_request(self):
            with lock:
                data = json.loads(DATA.read_text())
                path = urlsplit(self.path)
                raw = self.rfile.read(int(self.headers.get('Content-Length', 0)))
                data['calls'].append([self.command, self.headers.get('Host'), path.path])
                DATA.write_text(json.dumps(data))
                scheme, _, credential = self.headers.get('Authorization', '').partition(' ')
                if self.headers.get('Host') == 'github.com':
                    prefix = '/hardimpactdev/orbit/'
                    if path.path.startswith(prefix + 'raw/'):
                        sha, _, file = path.path[len(prefix + 'raw/'):].partition('/')
                        shown = subprocess.run(['git', '--git-dir', str(ORIGIN), 'show', f'{sha}:{file}'], capture_output=True, timeout=30, check=False,
                                           env={**os.environ, **TRUSTED})
                        return self.send(shown.stdout, 200 if shown.returncode == 0 else 404, 'text/plain')
                    if path.path.startswith(prefix + 'releases/download/'):
                        asset = ASSETS / path.path[len(prefix + 'releases/download/'):]
                        return self.send(asset.read_bytes(), 200, 'application/octet-stream') if asset.is_file() else self.send(b'', 404, 'text/plain')
                    expected = base64.b64encode(('x-access-token:' + str(data['token'])).encode()).decode()
                    if scheme.lower() != 'basic' or data['token'] is None or credential != expected:
                        self.send_response(401)
                        self.send_header('WWW-Authenticate', 'Basic realm=proof')
                        self.send_header('Content-Length', '0')
                        self.end_headers()
                        return None
                    env = {**os.environ, **TRUSTED, 'GIT_PROJECT_ROOT': str(STATE / 'git'), 'GIT_HTTP_EXPORT_ALL': '1', 'PATH_INFO': path.path,
                           'QUERY_STRING': path.query, 'REQUEST_METHOD': self.command, 'CONTENT_TYPE': self.headers.get('Content-Type', ''),
                           'CONTENT_LENGTH': str(len(raw)), 'REMOTE_USER': 'proof', 'REMOTE_ADDR': '127.0.0.1'}
                    reply = subprocess.run(['/usr/lib/git-core/git-http-backend'], input=raw, capture_output=True, env=env, timeout=120, check=True).stdout
                    headers, body = reply.split(b'\r\n\r\n', 1)
                    self.send_response(200)
                    self.send_header('Content-Length', str(len(body)))
                    for header in headers.decode().split('\r\n'):
                        name, value = header.split(':', 1)
                        self.send_header(name, value.strip())
                    self.end_headers()
                    self.wfile.write(body)
                    return None
                assert self.headers.get('Host') == 'api.github.com'
                repository = '/repos/hardimpactdev/orbit'
                if path.path == repository + '/installation':
                    return self.respond({'id': 123})
                if path.path == '/app/installations/123/access_tokens':
                    token = 'proof-' + secrets.token_urlsafe(24)
                    permissions = json.loads(raw)['permissions']
                    # The newest minted token is the run's: the release fetch and download must use it.
                    data['token'] = token
                    data['minted'].append(permissions)
                    DATA.write_text(json.dumps(data))
                    return self.respond({'token': token, 'permissions': {**permissions, 'metadata': 'read'},
                                         'expires_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime(time.time() + 3500))}, 201)
                if scheme.lower() not in ('token', 'bearer') or credential != data['token']:
                    return self.respond({'message': 'Bad credentials'}, 401)
                if path.path == '/installation/repositories':
                    return self.respond({'total_count': 1, 'repositories': [{'full_name': 'hardimpactdev/orbit'}]})
                if path.path == '/installation/token':
                    return self.respond(None, 204)
                run = {'id': RUN, 'event': 'push', 'head_branch': 'main', 'path': '.github/workflows/ci.yml', 'head_sha': data['target'],
                       'repository': {'id': 1, 'full_name': 'hardimpactdev/orbit'}, 'head_repository': {'id': 1, 'full_name': 'hardimpactdev/orbit'}}
                if path.path == repository + '/actions/artifacts' and parse_qs(path.query).get('name') == ['web-dist-' + data['target']]:
                    return self.respond({'total_count': 1, 'artifacts': [{
                        'id': ARTIFACT, 'name': 'web-dist-' + data['target'], 'size_in_bytes': data['size'], 'digest': 'sha256:' + data['digest'],
                        'expired': False, 'created_at': '2026-11-03T18:00:00Z',
                        'workflow_run': {'id': RUN, 'repository_id': 1, 'head_repository_id': 1, 'head_branch': 'main', 'head_sha': data['target']}}]})
                if path.path == f'{repository}/actions/runs/{RUN}':
                    return self.respond(run)
                if path.path == f'{repository}/actions/artifacts/{ARTIFACT}/zip':
                    return self.send((STATE / 'web.zip').read_bytes(), 200, 'application/zip')
                return self.respond({'message': 'Unexpected proof request ' + path.path}, 404)

        def respond(self, value, status=200):
            return self.send(json.dumps(value).encode() if value is not None else b'', status, 'application/json')

        def send(self, body, status, kind):
            self.send_response(status)
            self.send_header('Content-Type', kind)
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)

    server = ThreadingHTTPServer((LISTEN, 443), Handler)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(STATE / 'tls/cert.pem', STATE / 'tls/key.pem')
    server.socket = context.wrap_socket(server.socket, server_side=True)
    (STATE / 'ready').touch()
    server.serve_forever()


def release_github():
    """github.com resolves publicly again, for the topology's own downloads such as Caddy."""
    hosts = Path('/etc/hosts')
    hosts.write_text('\n'.join(line for line in hosts.read_text().splitlines() if not line.startswith(LISTEN + ' ')) + '\n')
    # The nested Nodes resolve through the proof network's dnsmasq, which caches this file's names.
    subprocess.run(['pkill', '-HUP', 'dnsmasq'], check=False)


def parity():
    release_github()
    data = json.loads(DATA.read_text())
    operation = json.loads(Path(os.environ['PROOF_OPERATION_FILE']).read_text())
    target = data['target']
    assert operation['status'] == 'succeeded', operation
    assert operation['result'] == {'sha': target, 'previous': data['staged'][:12], 'snapshot': operation['result']['snapshot']}, operation['result']
    assert os.readlink(HOME / 'orbit') == 'releases/' + target[:12]
    assert (HOME / 'releases' / target[:12] / 'REVISION').read_text().strip() == target
    assert os.readlink(HOME / 'web/current') == 'releases/' + target[:12]
    assert (HOME / 'web/releases' / target[:12] / 'version.json').is_file()
    assert Path(operation['result']['snapshot']).is_file()
    status = json.loads(command(['curl', '-fsS', '--cacert', str(HOME / '.orbit/ca/root.pem'), 'https://gateway.orbit/api/v1/gateway/status']))
    assert (status.get('data') or status)['version'] == target, status
    # One Actions-only token selected the web build; the run's own token read contents and Actions.
    assert data['minted'][0] == {'actions': 'read'} and data['minted'][-1] == {'contents': 'read', 'actions': 'read'}, data['minted']
    fetched = [call for call in data['calls'] if call[1] == 'github.com' and 'git-upload-pack' in call[2]]
    downloaded = [call for call in data['calls'] if call[2].endswith(f'/actions/artifacts/{ARTIFACT}/zip')]
    assert fetched and downloaded, data['calls']
    print(f'Release {target[:12]} is current; status reports it; the run fetched the commit and the web build with its own token.')


if __name__ == '__main__':
    {'setup': lambda: setup(int(sys.argv[2])), 'serve': serve, 'parity': parity}[sys.argv[1]]()
