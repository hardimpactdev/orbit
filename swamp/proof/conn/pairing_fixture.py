"""A disposable T3 pairing API and one-time Gateway delivery parity, on proof VMs only."""
import json
import os
import secrets
import subprocess
import sys
import threading
from datetime import UTC, datetime, timedelta
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

SOURCE = Path(__file__).resolve().parents[3]
STATE = Path('/home/orbit/.orbit/swamp-proof/conn-pairing')
DATA = STATE / 'server.json'


def php(code, data=None):
    bootstrap = r"require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); "
    return subprocess.run(['runuser', '-u', 'orbit', '--', 'php', '-r', bootstrap + code], input=json.dumps(data) if data is not None else None, cwd=SOURCE / 'apps/gateway', text=True, capture_output=True, timeout=30, check=True).stdout


def serve():
    lock = threading.Lock()

    class Handler(BaseHTTPRequestHandler):
        def log_message(self, *_args):
            pass

        def do_POST(self):
            with lock:
                data = json.loads(DATA.read_text())
                body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                assert self.path == '/api/auth/pairing-token'
                assert self.headers.get('Authorization') == 'Bearer ' + data['admin']
                assert body['label'] and set(body['scopes']) == {'orchestration:read', 'orchestration:operate', 'settings:write', 'providers:manage', 'environment:maintain', 'preview:operate', 'diagnostics:read', 'terminal:read', 'terminal:operate', 'source-control:write', 'filesystem:read', 'filesystem:write', 'relay:read', 'access:read', 'access:write', 'relay:write'}
                data['calls'] += 1
                data['label'] = body['label']
                DATA.write_text(json.dumps(data))
                self.send_response(401 if data['case'] == 'rejected' else 200)
                self.end_headers()
                self.wfile.write(json.dumps({'id': 'public-link', 'credential': data['credential'], 'expiresAt': (datetime.now(UTC) + timedelta(minutes=5)).isoformat()}).encode())

    server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
    (STATE / 'port').write_text(str(server.server_port))
    server.serve_forever()


def setup(case):
    assert json.loads((STATE.parent / 'up.json').read_text())['nodes']['gateway'] == 209
    STATE.mkdir(mode=0o700, exist_ok=True)
    data = {'case': case, 'admin': secrets.token_urlsafe(32), 'credential': secrets.token_urlsafe(32), 'calls': 0}
    with open(DATA, 'w', opener=lambda p, f: os.open(p, f, 0o600)) as stream:
        json.dump(data, stream)
    (STATE / 'port').unlink(missing_ok=True)
    subprocess.Popen(['python3', '-I', str(Path(__file__).resolve()), 'serve'], stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
    import time
    for _ in range(100):
        if (STATE / 'port').exists():
            break
        time.sleep(0.05)
    data['url'] = 'http://127.0.0.1:' + (STATE / 'port').read_text()
    php(r'''$v=json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
Orbit\Extensions\Conn\Models\ConnPairing::query()->delete(); Orbit\Extensions\Conn\Models\ConnEnvironment::query()->delete();
Orbit\Extensions\Conn\Models\ConnEnvironment::query()->create(['environment_id'=>'pairing-proof','label'=>'proof','node_id'=>209,'url'=>$v['url'],'admin_session'=>$v['admin'],'admin_session_expires_at'=>now()->addDay(),'registered_at'=>now()]);''', data)
    print('{}')


def reference(value):
    if isinstance(value, dict):
        if 'credential_ref' in value:
            return value['credential_ref']
        for item in value.values():
            found = reference(item)
            if found:
                return found
    elif isinstance(value, list):
        for item in value:
            found = reference(item)
            if found:
                return found
    elif isinstance(value, str):
        try:
            parsed = json.loads(value)
        except ValueError:
            return None
        if isinstance(parsed, (dict, list)):
            return reference(parsed)
    return None


def parity():
    data = json.loads(DATA.read_text())
    operation = json.loads(Path(os.environ['PROOF_OPERATION_FILE']).read_text())
    up = json.loads((STATE.parent / 'up.json').read_text())
    assert operation['kind'] == 'conn.pairing_create' and data['calls'] == 1
    assert not list(Path('/tmp').glob('orbit-conn-pairing.*'))
    assert data['credential'] not in json.dumps(operation) and data['admin'] not in json.dumps(operation)
    if data['case'] == 'rejected':
        assert operation['status'] == 'failed' and operation['error_code'] == 'conn.session_rejected'
        assert php(r'echo Orbit\Extensions\Conn\Models\ConnPairing::query()->whereNotNull("credential")->count();') == '0'
        print('One rejected T3 call, safe error, no credential stored, private reply removed.')
        return
    assert operation['status'] == 'succeeded'
    assert operation['result']['pairing_link_id'] == 'public-link'
    ref = reference(json.loads(Path(os.environ['PROOF_OUTPUTS']).read_text()))
    assert ref and f'-pairing-{operation["target_id"]}-credential' in ref['key']
    # Inspect metadata only, never read the credential a second time.
    env = {'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': '/home/orbit', 'SWAMP_HOME': str(STATE.parent / 'home'), 'SWAMP_CONFIG_DIR': str(STATE.parent / 'config'), 'SWAMP_API_KEY_FILE': '/home/orbit/.config/swamp/club-api-key', 'SWAMP_NO_UPDATE_CHECK': '1', 'SWAMP_NO_TELEMETRY': '1'}
    inspected = subprocess.run(['/usr/sbin/runuser', '-u', 'orbit', '--', '/usr/local/bin/swamp', 'vault', 'inspect', ref['vault'], ref['key'], '--server', 'wss://swamp.orbit', '--token-file', str(STATE.parent / 'credentials/operator'), '--ca-cert', str(STATE.parent / 'tls/ca.pem'), '--repo-dir', str(SOURCE / 'swamp'), '--json'], capture_output=True, text=True, timeout=30, env=env)
    assert inspected.returncode != 0 and ('not_found' in inspected.stdout + inspected.stderr or 'not found' in inspected.stdout + inspected.stderr)
    path = 'conn/environments/pairing-proof/pairings/' + str(operation['target_id'])

    def request(caller, suffix):
        response = subprocess.run(['runuser', '-u', 'orbit', '--', 'php', str(SOURCE / 'tools/swamp-proof-guest/gateway.php'), 'request'], input=json.dumps({'method': 'GET', 'path': path, 'caller': caller, 'operation': False, 'key': 'proof-' + os.environ['PROOF_RUN'] + suffix, 'seconds': 30}), cwd=SOURCE / 'apps/gateway', capture_output=True, text=True, timeout=60, check=True)
        return json.loads(response.stdout.strip().splitlines()[-1])

    foreign = request(up['targets'][-1]['address'], '-foreign')
    assert foreign['http_status'] == 404
    first = request(up['operator_address'], '-first')
    assert first['http_status'] == 200 and first['response']['data']['pairing_url'].endswith('#token=' + data['credential'])
    second = request(up['operator_address'], '-second')
    assert second['http_status'] == 409
    assert php(r'echo Orbit\Extensions\Conn\Models\ConnPairing::query()->whereNotNull("credential")->count();') == '0'
    print('One pairing minted, its unique native key deleted, another Node refused, owner link read once, private reply removed.')


if __name__ == '__main__':
    if sys.argv[1] == 'serve':
        serve()
    elif sys.argv[1] == 'setup':
        setup(sys.argv[2])
    else:
        parity()
