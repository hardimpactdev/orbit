"""Native direct Incus DAG/recovery fixture. Never contacts a fleet Node or sudo."""
import json
from pathlib import Path
import unittest
import sys

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class DirectIncusNative(NativeTestCase):
    native_timeout = 50

    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/task-vms', 'operations/_lib')
        module = self.repo / 'extensions/behaviors/operations/task-vms/incus_direct.ts'
        module.write_text('import {fixtureHttp, fixtureAction} from "./incus_native_fixture.ts";\n' + module.read_text().replace('...incusDirect()', '...incusDirect(() => fixtureHttp, fixtureAction)'))
        source = ROOT / 'workflows/workflow-operation-task-vm-incus.yaml'
        self.assertIn('target: \'${{ "orbit-node-" + string(inputs.node_id) }}\'', source.read_text())
        # Disposable local DAG proof only. The live source keeps exact Node placement.
        (self.repo / 'workflows' / source.name).write_text('\n'.join(line for line in source.read_text().splitlines() if not line.strip().startswith(('target:', 'labels:'))) + '\n')
        self.state = {'operation': {'id': 7, 'node_id': 13, 'kind': 'task_vm.incus_start', 'status': 'queued', 'result': None, 'cancel_requested': False, 'input': {'agent_sha256': 'a' * 64, 'intent': {'task_vm_id': 5, 'vm': {'project': 'orbit-tasks', 'name': 'tvm-5', 'cidr': '10.251.77.0/24'}, 'operation': {'kind': 'start'}}}}, 'calls': [], 'actions': [], 'bound': True}
        self.save()

    def save(self):
        (self.repo / 'incus-direct-fixture.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / 'incus-direct-fixture.json').read_text())

    def run_workflow(self, success=True):
        result = self.cli('workflow', 'run', 'operation-task-vm-incus', '--input', 'operation_id=7', '--input', 'node_id=13', '--input', 'kind=' + self.state['operation']['kind'], success=success)
        return json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']

    def test_all_four_effects_use_the_direct_dag_and_only_admitted_input(self):
        for verb in ['start', 'stop', 'delete', 'create']:
            self.state['operation'].update(kind='task_vm.incus_' + verb, status='queued')
            self.state['operation']['input']['intent']['operation'] = {
                'start': {'kind': 'start'}, 'stop': {'kind': 'stop'}, 'delete': {'kind': 'delete', 'pool': 'orbit-tasks'},
                'create': {'kind': 'create', 'image': 'stock', 'expected_fingerprint': None, 'base_alias': 'stock', 'gateway_public_key': 'ssh-ed25519 public', 'cpus': 2, 'memory': '4GiB', 'disk': '20GiB', 'network': 'orbit-tasks', 'pool': 'orbit-tasks', 'topology': False},
            }[verb]
            self.save()
            self.run_workflow()
            saved = self.read()
            self.assertEqual(saved['operation']['status'], 'succeeded')
            self.assertEqual(saved['actions'][-1]['input']['operation'], self.state['operation']['input']['intent']['operation'])
            self.assertTrue(all('/direct-operations/' in path for path in saved['calls']))

    def test_lost_projection_reply_resumes_without_repeating_the_effect(self):
        self.state['loseProjection'] = True
        self.save()
        run = self.run_workflow(success=False)
        self.cli('workflow', 'resume', 'operation-task-vm-incus', '--run', run)
        self.assertEqual(len(self.read()['actions']), 1)
        self.assertEqual(self.read()['operation']['status'], 'succeeded')

    def test_lost_node_reply_never_proves_success_and_resumes_the_same_command(self):
        self.state['loseNode'] = True
        self.save()
        run = self.run_workflow(success=False)
        self.assertEqual(self.read()['operation']['status'], 'running')
        self.cli('workflow', 'resume', 'operation-task-vm-incus', '--run', run)
        saved = self.read()
        self.assertEqual(saved['actions'][0], saved['actions'][1])
        self.assertEqual(saved['operation']['status'], 'succeeded')

    def test_unbound_start_and_foreign_node_never_reach_an_action(self):
        self.state['bound'] = False
        self.save()
        self.run_workflow(success=False)
        self.assertEqual(self.read()['actions'], [])
        self.state['bound'] = True
        self.state['operation']['node_id'] = 209
        self.save()
        self.run_workflow(success=False)
        self.assertEqual(self.read()['actions'], [])


if __name__ == '__main__':
    unittest.main()
