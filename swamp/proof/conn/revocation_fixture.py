"""Disposable T3 API contract fixture and independent Gateway parity; proof VMs only."""
import json
import os
import secrets
import subprocess
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

SOURCE = Path(__file__).resolve().parents[3]
STATE = Path('/home/orbit/.orbit/swamp-proof/conn-revocation')
DATA = STATE / 'server.json'


def serve():
    lock = threading.Lock()

    class Handler(BaseHTTPRequestHandler):
        def log_message(self, *_args):
            pass

        def reply(self, status, value):
            self.send_response(status)
            self.end_headers()
            self.wfile.write(value if isinstance(value, bytes) else json.dumps(value).encode())

        def do_GET(self):
            with lock:
                data = json.loads(DATA.read_text())
                if self.headers.get('Authorization') != 'Bearer ' + data['admin']:
                    return self.reply(401, {'private': data['private']})
                if self.path != '/api/auth/clients':
                    return self.reply(404, {})
                data['reads'] += 1
                DATA.write_text(json.dumps(data))
                self.reply(200, b'invalid-json' if data['case'] == 'invalid-list' else data['sessions'])

        def do_POST(self):
            with lock:
                data = json.loads(DATA.read_text())
                if self.headers.get('Authorization') != 'Bearer ' + data['admin']:
                    return self.reply(401, {'private': data['private']})
                body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                if self.path == '/api/auth/clients/revoke':
                    identity = body['sessionId']
                    assert identity not in ['gateway', 'foreign']
                    found = any(row['sessionId'] == identity for row in data['sessions'])
                    data['sessions'] = [row for row in data['sessions'] if row['sessionId'] != identity]
                    data['revoked_sessions'].append(identity)
                elif self.path == '/api/auth/pairing-links/revoke':
                    identity = body['id']
                    assert identity in ['first-link', 'second-link']
                    if data['case'] == 'partial' and identity == 'second-link':
                        return self.reply(500, {'private': data['private']})
                    data['revoked_links'].append(identity)
                    found = True
                else:
                    return self.reply(404, {})
                DATA.write_text(json.dumps(data))
                self.reply(200, {'revoked': found, 'private': data['private']})

    server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
    (STATE / 'port').write_text(str(server.server_port))
    server.serve_forever()


def setup(case):
    up = json.loads((STATE.parent / 'up.json').read_text())
    STATE.mkdir(mode=0o700, exist_ok=True)
    private = secrets.token_urlsafe(32)
    admin = secrets.token_urlsafe(32)
    data = {'case': case, 'admin': admin, 'private': private, 'reads': 0, 'revoked_sessions': [], 'revoked_links': [], 'sessions': [
        {'sessionId': 'first-session', 'client': {'label': 'first'}, 'private': private},
        {'sessionId': 'second-session', 'client': {'label': 'second'}, 'private': private},
        {'sessionId': 'gateway', 'current': True, 'client': {'label': 'first'}, 'private': private},
        {'sessionId': 'foreign', 'client': {'label': 'foreign'}, 'private': private},
    ]}
    with open(DATA, 'w', opener=lambda p, f: os.open(p, f, 0o600)) as stream:
        json.dump(data, stream)
    (STATE / 'port').unlink(missing_ok=True)
    subprocess.Popen(['python3', '-I', str(Path(__file__).resolve()), 'serve'], stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
    # Fixture-only service startup, bounded by the proof driver, never used by an operation.
    import time
    for _ in range(100):
        if (STATE / 'port').exists():
            break
        time.sleep(0.05)
    port = int((STATE / 'port').read_text())
    php = r'''require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$v = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$env = Orbit\Extensions\Conn\Models\ConnEnvironment::query()->create(['environment_id'=>'revoke-proof','label'=>'proof','node_id'=>209,'url'=>$v['url'],'admin_session'=>$v['admin'],'admin_session_expires_at'=>now()->addDay(),'registered_at'=>now()]);
foreach ([['first','first-link'],['second','second-link']] as [$label,$link]) { Orbit\Extensions\Conn\Models\ConnPairing::query()->create(['node_id'=>$v['owner'],'conn_environment_id'=>$env->id,'client_label'=>$label,'pairing_link_id'=>$link,'credential'=>$v['private']]); }
Orbit\Extensions\Conn\Models\ConnPairing::query()->create(['node_id'=>$v['other'],'conn_environment_id'=>$env->id,'client_label'=>'foreign','pairing_link_id'=>'foreign-link','credential'=>$v['private']]);'''
    subprocess.run(['runuser', '-u', 'orbit', '--', 'php', '-r', php], input=json.dumps({'url': f'http://127.0.0.1:{port}', 'admin': admin, 'private': private, 'owner': up['nodes']['operator'], 'other': up['nodes']['peer']}), cwd=SOURCE / 'apps/gateway', text=True, capture_output=True, timeout=30, check=True)
    print('{}')


def parity():
    data = json.loads(DATA.read_text())
    operation = json.loads(Path(os.environ['PROOF_OPERATION_FILE']).read_text())
    case = data['case']
    assert operation['kind'] == 'conn.pairing_revoke'
    assert operation['status'] == ('succeeded' if case == 'success' else 'failed')
    assert not list(Path('/tmp').glob('orbit-conn-revoke.*'))
    assert data['reads'] == 1
    if case == 'invalid-list':
        assert not data['revoked_sessions'] and not data['revoked_links']
    else:
        assert sorted(data['revoked_sessions']) == ['first-session', 'second-session']
        assert sorted(data['revoked_links']) == (['first-link', 'second-link'] if case == 'success' else ['first-link'])
        assert {row['sessionId'] for row in data['sessions']} == {'gateway', 'foreign'}
    php = r'''require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo Orbit\Extensions\Conn\Models\ConnPairing::query()->orderBy('id')->get()->map(fn ($p)=>['label'=>$p->client_label,'revoked'=>$p->revoked_at !== null,'credential'=>$p->credential !== null])->toJson();'''
    reply = subprocess.run(['runuser', '-u', 'orbit', '--', 'php', '-r', php], cwd=SOURCE / 'apps/gateway', text=True, capture_output=True, timeout=30, check=True)
    rows = json.loads(reply.stdout)
    for row in rows:
        expected = row['label'] in (['first', 'second'] if case == 'success' else ['first'] if case == 'partial' else [])
        assert row['revoked'] is expected and row['credential'] is not expected
    history = json.dumps(operation)
    assert data['admin'] not in history and data['private'] not in history
    if case == 'success':
        assert operation['result'] == {'backend': 'revoked', 'revoked_sessions': 2}
    print('Exact sessions and links checked; Gateway partial state, current admin, foreign pairing and private-cache cleanup verified.')


if __name__ == '__main__':
    if sys.argv[1] == 'serve':
        serve()
    elif sys.argv[1] == 'setup':
        setup(sys.argv[2])
    else:
        parity()
