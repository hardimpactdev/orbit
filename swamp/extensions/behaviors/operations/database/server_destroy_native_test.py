"""Pinned native fan-out checks every ownership observation before stopping a server."""
import json
import shutil
import sys
import unittest
from pathlib import Path

import yaml

ROOT=Path(__file__).resolve().parents[4]
sys.path.insert(0,str(ROOT/'tests'))
from native_harness import NativeTestCase


def reply(raw):
    return json.JSONDecoder().raw_decode(raw.lstrip())[0]


class ServerDestroyNative(NativeTestCase):
    native_timeout=120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/database-server-destroy','operations/process-commands','operations/processes-schedules')
        source=ROOT/'.swamp/pulled-extensions/@swamp/ssh'
        destination=self.repo/'.swamp/pulled-extensions/@swamp/ssh'
        destination.parent.mkdir(parents=True);shutil.copytree(source,destination)
        index=self.repo/'extensions/models/upstream_extensions.json';index.parent.mkdir(parents=True)
        index.write_text(json.dumps({'@swamp/ssh':json.loads((ROOT/'extensions/models/upstream_extensions.json').read_text())['@swamp/ssh']}))
        self.state=self.repo/'state';self.state.mkdir();self.calls=self.state/'calls'
        fake=self.repo/'fixture-ssh'
        fake.write_text('#!'+sys.executable+'\n'+r'''import json,shlex,sys
from pathlib import Path
if '-M' in sys.argv or '-O' in sys.argv:sys.exit(0)
state=Path(STATE);args=shlex.split(sys.argv[-1]);paths=[a for a in args if a.endswith('.py')]
if not paths:sys.exit(0)
control=json.loads(next(a.split('=',1)[1] for a in args if a.startswith('ORBIT_PROCESS_CONTROL=')))
slot=control['slot'];action=Path(paths[0]).name;p=state/(slot+'.json')
value=json.loads(p.read_text()) if p.exists() else None
with (state/'calls').open('a') as calls:calls.write(json.dumps({'slot':slot,'action':action})+'\n')
code=0;result={'ready':True}
if value and value.get('foreign'):code=1;result={'error_code':'process.name_collision'}
elif action=='inspect.py':result={'present':value is not None,'state':value['state'] if value else 'absent',**({'spec':'b'*64} if value else {})}
elif action=='control.py':
 if not value:code=1;result={'error_code':'process.not_found'}
 elif value.get('fail_stop'):code=1;result={'error_code':'process.stop_failed'}
 else:value['state']='exited';p.write_text(json.dumps(value))
elif action=='remove_container.py':
 if value and value['state'] not in ('created','exited','dead'):code=1;result={'error_code':'process.state_mismatch'}
 else:p.unlink(missing_ok=True)
print(json.dumps(result));sys.exit(code)
'''.replace('STATE',repr(str(self.state))))
        fake.chmod(0o700)
        scp=self.repo/'fixture-scp';scp.write_text('#!/bin/sh\nexit 0\n');scp.chmod(0o700)
        identity=self.repo/'fixture-key';identity.write_text('fixture key');identity.chmod(0o600)
        self.workflow=yaml.safe_load((ROOT/'workflows/workflow-operation-database-server-destroy.yaml').read_text())
        child=yaml.safe_load((ROOT/'workflows/workflow-database-server-remove-container.yaml').read_text())
        for workflow in (self.workflow,child):
            for job in workflow['jobs']:
                self.assertEqual(job.pop('target'),'orbit-node-209');self.assertNotIn('labels',job)
                for step in job['steps']:
                    self.assertEqual(step.pop('target'),'orbit-node-209');self.assertNotIn('labels',step)
                    if step['task'].get('behaviorType')=='@swamp/ssh':
                        globals=step['task']['globalArgs'];globals['sshBinary']=str(fake);globals['scpBinary']=str(scp);globals['transport']['identityFile']=str(identity)
        (self.repo/'workflows/workflow-database-server-remove-container.yaml').write_text(yaml.safe_dump(child,sort_keys=False))
        self.save()
        self.public={'operation_id':17,'node_id':9,'kind':'database_server.destroy','intent':{'process_id':42,'container':'orbit-process-42-proof','ssh':{'host':'fixture','user':'orbit','host_keys':['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}},'execution_secrets':{}}

    def save(self):
        (self.repo/'workflows/workflow-operation-database-server-destroy.yaml').write_text(yaml.safe_dump(self.workflow,sort_keys=False))

    def container(self,slot,state='running',foreign=False):
        (self.state/(slot+'.json')).write_text(json.dumps({'state':state,'foreign':foreign}))

    def run_server(self,success=True):
        self.cli('workflow','validate','operation-database-server-destroy')
        return reply(self.cli('workflow','run','operation-database-server-destroy','--input',json.dumps(self.public),success=success).stdout)

    def output(self,run):
        history=reply(self.cli('workflow','history','get',run['id']).stdout)
        return next(step['outputs'] for job in history['jobs'] if job['name']=='result' for step in job['steps'] if step['name']=='result')

    def test_removes_all_owned_slots_after_inspection(self):
        for slot in ('canonical','candidate','rollback-running','rollback-stopped','failed'):self.container(slot)
        run=self.run_server();self.assertEqual(run['status'],'succeeded')
        self.assertEqual(list(self.state.glob('*.json')),[])
        calls=[json.loads(line) for line in self.calls.read_text().splitlines()]
        self.assertEqual([c['action'] for c in calls[:5]],['inspect.py']*5)
        records=reply(self.cli('data','query','modelName.startsWith("dbdi-") && name == "run-exec-server"','--select','modelName').stdout)['results']
        self.assertEqual(set(records),{'dbdi-'+run['id']+'-'+str(index)for index in range(5)})
        self.assertEqual(json.loads(self.output(run)['stdout']),{'backend':'absent'})

    def test_foreign_last_slot_refuses_before_any_stop_or_remove(self):
        self.container('canonical');self.container('failed',foreign=True)
        run=self.run_server(False)
        self.assertEqual(json.loads(self.output(run)['stdout']),{'error_code':'process.name_collision'})
        self.assertTrue((self.state/'canonical.json').exists())
        self.assertEqual({json.loads(line)['action'] for line in self.calls.read_text().splitlines()},{'inspect.py'})

    def test_missing_containers_are_an_idempotent_success(self):
        run=self.run_server();self.assertEqual(run['status'],'succeeded')
        self.assertEqual(json.loads(self.output(run)['stdout']),{'backend':'absent'})
        self.assertEqual(len(self.calls.read_text().splitlines()),5)

    def test_record_only_cleanup_has_no_remote_process_call(self):
        self.public['intent'].update(process_id=None,container=None)
        self.container('canonical')
        run=self.run_server();self.assertEqual(run['status'],'succeeded')
        self.assertFalse(self.calls.exists());self.assertTrue((self.state/'canonical.json').exists())

    def test_stop_failure_keeps_public_refusal_and_native_phase_resume_reobserves(self):
        self.container('canonical');self.container('candidate',state='created')
        path=self.state/'canonical.json'
        value=json.loads(path.read_text());value['fail_stop']=True;path.write_text(json.dumps(value))
        run=self.run_server(False)
        self.assertEqual(json.loads(self.output(run)['stdout']),{'error_code':'process.stop_failed'})
        self.assertTrue(path.exists())
        value['fail_stop']=False;path.write_text(json.dumps(value))
        resumed=reply(self.cli('workflow','resume','operation-database-server-destroy','--run',run['id'],'--from','prepared').stdout)
        self.assertEqual(resumed['status'],'succeeded')
        self.assertEqual(list(self.state.glob('*.json')),[])

    def test_terminal_delivery_resume_does_not_replay_effects(self):
        self.container('canonical')
        marker=self.repo/'delivery-failed';marker.touch()
        self.workflow['jobs'][-1]['steps'].append({'name':'fixture_delivery','dependsOn':[{'step':'accept_result','condition':{'type':'succeeded'}}],'task':{'type':'behavior','behaviorType':'command/shell','behaviorName':'fixture-delivery-${{ run.id }}','methodName':'execute','inputs':{'run':f'test ! -e "{marker}"'}}})
        self.save();run=self.run_server(False);before=self.calls.read_text();marker.unlink()
        self.cli('workflow','resume','operation-database-server-destroy','--run',run['id'])
        self.assertEqual(self.calls.read_text(),before)


if __name__=='__main__':unittest.main()
