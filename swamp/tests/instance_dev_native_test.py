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


class InstanceNativeFixture(NativeTestCase):
    native_timeout = 180

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/instance-dev-command','operations/instance-vp-command','operations/instance-source-command')
        for family, files in {'instance-lifecycle':['source_profile.ts','source_identity.ts'], 'instance-deploy':['vp_requirements.ts','dotenv.ts'], 'tools':['constraints.ts','composer-constraints.json'], 'node-bootstrap':['tool_setup_assets.ts']}.items():
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
        self.workflow.pop('target', None); self.workflow.pop('labels', None)
        for job in self.workflow['jobs']:
            job.pop('labels', None)
            for step in job['steps']:
                step.pop('target', None); step.pop('labels', None)
                task = step['task']
                if task.get('behaviorType') == '@swamp/ssh': task['globalArgs'].update(sshBinary=str(binary), scpBinary=str(scp))
        (self.repo / 'workflows/workflow-create.yaml').write_text(yaml.safe_dump(self.workflow,sort_keys=False))
        self.cli('vault','create','local_encryption','orbit-control-inputs')
        self.environment('STORED="stored-private-sentinel"\n')
        self.cli('vault','put','orbit-control-inputs','instance-git-operation-71',json.dumps({'github_token':None}))
        self.setup_commands([])
        ssh = {'identity_file':str(self.identity),'target':{'host':'node.invalid','port':22,'user':pwd.getpwuid(os.geteuid()).pw_name,'host_keys':['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}}
        secrets = {'environment':{'vault':'orbit-control-inputs','key':'instance-create-environment-input-71'},'git_read':{'vault':'orbit-control-inputs','key':'instance-git-operation-71'},'setup':{'vault':'orbit-control-inputs','key':'instance-create-71'}}
        self.input = {'operation_id':71,'node_id':9,'kind':'instance.create','execution_secrets':secrets,'intent':{'instance_id':17,'instance_name':'proof','already_active':False,'ssh_execution':ssh,'source':{'root':str(self.host/'apps'),'checkout':str(self.application),'user':ssh['target']['user'],'repository':'https://github.com/example/project.git','prepare_id':'admitted-prepare','seed_repository':None},'resolve':{'branch':'main','default_branch':'main','seed_commit':None,'network':True,'protocols':['https']},'checkout':str(self.application),'application':str(self.application),'project_type':'node-package','entry_point':'artisan','php_versions':['8.4'],'skip_profile':False,'retained_source':{'commit':commit},'processes':[]}}

    def git(self,*args):
        return subprocess.run(['git','-C',str(self.application),*args],check=True,capture_output=True,text=True).stdout

    def run_create(self,success=True):
        run=json.loads(self.cli('workflow','run','operation-instance-create','--input',json.dumps(self.input),success=success).stdout)
        self.run_id=run['id']
        history=json.loads(self.cli('workflow','history','get',self.run_id).stdout)
        raw=json.dumps(history)
        for private in ('stored-private-sentinel','private-setup-sentinel'): self.assertNotIn(private,raw)
        self.steps={step['name']:step for job in history['jobs'] for step in job['steps']}
        self.assertNotIn('No such key',raw)
        return history

    def result(self):
        return json.loads(self.steps['finish']['outputs']['stdout'])

    def environment(self, content, directory=None, checkout=None):
        checkout = checkout or self.application
        self.cli('vault','put','orbit-control-inputs','instance-create-environment-input-71',json.dumps({'directory':str(directory or checkout),'checkout':str(checkout),'content':content}))

    def setup_commands(self, commands):
        import hashlib
        steps=[{'name':'step-'+str(index),'command':command,'sha256':hashlib.sha256(command.encode()).hexdigest(),'timeout_seconds':30,'directory':str(self.application),'instance_id':17,'instance_name':'proof','seed_path':'','seed_commit':''} for index,command in enumerate(commands)]
        self.cli('vault','put','orbit-control-inputs','instance-create-71',json.dumps({'steps':steps}))


class InstanceDevelopmentNative(InstanceNativeFixture):
    def test_full_create_writes_the_rendered_environment_and_returns_only_the_commit(self):
        history=self.run_create()
        self.assertEqual(history['status'],'succeeded')
        self.assertEqual([self.steps[name]['status'] for name in ['assets','prepare','source','environment','setup','finish','accepted']],['succeeded']*7)
        self.assertEqual(self.result(),{'instance_id':17,'ready':True,'name_converted_from':None,'commit':self.git('rev-parse','HEAD').strip()})
        self.assertEqual((self.application/'.env').read_text(),'STORED="stored-private-sentinel"\n')

    def test_nested_application_gets_its_env_and_setup_runs_at_the_checkout_root(self):
        nested=self.application/'apps/site'; nested.mkdir(parents=True)
        self.environment('NESTED="yes"\n', nested)
        self.setup_commands(['test -d apps/site && touch .git/root-setup'])
        history=self.run_create()
        self.assertEqual(history['status'],'succeeded')
        self.assertEqual((nested/'.env').read_text(),'NESTED="yes"\n')
        self.assertFalse((self.application/'.env').exists())
        self.assertTrue((self.application/'.git/root-setup').is_file())

    def test_changed_source_identity_skips_later_phases_and_reports_source(self):
        self.input['intent']['retained_source']['commit'] = 'b'*40
        history = self.run_create(False)
        self.assertEqual(history['status'],'failed')
        self.assertEqual([self.steps[name]['status'] for name in ['environment','setup']],['skipped']*2)
        result = self.result()
        self.assertFalse(result['ready'])
        self.assertEqual((result['failed_step'],result['error_code']),('source','instance.source_identity_changed'))
        self.assertFalse((self.application / '.env').exists())

    def test_new_branch_uses_captured_seed_commit_without_moving_default(self):
        seed = self.application
        commit = self.git('rev-parse', 'HEAD').strip()
        target = seed.with_name('fresh-branch')
        self.input['intent']['retained_source'] = None
        self.input['intent']['source'].update(checkout=str(target), seed_repository=str(seed))
        self.input['intent']['resolve'].update(branch='fresh-branch', seed_commit=commit, network=False)
        self.input['intent'].update(checkout=str(target), application=str(target))
        self.environment('NEW="branch"\n', checkout=target)
        history = self.run_create()
        self.assertEqual(history['status'], 'succeeded')
        self.assertEqual((target / '.env').read_text(), 'NEW="branch"\n')
        result = subprocess.run(['git', '-C', str(target), 'rev-parse', '--abbrev-ref', 'HEAD'], capture_output=True, text=True, check=True)
        self.assertEqual(result.stdout.strip(), 'fresh-branch')
        self.assertEqual(self.git('rev-parse', 'HEAD').strip(), commit)
        self.assertFalse((seed / '.env').exists())

    def test_seed_branch_collision_refuses_before_preparing_target(self):
        seed = self.application
        commit = self.git('rev-parse', 'HEAD').strip()
        target = seed.with_name('collision')
        self.input['intent']['retained_source'] = None
        self.input['intent']['source'].update(checkout=str(target), seed_repository=str(seed))
        self.input['intent']['resolve']['seed_commit'] = commit
        self.input['intent'].update(checkout=str(target), application=str(target))
        self.run_create(False)
        self.assertEqual(self.steps['source']['status'], 'skipped')
        self.assertEqual((self.result()['failed_step'], self.result()['error_code']), ('prepare', 'instance.branch_in_use'))
        self.assertFalse(target.exists())
        self.assertEqual(self.git('rev-parse', 'HEAD').strip(), commit)
        self.assertFalse((seed / '.env').exists())

    def test_setup_commands_run_in_order_with_private_stdin_and_no_stdout(self):
        self.setup_commands(['printf private-setup-sentinel; touch .git/first','test -f .git/first; touch .git/second'])
        history=self.run_create()
        self.assertEqual(history['status'],'succeeded');self.assertTrue((self.application/'.git/second').is_file())

    def test_active_create_does_not_repeat_setup_or_environment_write(self):
        self.input['intent']['already_active'] = True
        self.setup_commands(['touch .git/should-not-run'])
        (self.application/'.env').write_text('preserve-active')
        history = self.run_create()
        self.assertEqual(history['status'], 'succeeded')
        self.assertEqual(self.result(), {'ready':True,'instance_id':17,'already_active':True})
        self.assertFalse((self.application/'.git/should-not-run').exists())
        self.assertEqual((self.application/'.env').read_text(), 'preserve-active')

    def test_failed_setup_skips_later_command_and_fresh_root_converges(self):
        self.setup_commands(['test -f .git/allow','touch .git/second','touch .git/third'])
        history=self.run_create(False)
        self.assertEqual(history['status'],'failed');self.assertFalse((self.application/'.git/second').exists());self.assertFalse((self.application/'.git/third').exists())
        self.assertEqual(self.result()['failed_step'],'setup');self.assertEqual(self.result()['error_code'],'instance.setup_step_failed');self.assertEqual(self.result()['failed_command'],'step-0')
        (self.application/'.git/allow').touch()
        history=self.run_create()
        self.assertEqual(history['status'],'succeeded')
        self.assertTrue((self.application/'.git/third').exists())


class InstanceDestroyNative(InstanceNativeFixture):
    def setUp(self):
        super().setUp()
        for family in ['operations/routes/mutation-command','operations/routes/update-command','operations/firewall']:
            self.copy_behaviors(family)
        dependency=self.repo/'extensions/behaviors/operations/routes/publication-command';dependency.mkdir(parents=True)
        shutil.copy(ROOT/'extensions/behaviors/operations/routes/publication-command/sections.ts',dependency/'sections.ts')
        for name in ['operation-instance-destroy','route-mutation-forward']:
            workflow=yaml.safe_load((ROOT/f'workflows/workflow-{name}.yaml').read_text())
            workflow.pop('labels',None)
            for job in workflow['jobs']:
                job.pop('labels',None)
                for step in job['steps']:
                    step.pop('labels',None)
                    task=step['task']
                    if task.get('behaviorType')=='@swamp/ssh':task['globalArgs'].update(sshBinary=str(self.repo/'ssh'),scpBinary=str(self.repo/'scp'))
                    # The fixture Node runs as one account and refuses sudo; the gone check only reads.
                    task.get('inputs',{}).pop('sudo',None)
            (self.repo/f'workflows/workflow-{name}.yaml').write_text(yaml.safe_dump(workflow,sort_keys=False))
        (self.repo/'workflows/workflow-create.yaml').unlink()
        from uuid import uuid4
        member={'instance_id':17,'checkout_path':str(self.application),'source_root':str(self.host/'apps'),'source_layout':'checkout','repository_identity':'github.com/example/project','branch':'main','starting_commit':self.git('rev-parse','HEAD').strip(),'user':self.input['intent']['ssh_execution']['target']['user'],'source_prepare_id':'admitted-prepare','registration_request_id':None,'stored_status':'active','failed_creation':False}
        empty={name:[] for name in ['publications','prepare_certificates','certificates','cleanup','lan','retire_lan','url_writes','routes','ingress','retire_ingress']}
        intent={'instance_id':17,'removal_id':str(uuid4()),'force':True,'inventory_digest':'a'*64,'source_pending':True,'repository':'https://github.com/example/project.git','protected_source_roots':[],'members':[member],'direct_destroy':{'node_user':member['user'],'runtime':{'processes':[],'schedules':[]},'database':{'sqlite':[],'servers':[]}},'native_runtime':{'processes':[],'schedules':[]},'source_capture':None,'teardown_steps':[],'route_mutations':{'forward':empty,'artifacts':{'php':{},'caddy':{},'dns':{}}},'ssh_execution':self.input['intent']['ssh_execution']}
        self.input={'operation_id':71,'node_id':9,'kind':'instance.destroy','intent':intent,'execution_secrets':{'git_read':{'vault':'orbit-control-inputs','key':'instance-git-operation-71'}}}
    def destroy(self,success=True):
        run=json.loads(self.cli('workflow','run','operation-instance-destroy','--stdin',input=json.dumps(self.input),success=success).stdout)
        history=json.loads(self.cli('workflow','history','get',run['id']).stdout)
        self.steps={step['name']:step for job in history['jobs'] for step in job['steps']}
        self.assertNotIn('No such key',json.dumps(history))
        return run, json.loads(self.steps['Report the result']['outputs']['stdout'])
    def teardown(self,directory,owner,commands):
        import hashlib
        self.input['intent']['teardown_steps'] += [{'name':'step-'+str(index),'command':command,'sha256':hashlib.sha256(command.encode()).hexdigest(),'timeout_seconds':30,'directory':str(directory),'instance_id':owner,'instance_name':str(owner),'seed_path':'','seed_commit':''} for index,command in enumerate(commands)]
    def test_full_destroy_empty_children_removes_only_owned_checkout(self):
        self.cli('workflow','validate','operation-instance-destroy')
        run,result=self.destroy()
        self.assertEqual(run['status'],'succeeded');self.assertFalse(self.application.exists())
        self.assertTrue(result['removed']);self.assertEqual(result['source_inspection']['members'][0]['instance_id'],17)
        self.assertTrue((self.host/'apps').is_dir())
    def test_two_members_run_their_teardown_in_order_and_remove_the_worktree_first(self):
        sibling = self.application.with_name('sibling')
        self.git('worktree','add','-qb','sibling',str(sibling))
        member = dict(self.input['intent']['members'][0],instance_id=18,checkout_path=str(sibling),source_layout='worktree',branch='sibling')
        self.input['intent']['members'].append(member)
        log = self.host/'teardown-observed'
        for owner, directory in [(17,self.application),(18,sibling)]:
            self.teardown(directory, owner, ['printf '+str(owner)+'-'+str(index)+'_ >> '+str(log) for index in range(3)])
        run,_ = self.destroy()
        self.assertEqual(run['status'],'succeeded')
        self.assertEqual(log.read_text(),'17-0_17-1_17-2_18-0_18-1_18-2_')
        self.assertFalse(sibling.exists()); self.assertFalse(self.application.exists())
    def test_a_failed_teardown_stops_before_route_and_source(self):
        self.teardown(self.application, 17, ['true', 'echo broke >&2; exit 4', 'touch never'])
        _,result = self.destroy(False)
        self.assertEqual((result['removed'],result['failed_step'],result['error_code']),(False,'Run teardown steps','instance.teardown_step_failed'))
        self.assertTrue(self.application.is_dir()); self.assertFalse((self.application/'never').exists())
        self.assertEqual([self.steps[name]['status'] for name in ['Withdraw the Route','Remove the source']],['skipped','skipped'])
        self.assertIn('source_inspection',result)
    def test_dirty_source_refuses_a_normal_destroy_before_any_effect(self):
        self.input['intent']['force'] = False
        (self.application/'uncommitted').write_text('work')
        _,result = self.destroy(False)
        self.assertEqual((result['failed_step'],result['error_code']),('Verify the source','instance.unsafe_content'))
        self.assertTrue((self.application/'uncommitted').is_file())
        self.assertEqual(self.steps['Remove the source']['status'],'skipped')
    def test_a_missing_shipped_script_fails_at_read_the_scripts_and_changes_nothing(self):
        (self.repo/'extensions/behaviors/operations/instance-dev-command/destroy/verify.py').unlink()
        _,result = self.destroy(False)
        self.assertEqual((result['removed'],result['failed_step'],result['error_code']),(False,'Read the scripts','instance.removal_incomplete'))
        self.assertTrue(self.application.is_dir())
    def test_a_retained_capture_is_reused_after_the_checkout_is_gone(self):
        run,first = self.destroy()
        self.input['intent']['source_capture'] = first['source_inspection']
        run,second = self.destroy()
        self.assertEqual(run['status'],'succeeded'); self.assertEqual(second['source_inspection'],first['source_inspection'])

    def test_destroy_composes_real_owned_sqlite_child_before_source_removal(self):
        import sqlite3
        from contextlib import closing
        self.copy_behaviors('operations/database-server-commands')
        database = self.application/'owned.sqlite'
        with closing(sqlite3.connect(database)) as connection:
            connection.execute('CREATE TABLE proof(value)')
        workflow = yaml.safe_load((ROOT/'workflows/workflow-database-owned-sqlite-remove.yaml').read_text())
        workflow = json.loads(json.dumps(workflow).replace('/tmp/orbit-db-owned-', str(self.control)+'/db-owned-').replace('/etc/orbit/swamp-role-worker/ssh-identity',str(self.identity)))
        for job in workflow['jobs']:
            job.pop('target', None); job.pop('labels', None)
            for step in job['steps']:
                step.pop('target', None); step.pop('labels', None)
                if step['task'].get('behaviorType') == '@swamp/ssh':
                    step['task']['globalArgs'].update(sshBinary=str(self.repo/'ssh'),scpBinary=str(self.repo/'scp'))
        (self.repo/'workflows/workflow-sqlite.yaml').write_text(yaml.safe_dump(workflow,sort_keys=False))
        self.input['intent']['direct_destroy']['database']['sqlite'] = [{'connection_id':23,'node_id':9,'checkout':str(self.application),'path':str(database)}]
        run,_ = self.destroy()
        self.assertEqual(run['status'], 'succeeded')
        self.assertFalse(database.exists())


SSH = '''import os,shlex,subprocess,sys
if '-O' in sys.argv or '-N' in sys.argv or '-MNf' in sys.argv: sys.exit(0)
command=sys.argv[-1].replace(CONTROL,HOST)
if os.path.isfile(FAILURE) and 'checkout' in shlex.split(command):
    sys.stderr.write('fixture source checkout failure\\n');sys.exit(128)
body=sys.stdin.buffer.read().replace(CONTROL.encode(),HOST.encode())
if 'sudo' in command or b'Install only the admitted' in body: raise AssertionError('Unadmitted installer in no-VP fixture')
if command.startswith('test ') or command.startswith("'timeout' ") or command.startswith('mkdir ') or command.startswith('chmod ') or command.startswith('rm ') or command.startswith('python') or '/usr/bin/' in command or command.startswith('sh ') or command.startswith('/bin/'):
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
