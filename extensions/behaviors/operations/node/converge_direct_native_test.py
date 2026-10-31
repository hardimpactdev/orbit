"""Pinned native DAG proof in an isolated repository. No worker or fleet effects."""
import json
import re
import sys
from pathlib import Path
import shutil
import unittest
import threading
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlsplit

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

class DirectConvergeNative(NativeTestCase):
    native_timeout = 50
    def setUp(self):
        super().setUp()
        self.native_env['SWAMP_NO_INBOX_NOTICE'] = '1'
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/node')
        target = self.repo / 'extensions/behaviors'
        (target / 'operations/fleet').mkdir()
        for name in ['contract.ts', 'peer_dns_local.ts']:
            shutil.copy(ROOT / 'extensions/behaviors/operations/fleet' / name, target / 'operations/fleet' / name)
        module = target / 'operations/node/converge_direct.ts'
        module.write_text('import {fixtureHttp, fixtureAction} from "./converge_direct_native_fixture.ts";\n' + module.read_text().replace('export const behavior = nodeConverge();', 'export const behavior = nodeConverge(fixtureAction, () => fixtureHttp);'))
        source = ROOT / 'workflows/workflow-operation-node-converge-direct.yaml'
        # Placement is inspected in source and proved separately on the real worker venue.
        text = source.read_text()
        text = '\n'.join(line for line in text.splitlines() if not re.match(r'^\s*(?:target|labels|node):', line)) + '\n'
        (self.repo / 'workflows' / source.name).write_text(text)
        self.state = {'operation': {'id': 7, 'node_id': 209, 'kind': 'node.converge', 'status': 'queued', 'result': None, 'cancel_requested': False, 'input': {
            'schema_version': 1, 'node_id': 209, 'agent_sha256': 'a' * 64,
            'force_artifacts': False, 'first_visit': False, 'refusal': None, 'network': None,
            'artifacts': [{'name': name, 'digest': 'a' * 64, 'required': True, 'repair': False} for name in ['agent', 'tmpfiles']], 'payloads': {},
        }}, 'effects': [], 'calls': []}
        self.save()

    def save(self):
        (self.repo / 'native-converge.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / 'native-converge.json').read_text())

    def run_workflow(self, success=True):
        self.cli('workflow', 'validate', 'operation-node-converge-direct')
        result = self.cli('workflow', 'run', 'operation-node-converge-direct', '--input', 'operation_id=7', '--input', 'node_id=209', '--input', 'kind=node.converge', success=success)
        self.run_id = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']

    def test_native_evaluation_preserves_exact_admitted_node_placement(self):
        source = ROOT / 'workflows/workflow-operation-node-converge-direct.yaml'
        (self.repo / 'workflows' / source.name).write_text(source.read_text())
        for node_id in (9, 209):
            with self.subTest(node_id=node_id):
                result = self.cli('workflow', 'evaluate', 'operation-node-converge-direct',
                                  '--input', 'operation_id=7', '--input', f'node_id={node_id}',
                                  '--input', 'kind=node.converge')
                data = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]
                evaluated = Path(data['outputPath']).read_text()
                self.assertRegex(evaluated, rf'(?m)^target: orbit-node-{node_id}$')
                self.assertRegex(evaluated, rf"(?m)^labels:\n  node: '{node_id}'$")
                self.assertEqual(self.read()['effects'], [])

    def test_one_root_projects_bounded_artifacts_without_gateway_children(self):
        self.run_workflow()
        state = self.read()
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(len(state['effects']), 9)
        self.assertEqual(len(state['operation']['result']['artifacts']), 2)
        self.assertTrue(all('/direct-operations/' in call['path'] for call in state['calls']))

    def test_known_refusal_stops_following_effects_and_projects_failure(self):
        self.state['refusal'] = 'tmpfiles'
        self.save()
        self.run_workflow(success=False)
        state = self.read()
        self.assertEqual(state['operation']['status'], 'failed')
        self.assertNotIn('agent', state['effects'])
        self.assertEqual(state['operation']['error_code'], 'node.tmpfiles_failed')

    def test_uncertainty_stays_running_and_resume_reuses_completed_stages(self):
        self.state['uncertain'] = 'tmpfiles'
        self.save()
        self.run_workflow(success=False)
        self.assertEqual(self.read()['operation']['status'], 'running')
        self.cli('workflow', 'resume', 'operation-node-converge-direct', '--run', self.run_id)
        state = self.read()
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['effects'].count('tmpfiles'), 2)
        self.assertEqual(state['effects'].count('agent-install'), 1)
        self.assertEqual(state['effects'].count('caddy'), 1)

    def test_uncertain_write_reconciles_before_later_cancellation_is_terminal(self):
        self.state['uncertain'] = 'tmpfiles'
        self.save()
        self.run_workflow(success=False)
        self.state = self.read()
        self.assertEqual(self.state['operation']['status'], 'running')
        self.state['operation']['cancel_requested'] = True
        self.save()
        self.cli('workflow', 'resume', 'operation-node-converge-direct', '--run', self.run_id, success=False)
        state = self.read()
        self.assertEqual(state['effects'].count('tmpfiles'), 2)
        self.assertNotIn('agent', state['effects'])
        self.assertEqual(state['operation']['status'], 'cancelled')

    def test_lost_projection_retries_only_projection(self):
        self.state['loseProjection'] = True
        self.save()
        self.run_workflow(success=False)
        self.cli('workflow', 'resume', 'operation-node-converge-direct', '--run', self.run_id)
        self.assertEqual(len(self.read()['effects']), 9)
        self.assertEqual(self.read()['operation']['status'], 'succeeded')

    def test_cancellation_before_work_dispatches_no_effects(self):
        self.state['operation']['cancel_requested'] = True
        self.save()
        self.run_workflow()
        self.assertEqual(self.read()['effects'], [])
        self.assertEqual(self.read()['operation']['status'], 'cancelled')

    def test_cancellation_after_admission_stops_at_the_next_native_boundary(self):
        self.state['cancelAfter'] = 'caddy'
        self.save()
        self.run_workflow(success=False)
        state = self.read()
        self.assertEqual(state['operation']['status'], 'cancelled')
        self.assertEqual(state['effects'], ['agent-install', 'caddy-package', 'caddy'])
        self.assertEqual(state['operation']['result'], {})

    def test_first_read_waits_for_gateway_start_binding(self):
        self.state['unbound'] = True
        self.save()
        self.run_workflow()
        state = self.read()
        self.assertEqual(state['calls'][0]['method'], 'GET')
        self.assertEqual(state['calls'][1]['method'], 'GET')
        self.assertEqual(state['calls'][2]['body']['status'], 'running')
        self.assertEqual(state['operation']['status'], 'succeeded')

    def test_real_gateway_http_private_callback_leaves_no_credentials_in_native_scratch(self):
        self.secrets = (uuid.uuid4().hex + uuid.uuid4().hex,
                        'enrollment-' + uuid.uuid4().hex,
                        'access-' + uuid.uuid4().hex)
        self.native_env.update(zip(('ORBIT_CONVERGE_PRIVATE_HASH',
                                   'ORBIT_CONVERGE_PRIVATE_ENROLLMENT',
                                   'ORBIT_CONVERGE_PRIVATE_ACCESS'), self.secrets))
        self.state['operation']['input']['payloads']['agent'] = {
            'configuration': '[swamp_worker]\nnode_id = 209\nuser = "orbit"\n',
            'certificate': 'PUBLIC TEST CA', 'unit': '[Service]\nProtectHome=yes\n',
            'user': 'orbit', 'apps_root': None, 'checkouts': [], 'url': None,
        }
        self.save()
        owner = self
        private_reads = []
        projections = []
        errors = []

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def respond(self, body, status=200):
                payload = json.dumps(body).encode()
                self.send_response(status)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Cache-Control', 'no-store')
                self.send_header('Content-Length', str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)

            def do_GET(self):
                route = urlsplit(self.path)
                run_id = parse_qs(route.query).get('run_id', [''])[0]
                if not re.fullmatch(r'[a-f0-9-]{36}', run_id):
                    errors.append('Unbound HTTP fixture read')
                    return self.respond({}, 422)
                if route.path == '/api/v1/direct-operations/7/private-material':
                    private_reads.append(run_id)
                    return self.respond({'data': {
                        'secret_hash': owner.secrets[0],
                        'worker_credentials': {'enrollment_token': owner.secrets[1],
                                               'server_token': owner.secrets[2]},
                    }})
                if route.path != '/api/v1/direct-operations/7':
                    errors.append('Unexpected HTTP fixture read')
                    return self.respond({}, 404)
                self.respond({'data': owner.state['operation']})

            def do_POST(self):
                body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                if self.path != '/api/v1/direct-operations/7/result':
                    errors.append('Unexpected HTTP fixture write')
                    return self.respond({}, 404)
                if any(secret in json.dumps(body) for secret in owner.secrets):
                    errors.append('Private bytes escaped into a projection')
                    return self.respond({}, 422)
                projections.append(body)
                owner.state['operation']['status'] = body['status']
                owner.state['operation']['result'] = body.get('result')
                self.respond({'data': owner.state['operation']})

        server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.native_env['ORBIT_CONVERGE_HTTP_FIXTURE'] = f'http://127.0.0.1:{server.server_port}'
        try:
            self.run_workflow()
            self.assertEqual(owner.state['operation']['status'], 'succeeded')
            self.assertEqual(private_reads, [self.run_id])
            self.assertEqual(projections[-1]['run_id'], self.run_id)
            artifacts = {row['name']: row['status'] for row in projections[-1]['result']['artifacts']}
            self.assertEqual(artifacts['agent'], 'unchanged')
            self.native_env['ORBIT_CONVERGE_PRIVATE_FAILURE'] = 'true'
            owner.state['operation']['status'] = 'queued'
            owner.state['operation']['result'] = None
            self.run_workflow(success=False)
            self.assertEqual(owner.state['operation']['status'], 'running')
            self.assertEqual(len(private_reads), 2)
            self.assertEqual(private_reads[-1], self.run_id)
            self.assertEqual(errors, [])
            self.assert_private_absent()
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=5)

if __name__ == '__main__':
    unittest.main()
