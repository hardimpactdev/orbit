"""Exercise the pinned Swamp DAG and recover it in a fresh process."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[4]
SWAMP = os.environ.get('SWAMP_BIN', shutil.which('swamp') or 'swamp')


class NativeProcessesSchedulesTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='lane9-native-')
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        shutil.copy(ROOT / '.swamp.yaml', self.repo)
        for family in ['automatic-deploy', 'operation-runner', 'operations/processes-schedules']:
            shutil.copytree(ROOT / 'extensions/behaviors' / family, self.repo / 'extensions/behaviors' / family)
        for family, factory, relative in [('operation-runner', 'operationDiscovery', '../operations/processes-schedules/native_fixture.ts'), ('operations/processes-schedules', 'processesSchedules', './native_fixture.ts')]:
            path = self.repo / 'extensions/behaviors' / family / 'mod.ts'
            source = path.read_text().replace(f'...{factory}()', f'...{factory}(() => fixtureHttp)')
            path.write_text(f'import {{ fixtureHttp }} from "{relative}";\n' + source)
        (self.repo / 'workflows').mkdir()
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
        result = subprocess.run([SWAMP, *args, '--json'], cwd=self.repo, capture_output=True, text=True, timeout=55, env={**os.environ, 'SWAMP_NO_UPDATE_CHECK': '1', 'SWAMP_NO_TELEMETRY': '1'})
        if args[:2] == ("workflow", "run"):
            value = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]
            self.last_run_id = value.get("id")
        if success:
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        else:
            self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
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

    def assert_control_reply_recovery(self, kind, runtime_input):
        self.state['operation']['kind'] = kind
        self.state['operation']['input'] = {'node_id': 2, **runtime_input}
        self.state['loseRunReply'] = True
        self.save()
        self.swamp('workflow', 'run', 'operation-processes-schedules', success=False)
        self.assertEqual(self.read()['runPosts'], 1)
        self.swamp('workflow', 'resume', 'operation-processes-schedules')
        self.assertEqual(self.read()['operation']['status'], 'succeeded')
        self.assertEqual(self.read()['runPosts'], 1)

    def test_restart_new_process_reads_original_receipt(self):
        self.assert_control_reply_recovery('process.restart', {'process_id': 12, 'runtime': 'systemd', 'name': 'orbit-process-12-proof.service', 'on_demand': False})

    def test_schedule_run_new_process_reads_original_receipt(self):
        self.assert_control_reply_recovery('schedule.run', {'id': '830fd68c-948b-4e46-a215-229c7f510246', 'user': 'orbit', 'working_directory': '/home/orbit'})


if __name__ == '__main__':
    unittest.main()
