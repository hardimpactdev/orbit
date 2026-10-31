"""Real native dev root and HTTP boundary; all Node effects are fixture ports."""
import hashlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from pathlib import Path
import shutil
import ssl
import threading
import unittest
import uuid
from native_harness import NativeTestCase

ROOT = Path(__file__).resolve().parents[1]


class DevDeployDirectNativeTest(NativeTestCase):
    native_timeout = 90

    def test_native_parent_uses_http_private_binding_and_leaves_no_private_artifacts(self):
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/instance-deploy')
        # Unit source files are not part of the native extension fixture.
        for path in (self.repo / 'extensions').rglob('*_test.ts'):
            path.unlink()
        target = self.repo / 'extensions/behaviors/fixture'
        target.mkdir()
        source = (ROOT / 'tests/fixtures/dev-deploy-direct/mod.ts').read_text()
        (target / 'mod.ts').write_text(source.replace('../../../extensions/behaviors/', '../'))
        secret = 'private-deploy-' + uuid.uuid4().hex
        self.secrets = (secret,)
        script = 'printf ' + secret
        safe = {
            'instance_id': 101, 'project_id': 102, 'agent_sha256': 'd' * 64,
            'commit': 'a' * 40, 'release_name': 'unused-dev-release', 'timeout_seconds': 960,
            'deployment': {
                'variant': 'development', 'home': '/fast/apps/proof/default',
                'repository': 'https://example.test/proof.git', 'branch': 'main',
                'node_user': 'orbit', 'protocols': ['https'], 'needs_vp': False,
                'projection_route_id': None,
                'steps': [{'name': 'proof', 'phase': 'before_activation', 'required': True,
                           'timeout_seconds': 60, 'command_digest': hashlib.sha256(script.encode()).hexdigest()}],
            },
        }
        state = {'status': 'queued', 'posts': [], 'paths': [], 'run_id': None}

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
                if self.path == '/api/v1/projects/102/development-deploy-steps':
                    self.reply([{'name': 'proof', 'command': script, 'timeout_seconds': 60}])
                    return
                base, separator, run_id = self.path.partition('?run_id=')
                if separator != '?run_id=' or (state['run_id'] is not None and run_id != state['run_id']):
                    self.send_error(409)
                    return
                state['run_id'] = run_id
                if base == '/api/v1/direct-operations/100/private-material':
                    self.reply({'git_read': secret, 'vp_materialize': {}})
                elif base == '/api/v1/direct-operations/100':
                    self.reply({'id': 100, 'kind': 'instance.deploy', 'node_id': 9,
                                'status': state['status'], 'input': safe, 'result': None, 'cancel_requested': False})
                else:
                    self.send_error(404)

            def do_POST(self):
                state['paths'].append(self.path)
                body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                if self.path != '/api/v1/direct-operations/100/result' or body['run_id'] != state['run_id']:
                    self.send_error(409)
                    return
                state['posts'].append(body)
                state['status'] = body['status']
                self.reply({})

        server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(thread.join)
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        ca = next(Path(ssl.get_default_verify_paths().capath).glob('*.pem'))
        self.native_env.update(ORBIT_DEPLOY_SENTINEL=secret,
                               ORBIT_DEPLOY_TEST_URL=f'http://127.0.0.1:{server.server_port}',
                               ORBIT_DEPLOY_TEST_CA=str(ca))
        created = json.loads(self.cli('workflow', 'create', 'dev-deploy-direct-proof').stdout)
        workflow = {'id': created['id'], 'name': 'dev-deploy-direct-proof', 'vaults': [],
                    'jobs': [{'name': 'dev', 'steps': [{'name': 'deploy', 'task': {
                        'type': 'behavior', 'behaviorType': '@fixture/dev-deploy-direct',
                        'behaviorName': 'dev-deploy-direct-proof', 'methodName': 'run',
                        'inputs': {'operation_id': 100, 'node_id': 9, 'kind': 'instance.deploy',
                                   'run_id': '${{ run.id }}'},
                    }}]}]}
        Path(created['path']).write_text(json.dumps(workflow))
        self.cli('workflow', 'validate', 'dev-deploy-direct-proof')
        self.cli('workflow', 'run', 'dev-deploy-direct-proof')
        self.assertEqual(state['status'], 'succeeded')
        self.assertEqual([row['status'] for row in state['posts']], ['running', 'succeeded'])
        self.assertEqual(state['posts'][-1]['result']['commit'], 'a' * 40)
        self.assertFalse(any('children' in path or 'operation-executor' in path for path in state['paths']))
        self.assert_private_absent(json.dumps(state['posts']))
        # An error after the private script returns must retain only bounded failure facts.
        state.update(status='queued', posts=[], paths=[], run_id=None)
        self.native_env['ORBIT_DEPLOY_TEST_FAIL_AFTER_STEPS'] = '1'
        self.cli('workflow', 'run', 'dev-deploy-direct-proof', success=False)
        self.assertEqual(state['status'], 'failed')
        self.assertEqual(state['posts'][-1]['error_code'], 'deployment.interrupted')
        self.assertEqual(state['posts'][-1]['result']['failed_step'], 'verification')
        self.assertEqual(state['posts'][-1]['result']['commit'], 'a' * 40)
        self.assert_private_absent(json.dumps(state['posts']))
        # A lost mutation outcome leaves the same parent open and does not free its target.
        state.update(status='queued', posts=[], paths=[], run_id=None)
        self.native_env['ORBIT_DEPLOY_TEST_UNCERTAIN_SOURCE'] = '1'
        self.cli('workflow', 'run', 'dev-deploy-direct-proof', success=False)
        self.assertEqual(state['status'], 'running')
        self.assertEqual([row['status'] for row in state['posts']], ['running'])
        self.assertNotIn('finished_at', state['posts'][0])
        self.assert_private_absent(json.dumps(state['posts']))
        # Resume the same native run: its checkpoint must survive the failed method.
        before = list(state['posts'])
        self.cli('workflow', 'resume', 'dev-deploy-direct-proof', success=False)
        self.assertEqual(state['status'], 'running')
        self.assertEqual(state['posts'], before)


if __name__ == '__main__':
    unittest.main()
