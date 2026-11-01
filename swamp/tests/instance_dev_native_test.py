"""Real e459 graph and official SSH methods, confined to a disposable local binary seam."""
import json
import os
import pwd
import shutil
import subprocess
import sys
import unittest

import yaml
from native_harness import ROOT, NativeTestCase


class InstanceDevelopmentNative(NativeTestCase):
    native_timeout = 180

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/instance-dev-command','operations/instance-vp-command','operations/instance-source-command')
        for family, files in {'instance-lifecycle':['source_profile.ts','source_identity.ts','destroy_inspection.ts','destroy_path.ts','destroy_refusal.ts'], 'instance-deploy':['vp_requirements.ts','dotenv.ts'], 'tools':['constraints.ts','composer-constraints.json'], 'node-bootstrap':['tool_setup_assets.ts']}.items():
            target=self.repo/'extensions/behaviors/operations'/family
            target.mkdir(parents=True,exist_ok=True)
            for file in files: shutil.copy(ROOT/'extensions/behaviors/operations'/family/file,target/file)
        shutil.copytree(ROOT/'extensions/behaviors/operations/node-bootstrap/tool_scripts',self.repo/'extensions/behaviors/operations/node-bootstrap/tool_scripts')
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        self.control = self.repo / 'control'
        self.host = self.repo / 'host'
        self.control.mkdir(); self.host.mkdir()
        self.application = self.host / 'apps/project/proof'
        self.application.mkdir(parents=True)
        self.git('init', '--initial-branch=main')
        self.git('config', 'user.email', 'fixture@example.test'); self.git('config', 'user.name', 'Fixture')
        self.git('remote', 'add', 'origin', 'https://github.com/example/project.git')
        (self.application / '.env.example').write_text('TEMPLATE="template-private-sentinel"\n')
        (self.application / 'package.json').write_text('{"name":"fixture"}')
        self.git('add', '.'); self.git('commit', '-qm', 'fixture')
        commit = self.git('rev-parse', 'HEAD').strip()
        info = self.application.stat()
        (self.application / '.git/orbit-source-prepare').write_text('admitted-prepare:'+str(info.st_dev)+':'+str(info.st_ino)+'\n')
        self.identity = self.repo / 'identity'; self.identity.write_text('synthetic identity'); self.identity.chmod(0o600)
        self.failure = self.repo / 'fail-command'
        binary = self.repo / 'ssh'
        binary.write_text('#!'+sys.executable+'\n'+SSH.replace('CONTROL', repr(str(self.control))).replace('HOST', repr(str(self.host))).replace('FAILURE',repr(str(self.failure)))); binary.chmod(0o700)
        scp = self.repo / 'scp'
        scp.write_text('#!'+sys.executable+'\n'+SCP.replace('CONTROL',repr(str(self.control))).replace('HOST',repr(str(self.host)))); scp.chmod(0o700)
        self.workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-instance-create.yaml').read_text())
        self.workflow = json.loads(json.dumps(self.workflow).replace('/tmp/orbit-instance-',str(self.control)+'/stage-').replace('/tmp/orbit-source-',str(self.control)+'/source-'))
        for job in self.workflow['jobs']:
            job.pop('target')
            for step in job['steps']:
                step.pop('target')
                task = step['task']
                if task.get('behaviorType') == '@swamp/ssh': task['globalArgs'].update(sshBinary=str(binary), scpBinary=str(scp))
                if step['name'] == 'create-mint': task['inputs']['run'] = 'true'
        (self.repo / 'workflows/workflow-create.yaml').write_text(yaml.safe_dump(self.workflow,sort_keys=False))
        self.cli('vault','create','local_encryption','orbit-control-inputs')
        self.cli('vault','put','orbit-control-inputs','environment',json.dumps({'values':{'STORED':'stored-private-sentinel'},'name':'Fixture','framework_key':None}))
        self.cli('vault','put','orbit-control-inputs','git',json.dumps({'github_token':None}))
        ssh = {'identity_file':str(self.identity),'target':{'host':'node.invalid','port':22,'user':pwd.getpwuid(os.geteuid()).pw_name,'host_keys':['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}}
        self.input = {'operation_id':71,'node_id':9,'kind':'instance.create','execution_secrets':{'environment':{'vault':'orbit-control-inputs','key':'environment'},'git_read':{'vault':'orbit-control-inputs','key':'git'}},'intent':{'instance_id':17,'instance_name':'proof','already_active':False,'ssh_execution':ssh,'source':{'root':str(self.host/'apps'),'checkout':str(self.application),'user':ssh['target']['user'],'repository':'https://github.com/example/project.git','prepare_id':'admitted-prepare','seed_repository':None},'resolve':{'instance_name':'proof','default_branch':'main','branch_override':'main','seed_commit':None,'network':True,'protocols':['https']},'checkout':str(self.application),'application':str(self.application),'project_type':'node-package','entry_point':'artisan','php_versions':['8.4'],'skip_profile':False,'retained_source':{'branch':'main','commit':commit,'php_version':None,'laravel':False},'setup_commands':[],'vp_setup':None,'environment_output':{'vault':'orbit-control-inputs','key':'instance-create-environment-result-71'},'execution_references_json':'{}'}}

    def git(self,*args):
        return subprocess.run(['git','-C',str(self.application),*args],check=True,capture_output=True,text=True).stdout

    def run_create(self,success=True):
        run=json.loads(self.cli('workflow','run','operation-instance-create','--input',json.dumps(self.input),success=success).stdout)
        self.run_id=run['id']
        history=json.loads(self.cli('workflow','history','get',self.run_id).stdout)
        raw=json.dumps(history)
        self.assertNotIn('template-private-sentinel',raw);self.assertNotIn('stored-private-sentinel',raw)
        return history

    def test_full_create_keeps_environment_private_and_empty_vp_setup_valid(self):
        history=self.run_create()
        self.assertEqual(history['status'],'succeeded')
        result=json.loads(next(step for step in next(job for job in history['jobs'] if job['name']=='create')['steps'] if step['name']=='finish-result')['outputs']['stdout'])
        self.assertTrue(result['ready']);self.assertEqual(result['source']['branch'],'main')
        self.assertIn('stored-private-sentinel',(self.application/'.env').read_text())

    def setup_commands(self, commands):
        import hashlib
        for index,command in enumerate(commands):
            field='setup_'+str(index)
            digest=hashlib.sha256(command.encode()).hexdigest()
            self.input['intent']['setup_commands'].append({'index':index,'name':'step-'+str(index),'command_digest':digest,'timeout_seconds':30,'secret_field':field})
            self.input['execution_secrets'][field]={'vault':'orbit-control-inputs','key':field}
            self.cli('vault','put','orbit-control-inputs',field,json.dumps({'command':command,'sha256':digest,'directory':str(self.application),'instance_id':17,'instance_name':'proof','seed_path':'','seed_commit':''}))

    def test_setup_commands_run_in_order_with_private_stdin(self):
        self.setup_commands(['printf private-setup-sentinel; touch .git/first','test -f .git/first; touch .git/second'])
        history=self.run_create()
        self.assertEqual(history['status'],'succeeded');self.assertTrue((self.application/'.git/second').is_file())
        self.assertNotIn('private-setup-sentinel',json.dumps(history))

    def test_active_create_does_not_repeat_setup_or_environment_write(self):
        self.input['intent']['already_active'] = True
        self.setup_commands(['touch .git/should-not-run'])
        (self.application/'.env').write_text('preserve-active')
        history = self.run_create()
        self.assertEqual(history['status'], 'succeeded')
        self.assertFalse((self.application/'.git/should-not-run').exists())
        self.assertEqual((self.application/'.env').read_text(), 'preserve-active')

    def test_failed_setup_skips_later_command_and_fresh_root_converges(self):
        self.setup_commands(['test -f .git/allow','touch .git/second','touch .git/third'])
        history=self.run_create(False)
        self.assertEqual(history['status'],'failed');self.assertFalse((self.application/'.git/second').exists());self.assertFalse((self.application/'.git/third').exists())
        (self.application/'.git/allow').touch()
        self.input['operation_id']=72
        self.input['intent']['environment_output']['key']='instance-create-environment-result-72'
        history=self.run_create()
        self.assertEqual(history['status'],'succeeded')
        self.assertTrue((self.application/'.git/second').exists())


class InstanceDestroyNative(InstanceDevelopmentNative):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/routes/mutation-command')
        dependency=self.repo/'extensions/behaviors/operations/routes/publication-command';dependency.mkdir(parents=True)
        shutil.copy(ROOT/'extensions/behaviors/operations/routes/publication-command/sections.ts',dependency/'sections.ts')
        workflow=yaml.safe_load((ROOT/'workflows/workflow-operation-instance-destroy.yaml').read_text())
        workflow=json.loads(json.dumps(workflow).replace('/tmp/orbit-instance-',str(self.control)+'/stage-'))
        for job in workflow['jobs']:
            job.pop('target',None)
            for step in job['steps']:
                step.pop('target',None)
                task=step['task']
                if task.get('behaviorType')=='@swamp/ssh':task['globalArgs'].update(sshBinary=str(self.repo/'ssh'),scpBinary=str(self.repo/'scp'))
                if step['name']=='destroy-mint':task['inputs']['run']='true'
        (self.repo/'workflows/workflow-create.yaml').unlink()
        (self.repo/'workflows/workflow-destroy.yaml').write_text(yaml.safe_dump(workflow,sort_keys=False))
        from uuid import uuid4
        member={'instance_id':17,'checkout_path':str(self.application),'source_root':str(self.host/'apps'),'source_layout':'checkout','repository_identity':'github.com/example/project','branch':'main','starting_commit':self.git('rev-parse','HEAD').strip(),'user':self.input['intent']['ssh_execution']['target']['user'],'source_prepare_id':'admitted-prepare','registration_request_id':None,'stored_status':'active','failed_creation':False}
        intent={'instance_id':17,'removal_id':str(uuid4()),'force':True,'inventory_digest':'a'*64,'source_pending':True,'repository':'https://github.com/example/project.git','protected_source_roots':[],'members':[member],'direct_destroy':{'node_user':member['user'],'runtime':{'processes':[],'schedules':[]},'database':{'sqlite':[],'servers':[]}},'native_runtime':{'processes':[],'schedules':[]},'source_capture':None,'teardown_commands':[],'route_mutations':{'forward':{name:[] for name in ['publications','prepare_certificates','certificates','cleanup','lan','retire_lan','url_writes','routes','ingress','retire_ingress']}},'ssh_execution':self.input['intent']['ssh_execution'],'execution_references_json':'{}'}
        self.input={'operation_id':71,'node_id':9,'kind':'instance.destroy','intent':intent,'execution_secrets':{'git_read':{'vault':'orbit-control-inputs','key':'git'}}}
    def test_full_destroy_empty_children_removes_only_owned_checkout(self):
        run=json.loads(self.cli('workflow','run','operation-instance-destroy','--input',json.dumps(self.input)).stdout)
        self.assertEqual(run['status'],'succeeded');self.assertFalse(self.application.exists())
        history=json.loads(self.cli('workflow','history','get',run['id']).stdout)
        result=json.loads(next(step for step in history['jobs'][0]['steps'] if step['name']=='remove-result')['outputs']['stdout'])
        self.assertTrue(result['removed']);self.assertEqual(result['source_inspection']['members'][0]['instance_id'],17)
    def test_two_members_each_run_seventeen_ordered_teardown_commands(self):
        import hashlib
        sibling = self.application.with_name('sibling')
        self.git('worktree','add','-qb','sibling',str(sibling))
        member = dict(self.input['intent']['members'][0],instance_id=18,checkout_path=str(sibling),source_layout='worktree',branch='sibling')
        self.input['intent']['members'].append(member)
        log = self.host/'teardown-observed'
        for owner, directory in [(17,self.application),(18,sibling)]:
            for index in range(17):
                field = 'teardown_' + str(owner) + '_' + str(index)
                command = 'printf done\\n >> ' + str(log)
                self.input['intent']['teardown_commands'].append({'index':index,'secret_field':field,'timeout_seconds':30,'name':'step-'+str(index)})
                self.input['execution_secrets'][field] = {'vault':'orbit-control-inputs','key':field}
                self.cli('vault','put','orbit-control-inputs',field,json.dumps({'command':command,'sha256':hashlib.sha256(command.encode()).hexdigest(),'directory':str(directory),'instance_id':owner,'instance_name':str(owner),'seed_path':'','seed_commit':''}))
        run = json.loads(self.cli('workflow','run','operation-instance-destroy','--input',json.dumps(self.input)).stdout)
        self.assertEqual(run['status'],'succeeded')
        self.assertEqual(log.read_text().count('done'),34)
        self.assertFalse(sibling.exists())
        self.assertFalse(self.application.exists())

    def test_destroy_composes_real_owned_sqlite_child_before_source_removal(self):
        import sqlite3
        from contextlib import closing
        self.copy_behaviors('operations/database-server-commands')
        database = self.application/'owned.sqlite'
        with closing(sqlite3.connect(database)) as connection:
            connection.execute('CREATE TABLE proof(value)')
        workflow = yaml.safe_load((ROOT/'workflows/workflow-database-owned-sqlite-remove.yaml').read_text())
        workflow = json.loads(json.dumps(workflow).replace('/tmp/orbit-db-owned-', str(self.control)+'/db-owned-').replace('/run/credentials/orbit-node-worker.service/proxy-ssh-identity',str(self.identity)))
        for job in workflow['jobs']:
            job.pop('target', None)
            for step in job['steps']:
                step.pop('target', None)
                if step['task'].get('behaviorType') == '@swamp/ssh':
                    step['task']['globalArgs'].update(sshBinary=str(self.repo/'ssh'),scpBinary=str(self.repo/'scp'))
        (self.repo/'workflows/workflow-sqlite.yaml').write_text(yaml.safe_dump(workflow,sort_keys=False))
        self.input['intent']['direct_destroy']['database']['sqlite'] = [{'connection_id':23,'node_id':9,'checkout':str(self.application),'path':str(database)}]
        run = json.loads(self.cli('workflow','run','operation-instance-destroy','--input',json.dumps(self.input)).stdout)
        self.assertEqual(run['status'], 'succeeded')
        self.assertFalse(database.exists())
        self.assertFalse(self.application.exists())

    def test_nonforce_fresh_root_recovers_original_removed_checkout_receipt(self):
        run = json.loads(self.cli('workflow','run','operation-instance-destroy','--input',json.dumps(self.input)).stdout)
        history = json.loads(self.cli('workflow','history','get',run['id']).stdout)
        result = json.loads(next(step for step in history['jobs'][0]['steps'] if step['name']=='remove-result')['outputs']['stdout'])
        self.input['operation_id'] = 72
        self.input['intent']['force'] = False
        self.input['intent']['source_capture'] = result['source_inspection']
        run = json.loads(self.cli('workflow','run','operation-instance-destroy','--input',json.dumps(self.input)).stdout)
        self.assertEqual(run['status'], 'succeeded')
        self.assertFalse(self.application.exists())

    test_active_create_does_not_repeat_setup_or_environment_write=None
    test_full_create_keeps_environment_private_and_empty_vp_setup_valid=None
    test_setup_commands_run_in_order_with_private_stdin=None
    test_failed_setup_skips_later_command_and_fresh_root_converges=None


SSH = '''import os,subprocess,sys
if '-O' in sys.argv or '-N' in sys.argv or '-MNf' in sys.argv: sys.exit(0)
command=sys.argv[-1].replace(CONTROL,HOST)
body=sys.stdin.buffer.read().replace(CONTROL.encode(),HOST.encode())
if 'sudo' in command or b'Install only the admitted' in body: raise AssertionError('Unadmitted installer in no-VP fixture')
if command.startswith('test ') or command.startswith('mkdir ') or command.startswith('chmod ') or command.startswith('rm ') or command.startswith('python') or '/usr/bin/' in command or command.startswith('sh ') or command.startswith('/bin/'):
    result=subprocess.run(['/bin/sh','-c',command],input=body);sys.exit(result.returncode)
raise AssertionError('Unexpected SSH action: '+command)
'''
SCP = '''import pathlib,shutil,sys
source,destination=sys.argv[-2:]
def local(value):
    if 'node.invalid:' in value: return value.split(':',1)[1].replace(CONTROL,HOST)
    return value
source,destination=map(local,(source,destination))
src,dst=pathlib.Path(source),pathlib.Path(destination)
if src.is_dir():
    dst.mkdir(parents=True,exist_ok=True)
    target=dst/src.name if not source.endswith('/.') else dst
    shutil.copytree(src,target,dirs_exist_ok=True)
else:
    if dst.is_dir(): dst=dst/src.name
    shutil.copy2(src,dst)
'''

if __name__ == '__main__': unittest.main()
