"""Actual native removal DAG with official SSH and disposable manager facts."""
import json
import sys
import unittest

import yaml
from native_harness import ROOT, NativeTestCase


class ToolRemoveNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/tools')
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        self.audit = self.repo / 'audit.jsonl'
        self.state = self.repo / 'present'
        self.state.touch()
        self.no_effect = self.repo / 'no-effect'
        self.unsafe = self.repo / 'unsafe-plan'
        self.fail_once = self.repo / 'fail-once'
        self.own = self.repo / 'own-identity'; self.own.write_text('synthetic fixture identity'); self.own.chmod(0o600)
        fixture = self.repo / 'fixture-ssh'
        fixture.write_text('#!' + sys.executable + '\n' + SCRIPT.replace('OWN', repr(str(self.own))).replace('AUDIT', repr(str(self.audit))).replace('STATE', repr(str(self.state))).replace('NO_EFFECT', repr(str(self.no_effect))).replace('UNSAFE', repr(str(self.unsafe))).replace('FAIL_ONCE', repr(str(self.fail_once))))
        fixture.chmod(0o700)
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-tool-remove-209.yaml').read_text())
        for job in workflow['jobs']:
            self.assertEqual(job.pop('target'), 'orbit-node-209')
            for step in job['steps']:
                self.assertEqual(step.pop('target'), 'orbit-node-209')
                self.assertNotIn('labels', step)
                if step['task'].get('behaviorType') == '@swamp/ssh':
                    step['task']['globalArgs']['sshBinary'] = str(fixture)
        (self.repo/'workflows/workflow-operation-tool-remove-209.yaml').write_text(yaml.safe_dump(workflow,sort_keys=False))
        self.input = {'operation_id':1001,'node_id':9,'kind':'tool.remove','execution_secrets':{},
                      'intent':{'node_id':9,'manager':'apt','package':'hello','platform':'linux','architecture':'x86_64','user':'orbit','version_constraint':None,'tool_id':1,'tool_status':'installed','installed_version':'1.0.0','intent_only':False},
                      'ssh':{'control_node_id':209,'identity_file':str(self.own),'public_key':'ssh-ed25519 AAAA',
                             'target':{'name':'target','host':'node.invalid','user':'orbit','port':22,'host_keys':['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}}}

    def run_remove(self, success=True):
        self.cli('workflow','validate','operation-tool-remove-209')
        run=json.loads(self.cli('workflow','run','operation-tool-remove-209','--input',json.dumps(self.input),success=success).stdout)
        self.run_id=run['id']
        return json.loads(self.cli('workflow','history','get',self.run_id).stdout)

    def result(self, history):
        return json.loads(next(step for step in history['jobs'][0]['steps'] if step['name']=='result')['outputs']['stdout'])

    def calls(self):
        return [json.loads(line) for line in self.audit.read_text().splitlines()] if self.audit.exists() else []

    def test_each_manager_removes_then_proves_absence(self):
        for manager in ('apt','composer','vp','brew','brew-cask'):
            self.state.touch()
            self.input['intent'].update(manager=manager,package='vendor/package' if manager=='composer' else 'hello',platform='macos' if manager=='brew-cask' else 'linux')
            history=self.run_remove()
            self.assertEqual(history['status'],'succeeded')
            self.assertEqual(self.result(history),{'manager_version':'3.0.0','installed_version':None,'outcome':'applied'})
        self.assertEqual(len([r for r in self.calls() if r['action']=='remove']),5)

    def test_unsafe_apt_plan_preserves_package(self):
        self.unsafe.touch()
        history=self.run_remove(False)
        self.assertEqual(self.result(history)['error_code'],'tool.removal_plan_unsafe')
        self.assertTrue(self.state.exists())
        self.assertFalse(any(r['action']=='remove' for r in self.calls()))

    def test_zero_exit_without_effect_cannot_claim_removal(self):
        self.no_effect.touch()
        history=self.run_remove(False)
        self.assertEqual(self.result(history)['error_code'],'tool.remove_failed')
        self.assertTrue(self.state.exists())

    def test_absent_and_admitted_intent_only_need_no_mutation(self):
        self.state.unlink()
        self.assertEqual(self.run_remove()['status'],'succeeded')
        self.assertFalse(any(r['action']=='remove' for r in self.calls()))
        self.audit.unlink()
        self.input['intent']['intent_only']=True
        self.state.touch()
        self.assertEqual(self.run_remove()['status'],'succeeded')
        self.assertEqual(self.calls(),[])
        self.assertTrue(self.state.exists())

    def test_resume_rereads_absence_after_lost_mutation_reply(self):
        self.fail_once.touch()
        history=self.run_remove(False)
        self.assertEqual(history['status'],'failed')
        self.assertFalse(self.state.exists())
        self.cli('workflow','resume','operation-tool-remove-209','--run',self.run_id,'--from','before_assets')
        history=json.loads(self.cli('workflow','history','get',self.run_id).stdout)
        self.assertEqual(history['status'],'succeeded')
        self.assertEqual(len([r for r in self.calls() if r['action']=='remove']),1)


    def test_shared_repository_is_retained_and_last_reference_cleanup_retries_when_absent(self):
        self.input['intent']['repository'] = REPOSITORY
        (self.repo/'repository').write_text('managed repository')
        self.run_remove()
        self.assertTrue((self.repo/'repository').exists())
        self.input['intent']['remove_repository'] = True
        self.run_remove()
        self.assertFalse((self.repo/'repository').exists())
        self.run_remove()
        self.assertEqual(sum(c['action']=='remove' for c in self.calls()), 1)
        self.assertEqual(sum(c['action']=='repository_cleanup' for c in self.calls()), 2)


REPOSITORY = {'name':'vendor','uris':['https://packages.example.test/apt'],'suites':['preview'],'components':['main'],'signing_key':{'url':'https://packages.example.test/key.gpg','fingerprint':'A'*40}}

SCRIPT = r"""
import ast,json,sys
from pathlib import Path
args=sys.argv[1:]
assert OWN in args and 'IdentitiesOnly=yes' in args
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
tree=ast.parse(body)
node=next(n for n in tree.body if isinstance(n,ast.Assign) and isinstance(n.targets[0],ast.Name) and n.targets[0].id in ('QUERY_INPUT','REMOVE_INPUT'))
value=ast.literal_eval(node.value.args[0]) if isinstance(node.value,ast.Call) else ast.literal_eval(node.value)
input=json.loads(value)
action='query' if 'def observe(' in body or 'def brew_facts(' in body else 'plan' if 'packages = []' in body else 'remove'
assert ('sudo' in args[-1]) == (action=='remove' and input['manager']=='apt')
with Path(AUDIT).open('a') as out:out.write(json.dumps({'action':action,'manager':input['manager']})+'\n')
if action=='query':
 facts={'manager_version':'3.0.0','versions':['1.0.0'] if Path(STATE).exists() else [],'complete':True,'roots':[input['package']],'os_major':15,'prefix':'/prefix','home':'/home/orbit','scope_path':'/vp','token_count':1}
 if input['manager']=='brew-cask':facts['homebrew']={'token':input['package'],'tap':'homebrew/cask','artifacts':[{'font':['Font.ttf']}],'variations':{}}
 print(json.dumps(facts))
elif action=='plan':print(json.dumps({'packages':[input['package'],'foreign'] if Path(UNSAFE).exists() else [input['package']]}))
else:
 if not Path(NO_EFFECT).exists():Path(STATE).unlink(missing_ok=True)
 if Path(FAIL_ONCE).exists():Path(FAIL_ONCE).unlink();raise SystemExit(1)
 print('{"ready":true}')
"""

if __name__=='__main__':unittest.main()
