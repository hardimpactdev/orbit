"""Proof-only HTTPS GitHub contract and real Git smart HTTP; no external repository writes."""
import base64
import json
import os
import secrets
import signal
import ssl
import subprocess
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlsplit

SOURCE = Path(__file__).resolve().parents[3]
STATE = Path('/home/orbit/.orbit/swamp-proof/task-publication')
DATA = STATE / 'state.json'
WORKSPACE = '/home/orbit/task-publish-proof'
TITLE = 'Keep $title `literal`'
LISTEN_HOST = '127.0.0.2'
BODY = 'First line\n\nKeep $(literal) and `prose` unchanged.\n'


def command(argv, **kwargs):
    return subprocess.run(argv, capture_output=True, text=True, timeout=90, check=True, **kwargs).stdout.strip()


def target(*argv, **kwargs):
    return command(['incus', 'exec', 'target', '--', *argv], **kwargs)


def git(*argv):
    return target('runuser', '-u', 'orbit', '--', 'git', '-C', WORKSPACE, *argv)


def php(code):
    bootstrap = r"require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); "
    return command(['runuser', '-u', 'orbit', '--', 'php', '-r', bootstrap + code], cwd=SOURCE / 'apps/gateway')


def serve():
    lock = threading.Lock()

    class Handler(BaseHTTPRequestHandler):
        def log_message(self, *_args):
            pass

        def do_GET(self):
            self.handle_request()

        def do_POST(self):
            self.handle_request()

        def do_PATCH(self):
            self.handle_request()

        def do_DELETE(self):
            self.handle_request()

        def handle_request(self):
            with lock:
                data = json.loads(DATA.read_text())
                path = urlsplit(self.path)
                raw = self.rfile.read(int(self.headers.get('Content-Length', 0)))
                data['calls'].append([self.command, path.path])
                DATA.write_text(json.dumps(data))
                if self.headers.get('Host') == 'github.com':
                    expected = base64.b64encode(('x-access-token:' + data['token']).encode()).decode()
                    scheme, _, credential = self.headers.get('Authorization', '').partition(' ')
                    if scheme.lower() != 'basic' or credential != expected:
                        self.send_response(401)
                        self.send_header('WWW-Authenticate', 'Basic realm=proof')
                        self.send_header('Content-Length', '0')
                        self.end_headers()
                        return
                    env = {**os.environ, 'GIT_PROJECT_ROOT': str(STATE / 'git'), 'GIT_HTTP_EXPORT_ALL': '1',
                           'PATH_INFO': path.path, 'QUERY_STRING': path.query, 'REQUEST_METHOD': self.command,
                           'CONTENT_TYPE': self.headers.get('Content-Type', ''), 'CONTENT_LENGTH': str(len(raw)),
                           'REMOTE_USER': 'proof', 'REMOTE_ADDR': '127.0.0.1'}
                    reply = subprocess.run(['/usr/lib/git-core/git-http-backend'], input=raw, capture_output=True, env=env, timeout=60, check=True).stdout
                    headers, body = reply.split(b'\r\n\r\n', 1)
                    self.send_response(200)
                    self.send_header('Content-Length', str(len(body)))
                    for header in headers.decode().split('\r\n'):
                        name, value = header.split(':', 1)
                        self.send_header(name, value.strip())
                    self.end_headers()
                    self.wfile.write(body)
                    return
                assert self.headers.get('Host') == 'api.github.com'
                if path.path == '/repos/hardimpactdev/orbit/installation':
                    assert self.headers.get('Authorization', '').startswith('Bearer ')
                    self.respond({'id': 123})
                elif path.path == '/app/installations/123/access_tokens':
                    assert json.loads(raw) == {'repositories': ['orbit'], 'permissions': data.get('permissions', {'contents': 'write', 'pull_requests': 'write', 'workflows': 'write'})}
                    data['minted'] += 1
                    DATA.write_text(json.dumps(data))
                    self.respond({'token': data['token'], 'permissions': data.get('permissions', {}), 'expires_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime(time.time() + 3500))}, 201)
                else:
                    scheme, _, credential = self.headers.get('Authorization', '').partition(' ')
                    assert scheme.lower() in ('token', 'bearer') and credential == data['token']
                    if path.path == '/installation/repositories':
                        self.respond({'total_count': 1, 'repositories': [{'full_name': 'hardimpactdev/orbit'}]})
                    elif path.path == '/installation/token':
                        data['revoked'] += 1
                        DATA.write_text(json.dumps(data))
                        self.respond(None, 204)
                    elif self.command == 'GET' and path.path == '/repos/hardimpactdev/orbit/pulls':
                        self.respond(data['pulls'])
                    elif self.command in ('POST', 'PATCH') and path.path.startswith('/repos/hardimpactdev/orbit/pulls'):
                        body = json.loads(raw)
                        assert body['title'] == TITLE and body['body'] == BODY
                        data['prose'] = body
                        data['pulls'] = [{'number': 57, 'state': 'open', 'base': {'ref': 'main'}, 'head': {'ref': data['branch'], 'repo': {'full_name': 'hardimpactdev/orbit'}}}]
                        DATA.write_text(json.dumps(data))
                        self.respond({'number': 57}, 201 if self.command == 'POST' else 200)
                    else:
                        self.respond({'message': 'Unexpected proof request'}, 404)

        def respond(self, value, status=200):
            body = json.dumps(value).encode() if value is not None else b''
            self.send_response(status)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)

    server = ThreadingHTTPServer((LISTEN_HOST, 443), Handler)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(STATE / 'tls/cert.pem', STATE / 'tls/key.pem')
    server.socket = context.wrap_socket(server.socket, server_side=True)
    (STATE / 'ready').touch()
    server.serve_forever()


def setup(case, node, run_id):
    STATE.mkdir(mode=0o700, exist_ok=True)
    if (STATE / 'pid').exists():
        try:
            os.kill(int((STATE / 'pid').read_text()), signal.SIGTERM)
        except ProcessLookupError:
            pass
        time.sleep(.2)
    if not Path('/usr/bin/gh').exists():
        command(['apt-get', 'update'])
        command(['apt-get', 'install', '-y', 'gh'])
    tls = STATE / 'tls'
    tls.mkdir(exist_ok=True)
    ca = Path('/home/orbit/.orbit/ca')
    command(['openssl', 'req', '-new', '-newkey', 'rsa:2048', '-nodes', '-subj', '/CN=github.com', '-keyout', str(tls / 'key.pem'), '-out', str(tls / 'request.pem')])
    (tls / 'extensions').write_text('subjectAltName=DNS:github.com,DNS:api.github.com\nkeyUsage=digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n')
    command(['openssl', 'x509', '-req', '-in', str(tls / 'request.pem'), '-CA', str(ca / 'root.pem'), '-CAkey', str(ca / 'root.key'), '-CAcreateserial', '-days', '1', '-extfile', str(tls / 'extensions'), '-out', str(tls / 'cert.pem')])
    hosts = Path('/etc/hosts')
    hosts.write_text('\n'.join(line for line in hosts.read_text().splitlines() if not line.startswith('127.0.0.2 ')) + '\n127.0.0.2 github.com api.github.com\n')
    target('rm', '-rf', '--', WORKSPACE)
    target('install', '-d', '-o', 'orbit', '-g', 'orbit', WORKSPACE)
    git('init', '-qb', 'proof')
    git('config', 'user.email', 'proof@example.invalid')
    git('config', 'user.name', 'Proof')
    target('runuser', '-u', 'orbit', '--', 'tee', WORKSPACE + '/file', input='original\n')
    git('add', '.')
    git('commit', '-qm', 'Initial')
    head, tree, index = git('rev-parse', 'HEAD'), git('write-tree'), target('sha256sum', WORKSPACE + '/.git/index').split()[0]
    bindings = json.loads(command(['runuser', '-u', 'orbit', '--', 'php', str(SOURCE / 'swamp/proof/tasks.publish/prepare.php'), node, head, tree, run_id], cwd=SOURCE / 'apps/gateway').splitlines()[-1])
    remote = STATE / 'git/hardimpactdev/orbit.git'
    command(['rm', '-rf', '--', str(STATE / 'git')])
    command(['git', 'init', '--quiet', '--bare', str(remote)])
    command(['git', '-C', str(remote), 'config', 'http.receivepack', 'true'])
    data = {'case': case, 'token': secrets.token_urlsafe(32), 'branch': 'task-' + str(bindings['secrets']['group']),
            'head': head, 'tree': tree, 'index': index, 'group': bindings['secrets']['group'], 'calls': [], 'pulls': [], 'minted': 0, 'revoked': 0}
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
    assert (STATE / 'ready').exists(), 'GitHub proof server did not bind'
    if case == 'moved':
        target('runuser', '-u', 'orbit', '--', 'tee', WORKSPACE + '/file', input='moved\n')
    print(json.dumps(bindings))


def parity():
    data = json.loads(DATA.read_text())
    operation = json.loads(Path(os.environ['PROOF_OPERATION_FILE']).read_text())
    outputs = Path(os.environ['PROOF_OUTPUTS']).read_text()
    assert data['token'] not in json.dumps(operation) + outputs
    encoded = base64.b64encode(('x-access-token:' + data['token']).encode()).decode()
    assert encoded not in json.dumps(operation) + outputs
    assert data['minted'] == data['revoked'] == 1, 'token lifecycle did not complete'
    assert not list(Path('/tmp').glob('orbit-task-publish-*'))
    assert not target('find', '/tmp', '-maxdepth', '1', '-name', 'orbit-task-publish-*')
    assert target('sha256sum', WORKSPACE + '/.git/index').split()[0] == data['index']
    assert data['token'] not in target('cat', WORKSPACE + '/.git/config')
    state = json.loads(php('echo json_encode(App\\Models\\Task::topLevel()->findOrFail(' + str(data['group']) + ')->only(["status","pr_url","watched_pr_number","watched_pr_url"]));'))
    assert state['status'] == 'running'
    if data['case'] == 'moved':
        assert operation['status'] == 'failed' and operation['error_code'] == 'tasks.head_moved', operation
        assert not any('/hardimpactdev/orbit.git/' in path for _, path in data['calls'])
        assert state['pr_url'] is None
        print('Moved workspace refused before export/push; token revoked; both scratch locations removed; real index unchanged.')
        return
    assert operation['status'] == 'succeeded', operation
    remote = STATE / 'git/hardimpactdev/orbit.git'
    assert command(['git', '-C', str(remote), 'rev-parse', 'refs/heads/' + data['branch']]) == data['head']
    assert operation['result']['branch'] == data['branch'] and operation['result']['commit'] == data['head']
    if data['case'] == 'push-only':
        assert operation['result']['pull_request_number'] is None and state['pr_url'] is None
        assert not any('/pulls' in path for _, path in data['calls'])
    else:
        assert operation['result']['pull_request_number'] == state['watched_pr_number'] == 57
        assert state['pr_url'] == state['watched_pr_url'] == 'https://github.com/hardimpactdev/orbit/pull/57'
        assert data['prose']['title'] == TITLE and data['prose']['body'] == BODY
    print('Real pinned SSH, unchanged real index, exact checked Git tip over TLS, unchanged PR prose/watch state, token revoked, both scratch locations removed, public outputs contain no token.')


if __name__ == '__main__':
    if sys.argv[1] == 'serve':
        serve()
    elif sys.argv[1] == 'setup':
        setup(*sys.argv[2:])
    else:
        parity()
