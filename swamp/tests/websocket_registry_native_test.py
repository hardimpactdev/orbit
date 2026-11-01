"""Native inputs, official SSH, private stdin and current Reverb confirmation."""
import json
import os
from datetime import datetime
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from tests.native_harness import NativeTestCase
from tests.route_ssh_fixture import PIN, install_ssh

ROOT = Path(__file__).resolve().parents[1]
PROCESS = '8f61a312-2b19-4ef7-92d5-39dff3d42380'
BOOT = '52eb4df2-ee4b-4950-9c46-d6f9fb1836e9'


class WebSocketRegistryNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/websocket-registry')
        install_ssh(self)
        self.calls = []
        self.error = None
        self.stall = False
        self.credential = 'private-runtime-registry-fixture-719263'
        owner = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def do_POST(self):
                request = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                owner.calls.append(request)
                if owner.stall:
                    threading.Event().wait(3)
                    return
                if self.headers.get('Authorization') != 'Bearer ' + owner.credential:
                    code, data = 403, {'error_code': 'websocket.registry_unauthorized'}
                elif owner.error:
                    code, data = 409, {'error_code': owner.error}
                else:
                    code, data = 200, {'outcome': 'succeeded', 'data': {'backend': 'applied'}, 'runtime': {
                        'process_id': PROCESS, 'boot_id': BOOT, 'epoch': 'epoch-fixture', 'revision': 7, 'state': 'serving'}}
                payload = json.dumps(data).encode()
                self.send_response(code)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Content-Length', str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)

        self.server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(self.server.server_close)
        self.addCleanup(self.server.shutdown)
        self.secrets = (self.credential,)
        self.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        self.key = 'websocket-registry-' + PROCESS
        self.cli('vault', 'put', 'orbit-control-inputs', self.key, input=self.credential)
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-websocket-registry.yaml').read_text())
        workflow.pop('target')
        for job in workflow['jobs']:
            for step in job['steps']:
                self.assertEqual(step.pop('target'), 'orbit-node-209')
                self.assertNotIn('labels', step)
        self.workflow_path = self.repo / 'workflows/workflow-registry.yaml'
        self.workflow_path.write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.intent = {'start_tag': BOOT, 'registry_operation_id': BOOT, 'epoch': 'epoch-fixture', 'revision': 7,
                       'change': 'sync', 'app_id': None, 'credential_generation': None, 'missing_node_ids': [],
                       'runtimes': [{'process_id': PROCESS, 'node_id': 9, 'port': self.server.server_port,
                                     'credential_field': 'runtime', 'ssh': {'identity_file': '/fixture/key',
                                         'target': {'host': 'fixture', 'user': 'orbit', 'port': 22, 'host_keys': [PIN]}}}]}
        self.intent['runtimes'][0]['credential_field'] = 'runtime_' + PROCESS.replace('-', '_')
        self.references = {self.intent['runtimes'][0]['credential_field']: {'vault': 'orbit-control-inputs', 'key': self.key}}

    def run_kind(self, kind='sync', success=True):
        self.intent['change'] = kind
        self.intent['app_id'] = None if kind == 'sync' else 'managed-app'
        self.intent['credential_generation'] = 2 if kind == 'rotate' else None
        full = {'sync': 'websocket.sync', 'create': 'websocket.app_create', 'destroy': 'websocket.app_destroy', 'rotate': 'websocket.app_rotate'}[kind]
        result = self.cli('workflow', 'run', 'operation-websocket-registry', '--input', 'operation_id=71',
                          '--input', 'node_id=14', '--input', 'kind=' + full,
                          '--input', 'intent=' + json.dumps(self.intent), '--input', 'execution_secrets=' + json.dumps(self.references), success=success)
        self.native_run = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]
        if success:
            history = json.loads(self.cli('workflow', 'history', 'get', self.native_run['id']).stdout)
            elapsed = (datetime.fromisoformat(history['completedAt'].replace('Z', '+00:00')) - datetime.fromisoformat(history['startedAt'].replace('Z', '+00:00'))).total_seconds() * 1000
            if path := os.environ.get('ORBIT_WEBSOCKET_TIMINGS'):
                with open(path, 'a') as receipt:
                    receipt.write(json.dumps({'kind': full, 'run_id': history['id'], 'duration_ms': elapsed}) + '\n')
        return self.native_run

    def test_native_confirmation_uses_official_ssh_and_one_private_stdin_request(self):
        self.run_kind()
        self.assertEqual(len(self.calls), 1)
        self.assertEqual(self.calls[0]['process_id'], PROCESS)
        self.assertEqual(self.native_run['status'], 'succeeded')

    def test_all_four_kinds_keep_their_committed_change_identity(self):
        for kind in ['sync', 'create', 'destroy', 'rotate'] * 3:
            self.run_kind(kind)
            self.assertEqual(self.calls[-1]['kind'], kind)
            self.assertEqual(self.calls[-1]['app_id'], self.intent['app_id'])
            self.assertEqual(self.calls[-1]['credential_generation'], self.intent['credential_generation'])
        self.assertEqual(len(self.calls), 12)

    def test_provider_refusal_is_terminal_without_a_second_request(self):
        self.error = 'websocket.registry_superseded'
        self.run_kind(success=False)
        self.assertEqual(len(self.calls), 1)
        self.assertEqual(self.native_run['status'], 'failed')

    def test_missing_runtime_membership_cannot_claim_success(self):
        self.intent['runtimes'] = []
        self.run_kind(success=False)
        self.assertEqual(self.calls, [])

    def test_phase_timeout_returns_pending_without_a_second_apply(self):
        workflow = yaml.safe_load(self.workflow_path.read_text())
        argv = workflow['jobs'][1]['steps'][0]['task']['inputs']['argv']
        self.assertEqual(argv[:3], ['/usr/bin/timeout', '--kill-after=2s', '12s'])
        argv[2] = '1s'  # Only the disposable fixture shortens the production phase budget.
        self.workflow_path.write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.stall = True
        self.run_kind(success=False)
        history = json.loads(self.cli('workflow', 'history', 'get', self.native_run['id']).stdout)
        result = next(step for job in history['jobs'] if job['name'] == 'result'
                      for step in job['steps'] if step['name'] == 'result')
        self.assertEqual(json.loads(result['outputs']['stdout']), {'error_code': 'websocket.registry_pending'})
        self.assertEqual(len(self.calls), 1)
