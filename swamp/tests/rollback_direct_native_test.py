"""Real native rollback and HTTPS boundary, with no host or production effects."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from pathlib import Path
import shutil
import ssl
import subprocess
import threading
import unittest
import uuid

from native_harness import NativeTestCase

ROOT = Path(__file__).resolve().parents[1]


class RollbackDirectNativeTest(NativeTestCase):
    native_timeout = 90

    def test_retained_release_current_release_and_post_activation_cancellation(self):
        self.copy_behaviors('automatic-deploy', 'operations/_lib',
                            'operations/instance-deploy', 'operations/tools',
                            'operations/php', 'operations/processes-schedules')
        for family, name in [('operations/node', 'system_command.ts'), ('node-worker', 'command.ts')]:
            destination = self.repo / 'extensions/behaviors' / family
            destination.mkdir(parents=True, exist_ok=True)
            shutil.copy(ROOT / 'extensions/behaviors' / family / name, destination)
        for path in (self.repo / 'extensions').rglob('*_test.ts'):
            path.unlink()
        target = self.repo / 'extensions/behaviors/fixture'
        target.mkdir()
        source = (ROOT / 'tests/fixtures/rollback-direct/mod.ts').read_text()
        (target / 'mod.ts').write_text(source.replace('../../../extensions/behaviors/', '../'))
        self.secrets = ('never-read-private-rollback-' + uuid.uuid4().hex,)
        home = '/home/orbit-app-102'
        release = {'name': 'retained', 'commit': 'a' * 40}
        previous = {'name': 'previous', 'commit': 'b' * 40}
        safe = {
            'instance_id': 101, 'project_id': 102, 'node_id': 9,
            'platform': 'linux', 'architecture': 'x86_64', 'commit': None,
            'release': release['name'],
            'deployment': {'variant': 'rollback', 'home': home, 'user': 'orbit-app-102',
                           'repository': 'https://example.test/proof.git', 'branch': 'main',
                           'node_user': 'orbit', 'protocols': ['https'],
                           'relative_root': 'public', 'application_suffix': '',
                           'served': [], 'shared_paths': []},
            'php_input': None,
            'runtime_definitions': {'processes': [], 'schedules': [], 'restart': [
                {'process_id': 14, 'runtime': 'systemd', 'name': 'orbit-process-14-worker.service',
                 'on_demand': False, 'directory': home + '/current', 'vite': None}]},
        }
        state = {'status': 'running', 'posts': [], 'paths': [], 'run_id': None,
                 'cancel_requested': False, 'cancel_after_activation': False, 'effects': []}

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_):
                pass

            def reply(self, data):
                body = json.dumps({'data': data}).encode()
                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Content-Length', str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def do_GET(self):
                state['paths'].append(self.path)
                base, separator, run_id = self.path.partition('?run_id=')
                if (not separator or base != '/api/v1/direct-operations/100'
                        or (state['run_id'] is not None and run_id != state['run_id'])):
                    self.send_error(409)
                    return
                state['run_id'] = run_id
                self.reply({'id': 100, 'kind': 'instance.rollback', 'node_id': 9,
                            'status': state['status'], 'input': safe, 'result': None,
                            'cancel_requested': state['cancel_requested']})

            def do_POST(self):
                state['paths'].append(self.path)
                if self.path.startswith('/fixture/'):
                    name = self.path.removeprefix('/fixture/')
                    if name not in ('inspect-current', 'inspect-retained', 'activated',
                                    'php-refresh', 'process-restart'):
                        self.send_error(400)
                        return
                    state['effects'].append(name)
                    if name == 'activated':
                        state['cancel_requested'] = state['cancel_after_activation']
                    self.reply({})
                    return
                body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                if (self.path != '/api/v1/direct-operations/100/result'
                        or body['run_id'] != state['run_id']):
                    self.send_error(409)
                    return
                state['posts'].append(body)
                state['status'] = body['status']
                self.reply({})

        cert = self.repo / 'fixture.crt'
        key = self.repo / 'fixture.key'
        ca = self.repo / 'fixture-ca.crt'
        ca_key = self.repo / 'fixture-ca.key'
        csr = self.repo / 'fixture.csr'
        extensions = self.repo / 'fixture.ext'
        extensions.write_text('subjectAltName=IP:127.0.0.1\nbasicConstraints=critical,CA:FALSE\nextendedKeyUsage=serverAuth\n')
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
                        '-keyout', str(ca_key), '-out', str(ca), '-days', '1',
                        '-subj', '/CN=FixtureCA', '-addext', 'basicConstraints=critical,CA:TRUE'],
                       check=True, capture_output=True)
        subprocess.run(['openssl', 'req', '-new', '-newkey', 'rsa:2048', '-nodes',
                        '-keyout', str(key), '-out', str(csr), '-subj', '/CN=localhost'],
                       check=True, capture_output=True)
        subprocess.run(['openssl', 'x509', '-req', '-in', str(csr), '-CA', str(ca),
                        '-CAkey', str(ca_key), '-set_serial', '1', '-days', '1',
                        '-out', str(cert), '-extfile', str(extensions)],
                       check=True, capture_output=True)
        server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        tls.load_cert_chain(cert, key)
        server.socket = tls.wrap_socket(server.socket, server_side=True)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(thread.join)
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        self.native_env.update(ORBIT_ROLLBACK_SENTINEL=self.secrets[0],
                               ORBIT_ROLLBACK_TEST_URL=f'https://127.0.0.1:{server.server_port}',
                               ORBIT_ROLLBACK_TEST_CA=str(ca), DENO_CERT=str(ca),
                               NODE_EXTRA_CA_CERTS=str(ca), SSL_CERT_FILE=str(ca))
        created = json.loads(self.cli('workflow', 'create', 'rollback-direct-proof').stdout)
        workflow = {'id': created['id'], 'name': 'rollback-direct-proof', 'vaults': [],
                    'jobs': [{'name': 'rollback', 'writes': True, 'steps': [{'name': 'rollback', 'task': {
                        'type': 'behavior', 'behaviorType': '@fixture/rollback-direct',
                        'behaviorName': 'rollback-direct-proof', 'methodName': 'rollback',
                        'inputs': {'operation_id': 100, 'node_id': 9, 'kind': 'instance.rollback',
                                   'run_id': '${{ run.id }}'},
                    }}]}]}
        Path(created['path']).write_text(json.dumps(workflow))
        self.cli('workflow', 'validate', 'rollback-direct-proof')
        for cancel, current in ((False, False), (True, False), (False, True)):
            with self.subTest(cancel_after_activation=cancel, current_release=current):
                state.update(status='running', posts=[], paths=[], run_id=None,
                             cancel_requested=False, cancel_after_activation=cancel, effects=[])
                self.native_env['ORBIT_ROLLBACK_CURRENT'] = str(current).lower()
                self.cli('workflow', 'run', 'rollback-direct-proof')
                self.assertEqual(state['status'], 'succeeded')
                self.assertEqual([post['status'] for post in state['posts']], ['running', 'succeeded'])
                final = state['posts'][-1]['result']
                self.assertEqual(final['release'], release)
                self.assertEqual(final['selected_release'], release)
                self.assertEqual(final['previous'], release if current else previous)
                self.assertEqual(final['commit'], release['commit'])
                self.assertTrue(final['selection_confirmed'])
                self.assertIsNone(final['failed_step'])
                self.assertEqual(final['runtime_definitions'], {
                    'processes': [], 'schedules': [],
                    'restart': [{'process_id': 14, 'ready': True, 'state': 'active'}]})
                self.assertEqual(state['effects'], ['inspect-current', 'inspect-retained',
                                                   'activated', 'php-refresh', 'process-restart'])
                self.assertEqual(state['cancel_requested'], cancel)
                self.assertFalse(any('private-material' in path or 'children' in path
                                     or 'operation-executor' in path or 'deploy-steps' in path
                                     for path in state['paths']))
                self.assert_private_absent(json.dumps(state['posts']))


if __name__ == '__main__':
    unittest.main()
