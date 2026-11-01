"""Execute Doctor's real fan-out and aggregate against disposable Gateway HTTP."""
import json
from pathlib import Path
import sys
import threading
import unittest
import yaml
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class DoctorWorkflowNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('observation', 'automatic-deploy', 'operations', 'node-worker')
        module = self.repo / 'extensions/behaviors/observation/doctor.ts'
        module.write_text('import {fixtureHttp,fixtureObservation,fixtureLocal} from "./doctor_native_fixture.ts";\n' +
            module.read_text().replace('export const behavior = { ...doctor(), type: "@nckrtl/doctor" };',
                'export const behavior = { ...doctor(() => fixtureHttp, fixtureObservation, fixtureLocal), type: "@nckrtl/doctor" };'))
        # Placement remains checked separately; local harness executes the same jobs and expressions.
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-doctor-inspect.yaml').read_text())
        def unplace(value):
            if isinstance(value, dict):
                return {key: unplace(item) for key, item in value.items() if key not in ('target', 'labels')}
            if isinstance(value, list):
                return [unplace(item) for item in value]
            return value
        (self.repo / 'workflows/workflow-operation-doctor-inspect.yaml').write_text(json.dumps(unplace(workflow)))
        self.nodes = [self.node(14), self.node(9)]
        self.operation = {'id': 7, 'kind': 'doctor.inspect', 'node_id': 14, 'status': 'queued',
            'cancel_requested': False, 'result': None,
            'input': {'nodes': self.nodes, 'families': ['node'], 'collect_problems': False}}
        self.probes = []
        self.callbacks = []
        self.refuse_node = None
        self.refuse_first_read = False
        owner = self
        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_args): pass
            def respond(self, value, status=200):
                encoded = json.dumps(value).encode()
                self.send_response(status)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Content-Length', str(len(encoded)))
                self.end_headers()
                self.wfile.write(encoded)
            def do_GET(self):
                if owner.refuse_first_read:
                    owner.refuse_first_read = False
                    return self.respond({}, 503)
                self.respond({'data': owner.operation})
            def do_POST(self):
                data = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                if self.path == '/_observation':
                    owner.probes.append(data)
                    if data['node_id'] == owner.refuse_node:
                        return self.respond({}, 503)
                    return self.respond({'reachable': True, 'platform': 'linux', 'architecture': 'x86_64', 'wireguard': True})
                owner.callbacks.append(data)
                owner.operation['status'] = data.get('status', 'failed')
                owner.operation['result'] = data.get('result', {})
                self.respond({'data': owner.operation})
        server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        self.native_env['ORBIT_DOCTOR_HTTP_FIXTURE'] = f'http://127.0.0.1:{server.server_port}'

    @staticmethod
    def node(node_id, **overrides):
        return {'id': node_id, 'name': f'dev-{node_id}', 'platform': 'linux', 'architecture': 'x86_64',
            'user': 'orbit', 'address': f'10.44.0.{node_id}', 'managed': True, 'parked': False,
            'roles': [], 'resources': {}, 'agent_sha256': 'a' * 64, **overrides}

    def run_doctor(self, success=True):
        self.cli('workflow', 'validate', 'operation-doctor-inspect')
        return self.cli('workflow', 'run', 'operation-doctor-inspect', '--input', 'operation_id=7',
                        '--input', 'node_id=14', '--input', 'kind=doctor.inspect', success=success)

    def test_cross_job_fanout_reads_each_admitted_node_and_merges_actual_reports(self):
        self.run_doctor()
        self.assertEqual(sorted(p['node_id'] for p in self.probes), [9, 14])
        self.assertEqual(self.operation['status'], 'succeeded')
        report = self.operation['result']['report']
        self.assertTrue(report['healthy'])
        self.assertEqual(report['summary']['nodes'], 2)
        self.assertEqual(report['summary']['unverifiable'], 0)

    def test_empty_fanout_finishes_without_dispatching_parked_or_unmanaged_nodes(self):
        self.nodes[:] = [self.node(14, managed=False), self.node(9, parked=True)]
        self.run_doctor()
        self.assertEqual(self.probes, [])
        self.assertEqual(self.operation['status'], 'succeeded')
        self.assertEqual(self.operation['result']['report']['summary']['nodes'], 2)

    def test_refused_node_read_remains_an_unverifiable_finding_in_the_final_report(self):
        self.nodes[1]['resources'] = {'node_checks': [{'managed': True, 'status': 'active'}]}
        self.refuse_node = 9
        self.run_doctor()
        self.assertEqual(self.operation['status'], 'succeeded')
        report = self.operation['result']['report']
        self.assertFalse(report['healthy'])
        self.assertEqual(report['summary']['unverifiable'], 1)
        failed = next(n for n in report['nodes'] if n['node_id'] == 9)
        self.assertEqual(failed['families'][0]['issues'][0]['code'], 'node.inspection_failed')

    def test_failed_prepare_does_not_abort_later_planning_or_leave_the_gateway_running(self):
        self.refuse_first_read = True
        self.run_doctor(success=False)
        self.assertNotIn(self.operation['status'], ['queued', 'running'])
        self.assertEqual(self.probes, [])

if __name__ == '__main__':
    unittest.main()
