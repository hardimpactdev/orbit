"""Compose the real Instance parent with its canonical Process destroy child."""
import importlib.util
import json
import os
import pwd
import shutil
import subprocess
import unittest
from uuid import uuid4

import yaml
from native_harness import ROOT


class InstanceRuntimeNative(unittest.TestCase):
    def test_parent_removes_process_before_owned_source(self):
        path = ROOT/'extensions/behaviors/operations/process-commands/lifecycle_native_test.py'
        spec = importlib.util.spec_from_file_location('instance_process_fixture', path)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        fixture = module.ProcessLifecycleNative()
        self.addCleanup(fixture.doCleanups)
        fixture.setUp()
        fixture.run_lifecycle()
        control = fixture.inputs['intent']['control']
        connection = fixture.inputs['intent']['ssh_execution']
        for family in ['instance-dev-command','instance-source-command','routes/mutation-command']:
            fixture.copy_behaviors('operations/'+family)
        for family, files in {'instance-lifecycle':['source_profile.ts','source_identity.ts','destroy_inspection.ts','destroy_path.ts','destroy_refusal.ts'],'routes/publication-command':['sections.ts'],'tools':['constraints.ts','composer-constraints.json']}.items():
            target = fixture.repo/'extensions/behaviors/operations'/family
            target.mkdir(parents=True,exist_ok=True)
            for filename in files:
                shutil.copy(ROOT/'extensions/behaviors/operations'/family/filename,target/filename)
        checkout = fixture.host/'apps/project/proof'
        checkout.mkdir(parents=True)
        def git(*args):
            return subprocess.run(['git','-C',str(checkout),*args],check=True,capture_output=True,text=True).stdout.strip()
        git('init','--initial-branch=main')
        git('config','user.email','fixture@example.test')
        git('config','user.name','Fixture')
        git('remote','add','origin','https://github.com/example/project.git')
        (checkout/'tracked').write_text('preserve until process removal')
        git('add','tracked')
        git('commit','-qm','fixture')
        workflow = yaml.safe_load((ROOT/'workflows/workflow-operation-instance-destroy.yaml').read_text())
        workflow = json.loads(json.dumps(workflow).replace('/tmp/orbit-instance-',str(fixture.repo/'instance-staging-')))
        for job in workflow['jobs']:
            job.pop('target')
            for step in job['steps']:
                step.pop('target')
                task=step['task']
                if task.get('behaviorType')=='@swamp/ssh':
                    task['globalArgs'].update(sshBinary=str(fixture.ssh),scpBinary=str(fixture.scp))
                if step['name']=='destroy-mint':
                    task['inputs']['run']='true'
        (fixture.repo/'workflows/workflow-instance-destroy.yaml').write_text(yaml.safe_dump(workflow,sort_keys=False))
        member={'instance_id':17,'checkout_path':str(checkout),'source_root':str(fixture.host/'apps'),'source_layout':'checkout','repository_identity':'github.com/example/project','branch':'main','starting_commit':git('rev-parse','HEAD'),'user':pwd.getpwuid(os.geteuid()).pw_name,'source_prepare_id':'fixture','registration_request_id':None,'stored_status':'active','failed_creation':False}
        empty={name:[] for name in ['publications','prepare_certificates','certificates','cleanup','lan','retire_lan','url_writes','routes','ingress','retire_ingress']}
        intent={'instance_id':17,'removal_id':str(uuid4()),'force':True,'inventory_digest':'a'*64,'source_pending':True,'repository':'https://github.com/example/project.git','protected_source_roots':[],'members':[member],'direct_destroy':{'node_user':member['user'],'runtime':{'processes':[control],'schedules':[]},'database':{'sqlite':[],'servers':[]}},'native_runtime':{'processes':[control],'schedules':[]},'source_capture':None,'teardown_commands':[],'route_mutations':{'forward':empty},'ssh_execution':connection,'execution_references_json':'{}'}
        values={'operation_id':71,'node_id':9,'kind':'instance.destroy','intent':intent,'execution_secrets':{'git_read':{'vault':'orbit-control-inputs','key':'git'}}}
        run=json.loads(fixture.cli('workflow','run','operation-instance-destroy','--stdin',input=json.dumps(values)).stdout)
        self.assertEqual(run['status'],'succeeded')
        self.assertFalse(fixture.unit.exists())
        self.assertFalse(fixture.current()['active'])
        self.assertFalse(checkout.exists())


if __name__=='__main__':
    unittest.main()
