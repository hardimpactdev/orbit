"""Native SQL-user DAG with vault-backed official SSH and no Gateway callbacks."""
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


class UserCreateNative(NativeTestCase):
    native_timeout=120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/database-server-commands')
        source=ROOT/'.swamp/pulled-extensions/@swamp/ssh';dest=self.repo/'.swamp/pulled-extensions/@swamp/ssh'
        dest.parent.mkdir(parents=True);shutil.copytree(source,dest)
        index=self.repo/'extensions/models/upstream_extensions.json';index.parent.mkdir(parents=True)
        index.write_text(json.dumps({'@swamp/ssh':json.loads((ROOT/'extensions/models/upstream_extensions.json').read_text())['@swamp/ssh']}))
        self.state=self.repo/'state.json';self.state.write_text(json.dumps({'wal':'logical','effects':0,'calls':[],'fail_apply':False}))
        fake=self.repo/'fixture-ssh';fake.write_text('#!'+sys.executable+'\n'+r'''import json,shlex,sys
from pathlib import Path
if '-M' in sys.argv or '-O' in sys.argv:sys.exit(0)
args=shlex.split(sys.argv[-1]);scripts=[Path(a).name for a in args if a.endswith('.py')]
if not scripts:sys.exit(0)
path=Path(STATE);value=json.loads(path.read_text());action=scripts[0]
material=json.load(sys.stdin);assert isinstance(material['password'],str) and isinstance(material['sql'],str)
assert material['password'] not in repr(args) and material['sql'] not in repr(args)
value['calls'].append(action);code=0;result={'ready':True}
if action=='user_wal.py' and value['wal']!='logical':code=1;result={'error_code':'database.wal_level_not_logical'}
if action=='user_apply.py':
 value['effects']+=1
 if value['fail_apply']:code=1;result={'error_code':'database.server_admin_failed'}
 else:result={'backend':'active'}
path.write_text(json.dumps(value));print(json.dumps(result));sys.exit(code)
'''.replace('STATE',repr(str(self.state))));fake.chmod(0o700)
        scp=self.repo/'fixture-scp';scp.write_text('#!/bin/sh\nexit 0\n');scp.chmod(0o700)
        key=self.repo/'fixture-key';key.write_text('fixture key');key.chmod(0o600)
        self.workflow=yaml.safe_load((ROOT/'workflows/workflow-operation-database-user-create.yaml').read_text())
        for job in self.workflow['jobs']:
            job.pop('target');job.pop('labels')
            for step in job['steps']:
                self.assertEqual(step.pop('target'),'orbit-node-209');self.assertEqual(step.pop('labels'),{'node':'209'})
                if step['task'].get('behaviorType')=='@swamp/ssh':
                    args=step['task']['globalArgs'];args['sshBinary']=str(fake);args['scpBinary']=str(scp);args['transport']['identityFile']=str(key)
        self.save()
        self.material={'password':'private-root-user-sentinel','sql':"CREATE USER proof PASSWORD 'private-new-login-sentinel';"};self.secrets=(self.material['password'],self.material['sql'],'private-new-login-sentinel')
        self.public={'operation_id':17,'node_id':9,'kind':'database_user.create','intent':{'driver':'mysql','container':'orbit-process-42-proof','username':'proof','replication':False,'known_user':False,'ssh':{'host':'fixture','user':'orbit','host_keys':['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}},'execution_secrets':{'user_create':{'vault':'orbit-control-inputs','key':'database-user-create-intent-17'}}}
        self.cli('vault','create','local_encryption','orbit-control-inputs')
        self.cli('vault','put','orbit-control-inputs','database-user-create-intent-17',input=json.dumps(self.material))

    def save(self):
        (self.repo/'workflows/workflow-operation-database-user-create.yaml').write_text(yaml.safe_dump(self.workflow,sort_keys=False))

    def run_user(self,success=True):
        self.cli('workflow','validate','operation-database-user-create')
        return reply(self.cli('workflow','run','operation-database-user-create','--input',json.dumps(self.public),success=success).stdout)

    def history(self,run):
        return reply(self.cli('workflow','history','get',run['id']).stdout)

    def outcome(self,run):
        step=next(s for s in self.history(run)['jobs'][0]['steps'] if s['name']=='result')
        return json.loads(step['outputs']['stdout'])

    def test_mysql_runs_one_sql_action_with_vault_material_absent_from_history(self):
        run=self.run_user();state=json.loads(self.state.read_text())
        self.assertEqual(state['calls'],['user_apply.py']);self.assertEqual(state['effects'],1)
        self.assertEqual(self.outcome(run),{'backend':'active'})
        self.assert_private_absent(json.dumps(self.history(run)),self.state.read_text())

    def test_postgres_wal_check_is_a_separate_step_before_sql(self):
        self.public['intent'].update(driver='pgsql',replication=True)
        run=self.run_user();self.assertEqual(json.loads(self.state.read_text())['calls'],['user_wal.py','user_apply.py'])
        self.assertEqual(self.outcome(run),{'backend':'active'})

    def test_postgres_refusal_prevents_sql_and_keeps_bounded_error(self):
        self.public['intent'].update(driver='pgsql',replication=True)
        state=json.loads(self.state.read_text());state['wal']='replica';self.state.write_text(json.dumps(state))
        run=self.run_user(False);self.assertEqual(json.loads(self.state.read_text())['effects'],0)
        self.assertEqual(self.outcome(run),{'error_code':'database.wal_level_not_logical'})

    def test_failed_sql_exposes_no_private_diagnostics(self):
        state=json.loads(self.state.read_text());state['fail_apply']=True;self.state.write_text(json.dumps(state))
        run=self.run_user(False);self.assertEqual(self.outcome(run),{'error_code':'database.server_admin_failed'})
        self.assert_private_absent(json.dumps(self.history(run)))

    def test_later_run_does_not_overwrite_saved_result_for_terminal_resume(self):
        marker=self.repo/'delivery-failed';marker.touch()
        self.workflow['jobs'][0]['steps'].append({'name':'fixture_delivery','dependsOn':[{'step':'accept_result','condition':{'type':'succeeded'}}],'task':{'type':'behavior','behaviorType':'command/shell','behaviorName':'fixture-delivery-${{ run.id }}','methodName':'execute','inputs':{'run':f'test ! -e "{marker}"'}}})
        self.save();first=self.run_user(False);marker.unlink()
        self.public['operation_id']=18;second=self.run_user();self.assertNotEqual(first['id'],second['id'])
        before=self.state.read_text();self.cli('workflow','resume','operation-database-user-create','--run',first['id'])
        self.assertEqual(self.state.read_text(),before)
        self.assertEqual(self.outcome(first),{'backend':'active'})


if __name__=='__main__':unittest.main()
