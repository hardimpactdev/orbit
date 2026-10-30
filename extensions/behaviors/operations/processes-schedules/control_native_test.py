"""Native Swamp direct-boundary recovery tests. Placement is removed only for the local fixture."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[4]
SWAMP = os.environ['SWAMP_BIN']

class ScheduleNative(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='schedule-native-', dir=os.environ.get('TMPDIR'))
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        shutil.copy(ROOT / '.swamp.yaml', self.repo)
        for family in ['automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/processes-schedules']:
            shutil.copytree(ROOT/'extensions/behaviors'/family, self.repo/'extensions/behaviors'/family)
        module = self.repo/'extensions/behaviors/operations/processes-schedules/control.ts'
        module.write_text('import {http, action} from "./control_native_fixture.ts";\n'+module.read_text().replace('export const behavior = scheduleControl();', 'export const behavior = scheduleControl(() => http, action);'))
        source = ROOT/'workflows/workflow-operation-schedule-control.yaml'
        text = '\n'.join(line for line in source.read_text().splitlines() if not line.lstrip().startswith(('labels:', 'target:')))+'\n'
        (self.repo/'workflows').mkdir()
        (self.repo/'workflows'/source.name).write_text(text)
        self.state = {'operation': {'id': 17, 'node_id': 9, 'kind': 'schedule.run', 'status': 'queued', 'cancel_requested': False, 'result': None,
            'input': {'id': '9a067a27-c2ce-45c5-83f5-735e03dc5b2f', 'user': 'orbit', 'working_directory': '/home/orbit'}}, 'actions': 0, 'calls': []}
        self.save()

    def save(self):
        (self.repo/'schedule-native.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo/'schedule-native.json').read_text())

    def cli(self, *args, success=True):
        env = dict(os.environ, SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1')
        for key in ['SWAMP_SERVER_URL', 'SWAMP_SERVE_URL', 'SWAMP_SERVER_TOKEN', 'SWAMP_SERVER_TOKEN_FILE', 'SWAMP_REPO_DIR', 'SWAMP_CA_CERT']:
            env.pop(key, None)
        result = subprocess.run([SWAMP, *args, '--json'], cwd=self.repo, env=env, text=True, capture_output=True, timeout=60)
        self.assertEqual(result.returncode == 0, success, result.stdout+result.stderr)
        return result

    def run_workflow(self, success=True):
        result = self.cli('workflow', 'run', 'operation-schedule-control', '--input', 'operation_id=17', '--input', 'node_id=9', '--input', 'kind=schedule.run', success=success)
        self.run_id = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']

    def test_native_dag_executes_one_local_action_without_gateway_execution(self):
        self.run_workflow()
        state = self.read()
        self.assertEqual(state['actions'], 1)
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertTrue(all('/direct-operations/17' in call['url'] for call in state['calls']))

    def test_lost_committed_projection_is_read_without_another_action(self):
        self.state['loseProjection'] = True; self.save()
        self.run_workflow(success=False)
        self.cli('workflow', 'resume', 'operation-schedule-control', '--run', self.run_id, '--from', 'control')
        self.assertEqual(self.read()['actions'], 1)
        self.assertEqual(self.read()['operation']['status'], 'succeeded')

    def test_agent_refusal_is_projected_before_the_native_run_fails(self):
        self.state['refuse'] = True; self.save()
        self.run_workflow(success=False)
        self.assertEqual(self.read()['operation']['status'], 'failed')
        self.assertEqual(self.read()['actions'], 1)

    def test_first_read_refuses_until_gateway_binding_then_native_resume_succeeds(self):
        self.state['unbound'] = True; self.save()
        self.run_workflow(success=False)
        state = self.read()
        self.assertEqual(state['actions'], 0)
        self.assertEqual(len(state['calls']), 1)
        state['unbound'] = False; self.state = state; self.save()
        self.cli('workflow', 'resume', 'operation-schedule-control', '--run', self.run_id, '--from', 'control')
        self.assertEqual(self.read()['actions'], 1)
        self.assertEqual(self.read()['operation']['status'], 'succeeded')
