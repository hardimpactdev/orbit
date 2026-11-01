"""Official SSH native server DAG: public intent, vault stdin and independent actions."""

import json
import os
import shutil
import sys
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


def reply(raw):
    return json.JSONDecoder().raw_decode(raw.lstrip())[0]


class ServerCreateNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/database-server-commands', 'operations/process-commands', 'operations/processes-schedules')
        source = ROOT / '.swamp/pulled-extensions/@swamp/ssh'
        destination = self.repo / '.swamp/pulled-extensions/@swamp/ssh'
        destination.parent.mkdir(parents=True)
        shutil.copytree(source, destination)
        index = self.repo / 'extensions/models/upstream_extensions.json'
        index.parent.mkdir(parents=True)
        index.write_text(json.dumps({'@swamp/ssh': json.loads((ROOT / 'extensions/models/upstream_extensions.json').read_text())['@swamp/ssh']}))
        self.state = self.repo / 'fixture-state.json'
        self.state.write_text(json.dumps({'containers': {}, 'calls': [], 'policy_refused': False, 'fail_scrub_start': False}))
        fake = self.repo / 'fixture-ssh'
        fake.write_text('#!' + sys.executable + '\n' + '''import json,shlex,sys
from pathlib import Path
if '-M' in sys.argv or '-O' in sys.argv: sys.exit(0)
p=Path(STATE)
s=json.loads(p.read_text())
args=shlex.split(sys.argv[-1])
raw=sys.stdin.read()
code=0
result={'ready':True}
paths=[(i,a) for i,a in enumerate(args) if a.endswith(('.py',))]
if paths:
 i,path=paths[0]
 action=Path(path).name
 if action=='process.py':
  intent=json.loads(args[i+1]);slot,spec,action,directory,operation,destination,desired,private,scrub=args[i+2:]
  s['calls'].append({'action':action,'slot':slot,'spec':spec,'operation':operation,'argv':args})
  c=s['containers'];current=c.get(slot)
  if private=='true':
   material=json.loads(raw)
   assert 'password' in material and 'environment' in material
  if action=='inspect.py': result={'present':current is not None,'state':current['state'] if current else 'absent',**({'spec':current['spec']} if current else {})}
  elif action=='create_container.py':c[slot]={'spec':spec,'state':'created','initializer':scrub=='false'}
  elif action=='rename_container.py':
   if slot not in c or destination in c: result={'error_code':'process.recovery_required'};code=1
   else:c[destination]=c.pop(slot)
  elif action=='control.py':
   if operation=='start' and spec=='c'*64 and s['fail_scrub_start']:
    result={'error_code':'process.start_failed'};code=1
   elif slot not in c:result={'error_code':'process.not_found'};code=1
   else:c[slot]['state']='exited' if operation=='stop' else 'running'
  elif action=='verify.py':
   if current is None or current['state']!=('running' if desired=='running' else 'exited'):result={'error_code':'process.state_mismatch'};code=1
  elif action=='remove_container.py':c.pop(slot,None)
 elif action in ('inspect_policy.py','write_acl.py','wait_ready.py','ready.py'):
  s['calls'].append({'action':action,'argv':args})
  json.loads(raw)
  if action=='inspect_policy.py':
   result={'rebuild':True}
   if s['policy_refused']:result={'error_code':'database.server_adoption_refused'};code=1
p.write_text(json.dumps(s))
print(json.dumps(result))
sys.exit(code)
'''.replace('STATE', repr(str(self.state))))
        fake.chmod(0o700)
        scp = self.repo / 'fixture-scp'
        scp.write_text('#!/bin/sh\nexit 0\n')
        scp.chmod(0o700)
        identity = self.repo / 'fixture-key'
        identity.write_text('fixture key')
        identity.chmod(0o600)
        self.workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-database-server-create.yaml').read_text())
        self.child_workflow = yaml.safe_load((ROOT / 'workflows/workflow-database-server-container.yaml').read_text())
        for workflow in (self.workflow, self.child_workflow):
            for job in workflow['jobs']:
                self.assertEqual(job.pop('target'), 'orbit-node-209'); self.assertNotIn('labels', job)
                for step in job['steps']:
                    self.assertEqual(step.pop('target'), 'orbit-node-209')
                    self.assertNotIn('labels', step)
                    if step['task'].get('behaviorType') == '@swamp/ssh':
                        step['task']['globalArgs']['sshBinary'] = str(fake)
                        step['task']['globalArgs']['scpBinary'] = str(scp)
                        step['task']['globalArgs']['transport']['identityFile'] = str(identity)
        self.save()
        self.material = {'password': 'private-server-create-native-sentinel', 'environment': {'MYSQL_ROOT_PASSWORD': 'private-server-create-native-sentinel'}, 'acl': '', 'payload': {}}
        self.secrets = (self.material['password'],)
        self.public = {'operation_id':17,'node_id':9,'kind':'database_server.create','intent':{'driver':'mysql','process_id':42,'container':'orbit-process-42-proof',
            'container_input':{'image':'mysql:8.0','command':['mysqld'],'restart':'unless-stopped','working_directory':None,'ports':['10.44.0.9:3306:3306'],'mounts':[{'source':'orbit-proof-data','target':'/var/lib/mysql','read_only':False}]},
            'spec':'b'*64,'scrub_spec':'c'*64,'initialize':True,'validate_existing':False,'was_active':False,'default_open':False,'host':'10.44.0.9','port':3306,'volume':None,
            'ssh':{'host':'fixture','user':'orbit','host_keys':['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}},
            'execution_secrets':{'server_create':{'vault':'orbit-control-inputs','key':'database-server-create-intent-17'}}}
        self.cli('vault','create','local_encryption','orbit-control-inputs')
        self.cli('vault','put','orbit-control-inputs','database-server-create-intent-17',input=json.dumps(self.material))

    def save(self):
        (self.repo / 'workflows/workflow-database-server-container.yaml').write_text(yaml.safe_dump(self.child_workflow,sort_keys=False))
        (self.repo / 'workflows/workflow-operation-database-server-create.yaml').write_text(yaml.safe_dump(self.workflow,sort_keys=False))

    def run_server(self, success=True):
        self.cli('workflow','validate','operation-database-server-create')
        return reply(self.cli('workflow','run','operation-database-server-create','--input',json.dumps(self.public),success=success).stdout)

    def history(self, run):
        return reply(self.cli('workflow','history','get',run['id']).stdout)

    def test_initialization_readiness_scrub_and_terminal_result(self):
        run=self.run_server()
        self.assertEqual(run['status'],'succeeded')
        state=json.loads(self.state.read_text())
        self.assertEqual(state['containers'],{'canonical':{'spec':'c'*64,'state':'running','initializer':False}})
        actions=[c['action'] for c in state['calls']]
        self.assertEqual(actions.count('ready.py'),2)
        history=self.history(run)
        self.assertEqual(history['inputs'],self.public)
        result=next(s for s in history['jobs'][0]['steps'] if s['name']=='result')
        self.assertEqual(json.loads(result['outputs']['stdout']),{'backend':'active'})
        self.assert_private_absent(json.dumps(history),self.state.read_text())

    def test_adoption_refusal_prevents_all_domain_mutations(self):
        state=json.loads(self.state.read_text());state['policy_refused']=True;self.state.write_text(json.dumps(state))
        self.public['intent'].update(driver='redis',initialize=False,validate_existing=True)
        run=self.run_server(success=False)
        state=json.loads(self.state.read_text())
        self.assertEqual(state['containers'],{})
        self.assertEqual([c['action'] for c in state['calls']],['inspect_policy.py'])
        result=next(s for s in self.history(run)['jobs'][0]['steps'] if s['name']=='result')
        self.assertEqual(json.loads(result['outputs']['stdout']),{'error_code':'database.server_adoption_refused'})

    def test_scrub_failure_restores_then_native_phase_resume_keeps_initial_work(self):
        state=json.loads(self.state.read_text());state['fail_scrub_start']=True;self.state.write_text(json.dumps(state))
        run=self.run_server(success=False)
        state=json.loads(self.state.read_text())
        self.assertEqual(state['containers'],{'canonical':{'spec':'b'*64,'state':'running','initializer':True}})
        before=len(state['calls'])
        state['fail_scrub_start']=False;self.state.write_text(json.dumps(state))
        resumed=reply(self.cli('workflow','resume','operation-database-server-create','--run',run['id'],'--from','scrub').stdout)
        self.assertEqual(resumed['status'],'succeeded')
        state=json.loads(self.state.read_text())
        self.assertEqual(state['containers'],{'canonical':{'spec':'c'*64,'state':'running','initializer':False}})
        new_creates=[c for c in state['calls'][before:] if c['action']=='create_container.py']
        self.assertEqual([c['spec'] for c in new_creates],['c'*64])

    def test_terminal_delivery_resume_does_not_recreate_or_restart(self):
        marker=self.repo/'delivery-failed';marker.touch()
        self.workflow['jobs'][0]['steps'].append({'name':'fixture_delivery','dependsOn':[{'step':'accept_result','condition':{'type':'succeeded'}}],
            'task':{'type':'behavior','behaviorType':'command/shell','behaviorName':'fixture-delivery-${{ run.id }}','methodName':'execute','inputs':{'run':f'test ! -e "{marker}"'}}})
        self.save();run=self.run_server(success=False)
        before=self.state.read_text();marker.unlink()
        self.cli('workflow','resume','operation-database-server-create','--run',run['id'])
        self.assertEqual(self.state.read_text(),before)


if __name__=='__main__':unittest.main()
