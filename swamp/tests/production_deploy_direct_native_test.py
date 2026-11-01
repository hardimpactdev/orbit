"""Native production parent over real HTTPS; injected ports never mutate the host."""
import hashlib
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


class ProductionDeployDirectNativeTest(NativeTestCase):
    native_timeout = 90

    def test_native_production_private_members_vp_and_post_activation_cancellation(self):
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib',
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
        source = (ROOT / 'tests/fixtures/production-deploy-direct/mod.ts').read_text()
        (target / 'mod.ts').write_text(source.replace('../../../extensions/behaviors/', '../'))
        secret = 'private-production-' + uuid.uuid4().hex
        self.secrets = (secret,)
        digest = lambda value: hashlib.sha256(value.encode()).hexdigest()
        home = '/home/orbit-app-102'
        release = {'name': 'proof-release', 'commit': 'a' * 40}
        process = {'process_id': 14, 'runtime': 'systemd',
                   'name': 'orbit-process-14-worker.service', 'desired': 'running',
                   'on_demand': False, 'directory': home + '/current', 'retired': None,
                   'content_hash': digest(secret + '-unit'), 'activation_pending': True}
        schedule_id = '9a067a27-c2ce-45c5-83f5-735e03dc5b2f'
        safe = {
            'instance_id': 101, 'project_id': 102, 'node_id': 9,
            'platform': 'linux', 'architecture': 'x86_64',
            'commit': release['commit'], 'release_name': release['name'],
            'deployment': {'variant': 'production', 'home': home, 'user': 'orbit-app-102',
                           'repository': 'https://example.test/proof.git', 'branch': 'main',
                           'node_user': 'orbit', 'protocols': ['https'], 'needs_vp': True,
                           'relative_root': 'public', 'application_suffix': '',
                           'served': [], 'shared_paths': [],
                           'steps': [{'name': phase.replace('_', '-'), 'phase': phase, 'required': True,
                                      'timeout_seconds': 30, 'command_digest': digest('printf ' + secret)}
                                     for phase in ['before_activation', 'after_activation']]},
            'environment': {'base': home, 'user': 'orbit-app-102',
                            'environment': 'production', 'domain': 'proof.test'},
            'php_input': None,
            'runtime_definitions': {
                'processes': [process],
                'schedules': [{'id': schedule_id, 'command_hash': digest(secret + '-schedule'),
                               'user': 'orbit-app-102', 'home': home,
                               'working_directory': home + '/current', 'shell': '/bin/bash',
                               'login_shell': False, 'calendar': '2099-01-01 00:00:00',
                               'timeout_seconds': 30, 'desired': 'disabled'}],
                'restart': [{'process_id': 14, 'runtime': 'systemd', 'name': process['name'],
                             'on_demand': False, 'directory': home + '/current', 'vite': None}],
            },
        }
        material = {
            'git_read': secret, 'vp_materialize': {'user': 'orbit'},
            'environment_material': {**safe['environment'], 'required_capacity': 2048,
                                     'values': {'APP_ENV': 'production', 'TOKEN': secret},
                                     'owned_keys': [], 'testing': None},
            'definitions_material': {
                'schedules': {schedule_id: {'command': secret + '-schedule'}},
            },
        }
        state = {'status': 'running', 'posts': [], 'paths': [], 'run_id': None,
                 'cancel_requested': False, 'cancel_after_activation': False,
                 'activated': False, 'private_after_activation': 0}

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
                if self.path == '/api/v1/instances/101/deploy-steps':
                    self.reply([{**step, 'command': 'printf ' + secret}
                                for step in safe['deployment']['steps']])
                    return
                base, separator, run_id = self.path.partition('?run_id=')
                if not separator or (state['run_id'] is not None and run_id != state['run_id']):
                    self.send_error(409)
                    return
                state['run_id'] = run_id
                if base == '/api/v1/direct-operations/100/private-material':
                    if state['activated']:
                        state['private_after_activation'] += 1
                    self.reply(material)
                elif base == '/api/v1/direct-operations/100':
                    self.reply({'id': 100, 'kind': 'instance.deploy', 'node_id': 9,
                                'status': state['status'], 'input': safe, 'result': None,
                                'cancel_requested': state['cancel_requested']})
                else:
                    self.send_error(404)

            def do_POST(self):
                state['paths'].append(self.path)
                if self.path == '/fixture/activated':
                    state['activated'] = True
                    state['cancel_requested'] = state['cancel_after_activation']
                    self.reply({})
                    return
                body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                if self.path != '/api/v1/direct-operations/100/result' or body['run_id'] != state['run_id']:
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
        self.native_env.update(ORBIT_DEPLOY_SENTINEL=secret,
                               ORBIT_DEPLOY_TEST_URL=f'https://127.0.0.1:{server.server_port}',
                               ORBIT_DEPLOY_TEST_CA=str(ca), DENO_CERT=str(ca),
                               NODE_EXTRA_CA_CERTS=str(ca), SSL_CERT_FILE=str(ca))
        created = json.loads(self.cli('workflow', 'create', 'production-deploy-direct-proof').stdout)
        workflow = {'id': created['id'], 'name': 'production-deploy-direct-proof', 'vaults': [],
                    'jobs': [{'name': 'production', 'steps': [{'name': 'deploy', 'task': {
                        'type': 'behavior', 'behaviorType': '@fixture/production-deploy-direct',
                        'behaviorName': 'production-deploy-direct-proof', 'methodName': 'run',
                        'inputs': {'operation_id': 100, 'node_id': 9, 'kind': 'instance.deploy',
                                   'run_id': '${{ run.id }}'},
                    }}]}]}
        Path(created['path']).write_text(json.dumps(workflow))
        self.cli('workflow', 'validate', 'production-deploy-direct-proof')
        for cancel in (False, True):
            with self.subTest(cancel_after_activation=cancel):
                state.update(status='running', posts=[], paths=[], run_id=None,
                             cancel_requested=False, cancel_after_activation=cancel,
                             activated=False, private_after_activation=0)
                self.cli('workflow', 'run', 'production-deploy-direct-proof')
                self.assertEqual(state['status'], 'succeeded')
                final = state['posts'][-1]
                self.assertEqual(final['result']['selected_release'], release)
                self.assertTrue(final['result']['selection_confirmed'])
                self.assertEqual(final['result']['runtime_definitions'], {
                    'processes': [{'process_id': 14, 'ready': True, 'state': 'active'}],
                    'schedules': [{'id': schedule_id, 'ready': True}],
                    'restart': [{'process_id': 14, 'ready': True, 'state': 'active'}],
                })
                references = [post['result'] for post in state['posts'] if post['status'] == 'running']
                self.assertEqual([row['vp_environment']['status'] for row in references], ['checking', 'active'])
                self.assertTrue(all(row['release'] == release for row in references))
                self.assertEqual(state['private_after_activation'], 1)
                self.assertEqual(state['cancel_requested'], cancel)
                self.assertFalse(any('children' in path or 'operation-executor' in path
                                     for path in state['paths']))
                self.assert_private_absent(json.dumps(state['posts']))


if __name__ == '__main__':
    unittest.main()
