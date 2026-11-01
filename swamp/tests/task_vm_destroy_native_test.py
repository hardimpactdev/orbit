"""Actual Incus power + canonical Node-removal graphs; model HTTP is a public fixture seam."""
import json
import os
import pwd
import runpy
import shutil
import uuid
from pathlib import Path

import task_vm_lifecycle_native_test as lifecycle
import yaml
from native_harness import ROOT


class TaskVmDestroyNative(lifecycle.TaskVmLifecycleFixture):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/node-removal-command')
        source = ROOT / 'extensions/behaviors/operations/certificates/scripts/role_paths.py'
        target = self.repo / 'extensions/behaviors/operations/certificates/scripts/role_paths.py'
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy(source, target)
        self.state.write_text(json.dumps({'status':'Running','exists':True,'volumes':['tvm-42-topology-seed','tvm-43-topology-seed']}))
        for name in ('task-vm-destroy','operation-task-vm-destroy','node-role-remove','operation-node-remove'):
            value = yaml.safe_load((ROOT / ('workflows/workflow-' + name + '.yaml')).read_text())
            if 'target' in value:
                self.assertEqual(value.pop('target'), 'orbit-node-209')
            for job in value['jobs']:
                if 'target' in job:
                    self.assertEqual(job.pop('target'), 'orbit-node-209')
                for step in job['steps']:
                    self.assertEqual(step.pop('target'), 'orbit-node-209')
                    task = step['task']
                    if task.get('behaviorType') == '@swamp/ssh':
                        task['globalArgs']['sshBinary'] = str(self.repo / 'ssh')
                    if step['name'] in ('worker_tokens','access_tokens'):
                        task['inputs']['run'] = "printf '%s' '[]'"
                    if step['name'] == 'probe':
                        # If this actually runs, preflight sees an online guest
                        # without SSH and fails. Deleted machines must skip it.
                        task['inputs']['run'] = "printf '%s' '{\"reachable\":true}'"
            (self.repo / ('workflows/workflow-' + name + '.yaml')).write_text(yaml.safe_dump(value, sort_keys=False))
        for name in ('operation-route-php-delta','operation-route-caddy','route-dns-publish'):
            self.fixture(name, 'true')
        self.fixture('task-vm-model-revoke', 'printf \'%s\\n\' \'{"task_vm_id":42,"revoked":true}\'')
        model = yaml.safe_load((self.repo/'workflows/workflow-task-vm-model-revoke.yaml').read_text())
        model['jobs'][0]['steps'][0]['task']['inputs']['run'] = 'printf \'%s\\n\' \'{"model_revoked":true}\' >> "$CALLS"\n' + model['jobs'][0]['steps'][0]['task']['inputs']['run']
        model['jobs'][0]['steps'][0]['task']['inputs']['env'] = {'CALLS':str(self.calls)}
        (self.repo/'workflows/workflow-task-vm-model-revoke.yaml').write_text(yaml.safe_dump(model, sort_keys=False))
        role = {'schema_version':1,'node_id':142,'assignment_id':41,'role':'app-dev','ssh':None,
                'publications':[], **{field:[] for field in ('stop_units','remove_units','remove_footprints','reload_units','stop_containers','remove_containers','ci_host','firewall','certificates')}}
        removal = {'schema_version':1,'node_id':142,'name':'tvm-42','machine_deleted':True,'offline':True,'platform':'linux',
                   'probe':{'host':'192.0.2.142','port':22},'ssh':None,'roles':[role],'public_ssh':{'argv':[]},
                   'managed_keys':[], 'retained_on_node':[], 'preserved_on_node':[],
                   **{field:[] for field in ('hub','router_rules','peers','dns','metrics_files','metrics_reload')}}
        self.envelope = {'operation_id':7009,'node_id':99,'kind':'task_vm.destroy',
                         'intent':{'task_vm_id':42,'guest_node_id':142,'provider':'incus','provider_input':self.provider,
                                   'model_key_required':True,'model_intent':{'task_vm_id':42},'removal':removal},
                         'execution_secrets': {field: {'vault': 'orbit-control-inputs', 'key': key} for field, key in {'model_key': 'task-vm-model-key-42', 'model_router_management': 'model-router-management-node-6', 'model_router_anchor': 'model-router-anchor-node-6'}.items()}}

    def fixture(self, name, command):
        value={'id':str(uuid.uuid4()),'name':name,'inputs':{'type':'object','additionalProperties':True},
               'jobs':[{'name':'fixture','steps':[{'name':'result','task':{'type':'behavior','behaviorType':'command/shell',
                   'behaviorName':'${{ "fixture-"+run.id }}','methodName':'execute','inputs':{'run':command}}}]}]}
        (self.repo / ('workflows/workflow-' + name + '.yaml')).write_text(yaml.safe_dump(value,sort_keys=False))

    def destroy(self, success=True):
        run=json.loads(self.cli('workflow','run','operation-task-vm-destroy','--input',json.dumps(self.envelope),success=success).stdout)
        history=json.loads(self.cli('workflow','history','get',run['id']).stdout)
        return run,history

    def test_destroy_stops_then_revokes_then_deletes_then_returns_canonical_offline_cleanup(self):
        run,history=self.destroy()
        self.assertEqual(run['status'],'succeeded')
        receipt=json.loads(history['jobs'][0]['steps'][-1]['outputs']['stdout'])
        self.assertTrue(receipt['cleanup_complete'])
        self.assertTrue(receipt['removal']['offline'])
        self.assertEqual(receipt['removal']['roles_shed'],['app-dev'])
        self.assertFalse(json.loads(self.state.read_text())['exists'])
        self.assertEqual(json.loads(self.state.read_text())['volumes'],['tvm-43-topology-seed'])
        calls=[json.loads(line) for line in self.calls.read_text().splitlines()]
        revoked=next(i for i,call in enumerate(calls) if call.get('model_revoked'))
        deleted=next(i for i,call in enumerate(calls) if 'delete' in call.get('argv',[]))
        self.assertLess(revoked,deleted)
        self.assertTrue(all('/usr/bin/incus' in call['argv'] for call in calls if 'argv' in call))

    def test_tasks_cancel_destroys_the_owned_vm_club_file_and_preserves_other_guests(self):
        delivery = runpy.run_path(str(ROOT / 'extensions/behaviors/operations/task-repository/scripts/proof_club.py'))
        self.assertEqual(delivery['DESTINATION'], Path('/home/orbit/.config/swamp/club-api-key'))
        material = os.urandom(32).hex().encode()
        credential = self.guest / delivery['DESTINATION'].relative_to('/home/orbit')
        delivery['store'](material, pwd.getpwuid(os.getuid()).pw_name, credential)
        self.assertEqual(credential.stat().st_mode & 0o777, 0o600)
        self.assertEqual(credential.stat().st_uid, os.getuid())
        other = self.repo / 'other-guest/.config/swamp/club-api-key'
        other.parent.mkdir(parents=True)
        other.write_bytes(b'other-guest-owned-material')

        # The provider fixture deletes its backing guest filesystem only on the real Incus delete command.
        ssh = self.repo / 'ssh'
        source = ssh.read_text().replace('import json,shlex,subprocess,sys', 'import json,shlex,shutil,subprocess,sys')
        before = "elif action=='delete':value['exists']=False"
        self.assertIn(before, source)
        source = source.replace(before, "elif action=='delete':\n                value['exists']=False\n                shutil.rmtree(" + repr(str(self.guest)) + ")")
        ssh.write_text(source)

        self.envelope['intent']['removal']['task_vm_guest'] = True
        self.assert_ending_parent('tasks.cancel')
        self.assertFalse(credential.exists())
        self.assertFalse(self.guest.exists())
        self.assertEqual(other.read_bytes(), b'other-guest-owned-material')
        # The private guest file was legitimate until deletion; now all retained history/data must be clean.
        self.secrets = (material.decode(),)
        self.assert_private_absent()

    def test_absence_still_requires_model_and_node_cleanup(self):
        self.state.write_text(json.dumps({'status':'Stopped','exists':False,'volumes':['tvm-42-topology-seed','tvm-43-topology-seed']}))
        _,history=self.destroy()
        receipt=json.loads(history['jobs'][0]['steps'][-1]['outputs']['stdout'])
        self.assertTrue(receipt['provider']['absent'])
        self.assertTrue(receipt['removal']['removed'])
        calls=self.calls.read_text()
        self.assertIn('model_revoked',calls)
        self.assertNotIn('"stop"',calls)
        self.assertEqual(json.loads(self.state.read_text())['volumes'],['tvm-43-topology-seed'])

    def test_foreign_model_proof_blocks_provider_delete(self):
        self.fixture('task-vm-model-revoke','printf \'%s\\n\' \'{"task_vm_id":43,"revoked":true}\'')
        run,_=self.destroy(success=False)
        self.assertEqual(run['status'],'failed')
        self.assertTrue(json.loads(self.state.read_text())['exists'])
        self.assertNotIn('"delete"',self.calls.read_text())

    def test_cleanup_failure_after_provider_absence_never_returns_complete(self):
        self.fixture('operation-node-remove','exit 23')
        run,history=self.destroy(success=False)
        self.assertEqual(run['status'],'failed')
        self.assertFalse(json.loads(self.state.read_text())['exists'])
        self.assertEqual(history['jobs'][0]['steps'][-1]['status'],'skipped')


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
        task['inputs']['env'].update({'ROOT_ID': '${{ string(inputs.operation_id) }}',
                                     'ROOT_NODE': '${{ string(inputs.node_id) }}', 'ROOT_KIND': '${{ inputs.kind }}'})
        task['inputs']['run'] = 'printf \'{"root_operation_id":%s,"root_node_id":%s,"root_kind":"%s"}\\n\' "$ROOT_ID" "$ROOT_NODE" "$ROOT_KIND" >> "$CALLS"\n' + task['inputs']['run']
        (self.repo / 'workflows/workflow-task-vm-model-revoke.yaml').write_text(yaml.safe_dump(model, sort_keys=False))
        model_inputs = {**self.envelope, 'intent': self.envelope['intent']['model_intent']}
        self.cli('workflow', 'run', 'task-vm-model-revoke', '--input', json.dumps(model_inputs))
        parent = {'id': str(uuid.uuid4()), 'name': 'task-cancel-destroy-parent',
                  'inputs': {'type': 'object', 'additionalProperties': True},
                  'jobs': [{'name': 'cleanup', 'steps': [
                      {'name': 'vm', 'task': {'type': 'workflow', 'workflowIdOrName': 'task-vm-destroy',
                       'inputs': {key: '${{ inputs.' + key + ' }}' for key in self.envelope}}},
                      {'name': 'result', 'dependsOn': [{'step': 'vm', 'condition': {'type': 'succeeded'}}],
                       'task': {'type': 'behavior', 'behaviorType': 'command/shell', 'behaviorName': '${{ "cancel-result-"+run.id }}',
                                'methodName': 'execute', 'inputs': {'run': 'printf \'%s\\n\' "$RESULT"',
                                'env': {'RESULT': '${{ steps.vm.outputs.result.result_json }}'}}}}]}]}
        (self.repo / 'workflows/workflow-task-cancel-destroy-parent.yaml').write_text(yaml.safe_dump(parent, sort_keys=False))
        run = json.loads(self.cli('workflow', 'run', 'task-cancel-destroy-parent', '--input', json.dumps(self.envelope)).stdout)
        history = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
        receipt = json.loads(history['jobs'][0]['steps'][-1]['outputs']['stdout'])
        self.assertTrue(receipt['cleanup_complete'])
        self.assertEqual(receipt['task_vm_id'], 42)
        self.assertTrue(receipt['removal']['removed'])
        observed = [json.loads(line) for line in self.calls.read_text().splitlines()]
        roots = [row for row in observed if 'root_kind' in row]
        self.assertEqual(len(roots), 2)
        for root in roots:
            self.assertEqual(root, {'root_operation_id': self.envelope['operation_id'],
                                    'root_kind': kind, 'root_node_id': self.envelope['node_id']})
        self.assertFalse(json.loads(self.state.read_text())['exists'])
