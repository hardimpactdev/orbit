"""Resume the pinned native database workflow in a fresh process after a lost Node reply."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[4]


class DatabaseNativeTest(unittest.TestCase):
    def test_parent_dispatch_and_fresh_process_resume_reuse_the_receipt(self):
        with tempfile.TemporaryDirectory(prefix='database-native-') as directory:
            repo = Path(directory)
            shutil.copy(ROOT / '.swamp.yaml', repo)
            for name in ('automatic-deploy', 'operation-runner', 'operations'):
                shutil.copytree(ROOT / 'extensions/behaviors' / name, repo / 'extensions/behaviors' / name)
            runner = repo / 'extensions/behaviors/operation-runner/mod.ts'
            runner.write_text('import { fixtureHttp } from "../operations/database/tests/native-executor.ts";\n' + runner.read_text().replace('...operationRunner()', '...operationRunner(() => fixtureHttp)'))
            family = repo / 'extensions/behaviors/operations/database/mod.ts'
            family.write_text('import { fixtureHttp } from "./tests/native-executor.ts";\n' + family.read_text().replace('...database()', '...database(() => fixtureHttp)'))
            (repo / 'workflows').mkdir()
            for path in ROOT.glob('workflows/workflow-operation-*.yaml'):
                shutil.copy(path, repo / 'workflows' / path.name)
            descriptor = {'key': 'database-apply', 'step': 'database-apply', 'transport': 'agent', 'name': 'database.server-admin',
                          'input': {'password': {'$secret': 'database.intent', 'id': 9}, 'sql': {'$secret': 'database.intent', 'id': 9}},
                          'resumable': True, 'required': True}
            state_file = repo / 'native-database.json'
            state_file.write_text(json.dumps({'operation': {'id': 7, 'node_id': 2, 'kind': 'database.create', 'status': 'queued',
                'input': {'intent_id': 9, 'driver': 'pgsql'}, 'cancel_requested': False, 'steps': [], 'step_results': {}},
                'descriptors': [descriptor], 'runs': 0, 'completions': 0, 'receipts': {}, 'loseRunReply': True}))

            def swamp(*args):
                return subprocess.run(['swamp', *args, '--json'], cwd=repo, capture_output=True, text=True, timeout=60,
                    env={**os.environ, 'SWAMP_NO_UPDATE_CHECK': '1', 'SWAMP_NO_TELEMETRY': '1'})

            for workflow in ('operation-runner', 'operation-dispatch', 'operation-database'):
                result = swamp('workflow', 'validate', workflow)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            result = swamp('workflow', 'run', 'operation-runner')
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(json.loads(state_file.read_text())['runs'], 1)
            result = swamp('workflow', 'resume', 'operation-database', '--from', 'execute')
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            state = json.loads(state_file.read_text())
            self.assertEqual(state['runs'], 1)
            self.assertEqual(state['receiptReads'], 1)
            self.assertEqual(state['completions'], 1)
            self.assertEqual(state['operation']['status'], 'succeeded')
            self.assertEqual(state['operation']['steps'], [{'name': 'database-apply', 'status': 'succeeded'}])


if __name__ == '__main__':
    unittest.main()
