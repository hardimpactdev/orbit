"""Native unset confirms Gateway state; no host command or publication boundary."""
import json
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class TargetUnsetNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/routes')
        module = self.repo / 'extensions/behaviors/operations/routes/target-unset.ts'
        module.write_text('import {fixtureHttp} from "./verification_native_fixture.ts";\n' + module.read_text().replace('export const behavior = targetUnset();', 'export const behavior = targetUnset(() => fixtureHttp);'))
        source = ROOT / 'workflows/workflow-operation-route-target-unset.yaml'
        self.assertIn('orbit-node-" + string(inputs.node_id)', source.read_text())
        self.assertIn('labels: { node:', source.read_text())
        self.assertNotIn('queueTimeout:', source.read_text())
        (self.repo / 'workflows' / source.name).write_text('\n'.join(line for line in source.read_text().splitlines() if not line.lstrip().startswith(('target:', 'labels:'))) + '\n')
        self.state = {'bound': True, 'calls': [], 'operation': {
            'id': 17, 'kind': 'route.target_unset', 'node_id': 9, 'status': 'queued',
            'cancel_requested': False, 'result': None, 'input': {'route_id': 23, 'target_ids': []}}}
        self.save()

    def save(self):
        (self.repo / 'native-verification.json').write_text(json.dumps(self.state))

    def run_workflow(self, success=True):
        self.cli('workflow', 'validate', 'operation-route-target-unset')
        self.cli('workflow', 'run', 'operation-route-target-unset', '--input', 'operation_id=17', '--input', 'node_id=9', '--input', 'kind=route.target_unset', success=success)
        return json.loads((self.repo / 'native-verification.json').read_text())

    def test_confirms_stored_empty_targets_without_node_or_gateway_effect(self):
        state = self.run_workflow()
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['operation']['result'], {'ready': True})
        self.assertEqual(len(state['calls']), 3)
        self.assertTrue(all('/direct-operations/' in path for path in state['calls']))
        self.assertFalse(any('primitive' in path or 'claim' in path or 'children' in path for path in state['calls']))

    def test_refuses_nonempty_target_input_and_projects_failure(self):
        self.state['operation']['input']['target_ids'] = [41]
        self.save()
        state = self.run_workflow(success=False)
        self.assertEqual(state['operation']['status'], 'failed')
        self.assertEqual(state['operation']['result'], {'error_code': 'route.target_conflict'})


if __name__ == '__main__':
    unittest.main()
