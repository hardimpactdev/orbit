"""Canonical native hub DAG with real private HTTP and whole-scratch secret scans."""
import base64
import json
from pathlib import Path
import shutil
import sys
import threading
import unittest
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit
ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class HubNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.secrets = (base64.b64encode(uuid.uuid4().bytes + uuid.uuid4().bytes).decode(),)
        self.copy_behaviors('automatic-deploy', 'operations/_lib')
        self.copy_behaviors('operations/node-bootstrap')
        routes = self.repo / 'extensions/behaviors/operations/routes'
        routes.mkdir()
        for name in ['firewall-local.ts', 'firewall-program.ts', 'local-program.ts', 'router-lan.ts']:
            shutil.copy(ROOT / 'extensions/behaviors/operations/routes' / name, routes / name)
        target = self.repo / 'extensions/behaviors/operations/node'
        target.mkdir()
        for name in ['wireguard_direct.ts', 'wireguard_local.ts', 'wireguard_direct_native_fixture.ts', 'network_system.ts', 'network_native_program.ts', 'system_command.ts']:
            shutil.copy(ROOT / 'extensions/behaviors/operations/node' / name, target / name)
        module = target / 'wireguard_direct.ts'
        module.write_text('import {fixtureHttp,fixtureSystem,fixtureSsh} from "./wireguard_direct_native_fixture.ts";\n' + module.read_text().replace(
            'export const behavior = nodeWireGuard();',
            'export const behavior = nodeWireGuard(undefined, () => fixtureHttp, fixtureSystem, fixtureSsh);'))
        self.snapshot = {'schema_version': 1, 'executor_node_id': 1, 'hub_id': 1,
                         'context_node_id': 1, 'excluded_node_id': None, 'agent_sha256': 'a' * 64}
        self.operation = {'id': 7, 'kind': 'node.wireguard_hub_converge', 'node_id': 1,
                          'status': 'queued', 'cancel_requested': False, 'input': self.snapshot}
        self.effects = 0
        self.ssh_effects = 0
        self.private_reads = 0
        self.mode = 'success'
        workflow = json.loads((ROOT / 'tests/fixtures/node-hub-ssh/workflow.yaml').read_text())
        workflow.pop('target'); workflow.pop('labels')
        for job in workflow['jobs']:
            job.pop('target', None); job.pop('labels', None)
        (self.repo / 'workflows/workflow-operation-node-wireguard-hub-converge.yaml').write_text(json.dumps(workflow))
        owner = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def respond(self, data, status=200):
                payload = json.dumps(data).encode()
                self.send_response(status)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Cache-Control', 'no-store')
                self.send_header('Content-Length', str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)

            def do_GET(self):
                path = urlsplit(self.path).path
                if path.endswith('/private-material'):
                    owner.private_reads += 1
                    if owner.mode == 'cancel_during_private':
                        owner.operation['cancel_requested'] = True
                    if owner.mode == 'private_failure':
                        return self.respond({'error': {'message': owner.secrets[0]}}, 500)
                    material = {'snapshot': owner.snapshot, 'address': '10.44.0.1/24', 'port': 51820,
                                'private_key': owner.secrets[0], 'peers': []}
                    if owner.mode == 'ssh':
                        material['connection'] = {'target': {'node_id': 1, 'host': 'vpn.example.test', 'port': 22,
                            'user': 'orbit', 'fingerprint': 'SHA256:' + 'A' * 43}, 'identity': '/private/existing-gateway-key'}
                    return self.respond({'data': {'configuration': material}})
                self.respond({'data': owner.operation})

            def do_POST(self):
                body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                if self.path == '/_system':
                    owner.assertNotEqual(owner.mode, 'ssh', 'Gateway must not run the hub program locally')
                    owner.effects += 1
                    owner.assertEqual(body['name'], 'wireguard.project-hub')
                    owner.assertIn(owner.secrets[0], body['input']['config'])
                    return self.respond({'error_code': 'unknown.failure', 'diagnostic': owner.secrets[0]} if owner.mode == 'unknown_system' else {})
                if self.path == '/_ssh':
                    owner.ssh_effects += 1
                    owner.assertEqual(body['target']['node_id'], 1)
                    owner.assertEqual(body['identity'], '/private/existing-gateway-key')
                    owner.assertEqual(body['argv'][:4], ['/usr/bin/sudo', '-n', '--', '/usr/bin/python3'])
                    owner.assertNotIn(owner.secrets[0], json.dumps(body['argv']))
                    owner.assertEqual(body['input']['name'], 'wireguard.project-hub')
                    owner.assertIn(owner.secrets[0], body['input']['input']['config'])
                    return self.respond({})
                owner.assertNotIn(owner.secrets[0], json.dumps(body))
                if self.path.endswith('/failure'):
                    owner.operation['status'] = 'failed'
                    owner.operation['error_code'] = 'operation.native_failed'
                    return self.respond({'data': owner.operation})
                owner.operation['status'] = body['status']
                owner.operation['result'] = body['result']
                if body['status'] == 'running' and owner.mode == 'cancel_after_admit':
                    owner.operation['cancel_requested'] = True
                self.respond({'data': owner.operation})

        server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        self.native_env['ORBIT_WIREGUARD_HTTP_FIXTURE'] = f'http://127.0.0.1:{server.server_port}'

    def run_hub(self, success=True):
        self.cli('workflow', 'validate', 'operation-node-wireguard-hub-converge')
        self.cli('workflow', 'run', 'operation-node-wireguard-hub-converge', '--input', 'operation_id=7',
                 '--input', 'node_id=1', '--input', 'kind=node.wireguard_hub_converge', success=success)

    def test_real_http_private_callback_then_bounded_projection(self):
        self.run_hub()
        self.assertEqual(self.effects, 1)
        self.assertEqual(self.operation['status'], 'succeeded')
        self.assertEqual(self.operation['result'], {'schema_version': 1, 'hub_id': 1, 'status': 'succeeded', 'projected': True})

    def test_secret_bearing_http_failure_stays_uncertain(self):
        self.mode = 'private_failure'
        self.run_hub(success=False)
        self.assertEqual(self.effects, 0)
        self.assertEqual(self.operation['status'], 'failed')

    def test_unknown_system_failure_strips_private_diagnostic(self):
        self.mode = 'unknown_system'
        self.run_hub(success=False)
        self.assertEqual(self.effects, 1)
        self.assertEqual(self.operation['status'], 'failed')

    def test_gateway_placed_taskvm_destroy_hub_uses_pinned_ssh_in_the_shared_native_method(self):
        self.mode = 'ssh'
        self.snapshot['executor_node_id'] = 14
        self.operation['node_id'] = 14
        self.operation['kind'] = 'task_vm.destroy'
        path = self.repo / 'workflows/workflow-operation-node-wireguard-hub-converge.yaml'
        workflow = json.loads(path.read_text())
        workflow['inputs']['properties']['kind']['enum'] = ['task_vm.destroy']
        path.write_text(json.dumps(workflow))
        self.cli('workflow', 'validate', 'operation-node-wireguard-hub-converge')
        self.cli('workflow', 'run', 'operation-node-wireguard-hub-converge', '--input', 'operation_id=7',
                 '--input', 'node_id=14', '--input', 'kind=task_vm.destroy')
        self.assertEqual(self.effects, 0)
        self.assertEqual(self.ssh_effects, 1)
        self.assertEqual(self.operation['status'], 'succeeded')

    def test_cancel_after_admission_refuses_before_private_read(self):
        self.mode = 'cancel_after_admit'
        self.run_hub(success=False)
        self.assertEqual(self.private_reads, 0)
        self.assertEqual(self.effects, 0)
        self.assertEqual(self.ssh_effects, 0)

    def test_cancel_during_private_read_refuses_before_effect(self):
        self.mode = 'cancel_during_private'
        self.run_hub(success=False)
        self.assertEqual(self.private_reads, 1)
        self.assertEqual(self.effects, 0)
        self.assertEqual(self.ssh_effects, 0)

    def test_cancelled_enrollment_restores_hub_by_pinned_ssh_without_settling_parent(self):
        self.mode = 'ssh'
        self.snapshot.update(executor_node_id=14, excluded_node_id=None)
        self.operation.update(node_id=14, kind='node.add', status='running', cancel_requested=True)
        path = self.repo / 'workflows/workflow-operation-node-wireguard-hub-converge.yaml'
        workflow = json.loads(path.read_text())
        workflow['inputs']['properties']['kind']['enum'] = ['node.add']
        publish = workflow['jobs'][0]['steps'][1]
        publish.pop('guard')
        publish.pop('dependsOn')
        publish['task']['behaviorName'] = 'wireguard-hub-1'
        publish['task']['inputs'].update(snapshot=self.snapshot, recovery=True)
        workflow['jobs'] = [{'name': 'restore_hub', 'steps': [publish]}]
        path.write_text(json.dumps(workflow))
        self.cli('workflow', 'validate', 'operation-node-wireguard-hub-converge')
        self.cli('workflow', 'run', 'operation-node-wireguard-hub-converge', '--input', 'operation_id=7',
                 '--input', 'node_id=14', '--input', 'kind=node.add')
        self.assertEqual(self.effects, 0)
        self.assertEqual(self.ssh_effects, 1)
        self.assertEqual(self.private_reads, 1)
        self.assertEqual(self.operation['status'], 'running')
        self.assertTrue(self.operation['cancel_requested'])


if __name__ == '__main__':
    unittest.main()
