"""Prove integration recovery in a new pinned Swamp process, using a disposable Gateway fixture."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[4]
FAMILY = Path(__file__).resolve().parent
SWAMP = os.environ.get('SWAMP_BIN', shutil.which('swamp') or 'swamp')

class IntegrationNativeTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='integration-native-')
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        shutil.copy(ROOT / '.swamp.yaml', self.repo)
        for name in ('automatic-deploy', 'operation-runner'):
            shutil.copytree(ROOT / 'extensions/behaviors' / name, self.repo / 'extensions/behaviors' / name)
        destination = self.repo / 'extensions/behaviors/operations/integrations'
        shutil.copytree(FAMILY, destination)
        fixture = self.repo / 'extensions/behaviors/_test/executor.ts'
        fixture.parent.mkdir()
        shutil.copy(FAMILY / 'native_fixture.ts', fixture)
        for name, factory, relative in [('operation-runner', 'operationRunner', '../_test/executor.ts'), ('operations/integrations', 'integrations', '../../_test/executor.ts')]:
            path = self.repo / 'extensions/behaviors' / name / 'mod.ts'
            path.write_text('import { fixtureHttp } from "' + relative + '";\n' + path.read_text().replace('...' + factory + '()', '...' + factory + '(() => fixtureHttp)'))
        (self.repo / 'workflows').mkdir()
        for name in ('runner', 'dispatch', 'integrations'):
            source = (ROOT / f'workflows/workflow-operation-{name}.yaml').read_text()
            source = '\n'.join(line for line in source.splitlines() if not line.startswith(('labels:', 'queueTimeout:'))) + '\n'
            (self.repo / f'workflows/workflow-operation-{name}.yaml').write_text(source)
        self.state = {'operation': {'id': 12, 'kind': 'metrics.node_exporter_converge', 'node_id': 2,
            'status': 'queued', 'cancel_requested': False, 'step_results': {}, 'steps': [],
            'input': {'address': '10.44.0.2', 'metrics_address': '10.44.0.3'}},
            'runs': 0, 'completions': [], 'receipt': None, 'loseRunReply': False, 'loseCompletionReply': False}
        self.save()
    def save(self): (self.repo / 'native-executor.json').write_text(json.dumps(self.state))
    def read(self): return json.loads((self.repo / 'native-executor.json').read_text())
    def swamp(self, *args, success=True):
        result = subprocess.run([SWAMP, *args, '--json'], cwd=self.repo, text=True, capture_output=True, timeout=45,
            env={**os.environ, 'SWAMP_NO_UPDATE_CHECK': '1', 'SWAMP_NO_TELEMETRY': '1'})
        if success: self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        else: self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
        return result
    def test_native_dispatch_keeps_the_admitted_target_and_proven_step(self):
        for name in ('operation-runner', 'operation-dispatch', 'operation-integrations'):
            self.swamp('workflow', 'validate', name)
        self.swamp('workflow', 'run', 'operation-runner')
        state = self.read()
        self.assertEqual(state['runs'], 1)
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['operation']['step_results'], {'node-exporter-converge': {'changed': True}})
    def test_new_process_resumes_a_lost_effect_reply_with_the_same_key(self):
        self.state['loseRunReply'] = True
        self.save()
        self.swamp('workflow', 'run', 'operation-runner', success=False)
        self.assertEqual(self.read()['runs'], 1)
        self.swamp('workflow', 'resume', 'operation-integrations', '--from', 'execute')
        self.assertEqual(self.read()['runs'], 1)
        self.assertEqual(self.read()['operation']['status'], 'succeeded')
        self.assertEqual(self.read()['receiptReads'], 1)
    def test_new_process_replays_saved_completion_without_another_effect(self):
        self.state['loseCompletionReply'] = True
        self.save()
        self.swamp('workflow', 'run', 'operation-runner', success=False)
        self.assertEqual(self.read()['runs'], 1)
        self.swamp('workflow', 'resume', 'operation-integrations', '--from', 'execute')
        state = self.read()
        self.assertEqual(state['runs'], 1)
        self.assertEqual(len(state['completions']), 1)
        self.assertEqual(sum(call['path'].endswith('/claim') for call in state['calls']), 1)
        self.assertTrue(any(call['path'] == '/api/v1/operations/12' for call in state['calls']))
        self.assertEqual(state['operation']['status'], 'succeeded')

if __name__ == '__main__': unittest.main()
