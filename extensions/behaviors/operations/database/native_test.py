"""Resume the pinned native database workflow in a fresh process after a lost Node reply."""
import json
from pathlib import Path
import unittest
import sys

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class DatabaseNativeTest(NativeTestCase):
    def test_parent_dispatch_and_fresh_process_resume_reuse_the_receipt(self):
        repo = self.repo
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations')
        runner = repo / 'extensions/behaviors/operation-runner/mod.ts'
        runner.write_text('import { fixtureHttp } from "../operations/database/tests/native-executor.ts";\n' + runner.read_text().replace('...operationDiscovery()', '...operationDiscovery(() => fixtureHttp)'))
        family = repo / 'extensions/behaviors/operations/database/mod.ts'
        family.write_text('import { fixtureHttp } from "./tests/native-executor.ts";\n' + family.read_text().replace('...database()', '...database(() => fixtureHttp)'))
        for path in ROOT.glob('workflows/workflow-operation-*.yaml'):
            text = path.read_text()
            if path.name == 'workflow-operation-database.yaml':
                text = '\n'.join(line for line in text.splitlines() if not line.startswith(('labels:', 'queueTimeout:'))) + '\n'
            (repo / 'workflows' / path.name).write_text(text)
        descriptor = {'key': 'database-apply', 'step': 'database-apply', 'transport': 'agent', 'name': 'database.server-admin',
                      'input': {'password': {'$secret': 'database.intent', 'id': 9}, 'sql': {'$secret': 'database.intent', 'id': 9}},
                      'resumable': True, 'required': True}
        state_file = repo / 'native-database.json'
        state_file.write_text(json.dumps({'operation': {'id': 7, 'node_id': 2, 'kind': 'database.clone', 'status': 'queued',
            'input': {'intent_id': 9, 'driver': 'pgsql'}, 'cancel_requested': False, 'steps': [], 'step_results': {}},
            'descriptors': [descriptor], 'runs': 0, 'completions': 0, 'receipts': {}, 'loseRunReply': True}))

        for workflow in ('operation-database',):
            result = self.cli('workflow', 'validate', workflow)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        result = self.cli('workflow', 'run', 'operation-database', success=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(json.loads(state_file.read_text())['runs'], 1)
        result = self.cli('workflow', 'resume', 'operation-database', '--run', json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id'])
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        state = json.loads(state_file.read_text())
        self.assertEqual(state['runs'], 1)
        self.assertEqual(state['receiptReads'], 1)
        self.assertEqual(state['completions'], 1)
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['operation']['steps'], [{'name': 'database-apply', 'status': 'succeeded'}])


if __name__ == '__main__':
    unittest.main()
