"""Official SSH install/update graph; all package and manager actions are harmless fixtures."""
import json
import sys
import unittest

import yaml
from native_harness import ROOT, NativeTestCase


class ToolWriteNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/tools', 'operations/node-bootstrap')
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        self.state = self.repo / 'version'
        self.audit = self.repo / 'audit.jsonl'
        self.identity = self.repo / 'identity'; self.identity.write_text('synthetic key'); self.identity.chmod(0o600)
        self.no_effect = self.repo / 'no-effect'
        self.lost = self.repo / 'lost'
        self.available = self.repo / 'manager-available'; self.available.touch()
        fixture = self.repo / 'fixture-ssh'
        source = SCRIPT
        for name, value in [('STATE',self.state),('AUDIT',self.audit),('KEY',self.identity),('NO_EFFECT',self.no_effect),('LOST',self.lost),('AVAILABLE',self.available)]:
            source = source.replace(name, repr(str(value)))
        fixture.write_text('#!'+sys.executable+'\n'+source);fixture.chmod(0o700)
        workflow = yaml.safe_load((ROOT/'workflows/workflow-operation-tool-write-209.yaml').read_text())
        for job in workflow['jobs']:
            self.assertEqual(job.pop('target'), 'orbit-node-209')
            for step in job['steps']:
                self.assertEqual(step.pop('target'), 'orbit-node-209');self.assertNotIn('labels',step)
                if step['task'].get('behaviorType') == '@swamp/ssh':
                    step['task']['globalArgs']['sshBinary'] = str(fixture)
        (self.repo/'workflows/workflow-operation-tool-write-209.yaml').write_text(yaml.safe_dump(workflow,sort_keys=False))
        self.input = {'operation_id':1001,'node_id':9,'kind':'tool.install','execution_secrets':{},'intent':{'kind':'tool.install','node_id':9,'manager':'apt','package':'hello','user':'orbit','platform':'linux','architecture':'x86_64','tool_id':None,'setup':None},
            'ssh':{'control_node_id':209,'identity_file':str(self.identity),'target':{'host':'node.invalid','name':'target','user':'orbit','port':22,'host_keys':['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}}}

    def run_write(self, success=True):
        self.cli('workflow','validate','operation-tool-write-209')
        data=json.loads(self.cli('workflow','run','operation-tool-write-209','--input',json.dumps(self.input),success=success).stdout)
        self.run_id=data['id']
        return json.loads(self.cli('workflow','history','get',self.run_id).stdout)

    def result(self, history):
        return json.loads(next(s for s in history['jobs'][0]['steps'] if s['name']=='result')['outputs']['stdout'])

    def calls(self):
        return [json.loads(l) for l in self.audit.read_text().splitlines()] if self.audit.exists() else []

    def kind(self, kind):
        self.input['kind']=kind;self.input['intent']['kind']=kind

    def test_all_managers_install_and_update_with_separate_queries_and_one_mutation(self):
        for kind in ['tool.install','tool.update']:
            self.kind(kind)
            for manager in ['apt','composer','vp','brew','brew-cask']:
                self.input['intent'].update(manager=manager,package='vendor/package' if manager=='composer' else 'hello',platform='macos' if manager=='brew-cask' else 'linux',tool_id=None if kind=='tool.install' else 1)
                self.state.unlink(missing_ok=True)
                if kind=='tool.update':self.state.write_text('1.0.0')
                history=self.run_write()
                self.assertEqual(self.result(history)['installed_version'],'2.0.0')
        self.assertEqual(sum(c['action']=='mutate' for c in self.calls()),10)

    def test_constraints_block_install_and_keep_update_unchanged(self):
        self.input['intent']['version_constraint']='^1.0'
        history=self.run_write(False)
        self.assertEqual(self.result(history)['error_code'],'tool.version_constraint_blocked')
        self.assertFalse(self.state.exists())
        self.kind('tool.update');self.input['intent']['tool_id']=1;self.state.write_text('1.0.0')
        self.assertEqual(self.result(self.run_write())['outcome'],'blocked_by_constraint')
        self.assertFalse(any(c['action']=='mutate' for c in self.calls()))

    def test_silent_adoption_and_zero_effect_install_are_refused(self):
        self.state.write_text('1.0.0')
        self.assertEqual(self.result(self.run_write(False))['error_code'],'tool.package_already_installed')
        self.state.unlink();self.no_effect.touch()
        self.assertEqual(self.result(self.run_write(False))['error_code'],'tool.version_probe_failed')

    def test_same_root_resume_reconciles_lost_reply_without_repeating_mutation(self):
        self.lost.touch();self.run_write(False)
        self.assertEqual(self.state.read_text(),'2.0.0')
        self.state.write_text('2.0.1')  # Normal manager candidate advanced before the lost reply.
        self.cli('workflow','resume','operation-tool-write-209','--run',self.run_id,'--from','reconcile_query')
        history=json.loads(self.cli('workflow','history','get',self.run_id).stdout)
        self.assertEqual(history['status'],'succeeded')
        self.assertEqual(sum(c['action']=='mutate' for c in self.calls()),1)

    def test_manager_convergence_uses_same_query_and_no_package_mutation(self):
        self.kind('tool.manager_converge');self.input['intent'].pop('package')
        history=self.run_write()
        self.assertEqual(self.result(history)['manager_version'],'3.0.0')
        self.assertFalse(any(c['action']=='mutate' for c in self.calls()))

    def test_linux_first_use_composes_canonical_setup_and_mac_does_not(self):
        self.available.unlink();self.input['intent'].update(manager='composer',package='vendor/package',setup={'manager':'composer','input':{'user':'orbit','version':'2.9.5-1'},'packages':[],'apt':{'snapshot':'20260101T000000Z','packages':[]}})
        self.assertEqual(self.run_write()['status'],'succeeded')
        self.assertTrue(any(c['action']=='setup' for c in self.calls()))
        self.available.unlink();self.state.unlink();self.kind('tool.update');self.input['intent'].update(manager='vp',package='hello',platform='macos',setup=None)
        self.assertEqual(self.result(self.run_write(False))['error_code'],'tool.manager_unavailable')


    def test_repository_is_prepared_before_candidate_and_changed_suite_upgrades(self):
        self.input['intent']['repository'] = REPOSITORY
        self.run_write()
        actions = [c['action'] for c in self.calls()]
        self.assertLess(actions.index('repository_prepare'), actions.index('candidate'))
        self.assertEqual(json.loads((self.repo/'repository').read_text())['suites'], ['preview'])
        self.kind('tool.update'); self.input['intent']['tool_id'] = 1
        self.input['intent']['repository'] = {**REPOSITORY, 'suites': ['stable'], 'uris': ['https://next.example.test/apt']}
        self.state.write_text('1.0.0')
        self.run_write()
        self.assertEqual(json.loads((self.repo/'repository').read_text())['suites'], ['stable'])
        self.assertEqual(self.state.read_text(), '2.0.0')

    def test_unavailable_repository_and_key_mismatch_stop_before_candidates_or_install(self):
        self.input['intent']['repository'] = REPOSITORY
        for code in ['tool.repository_unavailable', 'tool.repository_key_mismatch']:
            (self.repo/'repository-error').write_text(code)
            self.assertEqual(self.result(self.run_write(False))['error_code'], code)
        self.assertFalse(any(c['action'] in ['candidate', 'mutate'] for c in self.calls()))
        self.assertFalse(self.state.exists())


REPOSITORY = {'name':'vendor','uris':['https://packages.example.test/apt'],'suites':['preview'],'components':['main'],'signing_key':{'url':'https://packages.example.test/key.gpg','fingerprint':'A'*40}}

SCRIPT = r'''
import ast,json,sys
from pathlib import Path
args=sys.argv[1:];assert KEY in args and 'IdentitiesOnly=yes' in args
body=sys.stdin.read()
if not body and 'managed apt repository phase' in args[-1]:
 import shlex
 command=shlex.split(args[-1]);action=command[-2]
 assert action in ('prepare','cleanup') and 'sudo' in args[-1]
 with Path(AUDIT).open('a') as out:out.write(json.dumps({'action':'repository_'+action,'manager':'apt'})+'\n')
 marker=Path(AUDIT).with_name('repository')
 error=Path(AUDIT).with_name('repository-error')
 if action=='prepare':
  if error.exists():print(json.dumps({'ready':False,'error_code':error.read_text()}));raise SystemExit(0)
  marker.write_text(command[-1])
 else:marker.unlink(missing_ok=True)
 print('{"ready":true}');raise SystemExit(0)
mutation_argv=[]
if not body:
 import shlex
 command=shlex.split(args[-1]);index=len(command)-1-command[::-1].index('-c')
 body=command[index+1];mutation_argv=command[index+2:]
tree=ast.parse(body)
node=next((n for n in tree.body if isinstance(n,ast.Assign) and isinstance(n.targets[0],ast.Name) and n.targets[0].id=='QUERY_INPUT'),None)
if node:
 input=json.loads(ast.literal_eval(node.value));action='mutate' if any(isinstance(n,ast.Call) and isinstance(n.func,ast.Name) and n.func.id=='print' and n.args and isinstance(n.args[0],ast.Constant) and n.args[0].value=='{"ready":true}' for n in ast.walk(tree)) else 'candidate' if input.get('before') else 'query'
 manager=input['manager']
else:
 action='setup';manager='composer'
with Path(AUDIT).open('a') as out:out.write(json.dumps({'action':action,'manager':manager})+'\n')
if action=='setup':
 Path(AVAILABLE).touch();print('{"ready":true}')
elif action=='query':
 if not Path(AVAILABLE).exists():print('{"scope":"absent"}')
 else:
  facts={'manager_version':'3.0.0','versions':[Path(STATE).read_text()] if Path(STATE).exists() else [],'complete':True,'roots':[input['package']],'scope_path':'/vp','prefix':'/brew','home':'/home/orbit','os_major':15,'token_count':1}
  if manager=='brew-cask':facts['homebrew']={'token':input['package'],'tap':'homebrew/cask','artifacts':[{'font':['Font.ttf']}]}
  print(json.dumps(facts))
elif action=='candidate':
 if manager.startswith('brew'):
  facts={**input['before'],'homebrew':{'name':'hello','full_name':'hello','tap':'homebrew/core','disabled':False,'installed':[],'versions':{'stable':'2.0.0','bottle':True},'bottle':{'stable':{'files':{'x86_64_linux':{'sha256':'a'*64,'url':'https://ghcr.io/v2/homebrew/core/hello/sha256:'+'a'*64}}}}}}
  if manager=='brew-cask':facts['homebrew']={'token':'hello','full_token':'hello','tap':'homebrew/cask','disabled':False,'url':'https://example.test/hello.zip','version':'2.0.0','sha256':'a'*64,'artifacts':[{'font':['Font.ttf']}]}
  print(json.dumps(facts))
 else:print('{"version":"2.0.0"}')
else:
 assert ('sudo' in args[-1]) == (manager=='apt')
 assert len(mutation_argv)==1
 if input.get('repository'):assert json.loads(mutation_argv[0])['version']=='2.0.0' and '2.0.0' not in body
 if not Path(NO_EFFECT).exists():Path(STATE).write_text('2.0.0')
 if Path(LOST).exists():Path(LOST).unlink();raise SystemExit(1)
 print('{"ready":true}')
'''

if __name__=='__main__':unittest.main()
