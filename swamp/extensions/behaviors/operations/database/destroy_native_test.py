"""Native destructive-set safety, compensation and ordinary resume on official Swamp."""
import json
import os
import socket
import subprocess
import sys
import time
import unittest
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from database_command_native import DatabaseCommands, reply


class DatabaseDestroyNative(DatabaseCommands):
    fail = unittest.TestCase.fail
    def setUp(self):
        super().setUp()
        self.prepare_commands(['workflow-operation-database-destroy.yaml', 'workflow-database-destroy-actions.yaml'])
        self.material = {'password': 'private-destroy-admin-sentinel'}
        self.secrets = (self.material['password'],)
        self.public = {'operation_id':17,'node_id':9,'kind':'database.destroy',
            'intent':{'driver':'mysql','container':'orbit-process-42-proof','host':'10.44.0.9','port':3306,'default_open':False,'databases':['proof'],'users':['proof'],'test_prefixes':[],'owners':{'proof':'proof'},'ssh':{'host':'fixture','user':'orbit','host_keys':['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}},
            'execution_secrets':{'database_destroy':{'vault':'orbit-control-inputs','key':'database-destroy-intent-17'}}}
        self.cli('vault','create','local_encryption','orbit-control-inputs')
        self.cli('vault','put','orbit-control-inputs','database-destroy-intent-17',input=json.dumps(self.material))

    def run_parent(self, success=True):
        self.cli('workflow','validate','operation-database-destroy')
        return reply(self.cli('workflow','run','operation-database-destroy','--input',json.dumps(self.public),success=success).stdout)

    def actions(self):
        return [c['action'] for c in self.calls()]

    def test_single_destroy_has_no_discovery_claim_or_gateway_executor(self):
        state=json.loads(self.state.read_text());state['inventory']=['proof_testing_1'];self.state.write_text(json.dumps(state))
        self.public['intent'].update(test_prefixes=['proof_testing'],owners={'proof':'proof','proof_testing':'proof'})
        run=self.run_parent()
        self.assertEqual(self.actions(),['inventory','ownership','blockers','drop_database','drop_database','drop_user'])
        self.assertEqual(self.outcome(run),{'backend':'absent'})
        self.assert_private_absent(json.dumps(self.history(run)))

    def test_lost_terminal_projection_resumes_without_repeating_effect(self):
        marker=self.repo/'delivery-failed';marker.touch()
        self.workflows['workflow-operation-database-destroy.yaml']['jobs'][-1]['steps'].append({'name':'fixture_delivery','dependsOn':[{'step':'accept_result','condition':{'type':'succeeded'}}],'task':{'type':'behavior','behaviorType':'command/shell','behaviorName':'fixture-delivery-${{ run.id }}','methodName':'execute','inputs':{'run':f'test ! -e "{marker}"'}}})
        self.save();first=self.run_parent(False);marker.unlink();self.public['operation_id']=18;self.run_parent()
        before=self.calls();self.cli('workflow','resume','operation-database-destroy','--run',first['id'])
        self.assertEqual(self.calls(),before)
        self.assertEqual(self.outcome(first),{'backend':'absent'})

    def test_postgres_fence_precedes_drops_and_restores_after_refused_blockers(self):
        self.public['intent']['driver']='pgsql';DatabaseCommands.fail(self,'blockers','database.in_use')
        run=self.run_parent(False)
        self.assertEqual(self.actions(),['ownership','fence','blockers','restore'])
        self.assertEqual(self.outcome(run),{'error_code':'database.destroy_outcome_unknown'})

    def test_native_parent_resume_rechecks_safety_after_partial_database_failure(self):
        # Serve's resolver forwards globalArgs for a previously unexecuted item.
        # Official e459's local CLI resume resolver drops those arguments.
        self.cli('access','token','mint','fixture-admin','--principal','user:fixture')
        token=self.repo/'admin-token'
        token.write_text(reply(self.cli('access','token','reveal','fixture-admin','--yes').stdout)['token'])
        token.chmod(0o600)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        env=dict(os.environ,SWAMP_NO_UPDATE_CHECK='1',SWAMP_NO_TELEMETRY='1')
        for name in ('SWAMP_SERVER_URL','SWAMP_SERVE_URL','SWAMP_SERVER_TOKEN','SWAMP_SERVER_TOKEN_FILE','SWAMP_REPO_DIR','SWAMP_CA_CERT','SWAMP_EXTENSIONS_DIR'):
            env.pop(name,None)
        with (self.repo/'serve.log').open('w') as log:
            serve=subprocess.Popen([os.environ.get('SWAMP_BIN','swamp'),'serve','--host','127.0.0.1','--port',str(port),'--auth-mode','token','--admins','user:fixture'],cwd=self.repo,env=env,stdout=log,stderr=log)
            try:
                for _ in range(100):
                    try:
                        urllib.request.urlopen(f'http://127.0.0.1:{port}/ready',timeout=1).close();break
                    except OSError:
                        self.assertIsNone(serve.poll(),(self.repo/'serve.log').read_text()[-2000:]);time.sleep(.1)
                flags=('--server',f'ws://127.0.0.1:{port}','--token-file',str(token))
                self.public['intent']['driver']='pgsql';DatabaseCommands.fail(self,'drop_database')
                first=reply(self.cli('workflow','run','operation-database-destroy','--input',json.dumps(self.public),*flags,success=False).stdout)
                self.assertEqual(self.actions(),['ownership','fence','blockers','drop_database','restore'])
                DatabaseCommands.fail(self,'');self.cli('workflow','resume','operation-database-destroy','--run',first['id'],*flags)
                self.assertEqual(self.actions()[5:],['ownership','fence','blockers','drop_database','drop_user','restore'])
                self.assertEqual(self.outcome(first),{'backend':'absent'})
            finally:
                serve.terminate()
                try:serve.wait(timeout=10)
                except subprocess.TimeoutExpired:serve.kill();serve.wait(timeout=5)

    def test_missing_ownership_outcome_stops_before_any_drop(self):
        state=json.loads(self.state.read_text());state['transport_fail']='ownership';self.state.write_text(json.dumps(state))
        run=self.run_parent(False)
        self.assertEqual(self.actions(),['ownership'])
        self.assertEqual(self.outcome(run),{'error_code':'database.destroy_outcome_unknown'})

    def test_zero_exit_without_ready_json_cannot_authorize_a_drop(self):
        state=json.loads(self.state.read_text());state['empty_output']='ownership';self.state.write_text(json.dumps(state))
        run=self.run_parent(False)
        self.assertEqual(self.actions(),['ownership'])
        self.assertEqual(self.outcome(run),{'error_code':'database.destroy_outcome_unknown'})

    def test_valkey_save_failure_restores_only_admitted_users_and_preserves_default(self):
        self.public['intent'].update(driver='redis',container=None,databases=[],users=['default','proof'],owners={})
        DatabaseCommands.fail(self,'acl_save','database.server_command_failed')
        run=self.run_parent(False)
        self.assertEqual(self.actions(),['acl_snapshot','acl_remove','acl_save','acl_restore','acl_restore_save'])
        self.assertEqual(self.outcome(run),{'error_code':'database.destroy_outcome_unknown'})
        self.assertEqual([c['item'] for c in self.calls() if c['action'] in ('acl_remove','acl_restore')],['proof','proof'])
        DatabaseCommands.fail(self,'');self.cli('workflow','resume','operation-database-destroy','--run',run['id'])
        self.assertEqual(self.outcome(run),{'backend':'absent'})


if __name__=='__main__': unittest.main()
