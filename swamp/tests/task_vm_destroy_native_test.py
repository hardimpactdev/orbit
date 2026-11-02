"""The destroy child and root against a fake Incus host behind official SSH; model HTTP and Node removal are real graphs or fixtures.

The fake `ssh` runs nothing remote: it checks that every host command is `sudo -n -- timeout N incus …`,
answers from a JSON state file and logs each argv, so the test sees the exact commands and their order.
"""
import json
import sys
import uuid

import yaml

import task_vm_lifecycle_native_test as lifecycle
from native_harness import ROOT

FAKE_HOST = '''import fcntl,json,shlex,sys
from pathlib import Path
from urllib.parse import parse_qs, urlsplit
if '-M' in sys.argv or '-O' in sys.argv:raise SystemExit(0)
args=shlex.split(sys.argv[-1]);state=Path(STATE)
lock=open(STATE+'.lock','w');fcntl.flock(lock,fcntl.LOCK_EX)
with Path(CALLS).open('a') as output:output.write(json.dumps({'argv':args})+'\\n')
if Path(BLOCK).exists():raise SystemExit(255)
assert args[:4]==['sudo','-n','--','timeout'] and args[5]=='incus',args
value=json.loads(state.read_text());command=args[6:]
def url(base,name):return base+'/'+name+'?project=tasks'
if command[0]=='query':
    parsed=urlsplit(command[1]);assert parse_qs(parsed.query)=={'project':['tasks']},command
    if parsed.path=='/1.0/instances':print(json.dumps([url(parsed.path,'tvm-42')] if value['exists'] else []))
    elif parsed.path=='/1.0/storage-pools/tasks/volumes/custom':print(json.dumps([url(parsed.path,name) for name in value['volumes']]))
    else:raise SystemExit(9)
    raise SystemExit(0)
if command[:4]==['delete','--force','--project','tasks'] and command[4]=='--':
    if not value['exists']:print('Error: Instance not found',file=sys.stderr);raise SystemExit(1)
    if not value.get('stuck'):value['exists']=False
elif command[:5]==['storage','volume','delete','--project','tasks'] and command[5:7]==['--','tasks']:
    if command[7] not in value['volumes']:print('Error: Storage volume not found',file=sys.stderr);raise SystemExit(1)
    if value['exists']:print('Error: Storage volume is in use',file=sys.stderr);raise SystemExit(1)
    value['volumes'].remove(command[7])
else:raise SystemExit(9)
state.write_text(json.dumps(value))
'''


class TaskVmDestroyNative(lifecycle.TaskVmLifecycleFixture):
    def setUp(self):
        super().setUp()
        self.state.write_text(json.dumps({'exists': True, 'volumes': ['tvm-42-topology-seed', 'tvm-43-topology-seed']}))
        host = self.repo / 'incus-host'
        host.write_text('#!' + sys.executable + '\n' + FAKE_HOST.replace('STATE', repr(str(self.state)))
                        .replace('CALLS', repr(str(self.calls))).replace('BLOCK', repr(str(self.block))))
        host.chmod(0o755)
        for name in ('task-vm-destroy', 'operation-task-vm-destroy', 'node-role-remove', 'operation-node-remove'):
            value = yaml.safe_load((ROOT / ('workflows/workflow-' + name + '.yaml')).read_text())
            for job in value['jobs']:
                if 'labels' in job:
                    self.assertEqual(job.pop('labels'), {'role': 'swamp'})
                for step in job['steps']:
                    self.assertEqual(step.pop('labels'), {'role': 'swamp'})
                    task = step['task']
                    if task.get('behaviorType') == '@swamp/ssh':
                        task['globalArgs']['sshBinary'] = str(host if name == 'task-vm-destroy' else self.repo / 'ssh')
                    if step['name'] in ('worker_tokens', 'access_tokens'):
                        task['inputs']['run'] = "printf '%s' '[]'"
                    if step['name'] == 'probe':
                        # If this actually runs, preflight sees an online guest
                        # without SSH and fails. Deleted machines must skip it.
                        task['inputs']['run'] = "printf '%s' '{\"reachable\":true}'"
            (self.repo / ('workflows/workflow-' + name + '.yaml')).write_text(yaml.safe_dump(value, sort_keys=False))
        for name in ('operation-route-php-delta', 'operation-route-caddy', 'route-dns-publish'):
            self.fixture(name, 'true')
        self.fixture('task-vm-model-revoke', 'printf \'%s\\n\' \'{"model_revoked":true}\' >> ' + str(self.calls))
        role = {'schema_version': 1, 'node_id': 142, 'assignment_id': 41, 'role': 'app-dev', 'ssh': None,
                'publications': [], **{field: [] for field in ('public_ssh', 'files', 'directories', 'commands', 'stop_units', 'remove_units', 'remove_footprints', 'reload_units', 'stop_containers', 'remove_containers', 'ci_host', 'firewall', 'certificates')}}
        removal = {'schema_version': 1, 'node_id': 142, 'name': 'tvm-42', 'machine_deleted': True, 'offline': True, 'platform': 'linux',
                   'probe': {'host': '192.0.2.142', 'port': 22}, 'ssh': None, 'roles': [role], 'public_ssh': {'argv': []},
                   'managed_keys': [], 'retained_on_node': [], 'preserved_on_node': [],
                   **{field: [] for field in ('hub', 'router_rules', 'peers', 'dns', 'metrics_files', 'metrics_reload')}}
        self.envelope = {'operation_id': 7009, 'node_id': 99, 'kind': 'task_vm.destroy',
                         'intent': {'task_vm_id': 42, 'guest_node_id': 142, 'provider': 'incus', 'provider_input': self.provider,
                                    'model_key_required': True, 'model_intent': {'task_vm_id': 42}, 'removal': removal},
                         'execution_secrets': {field: {'vault': 'orbit-control-inputs', 'key': key} for field, key in {'model_key': 'task-vm-model-key-42', 'model_router_management': 'model-router-management-node-6', 'model_router_anchor': 'model-router-anchor-node-6'}.items()}}

    def fixture(self, name, command):
        value = {'id': str(uuid.uuid4()), 'name': name, 'inputs': {'type': 'object', 'additionalProperties': True},
                 'jobs': [{'name': 'fixture', 'steps': [{'name': 'result', 'task': {'type': 'behavior', 'behaviorType': 'command/shell',
                           'behaviorName': '${{ "fixture-"+run.id }}', 'methodName': 'execute', 'inputs': {'run': command}}}]}]}
        (self.repo / ('workflows/workflow-' + name + '.yaml')).write_text(yaml.safe_dump(value, sort_keys=False))

    def destroy(self, success=True):
        run = json.loads(self.cli('workflow', 'run', 'operation-task-vm-destroy', '--input', json.dumps(self.envelope), success=success).stdout)
        history = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
        return run, history

    def child(self):
        """The newest run of the shared child: its own step statuses."""
        listed = json.loads(self.cli('workflow', 'history', 'get', 'task-vm-destroy').stdout)
        return {step['name']: step['status'] for job in listed['jobs'] for step in job['steps']}

    def host_calls(self):
        if not self.calls.exists():
            return []
        return [row['argv'][3:] for row in map(json.loads, self.calls.read_text().splitlines()) if 'argv' in row and row['argv'][:1] == ['sudo']]

    def test_destroy_revokes_then_deletes_vm_then_volume_and_checks_both_gone(self):
        run, history = self.destroy()
        self.assertEqual(run['status'], 'succeeded')
        self.assertEqual([step['name'] for job in history['jobs'] for step in job['steps']], ['Destroy the task VM'])
        state = json.loads(self.state.read_text())
        self.assertFalse(state['exists'])
        self.assertEqual(state['volumes'], ['tvm-43-topology-seed'])
        writes = [argv for argv in self.host_calls() if 'query' not in argv]
        self.assertEqual(writes, [['timeout', '180', 'incus', 'delete', '--force', '--project', 'tasks', '--', 'tvm-42'],
                                  ['timeout', '180', 'incus', 'storage', 'volume', 'delete', '--project', 'tasks', '--', 'tasks', 'tvm-42-topology-seed']])
        calls = self.calls.read_text().splitlines()
        self.assertLess(next(i for i, line in enumerate(calls) if 'model_revoked' in line), next(i for i, line in enumerate(calls) if '"delete"' in line))
        steps = self.child()
        for name in ('Revoke the model key', 'Delete the VM', 'Delete the seed volume', 'Remove the guest Node', 'Check the VM is gone'):
            self.assertEqual(steps[name], 'succeeded', name)
        # The intent has no WireGuard address, so the 209 known_hosts entry is left alone (task_vm_known_hosts_test runs it).
        for name in ('Forget the guest host key', 'Read the server', 'Stop the server', 'Delete the server'):
            self.assertEqual(steps[name], 'skipped', name)
        self.assertFalse({'plan', 'inspect', 'stop', 'revoked', 'seed-before', 'seed', 'seed-delete', 'seed-after', 'result'} & set(steps))

    def test_absent_vm_and_volume_count_as_done_and_still_revoke_and_remove_the_node(self):
        self.state.write_text(json.dumps({'exists': False, 'volumes': ['tvm-43-topology-seed']}))
        run, _ = self.destroy()
        self.assertEqual(run['status'], 'succeeded')
        self.assertIn('model_revoked', self.calls.read_text())
        self.assertEqual(self.child()['Remove the guest Node'], 'succeeded')
        self.assertEqual(json.loads(self.state.read_text())['volumes'], ['tvm-43-topology-seed'])

    def test_a_failed_revoke_blocks_the_provider_delete(self):
        self.fixture('task-vm-model-revoke', 'exit 7')
        run, _ = self.destroy(success=False)
        self.assertEqual(run['status'], 'failed')
        self.assertTrue(json.loads(self.state.read_text())['exists'])
        self.assertEqual(self.host_calls(), [])

    def test_a_vm_the_delete_leaves_fails_the_end_check(self):
        self.state.write_text(json.dumps({'exists': True, 'stuck': True, 'volumes': ['tvm-42-topology-seed']}))
        run, _ = self.destroy(success=False)
        self.assertEqual(run['status'], 'failed')
        self.assertEqual(self.child()['Check the VM is gone'], 'failed')

    def test_node_removal_failure_after_the_delete_fails_the_run_before_the_check(self):
        self.fixture('operation-node-remove', 'exit 23')
        run, _ = self.destroy(success=False)
        self.assertEqual(run['status'], 'failed')
        self.assertFalse(json.loads(self.state.read_text())['exists'])
        steps = self.child()
        self.assertEqual(steps['Remove the guest Node'], 'failed')
        self.assertNotEqual(steps.get('Check the VM is gone'), 'succeeded')

    def test_tasks_cancel_parent_preserves_envelope_through_existing_destroy_child(self):
        self.assert_ending_parent('tasks.cancel')

    def test_maintenance_finish_parent_preserves_envelope_through_existing_destroy_child(self):
        self.assert_ending_parent('tasks.maintenance_finish')

    def test_standalone_destroy_refuses_tasks_ending_kinds_before_effects(self):
        for kind in ('tasks.cancel', 'tasks.maintenance_finish'):
            self.envelope['kind'] = kind
            refused = self.cli('workflow', 'run', 'operation-task-vm-destroy', '--input', json.dumps(self.envelope), success=False)
            self.assertIn('input_validation_failed', refused.stdout + refused.stderr)
        self.assertTrue(json.loads(self.state.read_text())['exists'])
        self.assertFalse(self.calls.exists())

    def assert_ending_parent(self, kind):
        self.envelope['kind'] = kind
        self.envelope['intent']['model_intent'] = {'task_vm_id': 42, 'gateway_node_id': 14,
                                                   'model_router_node_id': 6, 'model_origin': 'http://192.0.2.6'}
        # Keep the real model child's closed input contract at the HTTP fixture boundary.
        model = yaml.safe_load((self.repo / 'workflows/workflow-task-vm-model-revoke.yaml').read_text())
        model['inputs'] = yaml.safe_load((ROOT / 'workflows/workflow-task-vm-model-revoke.yaml').read_text())['inputs']
        task = model['jobs'][0]['steps'][0]['task']
        task['inputs']['env'] = {'ROOT_ID': '${{ string(inputs.operation_id) }}', 'ROOT_NODE': '${{ string(inputs.node_id) }}', 'ROOT_KIND': '${{ inputs.kind }}'}
        task['inputs']['run'] = 'printf \'{"root_operation_id":%s,"root_node_id":%s,"root_kind":"%s"}\\n\' "$ROOT_ID" "$ROOT_NODE" "$ROOT_KIND" >> ' + str(self.calls)
        (self.repo / 'workflows/workflow-task-vm-model-revoke.yaml').write_text(yaml.safe_dump(model, sort_keys=False))
        parent = {'id': str(uuid.uuid4()), 'name': 'task-cancel-destroy-parent',
                  'inputs': {'type': 'object', 'additionalProperties': True},
                  'jobs': [{'name': 'cleanup', 'steps': [
                      {'name': 'Destroy the task VM', 'task': {'type': 'workflow', 'workflowIdOrName': 'task-vm-destroy',
                       'inputs': {key: '${{ inputs.' + key + ' }}' for key in self.envelope}}}]}]}
        (self.repo / 'workflows/workflow-task-cancel-destroy-parent.yaml').write_text(yaml.safe_dump(parent, sort_keys=False))
        run = json.loads(self.cli('workflow', 'run', 'task-cancel-destroy-parent', '--input', json.dumps(self.envelope)).stdout)
        self.assertEqual(run['status'], 'succeeded')
        roots = [row for row in map(json.loads, self.calls.read_text().splitlines()) if 'root_kind' in row]
        self.assertEqual(roots, [{'root_operation_id': self.envelope['operation_id'], 'root_kind': kind, 'root_node_id': self.envelope['node_id']}])
        self.assertFalse(json.loads(self.state.read_text())['exists'])
