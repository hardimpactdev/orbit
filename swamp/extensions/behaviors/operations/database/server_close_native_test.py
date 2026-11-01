"""Official native closure actions, compensation and terminal delivery; no fleet calls."""
import json
import os
import signal
import subprocess
import time
import sys
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[4]
sys.path.insert(0,str(ROOT/'tests'))
from database_command_native import DatabaseCommands, reply


class ServerCloseNative(DatabaseCommands):
    def setUp(self):
        super().setUp()
        self.prepare_commands(['workflow-operation-database-server-close.yaml'])
        self.secrets=('private-close-native-sentinel',)
        self.public={'operation_id':17,'node_id':9,'kind':'database_server.close',
            'intent':{'host':'10.44.0.9','port':6379,'default_open':True,'ssh':{'host':'fixture','user':'orbit','host_keys':['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}},
            'execution_secrets':{'server_close':{'vault':'orbit-control-inputs','key':'database-server-close-intent-17'}}}
        self.cli('vault','create','local_encryption','orbit-control-inputs')
        self.cli('vault','put','orbit-control-inputs','database-server-close-intent-17',input=json.dumps({'password':self.secrets[0]}))

    def run_close(self,success=True):
        self.cli('workflow','validate','operation-database-server-close')
        return reply(self.cli('workflow','run','operation-database-server-close','--input',json.dumps(self.public),success=success).stdout)

    def actions(self):return [c['action']for c in self.calls()]

    def test_closure_probes_changes_saves_then_disconnects(self):
        run=self.run_close()
        self.assertEqual(self.actions(),['probe','close','save','disconnect'])
        self.assertEqual(self.outcome(run),{'backend':'active'})

    def test_foreign_clients_stop_before_mutation(self):
        self.fail('probe','database.server_in_use');run=self.run_close(False)
        self.assertEqual(self.actions(),['probe'])
        self.assertEqual(self.outcome(run),{'error_code':'database.server_in_use'})

    def test_foreign_client_at_close_recheck_does_not_mutate_or_restore_acl(self):
        self.fail('close','database.server_in_use');run=self.run_close(False)
        self.assertEqual(self.actions(),['probe','close'])
        self.assertEqual(self.outcome(run),{'error_code':'database.server_in_use'})

    def test_failed_restore_preserves_unknown_access(self):
        self.fail('save','database.server_command_failed')
        state=json.loads(self.state.read_text());state['transport_fail']='restore';self.state.write_text(json.dumps(state))
        run=self.run_close(False)
        self.assertEqual(self.actions(),['probe','close','save','restore'])
        self.assertEqual(self.outcome(run),{'error_code':'database.server_restore_failed','default_open':'unknown'})

    def test_closed_intent_only_probes_password(self):
        self.public['intent']['default_open']=False
        run=self.run_close();self.assertEqual(self.actions(),['probe'])
        self.assertEqual(self.outcome(run),{'backend':'active'})

    def test_save_failure_restores_only_originally_open_access_and_native_retry_rechecks(self):
        self.fail('save','database.server_command_failed');run=self.run_close(False)
        self.assertEqual(self.actions(),['probe','close','save','restore'])
        self.assertEqual(self.outcome(run),{'error_code':'database.server_command_failed'})
        self.fail('');self.cli('workflow','resume','operation-database-server-close','--run',run['id'],'--from','assets')
        self.assertEqual(self.actions()[4:],['probe','close','save','disconnect'])
        self.assertEqual(self.outcome(run),{'backend':'active'})

    def test_previously_closed_access_is_not_reopened_on_save_failure(self):
        state=json.loads(self.state.read_text());state['was_open']=False;self.state.write_text(json.dumps(state))
        self.fail('save','database.server_command_failed');run=self.run_close(False)
        self.assertEqual(self.actions(),['probe','close','save'])
        self.assertEqual(self.outcome(run),{'error_code':'database.server_command_failed'})

    def test_zero_exit_without_probe_output_never_changes_access(self):
        state=json.loads(self.state.read_text());state['empty_output']='probe';self.state.write_text(json.dumps(state))
        run=self.run_close(False);self.assertEqual(self.actions(),['probe'])
        self.assertEqual(self.outcome(run),{'error_code':'database.server_command_failed'})

    def cancel_before_save(self, fail_restore):
        if fail_restore:self.fail('restore','database.server_command_failed')
        marker=self.repo/'cancel-ready'
        steps=self.workflows['workflow-operation-database-server-close.yaml']['jobs'][0]['steps']
        at=next(i for i,s in enumerate(steps) if s['name']=='save')
        steps.insert(at,{'name':'fixture_wait','dependsOn':[{'step':'close_checked','condition':{'type':'succeeded'}}],'task':{'type':'behavior','behaviorType':'command/shell','behaviorName':'fixture-wait','methodName':'execute','inputs':{'run':f'touch "{marker}"; sleep 90'}}})
        next(s for s in steps if s['name']=='save')['dependsOn'].append({'step':'fixture_wait','condition':{'type':'succeeded'}})
        self.save()
        env=dict(os.environ,SWAMP_NO_UPDATE_CHECK='1',SWAMP_NO_TELEMETRY='1')
        for key in ('SWAMP_SERVER_URL','SWAMP_SERVE_URL','SWAMP_SERVER_TOKEN','SWAMP_SERVER_TOKEN_FILE','SWAMP_REPO_DIR','SWAMP_CA_CERT','SWAMP_EXTENSIONS_DIR'):env.pop(key,None)
        process=subprocess.Popen([os.environ.get('SWAMP_BIN','swamp'),'workflow','run','operation-database-server-close','--input',json.dumps(self.public),'--json'],cwd=self.repo,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
        try:
            deadline=time.monotonic()+30
            while not marker.exists():
                self.assertIsNone(process.poll(),'Native cancellation gate was not reached')
                self.assertLess(time.monotonic(),deadline,'Native cancellation gate timed out')
                time.sleep(.05)
            self.assertEqual(self.actions(),['probe','close'])
            process.send_signal(signal.SIGINT)
            stdout,stderr=process.communicate(timeout=30)
            self.assert_private_absent(stdout,stderr)
            run=reply(stdout);self.assertEqual(run['status'],'cancelled')
            self.assertEqual(self.actions(),['probe','close','restore'])
            return self.outcome(run)
        finally:
            if process.poll() is None:process.kill();process.wait()

    def test_cancellation_after_close_runs_restore_before_terminal_result(self):
        self.assertEqual(self.cancel_before_save(False),{'error_code':'database.server_command_failed'})

    def test_cancelled_restore_failure_remains_unknown_in_root_output(self):
        self.assertEqual(self.cancel_before_save(True),{'error_code':'database.server_restore_failed','default_open':'unknown'})

    def test_lost_terminal_delivery_resumes_without_repeating_closure(self):
        marker=self.repo/'delivery-failed';marker.touch()
        self.workflows['workflow-operation-database-server-close.yaml']['jobs'][-1]['steps'].append({'name':'fixture_delivery','dependsOn':[{'step':'accepted','condition':{'type':'succeeded'}}],'task':{'type':'behavior','behaviorType':'command/shell','behaviorName':'fixture-delivery-${{ run.id }}','methodName':'execute','inputs':{'run':f'test ! -e "{marker}"'}}})
        self.save();first=self.run_close(False);marker.unlink()
        self.public['operation_id']=18;self.run_close()
        before=self.calls();self.cli('workflow','resume','operation-database-server-close','--run',first['id'])
        self.assertEqual(self.calls(),before)
        self.assertEqual(self.outcome(first),{'backend':'active'})


if __name__=='__main__':unittest.main()
