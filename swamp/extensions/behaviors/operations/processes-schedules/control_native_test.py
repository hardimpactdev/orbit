"""Native Swamp direct-boundary recovery tests. Placement is removed only for the local fixture."""
import hashlib
import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

class ScheduleNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/processes-schedules')
        helper = self.repo/'extensions/behaviors/operations/node/system_command.ts'
        helper.parent.mkdir(parents=True, exist_ok=True)
        helper.write_bytes((ROOT/'extensions/behaviors/operations/node/system_command.ts').read_bytes())
        failure = self.repo/'extensions/behaviors/operations/failure.ts'
        failure.write_text('import {http} from "./processes-schedules/control_native_fixture.ts";\n'+(ROOT/'extensions/behaviors/operations/failure.ts').read_text().replace('export const behavior = nativeFailure();', 'export const behavior = nativeFailure(() => http);'))
        module = self.repo/'extensions/behaviors/operations/processes-schedules/control.ts'
        module.write_text('import {http, action, install} from "./control_native_fixture.ts";\nimport {installSchedule} from "./installation.ts";\n'+module.read_text().replace('export const behavior = scheduleControl();', 'export const behavior = scheduleControl(() => http, action, (input, gateway, signal) => installSchedule(input, gateway, signal, install));'))
        source = ROOT/'workflows/workflow-operation-schedule-control.yaml'
        text = '\n'.join(line for line in source.read_text().splitlines() if not line.lstrip().startswith(('labels:', 'target:')))+'\n'
        (self.repo/'workflows'/source.name).write_text(text)
        self.state = {'operation': {'id': 17, 'node_id': 9, 'kind': 'schedule.run', 'status': 'queued', 'cancel_requested': False, 'result': None,
            'input': {'command_hash': hashlib.sha256(b'/usr/bin/true').hexdigest(), 'group': 'orbit', 'home': '/home/orbit', 'shell': '/bin/sh', 'login_shell': False, 'calendar': 'daily', 'timeout_seconds': 60, 'desired': 'disabled', 'id': '9a067a27-c2ce-45c5-83f5-735e03dc5b2f', 'user': 'orbit', 'working_directory': '/home/orbit'}}, 'actions': 0, 'calls': []}
        self.save()
        source = ROOT/'workflows/workflow-operation-schedule-create.yaml'
        text = '\n'.join(line for line in source.read_text().splitlines() if not line.lstrip().startswith(('labels:', 'target:')))+'\n'
        (self.repo/'workflows'/source.name).write_text(text)

    def save(self):
        (self.repo/'schedule-native.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo/'schedule-native.json').read_text())

    def run_workflow(self, success=True):
        result = self.cli('workflow', 'run', 'operation-schedule-control', '--input', 'operation_id=17', '--input', 'node_id=9', '--input', 'kind=schedule.run', success=success)
        payload = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]
        self.assertIn('id', payload, result.stdout+result.stderr)
        self.run_id = payload['id']

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

    def test_native_refusal_is_projected_before_the_native_run_fails(self):
        self.state['refuse'] = True; self.save()
        self.run_workflow(success=False)
        self.assertEqual(self.read()['operation']['status'], 'failed')
        self.assertEqual(self.read()['actions'], 1)

    def test_foreign_binding_refuses_until_corrected_then_native_resume_succeeds(self):
        self.state['unbound'] = True; self.save()
        self.run_workflow(success=False)
        state = self.read()
        self.assertEqual(state['actions'], 0)
        self.assertEqual(len(state['calls']), 2)
        self.assertTrue(state['calls'][-1]['url'].endswith('/failure'))
        state['unbound'] = False; self.state = state; self.save()
        self.cli('workflow', 'resume', 'operation-schedule-control', '--run', self.run_id, '--from', 'control')
        self.assertEqual(self.read()['actions'], 1)
        self.assertEqual(self.read()['operation']['status'], 'succeeded')

    def test_create_installs_private_command_in_same_native_root(self):
        self.state['operation']['kind'] = 'schedule.create'
        self.save()
        self.cli('workflow', 'run', 'operation-schedule-create', '--input', 'operation_id=17', '--input', 'node_id=9', '--input', 'kind=schedule.create')
        state = self.read()
        self.assertEqual(state['actions'], 1)
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['seen']['desired'], 'disabled')
        self.assertNotIn('command', state['seen'])
