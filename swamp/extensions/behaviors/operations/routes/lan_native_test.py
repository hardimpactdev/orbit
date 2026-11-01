"""Native LAN withdrawal uses the canonical publication model and same-bound intent."""
import json
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class LanNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operations/_lib', 'operations/routes')
        module = self.repo / 'extensions/behaviors/operations/routes/publication.ts'
        module.write_text('import {fixtureHttp,fixtureLan} from "./lan_native_fixture.ts";\n' + module.read_text().replace('export const behavior = publication();', 'export const behavior = publication(() => fixtureHttp, undefined, undefined, undefined, fixtureLan);'))
        source = ROOT / 'workflows/workflow-operation-route-lan-remove.yaml'
        self.assertIn('orbit-node-" + string(inputs.node_id)', source.read_text())
        self.assertIn('labels: { node:', source.read_text())
        self.assertNotIn('queueTimeout:', source.read_text())
        # Execution fixture only; production keeps exact target plus matching label.
        (self.repo / 'workflows' / source.name).write_text('\n'.join(line for line in source.read_text().splitlines() if not line.lstrip().startswith(('target:', 'labels:'))) + '\n')
        self.state = {'bound': True, 'calls': [], 'actions': [], 'lanResult': {'matches': True}, 'operation': {
            'id': 17, 'kind': 'route.lan_remove', 'node_id': 9, 'status': 'queued',
            'cancel_requested': False, 'result': None, 'input': {'route_id': 23}}}
        self.save()

    def save(self):
        (self.repo / 'native-verification.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / 'native-verification.json').read_text())

    def run_workflow(self, success=True):
        self.cli('workflow', 'validate', 'operation-route-lan-remove')
        result = self.cli('workflow', 'run', 'operation-route-lan-remove', '--input', 'operation_id=17', '--input', 'node_id=9', '--input', 'kind=route.lan_remove', success=success)
        return json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']

    def test_removes_only_the_admitted_comment_and_projects_absence(self):
        self.run_workflow()
        state = self.read()
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['operation']['result'], {'ready': True})
        self.assertEqual(state['actions'], [{'mode': 'route', 'operation': 'remove', 'route_id': 23}])
        self.assertEqual(len(state['calls']), 3)
        self.assertFalse(any('primitive' in path or 'claim' in path or 'children' in path for path in state['calls']))

    def test_unproven_absence_projects_failure_instead_of_completion(self):
        self.state['lanResult'] = {'error_code': 'firewall.remove_verify_failed'}
        self.save()
        self.run_workflow(success=False)
        state = self.read()
        self.assertEqual(state['operation']['status'], 'failed')
        self.assertEqual(state['operation']['result'], {'error_code': 'firewall.remove_verify_failed'})
        self.assertEqual(len(state['actions']), 1)


if __name__ == '__main__':
    unittest.main()
