"""Actual native behavior locking across distinct Task VM parent runs."""
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[4]))
from tests.native_harness import NativeTestCase

ROOT = Path(__file__).resolve().parents[4]


class ModelKeysNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operations/_lib')
        node = self.repo / 'extensions/behaviors/node-worker'
        node.mkdir(parents=True)
        shutil.copy(ROOT / 'extensions/behaviors/node-worker/canary.ts', node)
        directory = self.repo / 'extensions/behaviors/operations/task-vms'
        directory.mkdir()
        for name in ('model_keys.ts', 'model_keys_native.ts', 'model_keys_native_fixture.ts'):
            shutil.copy(ROOT / 'extensions/behaviors/operations/task-vms' / name, directory)
        module = directory / 'model_keys_native.ts'
        module.write_text('import {fixtureHttp,fixtureIdentity,fixtureRequest} from "./model_keys_native_fixture.ts";\n' + module.read_text().replace(
            'export const behavior = taskVmModelKeys();',
            'export const behavior = taskVmModelKeys(() => fixtureHttp,fixtureIdentity,fixtureRequest);'))
        self.native_env = {f'MODEL_TEST_{key}': secrets.token_hex(32) for key in ('ANCHOR', 'VM71', 'VM72', 'OTHER', 'MANAGEMENT')}
        self.secrets = tuple(self.native_env.values())
        binding = dict(model_router_node_id=17, gateway_node_id=14, model_origin='http://models.orbit:8317')
        state = dict(operations={
            '7': dict(id=7,kind='task_vm.provision',node_id=9,status='running',cancel_requested=False,input=dict(task_vm_id=71,**binding)),
            '8': dict(id=8,kind='task_vm.destroy',node_id=9,status='running',cancel_requested=False,input=dict(task_vm_id=72,**binding)),
        }, members=['anchor', 'vm72', 'other'], started={}, anonymous={}, events=[])
        (self.repo / 'model-key-test.json').write_text(json.dumps(state))
        self.cli('workflow', 'schema', 'get')
        for action in ('issue','revoke'):
            self.cli('workflow','create','model-key-'+action+'-parent')
            path = self.repo / 'workflows' / ('workflow-model-key-'+action+'-parent.yaml')
            workflow_id = path.read_text().split('id: ',1)[1].splitlines()[0]
            path.write_text(f'''id: {workflow_id}
name: model-key-{action}-parent
inputs:
  properties:
    operation_id: {{type: integer}}
    kind: {{type: string}}
  required: [operation_id, kind]
jobs:
  - name: key-step
    steps:
      - name: key-step
        writes: true
        task:
          type: behavior
          behaviorType: '@nckrtl/task-vm-model-keys'
          behaviorName: task-vm-model-router-17
          methodName: {action}
          inputs:
            operation_id: '${{{{ inputs.operation_id }}}}'
            kind: '${{{{ inputs.kind }}}}'
            node_id: 9
            run_id: '${{{{ run.id }}}}'
''')
            self.cli('workflow','validate','model-key-'+action+'-parent')

    def test_distinct_issue_and_revoke_parents_share_one_native_router_lock(self):
        env = dict(os.environ, **self.native_env, SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1')
        for key in ('SWAMP_SERVER_URL','SWAMP_SERVE_URL','SWAMP_SERVER_TOKEN','SWAMP_SERVER_TOKEN_FILE','SWAMP_REPO_DIR','SWAMP_CA_CERT'):
            env.pop(key,None)
        processes=[]
        try:
            for action,operation,kind in [('issue',7,'task_vm.provision'),('revoke',8,'task_vm.destroy')]:
                processes.append(subprocess.Popen([os.environ.get('SWAMP_BIN','swamp'),'workflow','run','model-key-'+action+'-parent','--input',f'operation_id={operation}','--input','kind='+kind,'--json'],cwd=self.repo,env=env,text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE))
            for process in processes:
                stdout,stderr=process.communicate(timeout=60)
                self.assert_private_absent(stdout,stderr)
                self.assertEqual(process.returncode,0,stdout+stderr)
        finally:
            for process in processes:
                if process.poll() is None:
                    process.kill()
                    process.communicate()
        state=json.loads((self.repo/'model-key-test.json').read_text())
        self.assertIn(state['events'],[['start-7','end-7','start-8','end-8'],['start-8','end-8','start-7','end-7']])
        self.assertCountEqual(state['members'],['anchor','other','vm71'])
        self.assertEqual(len(state['members']),3)
        self.assert_private_absent()


if __name__=='__main__':
    unittest.main()
