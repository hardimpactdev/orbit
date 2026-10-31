"""One native rename root reports its result without an executor or agent action."""
import json
from pathlib import Path
import sys
import unittest
import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class NativeRename(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operations/_lib')
        family = 'extensions/behaviors/operations/instance-lifecycle'
        target = self.repo / family
        target.mkdir(parents=True)
        for name in ('rename_root.ts', 'source_identity.ts', 'source_system.ts', 'rename_native_fixture.ts'):
            (target / name).write_bytes((ROOT / family / name).read_bytes())
        (self.repo / 'extensions/behaviors/operations/failure.ts').write_bytes((ROOT / 'extensions/behaviors/operations/failure.ts').read_bytes())
        module = target / 'rename_root.ts'
        module.write_text('import {fixtureHttp,fixtureInspect} from "./rename_native_fixture.ts";\n' + module.read_text().replace('renameRoot();', 'renameRoot(() => fixtureHttp, fixtureInspect);'))
        source = ROOT / 'workflows/workflow-operation-instance-rename.yaml'
        workflow = yaml.safe_load(source.read_text())
        for job in workflow['jobs']:
            job.pop('target', None)
            job.pop('labels', None)
        (self.repo / 'workflows' / source.name).write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.state = {'calls': [], 'operation': {'id':71, 'kind':'instance.rename', 'node_id':9,
            'status':'queued', 'cancel_requested':False, 'result':None, 'input':{}}}
        self.state_path = self.repo / 'rename-native.json'
        self.state_path.write_text(json.dumps(self.state))

    def run_rename(self):
        self.cli('workflow', 'validate', 'operation-instance-rename')
        return self.cli('workflow', 'run', 'operation-instance-rename',
                        '--input', 'operation_id=71', '--input', 'node_id=9', '--input', 'kind=instance.rename')

    def test_one_native_root_records_the_parent_result(self):
        self.run_rename()
        state = json.loads(self.state_path.read_text())
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['operation']['result'], {'branch':'feature'})
        roots = {c['body']['run_id'] for c in state['calls'] if c['method'] == 'POST'}
        self.assertEqual(len(roots), 1)
        self.assertTrue(all(c['path'].startswith('/api/v1/direct-operations/71') for c in state['calls']))
        self.assertFalse(any('operation-executor' in c['path'] or 'children' in c['path'] for c in state['calls']))

    def test_cancelled_intent_skips_the_local_action(self):
        self.state['operation']['cancel_requested'] = True
        self.state_path.write_text(json.dumps(self.state))
        self.run_rename()
        state = json.loads(self.state_path.read_text())
        self.assertEqual(state['operation']['status'], 'cancelled')
        self.assertEqual(state['operation']['result'], {})


if __name__ == '__main__':
    unittest.main()
