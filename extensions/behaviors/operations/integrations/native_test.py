"""Prove integration recovery in a new pinned Swamp process, using a disposable Gateway fixture."""
import json
from pathlib import Path
import shutil
import unittest
import sys

ROOT = Path(__file__).resolve().parents[4]
FAMILY = Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

class IntegrationNativeTest(NativeTestCase):
    native_timeout = 45

    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operation-runner')
        conn = self.repo / 'extensions/behaviors/operations/conn'
        conn.mkdir(parents=True, exist_ok=True)
        shutil.copy(FAMILY.parent / 'conn/maintenance.ts', conn / 'maintenance.ts')
        destination = self.repo / 'extensions/behaviors/operations/integrations'
        shutil.copytree(FAMILY, destination)
        fixture = self.repo / 'extensions/behaviors/_test/executor.ts'
        fixture.parent.mkdir()
        shutil.copy(FAMILY / 'native_fixture.ts', fixture)
        for name, factory, relative in [('operation-runner', 'operationDiscovery', '../_test/executor.ts'), ('operations/integrations', 'integrations', '../../_test/executor.ts')]:
            path = self.repo / 'extensions/behaviors' / name / 'mod.ts'
            path.write_text('import { fixtureHttp } from "' + relative + '";\n' + path.read_text().replace('...' + factory + '()', '...' + factory + '(() => fixtureHttp)'))
        for name in ('integrations',):
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
        if args[:2] == ("workflow", "resume"):
            args = (*args[:3], "--run", self.last_run_id)
        result = self.cli(*args, success=success)
        if args[:2] == ("workflow", "run"):
            value = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]
            self.last_run_id = value.get("id")
        return result
    def test_native_dispatch_keeps_the_admitted_target_and_proven_step(self):
        for name in ('operation-integrations',):
            self.swamp('workflow', 'validate', name)
        self.swamp('workflow', 'run', 'operation-integrations')
        state = self.read()
        self.assertEqual(state['runs'], 1)
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['operation']['step_results'], {'node-exporter-converge': {'changed': True}})
    def test_exporter_preference_without_metrics_runs_only_the_safe_receipt(self):
        self.state['operation']['kind'] = 'metrics.exporter_enable'
        self.state['operation']['input'] = {'preference_only': True, 'exporter_intent': {'node_id': 2, 'desired': True}, 'fleet': []}
        self.state['operation']['execution_context'] = {'descriptors': [{'key': 'integration-receipt', 'step': 'integration-receipt',
            'transport': 'gateway', 'name': 'metrics.receipt', 'input': {'backend': 'recorded'}, 'resumable': True, 'required': True}]}
        self.save()
        self.swamp('workflow', 'run', 'operation-integrations')
        state = self.read()
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['runs'], 0)
        self.assertEqual(state['gatewayRuns'], 1)
        self.assertEqual(state['completions'][0]['result'], {'backend': 'recorded'})

    def test_new_process_resumes_a_lost_effect_reply_with_the_same_key(self):
        self.state['loseRunReply'] = True
        self.save()
        self.swamp('workflow', 'run', 'operation-integrations', success=False)
        self.assertEqual(self.read()['runs'], 1)
        self.swamp('workflow', 'resume', 'operation-integrations')
        self.assertEqual(self.read()['runs'], 1)
        self.assertEqual(self.read()['operation']['status'], 'succeeded')
        self.assertEqual(self.read()['receiptReads'], 1)
    def test_new_process_replays_saved_completion_without_another_effect(self):
        self.state['loseCompletionReply'] = True
        self.save()
        self.swamp('workflow', 'run', 'operation-integrations', success=False)
        self.assertEqual(self.read()['runs'], 1)
        self.swamp('workflow', 'resume', 'operation-integrations')
        state = self.read()
        self.assertEqual(state['runs'], 1)
        self.assertEqual(len(state['completions']), 1)
        self.assertEqual(sum(call['path'].endswith('/claim') for call in state['calls']), 1)
        self.assertTrue(any(call['path'] == '/api/v1/operations/12' for call in state['calls']))
        self.assertEqual(state['operation']['status'], 'succeeded')

if __name__ == '__main__': unittest.main()
