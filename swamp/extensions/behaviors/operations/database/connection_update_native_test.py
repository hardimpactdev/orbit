"""Official native connection-update workflow and vault/SSH boundaries."""
import json
import sys
import unittest
import uuid
from pathlib import Path

ROOT=Path(__file__).resolve().parents[4]
sys.path.insert(0,str(ROOT/'tests'))
from database_command_native import DatabaseCommands, reply


class ConnectionUpdateNative(DatabaseCommands):
    def setUp(self):
        super().setUp()
        self.prepare_commands(['workflow-operation-database-connection-update.yaml'])
        fake=self.repo/'fixture-ssh'
        fake.write_text(fake.read_text().replace("else {'ready':True}","else {'backend':'active'} if action=='connection_update' else {'ready':True}"))
        self.material={'password':'private-update-'+uuid.uuid4().hex}
        self.secrets=(self.material['password'],)
        self.public={'operation_id':17,'node_id':9,'kind':'database.update','intent':{'validate_index':True,'host':'10.44.0.9','port':6379,'index':'3','ssh':{'host':'fixture','user':'orbit','host_keys':['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}},'execution_secrets':{'connection_update':{'vault':'orbit-control-inputs','key':'database-update-intent-17'}}}
        self.cli('vault','create','local_encryption','orbit-control-inputs')
        self.cli('vault','put','orbit-control-inputs','database-update-intent-17',input=json.dumps(self.material))

    def run_update(self,success=True):
        self.cli('workflow','validate','operation-database-connection-update')
        return reply(self.cli('workflow','run','operation-database-connection-update','--input',json.dumps(self.public),success=success).stdout)

    def test_exact_placed_read_resolves_vault_without_leaking_history(self):
        run=self.run_update()
        self.assertEqual([c['action'] for c in self.calls()],['connection_update'])
        self.assertEqual(self.outcome(run),{'backend':'active'})
        self.assert_private_absent(json.dumps(self.history(run)))

    def test_record_only_needs_neither_ssh_access_nor_a_vault_key(self):
        self.public['intent']={'validate_index':False,'ssh':self.public['intent']['ssh']};self.public['execution_secrets']={}
        run=self.run_update()
        self.assertEqual(self.calls(),[])
        self.assertEqual(self.outcome(run),{'backend':'active'})
        steps=self.history(run)['jobs'][0]['steps']
        for name in ('directory','copy','check','cleanup'):
            self.assertEqual(next(s for s in steps if s['name']==name)['status'],'skipped')

    def test_index_refusal_keeps_bounded_code_without_gateway_calls(self):
        self.fail('connection_update','database.index_invalid')
        run=self.run_update(False)
        self.assertEqual(self.outcome(run),{'error_code':'database.index_invalid'})

    def test_missing_or_transport_output_never_accepts_a_patch(self):
        for field in ('empty_output','transport_fail'):
            state=json.loads(self.state.read_text());state.pop('empty_output',None);state.pop('transport_fail',None);state[field]='connection_update';self.state.write_text(json.dumps(state))
            run=self.run_update(False)
            self.assertEqual(self.outcome(run),{'error_code':'database.server_command_failed'})

    def test_terminal_delivery_resume_after_later_run_does_not_read_again(self):
        marker=self.repo/'delivery-failed';marker.touch()
        self.workflows['workflow-operation-database-connection-update.yaml']['jobs'][0]['steps'].append({'name':'fixture_delivery','dependsOn':[{'step':'accept_result','condition':{'type':'succeeded'}}],'task':{'type':'behavior','behaviorType':'command/shell','behaviorName':'fixture-delivery-${{ run.id }}','methodName':'execute','inputs':{'run':f'test ! -e "{marker}"'}}})
        self.save();first=self.run_update(False);marker.unlink()
        self.public['operation_id']=18;self.run_update()
        before=self.calls_file.read_text()
        self.cli('workflow','resume','operation-database-connection-update','--run',first['id'])
        self.assertEqual(self.calls_file.read_text(),before)
        self.assertEqual(self.outcome(first),{'backend':'active'})


if __name__=='__main__':unittest.main()
