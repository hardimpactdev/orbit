"""Native owned-set fan-out, per-server secrets, typed aggregate and resume."""
import copy
import hashlib
import json
import os
import signal
import subprocess
import sys
import time
import unittest
import uuid
from pathlib import Path

ROOT=Path(__file__).resolve().parents[4]
sys.path.insert(0,str(ROOT/'tests'))
from database_command_native import DatabaseCommands, reply


class DatabaseDestroyOwnedNative(DatabaseCommands):
    # DatabaseCommands.fail injects fixture errors; keep unittest assertions fatal.
    fail = unittest.TestCase.fail
    def setUp(self):
        super().setUp()
        self.prepare_commands(['workflow-operation-database-destroy-owned.yaml','workflow-database-destroy-actions.yaml','workflow-database-owned-sqlite-remove.yaml'])
        fake=self.repo/'fixture-ssh';s=fake.read_text().replace('import json,os,shlex,sys','import json,os,shlex,sys,hashlib')
        s=s.replace("m=json.load(sys.stdin);assert m['password'] not in repr(args)","m={} if scripts[0]=='owned_sqlite_remove' else json.load(sys.stdin)\nif m:assert m['password'] not in repr(args)")
        s=s.replace("fd=os.open(", "scope=json.loads(args[-5])['container'] if scripts[0]=='destroy' else 'sqlite'\nif m:assert hashlib.sha256(m['password'].encode()).hexdigest()==v['password_hashes'][scope]\nfd=os.open(")
        s=s.replace("{'action':action,'item':item}","{'action':action,'item':item,'scope':scope,'scratch':args[-1] if scripts[0]=='destroy' else ''}")
        s=s.replace("else {'ready':True}))", "else {'backend':'absent'} if action=='owned_sqlite_remove' else {'ready':True}))")
        fake.write_text(s)
        ssh={'host':'fixture','user':'orbit','host_keys':['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}
        intent={'driver':'mysql','container':'orbit-process-42-proof','host':'10.44.0.9','port':3306,'default_open':False,'databases':['proof'],'users':['proof'],'test_prefixes':[],'owners':{'proof':'proof'},'ssh':ssh}
        servers=[{'server_id':42,'node_id':9,'intent':intent},{'server_id':43,'node_id':9,'intent':dict(copy.deepcopy(intent),container='orbit-process-43-proof',port=3307)}]
        self.public={'operation_id':17,'node_id':209,'kind':'database.destroy_owned','intent':{'servers':servers,'sqlite':[{'connection_id':2,'node_id':10,'checkout':'/apps/proof','path':'/apps/proof/database.sqlite','ssh':ssh}]},'execution_secrets':{}}
        self.cli('vault','create','local_encryption','orbit-control-inputs')
        secrets=[];hashes={}
        for server in servers:
            password='private-owned-'+uuid.uuid4().hex;secrets.append(password);hashes[server['intent']['container']]=hashlib.sha256(password.encode()).hexdigest()
            key='database-destroy-owned-intent-17-server-'+str(server['server_id'])
            self.cli('vault','put','orbit-control-inputs',key,input=json.dumps({'password':password}))
            self.public['execution_secrets']['server_'+str(server['server_id'])]={'vault':'orbit-control-inputs','key':key}
        self.secrets=tuple(secrets)
        state=json.loads(self.state.read_text());state['password_hashes']=hashes;self.state.write_text(json.dumps(state))

    def run_owned(self,success=True):
        self.cli('workflow','validate','operation-database-destroy-owned')
        return reply(self.cli('workflow','run','operation-database-destroy-owned','--input',json.dumps(self.public),success=success).stdout)

    def test_two_servers_on_one_node_and_sqlite_use_independent_children_and_passwords(self):
        run=self.run_owned();calls=self.calls()
        self.assertEqual(self.outcome(run),{'backend':'absent'})
        self.assertEqual(sum(c['action']=='drop_database' for c in calls),2)
        self.assertEqual(sum(c['action']=='owned_sqlite_remove' for c in calls),1)
        self.assertEqual(len({c['scratch'] for c in calls if c['scope']!='sqlite'}),2)
        models=reply(self.cli('data','query','modelName.startsWith("dbd-") && name == "run-exec-server"','--select','modelName').stdout)['results']
        self.assertEqual(len(set(models)),2)
        self.assert_private_absent(json.dumps(self.history(run)))

    def test_empty_set_needs_no_vault_reference_and_runs_no_ssh(self):
        self.public['intent']={'servers':[],'sqlite':[]};self.public['execution_secrets']={}
        run=self.run_owned();self.assertEqual(self.calls(),[]);self.assertEqual(self.outcome(run),{'backend':'absent'})

    def test_refusal_keeps_unknown_when_native_failed_child_omits_outputs(self):
        DatabaseCommands.fail(self,'ownership','database.ownership_unproven')
        run=self.run_owned(False)
        self.assertNotIn('drop_database',[c['action'] for c in self.calls()])
        self.assertEqual(self.outcome(run),{'error_code':'database.destroy_owned_outcome_unknown'})

    def test_missing_sqlite_output_does_not_claim_success(self):
        state=json.loads(self.state.read_text());state['empty_output']='owned_sqlite_remove';self.state.write_text(json.dumps(state))
        run=self.run_owned(False);self.assertEqual(self.outcome(run),{'error_code':'database.destroy_owned_outcome_unknown'})

    def test_terminal_resume_after_another_root_does_not_repeat_removal(self):
        marker=self.repo/'delivery-failed';marker.touch()
        self.workflows['workflow-operation-database-destroy-owned.yaml']['jobs'][-1]['steps'].append({'name':'fixture_delivery','dependsOn':[{'step':'accept_result','condition':{'type':'succeeded'}}],'task':{'type':'behavior','behaviorType':'command/shell','behaviorName':'fixture-delivery-${{ run.id }}','methodName':'execute','inputs':{'run':f'test ! -e "{marker}"'}}})
        self.save();first=self.run_owned(False);marker.unlink();self.public['operation_id']=18;self.run_owned()
        before=self.calls();self.cli('workflow','resume','operation-database-destroy-owned','--run',first['id'])
        self.assertEqual(self.calls(),before);self.assertEqual(self.outcome(first),{'backend':'absent'})

    def test_native_cancellation_after_postgres_fence_runs_restore_and_keeps_cancelled(self):
        self.public['intent']['servers']=self.public['intent']['servers'][:1];self.public['intent']['servers'][0]['intent']['driver']='pgsql';self.public['intent']['sqlite']=[]
        marker=self.repo/'cancel-ready'
        fake=self.repo/'fixture-ssh';s=fake.read_text();before="if v.get('transport_fail')==action:sys.exit(255)"
        s=s.replace(before,"if action=='drop_database':\n import time\n Path("+repr(str(marker))+").touch();time.sleep(60)\n"+before);fake.write_text(s)
        env=dict(os.environ,SWAMP_NO_UPDATE_CHECK='1',SWAMP_NO_TELEMETRY='1')
        for name in ('SWAMP_SERVER_URL','SWAMP_SERVE_URL','SWAMP_SERVER_TOKEN','SWAMP_SERVER_TOKEN_FILE','SWAMP_REPO_DIR','SWAMP_CA_CERT','SWAMP_EXTENSIONS_DIR'):env.pop(name,None)
        process=subprocess.Popen([os.environ.get('SWAMP_BIN','swamp'),'workflow','run','operation-database-destroy-owned','--input',json.dumps(self.public),'--json'],cwd=self.repo,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
        try:
            deadline=time.monotonic()+90
            while not marker.exists():
                self.assertIsNone(process.poll(),'Native cancellation gate was not reached');self.assertLess(time.monotonic(),deadline,'Native cancellation gate timed out');time.sleep(.05)
            process.send_signal(signal.SIGINT)
            stdout,stderr=process.communicate(timeout=90);self.assert_private_absent(stdout,stderr)
            run=reply(stdout);self.assertEqual(run['status'],'cancelled')
            self.assertIn('restore',[c['action'] for c in self.calls()])
        finally:
            if process.poll() is None:process.kill();process.communicate(timeout=10)


if __name__=='__main__':unittest.main()
