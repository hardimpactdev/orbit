"""Exercise shipped parent DAGs in the native engine, with only effects and HTTP faked."""
import json
import os
from pathlib import Path
import shutil
import sys
import unittest

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class FleetReleaseNative(NativeTestCase):
    native_timeout = 60

    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operations/_lib', 'operations/fleet', 'node-worker')
        module = self.repo / 'extensions/behaviors/operations/fleet/release.ts'
        module.write_text('import { http, cli, observer } from "./release_native_fixture.ts";\n' +
                          module.read_text().replace('fleetRelease();', 'fleetRelease(cli, observer, () => http, () => new Date("2026-10-31T02:30:00Z"));'))
        shutil.copy(ROOT / 'extensions/behaviors/operations/fleet/release_native_fixture.ts', module.parent)
        for kind in ('rollout', 'cli-converge'):
            name = 'workflow-operation-fleet-' + kind + '.yaml'
            import yaml
            workflow = yaml.safe_load((ROOT / 'workflows' / name).read_text())
            for job in workflow['jobs']:
                for step in job['steps']:
                    step.pop('target', None)
                    step.pop('labels', None)
            (self.repo / 'workflows' / name).write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.state = {'bound': True, 'calls': [], 'effects': [], 'failure': None, 'uncertain': False,
                      'operation': {'id': 7, 'kind': 'fleet.rollout', 'node_id': 1, 'status': 'queued',
                                    'cancel_requested': False, 'result': None, 'input': {'nodes': [self.node(i) for i in (2, 3, 4)]}}}

    def node(self, i):
        return {'node_id': i, 'row_id': i, 'user': 'node', 'release': {'version': '0.100.0', 'url': 'https://example.com/cli', 'sha256': 'a' * 64},
                'profile': '{}', 'skip_reason': None, 'plan_error': None, 'daytime': True,
                'agent': {'url': 'https://example.com/agent', 'sha256': 'b' * 64, 'version': '0.5.0'}}

    def run_parent(self, kind='rollout', success=True):
        self.state['operation']['kind'] = 'fleet.' + kind.replace('-', '_')
        (self.repo / 'fleet-native.json').write_text(json.dumps(self.state))
        workflow = 'operation-fleet-' + kind
        self.cli('workflow', 'validate', workflow)
        result = self.cli('workflow', 'run', workflow, '--input', json.dumps({'operation_id': 7, 'node_id': 1, 'kind': self.state['operation']['kind']}), success=success)
        state = json.loads((self.repo / 'fleet-native.json').read_text())
        return state, result

    def test_ordered_rollout_in_one_native_run(self):
        state, _ = self.run_parent()
        self.assertEqual(state['effects'], ['cli:2', 'observer:2', 'cli:3', 'observer:3', 'cli:4', 'observer:4'])
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['operation']['result']['rollout_status'], 'completed')

    def test_cli_parent_never_publishes_observer(self):
        state, _ = self.run_parent('cli-converge')
        self.assertEqual(state['effects'], ['cli:2', 'cli:3', 'cli:4'])
        self.assertEqual(state['operation']['status'], 'succeeded')

    def test_refusal_stops_later_nodes(self):
        self.state['failure'] = 3
        state, _ = self.run_parent(success=False)
        self.assertEqual(state['effects'], ['cli:2', 'observer:2', 'cli:3'])
        self.assertEqual(state['operation']['status'], 'failed')
        self.assertEqual(state['operation']['result']['halted_node_id'], 3)
        self.assertEqual(state['operation']['result']['nodes'][2]['outcome'], 'pending')

    def test_uncertain_effect_stops_later_nodes_and_is_not_success(self):
        self.state['failure'] = 3
        self.state['uncertain'] = True
        state, _ = self.run_parent(success=False)
        self.assertEqual(state['effects'], ['cli:2', 'observer:2', 'cli:3'])
        self.assertEqual(state['operation']['status'], 'failed')
        self.assertEqual(state['operation']['result']['error_code'], 'fleet.release_uncertain')

    def test_closed_window_defers_shared_nodes_without_effects(self):
        for node in self.state['operation']['input']['nodes']:
            node['daytime'] = False
        # 02:30Z is 03:30 Amsterdam; use a summer boundary by pinning the fixture's clock.
        module = self.repo / 'extensions/behaviors/operations/fleet/release.ts'
        module.write_text(module.read_text().replace('2026-10-31T02:30:00Z', '2026-10-31T12:30:00Z'))
        state, _ = self.run_parent()
        self.assertEqual(state['effects'], [])
        self.assertEqual(state['operation']['result']['rollout_status'], 'running')


if __name__ == '__main__':
    unittest.main()
