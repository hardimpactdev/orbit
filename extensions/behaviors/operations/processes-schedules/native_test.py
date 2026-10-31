"""Exercise the pinned Swamp DAG and recover it in a fresh process."""
import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class NativeProcessesSchedulesTest(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.native_timeout = 55
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/processes-schedules')
        for family, factory, relative in [('operation-runner', 'operationDiscovery', '../operations/processes-schedules/native_fixture.ts'), ('operations/processes-schedules', 'processesSchedules', './native_fixture.ts')]:
            path = self.repo / 'extensions/behaviors' / family / 'mod.ts'
            source = path.read_text().replace(f'...{factory}()', f'...{factory}(() => fixtureHttp)')
            path.write_text(f'import {{ fixtureHttp }} from "{relative}";\n' + source)
        for name in ['processes-schedules']:
            path = ROOT / f'workflows/workflow-operation-{name}.yaml'
            (self.repo / 'workflows' / path.name).write_text(path.read_text().replace('labels: { pool: migration }\n', '').replace('queueTimeout: 60\n', ''))
        self.state = {'operation': {'id': 9, 'node_id': 2, 'kind': 'process.create', 'status': 'queued', 'cancel_requested': False, 'steps': [], 'step_results': {}, 'input': {'node_id': 2, 'process_id': 12, 'runtime': 'systemd', 'name': 'orbit-process-12-proof.service', 'unit': 'orbit-process-12-proof.service', 'content': {'$secret': 'process.unit_content', 'id': 12}, 'desired': 'stopped', 'on_demand': True, 'activation_pending': False}}, 'receipts': {}, 'calls': [], 'completions': [], 'loseRunReply': False, 'loseCompletionReply': False}
        self.save()

    def save(self):
        (self.repo / 'executor.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / 'executor.json').read_text())

    def swamp(self, *args, success=True):
        if args[:2] == ("workflow", "resume"):
            args = (*args[:3], "--run", self.last_run_id)
        result = self.cli(*args, success=success)
        if args[:2] == ("workflow", "run"):
            value = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]
            self.last_run_id = value.get("id")
        return result

    def test_systemd_create_runs_native_parent_dispatch_and_family(self):
        for name in ['processes-schedules']:
            self.swamp('workflow', 'validate', f'operation-{name}')
        self.swamp('workflow', 'run', 'operation-processes-schedules')
        state = self.read()
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual([call['name'] for call in state['calls']], ['process.stage-unit', 'process.activate-unit', 'process.status'])
        self.assertEqual(state['calls'][1]['input']['desired'], 'stopped')
        self.assertEqual(len(state['completions']), 1)

    def test_resume_after_node_reply_loss_reuses_receipt(self):
        self.state['loseRunReply'] = True
        self.save()
        self.swamp('workflow', 'run', 'operation-processes-schedules', success=False)
        self.assertEqual(len(self.read()['calls']), 1)
        self.swamp('workflow', 'resume', 'operation-processes-schedules')
        self.assertEqual(len(self.read()['calls']), 3)
        self.assertEqual(self.read()['runPosts'], 2)
        self.assertEqual(self.read()['operation']['status'], 'succeeded')

    def test_resume_after_completion_reply_loss_runs_no_new_command(self):
        self.state['loseCompletionReply'] = True
        self.save()
        self.swamp('workflow', 'run', 'operation-processes-schedules', success=False)
        self.swamp('workflow', 'resume', 'operation-processes-schedules')
        self.assertEqual(len(self.read()['calls']), 3)
        self.assertEqual(self.read()['runPosts'], 2)
        self.assertEqual(self.read()['operation']['status'], 'succeeded')
