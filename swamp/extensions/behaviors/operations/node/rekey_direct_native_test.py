"""Real HTTP private-output proof in the canonical native scratch harness."""
import json
from pathlib import Path
import re
import shutil
import sys
import threading
import unittest
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlsplit

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class DirectAgentRekeyNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.secrets = (uuid.uuid4().hex + uuid.uuid4().hex,)
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/node')
        target = self.repo / 'extensions/behaviors'
        (target / 'operations/fleet').mkdir()
        for name in ['contract.ts', 'peer_dns_local.ts']:
            shutil.copy(ROOT / 'extensions/behaviors/operations/fleet' / name,
                        target / 'operations/fleet' / name)
        module = target / 'operations/node/rekey_direct.ts'
        module.write_text('import {fixtureHttp, fixtureAction} from "./rekey_direct_native_fixture.ts";\n' +
                          module.read_text().replace('export const behavior = nodeAgentRekey();',
                                                   'export const behavior = nodeAgentRekey(fixtureAction, () => fixtureHttp);'))
        self.workflow = ROOT / 'workflows/workflow-operation-node-agent-rekey.yaml'
        local = '\n'.join(line for line in self.workflow.read_text().splitlines()
                          if not re.match(r'^\s*(?:target|labels|node):', line)) + '\n'
        (self.repo / 'workflows' / self.workflow.name).write_text(local)
        self.operation = {
            'id': 7, 'node_id': 13, 'kind': 'node.agent_rekey', 'status': 'queued',
            'result': None, 'cancel_requested': False,
            'input': {'schema_version': 1, 'node_id': 13, 'configure_operation_id': 71, 'worker_user': 'operator',
                      'agent_sha256': 'a' * 64,
                      'configuration': {'configuration': '\n[swamp_worker]\nnode_id = 13\nuser = \"operator\"\n',
                                        'certificate': 'public-ca', 'unit': 'admitted-unit',
                                        'checkouts': [], 'binary_changed': False}},
        }
        self.calls = []
        self.effects = 0
        self.stores = 0
        self.errors = []
        self.mode = 'success'
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
                route = urlsplit(self.path)
                owner.calls.append(('GET', route.path))
                if route.path == '/_agent':
                    owner.effects += 1
                    if owner.mode == 'refusal':
                        return self.respond({'error_code': 'agent.version_mismatch'})
                    if owner.mode == 'post_rotation_failure':
                        return self.respond({'error_code': 'swamp.node_worker_not_ready'})
                    return self.respond({'secret_hash': owner.secrets[0],
                                         'restarted': True, 'unclosed': []})
                run_id = parse_qs(route.query).get('run_id', [''])[0]
                if route.path != '/api/v1/direct-operations/7' or not re.fullmatch(r'[a-f0-9-]{36}', run_id):
                    owner.errors.append('Invalid bound read')
                    return self.respond({}, 422)
                if owner.mode == 'cancel_after_observe' and sum(
                        path == '/api/v1/direct-operations/7' for method, path in owner.calls if method == 'GET') == 2:
                    owner.operation['cancel_requested'] = True
                return self.respond({'data': owner.operation})

            def do_POST(self):
                body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                owner.calls.append(('POST', self.path))
                if not re.fullmatch(r'[a-f0-9-]{36}', body.get('run_id', '')):
                    owner.errors.append('Invalid bound write')
                    return self.respond({}, 422)
                if self.path == '/api/v1/direct-operations/7/secret-output':
                    if body != {'run_id': body['run_id'], 'outputs': {'secret_hash': owner.secrets[0]}}:
                        owner.errors.append('Wrong private output')
                        return self.respond({}, 422)
                    if owner.mode == 'store_failure':
                        return self.respond({'error': {'message': owner.secrets[0]}}, 500)
                    owner.stores += 1
                    return self.respond({'data': {'stored': {'secret_hash': {'$secret': 'node.agent_secret_hash', 'id': 13}}}})
                if self.path != '/api/v1/direct-operations/7/result':
                    owner.errors.append('Unexpected endpoint')
                    return self.respond({}, 404)
                if owner.secrets[0] in json.dumps(body):
                    owner.errors.append('Private result escape')
                owner.operation['status'] = body['status']
                owner.operation['result'] = body.get('result')
                if owner.mode == 'lost_projection' and body['status'] == 'succeeded':
                    owner.mode = 'success'
                    return self.respond({}, 500)
                return self.respond({'data': owner.operation})

        server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        self.native_env['ORBIT_REKEY_HTTP_FIXTURE'] = f'http://127.0.0.1:{server.server_port}'

    def run_workflow(self, success=True):
        self.cli('workflow', 'validate', 'operation-node-agent-rekey')
        result = self.cli('workflow', 'run', 'operation-node-agent-rekey',
                          '--input', 'operation_id=7', '--input', 'node_id=13',
                          '--input', 'kind=node.agent_rekey', success=success)
        self.run_id = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']
        self.assertEqual(self.errors, [])

    def test_real_http_stores_privately_before_bounded_success(self):
        self.run_workflow()
        self.assertEqual((self.effects, self.stores), (1, 1))
        self.assertEqual(self.operation['status'], 'succeeded')
        self.assertEqual(self.operation['result'], {'rekeyed': True, 'restarted': True})
        self.assertLess(self.calls.index(('POST', '/api/v1/direct-operations/7/secret-output')),
                        len(self.calls) - 1)

    def test_secret_bearing_http_failure_stays_uncertain_and_scratch_is_clean(self):
        self.mode = 'store_failure'
        self.run_workflow(success=False)
        self.assertEqual((self.effects, self.stores), (1, 0))
        self.assertEqual(self.operation['status'], 'running')

    def test_possible_post_rotation_failure_stays_uncertain(self):
        self.mode = 'post_rotation_failure'
        self.run_workflow(success=False)
        self.assertEqual(self.operation['status'], 'running')
        self.assertEqual(self.stores, 0)

    def test_invalid_input_is_bounded_before_any_agent_effect(self):
        self.operation['input']['configuration']['configuration'] = 'missing admitted worker'
        self.run_workflow(success=False)
        self.assertEqual((self.effects, self.stores), (0, 0))
        self.assertEqual(self.operation['status'], 'failed')
        self.assertEqual(self.operation['result'], {'rekeyed': False, 'error_code': 'node.agent_rekey_unavailable'})

    def test_malformed_snapshot_is_bounded_before_any_agent_effect(self):
        self.operation['input']['agent_sha256'] = 'invalid'
        self.run_workflow(success=False)
        self.assertEqual((self.effects, self.stores), (0, 0))
        self.assertEqual(self.operation['status'], 'failed')
        self.assertEqual(self.operation['result'], {'rekeyed': False, 'error_code': 'node.agent_rekey_unavailable'})

    def test_cancellation_after_observe_is_ignored_even_before_invoke(self):
        self.mode = 'cancel_after_observe'
        self.run_workflow()
        self.assertTrue(self.operation['cancel_requested'])
        self.assertEqual(self.operation['status'], 'succeeded')
        self.assertEqual((self.effects, self.stores), (1, 1))

    def test_refusal_is_bounded_and_cancellation_has_no_effect(self):
        self.mode = 'refusal'
        self.run_workflow(success=False)
        self.assertEqual(self.operation['status'], 'failed')
        self.assertEqual(self.operation['result'], {'rekeyed': False, 'error_code': 'agent.version_mismatch'})
        self.operation.update(status='queued', result=None, cancel_requested=True)
        self.run_workflow()
        self.assertEqual(self.operation['status'], 'cancelled')
        self.assertEqual((self.effects, self.stores), (1, 0))

    def test_terminal_reread_after_lost_projection_does_not_rotate_again(self):
        self.mode = 'lost_projection'
        self.run_workflow(success=False)
        self.assertEqual(self.operation['status'], 'succeeded')
        self.cli('workflow', 'resume', 'operation-node-agent-rekey', '--run', self.run_id)
        self.assertEqual((self.effects, self.stores), (1, 1))

    def test_uncertain_rotation_reconciles_before_a_later_cancellation(self):
        self.mode = 'store_failure'
        self.run_workflow(success=False)
        self.operation['cancel_requested'] = True
        self.mode = 'success'
        self.cli('workflow', 'resume', 'operation-node-agent-rekey', '--run', self.run_id)
        self.assertEqual(self.operation['status'], 'succeeded')
        self.assertEqual((self.effects, self.stores), (2, 1))

    def test_native_placement_is_exact_target_and_matching_node_label(self):
        (self.repo / 'workflows' / self.workflow.name).write_text(self.workflow.read_text())
        for node in (13, 209):
            result = self.cli('workflow', 'evaluate', 'operation-node-agent-rekey',
                              '--input', 'operation_id=7', '--input', f'node_id={node}',
                              '--input', 'kind=node.agent_rekey')
            output = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]
            text = Path(output['outputPath']).read_text()
            self.assertRegex(text, rf'(?m)^target: orbit-node-{node}$')
            self.assertRegex(text, rf"(?m)^labels:\n  node: '{node}'$")
        self.assertEqual((self.effects, self.stores), (0, 0))


if __name__ == '__main__':
    unittest.main()
