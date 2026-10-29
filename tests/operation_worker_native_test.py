"""The real coordinator discovers locally; one container claims and drives the effect."""
import http.server
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import threading
import time
import unittest
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[1]
CLI = os.environ.get('SWAMP_BIN', 'swamp')
IMAGE = 'orbit-swamp-worker:20261028.205235.0-sha.1602a617'


def reply(s):
    for i, c in enumerate(s):
        if c in '{[':
            try:
                value, end = json.JSONDecoder().raw_decode(s[i:])
                if not s[i + end:].strip():
                    return value
            except ValueError:
                pass
    raise ValueError('No complete JSON')


class OperationWorkerNativeTest(unittest.TestCase):
    def test_claim_and_every_lease_write_stay_on_the_container_node(self):
        state = {'operation': {'id': 7, 'kind': 'firewall.deny', 'node_id': 2,
                 'status': 'queued', 'cancel_requested': False, 'step_results': {}, 'steps': [],
                 'input': {'node_id': 2, 'name': 'proof', 'action': 'deny',
                           'source': '192.0.2.1/32', 'protocol': 'tcp', 'port': '65431'}},
                 'calls': [], 'peer': None, 'worker': None, 'effects': 0}

        class Gateway(http.server.BaseHTTPRequestHandler):
            def log_message(self, *_):
                pass

            def do_GET(self):
                peer = self.headers['X-Proof-Peer']
                state['calls'].append(('GET', self.path, peer))
                self.respond({'kinds': [{'kind': 'firewall.deny', 'claimable': 1,
                                       'oldest_at': '2026-10-29T00:00:00Z'}]})

            def respond(self, data, status=200):
                self.send_response(status)
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(json.dumps({'data': data}).encode())

            def do_POST(self):
                body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                peer = self.headers['X-Proof-Peer']
                state['calls'].append(('POST', self.path, peer))
                op = state['operation']
                if self.path.endswith('/claim'):
                    if state['peer'] is not None and (state['peer'], state['worker']) != (peer, body['worker']):
                        return self.respond(None)
                    state.update(peer=peer, worker=body['worker'])
                    op['status'] = 'running'
                elif (state['peer'], state['worker']) != (peer, body['worker']):
                    return self.respond({'error': 'operation.lease_lost'}, 409)
                if self.path.endswith('/run'):
                    if body['input'] != {**op['input'], 'operation': 'converge'}:
                        return self.respond({'error': 'operation.execution_forbidden'}, 422)
                    state['effects'] += 1
                    return self.respond({'backend': 'active'})
                if self.path.endswith('/step') and body['status'] == 'succeeded':
                    op['step_results'][body['name']] = body['result']
                if self.path.endswith('/complete'):
                    if not op['step_results'] or state['effects'] != 1:
                        return self.respond({'error': 'operation.completion_unproven'}, 422)
                    op['status'] = body['status']
                self.respond(op)

        server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Gateway)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        with tempfile.TemporaryDirectory(prefix='operation-worker-native-') as temp:
            root = Path(temp)
            shutil.copy(ROOT / '.swamp.yaml', root)
            for folder in ('automatic-deploy', 'operation-runner', 'operations'):
                shutil.copytree(ROOT / 'extensions/behaviors' / folder, root / 'extensions/behaviors' / folder)
            fixture = root / 'extensions/behaviors/_test/http.ts'
            fixture.parent.mkdir()
            fixture.write_text('''export async function fixtureHttp(method:string,path:string,body?:unknown){
let peer="services";try{peer=await Deno.readTextFile("/worker-proof-identity")}catch(e){if(!(e instanceof Deno.errors.NotFound))throw e}
const r=await fetch("http://127.0.0.1:%d"+path,{method,headers:{"Content-Type":"application/json","X-Proof-Peer":peer},body:body===undefined?undefined:JSON.stringify(body)});
return {status:r.status,body:await r.json()};}''' % server.server_port)
            for folder, factory, relative in (('operation-runner', 'operationRunner', '../_test/http.ts'),
                                              ('operations/firewall', 'firewall', '../../_test/http.ts')):
                path = root / 'extensions/behaviors' / folder / 'mod.ts'
                path.write_text('import {fixtureHttp} from "%s";\n' % relative +
                                path.read_text().replace('...%s()' % factory, '...%s(() => fixtureHttp)' % factory))
            (root / 'workflows').mkdir()
            for path in ROOT.glob('workflows/workflow-operation-*.yaml'):
                shutil.copy(path, root / 'workflows' / path.name)
            env = {**os.environ, 'SWAMP_NO_UPDATE_CHECK': '1', 'SWAMP_NO_TELEMETRY': '1'}
            for key in ('SWAMP_SERVER_URL', 'SWAMP_SERVE_URL', 'SWAMP_SERVER_TOKEN', 'SWAMP_SERVER_TOKEN_FILE', 'SWAMP_REPO_DIR', 'SWAMP_CA_CERT'):
                env.pop(key, None)

            def cli(*args):
                r = subprocess.run([CLI, *args, '--json'], cwd=root, env=env,
                                   capture_output=True, text=True, timeout=60)
                self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
                return reply(r.stdout)

            cli('access', 'token', 'mint', 'fixture-admin', '--principal', 'user:fixture')
            admin = root / 'admin'
            admin.write_text(cli('access', 'token', 'reveal', 'fixture-admin', '--yes')['token'])
            admin.chmod(0o600)
            with socket.socket() as sock:
                sock.bind(('127.0.0.1', 0))
                port = sock.getsockname()[1]
            name = 'operation-worker-' + uuid.uuid4().hex[:10]
            serve = None
            try:
                with (root / 'serve.log').open('w') as log:
                    serve = subprocess.Popen([CLI, 'serve', '--port', str(port), '--host', '127.0.0.1',
                                              '--auth-mode', 'token', '--admins', 'user:fixture'],
                                             cwd=root, env=env, stdout=log, stderr=log)
                    for _ in range(100):
                        try:
                            urllib.request.urlopen('http://127.0.0.1:%d/ready' % port, timeout=1).close()
                            break
                        except OSError:
                            time.sleep(.1)
                    flags = ('--server', 'ws://127.0.0.1:%d' % port, '--token-file', str(admin))
                    identity = root / 'worker-proof-identity'
                    identity.write_text('worker-' + uuid.uuid4().hex)
                    token = root / 'enrollment'
                    token.write_text(cli('worker', 'token', 'create', 'fixture-worker', '--duration', '10m', *flags)['token'])
                    token.chmod(0o600)
                    cli('access', 'token', 'mint', 'fixture-worker-access', '--principal', 'worker:fixture-worker', *flags)
                    auth = root / 'worker-access'
                    auth.write_text(cli('access', 'token', 'reveal', 'fixture-worker-access', '--yes')['token'])
                    auth.chmod(0o600)
                    subprocess.run(['docker', 'run', '--detach', '--name', name, '--network=host',
                                    '--volume', str(root) + ':/fixture', '--volume', str(identity) + ':/worker-proof-identity:ro', IMAGE, 'worker', 'connect',
                                    'ws://127.0.0.1:%d' % port, '--token-file', '/fixture/enrollment',
                                    '--server-token-file', '/fixture/worker-access', '--concurrency', 'auto',
                                    '--label', 'pool=migration'], check=True, capture_output=True, timeout=30)
                    result = cli('workflow', 'run', 'operation-runner', *flags)
                    self.assertEqual(result['status'], 'succeeded')
                    self.assertEqual(state['operation']['status'], 'succeeded')
                    self.assertEqual(state['effects'], 1)
                    reads = [c for c in state['calls'] if c[0] == 'GET']
                    writes = [c for c in state['calls'] if c[0] == 'POST']
                    self.assertEqual(len(reads), 1)
                    self.assertEqual(writes[0][1], '/api/v1/operation-executor/claim')
                    self.assertEqual(len({c[2] for c in writes}), 1)
                    self.assertNotEqual(reads[0][2], writes[0][2], 'Services must never claim the worker lease')
            finally:
                subprocess.run(['docker', 'rm', '-f', name], capture_output=True, timeout=20)
                if serve:
                    serve.terminate()
                    try:
                        serve.wait(timeout=15)
                    except subprocess.TimeoutExpired:
                        serve.kill()
                        serve.wait(timeout=5)


if __name__ == '__main__':
    unittest.main()
