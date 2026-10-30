"""Pinned native Route verification workflow; Gateway and local probe are disposable fakes."""
import json
from pathlib import Path
import unittest
import sys

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class VerificationNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        behavior = self.repo / 'extensions/behaviors'
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/routes')
        module = behavior / 'operations/routes/verification.ts'
        module.write_text('import {fixtureHttp,fixtureVerify} from "./verification_native_fixture.ts";\n' + module.read_text().replace('export const behavior = verification();', 'export const behavior = verification(() => fixtureHttp, fixtureVerify);'))
        source = ROOT / 'workflows/workflow-operation-route-verification.yaml'
        self.assertIn('labels: { pool: migration }', source.read_text())
        self.assertNotIn('target:', source.read_text())
        self.assertNotIn('queueTimeout:', source.read_text())
        # The fixture exercises execution/recovery locally, not an enrolled worker.
        (self.repo / 'workflows' / source.name).write_text('\n'.join(line for line in source.read_text().splitlines() if not line.startswith('labels:')) + '\n')
        self.state = {'bound': True, 'probes': 0, 'calls': [], 'operation': {'id': 17, 'kind': 'route.workload_verify', 'node_id': 9, 'status': 'queued', 'cancel_requested': False, 'result': None, 'input': {'protocol': 'tls', 'agent_sha256': 'a' * 64, 'probe': {'address': '192.168.6.18', 'port': 443, 'domain': 'demo.private.test'}}}}
        self.save()

    def save(self):
        (self.repo / 'native-verification.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / 'native-verification.json').read_text())

    def run_workflow(self, success=True):
        result = self.cli('workflow', 'run', 'operation-route-verification', '--input', 'operation_id=17', '--input', 'node_id=9', '--input', 'kind=route.workload_verify', success=success)
        self.run_id = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']

    def test_native_workflow_uses_only_read_result_and_local_probe(self):
        self.run_workflow()
        state = self.read()
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['probes'], 1)
        self.assertEqual(len(state['calls']), 3)
        self.assertTrue(all('/direct-operations/' in path for path in state['calls']))
        self.assertFalse(any('primitive' in path or 'claim' in path for path in state['calls']))

    def test_unbound_read_refuses_before_probe_then_native_resume_uses_same_intent(self):
        self.state['bound'] = False
        self.save()
        self.run_workflow(success=False)
        self.state = self.read()
        self.assertEqual(self.state['probes'], 0)
        self.assertEqual(self.state['operation']['status'], 'queued')
        self.state['bound'] = True
        self.save()
        self.cli('workflow', 'resume', 'operation-route-verification', '--run', self.run_id, '--from', 'verify')
        self.assertEqual(self.read()['probes'], 1)
        self.assertEqual(self.read()['operation']['status'], 'succeeded')

    def test_lost_terminal_reply_is_read_without_repeating_probe(self):
        self.state['loseCompletion'] = True
        self.save()
        self.run_workflow(success=False)
        self.cli('workflow', 'resume', 'operation-route-verification', '--run', self.run_id, '--from', 'verify')
        self.assertEqual(self.read()['probes'], 1)
        self.assertEqual(self.read()['operation']['status'], 'succeeded')


if __name__ == '__main__':
    unittest.main()
