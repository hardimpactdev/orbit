"""Actual Incus power + canonical Node-removal graphs; model HTTP is a public fixture seam."""
import json
import shutil
import sys
import uuid

import yaml

import task_vm_lifecycle_native_test as lifecycle
from native_harness import ROOT


class TaskVmDestroyNative(lifecycle.TaskVmLifecycleFixture):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/node-removal-command')
        source = ROOT / 'extensions/behaviors/operations/certificates/scripts/role_paths.py'
        target = self.repo / 'extensions/behaviors/operations/certificates/scripts/role_paths.py'
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy(source, target)
        self.state.write_text(json.dumps({'status':'Running','exists':True}))
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
                         'execution_secrets':{}}

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
        calls=[json.loads(line) for line in self.calls.read_text().splitlines()]
        revoked=next(i for i,call in enumerate(calls) if call.get('model_revoked'))
        deleted=next(i for i,call in enumerate(calls) if 'delete' in call.get('argv',[]))
        self.assertLess(revoked,deleted)
        self.assertTrue(all('/usr/bin/incus' in call['argv'] for call in calls if 'argv' in call))

    def test_absence_still_requires_model_and_node_cleanup(self):
        self.state.write_text(json.dumps({'status':'Stopped','exists':False}))
        _,history=self.destroy()
        receipt=json.loads(history['jobs'][0]['steps'][-1]['outputs']['stdout'])
        self.assertTrue(receipt['provider']['absent'])
        self.assertTrue(receipt['removal']['removed'])
        calls=self.calls.read_text()
        self.assertIn('model_revoked',calls)
        self.assertNotIn('"stop"',calls)
        self.assertNotIn('"delete"',calls)

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
