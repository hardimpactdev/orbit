"""Native database-create composition, guards, fan-out, private inputs and resume."""

import os
import socket
import subprocess
import time
import urllib.request
import json
import sys
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from database_command_native import DatabaseCommands


def reply(raw):
    return json.JSONDecoder().raw_decode(raw.lstrip())[0]


class CreateNative(DatabaseCommands):
    native_timeout = 180

    def setUp(self):
        super().setUp()
        self.prepare_commands(['workflow-operation-database-create.yaml', 'workflow-database-create-action.yaml'])
        self.material = {'password': 'private-create-admin-sentinel', 'payload': {'password': 'a' * 40, 'salt': 'YWFhYWFhYWFhYWFhYWFhYQ=='}}
        self.secrets = (self.material['password'], self.material['payload']['password'])
        self.public = {'operation_id': 17, 'node_id': 9, 'kind': 'database.create', 'intent': {'repair': False, 'driver': 'mysql', 'username': 'proof_owner', 'databases': ['proof_main', 'proof_test'], 'test_prefix': 'proof_test', 'locale': None, 'host': '10.44.0.9', 'port': 3306, 'container': 'orbit-process-42-proof', 'index': None, 'patterns': [], 'allow': [], 'ssh': {'host': 'fixture', 'user': 'orbit', 'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}}, 'execution_secrets': {'database_create': {'vault': 'orbit-control-inputs', 'key': 'database-create-intent-17'}}}
        self.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        self.cli('vault', 'put', 'orbit-control-inputs', 'database-create-intent-17', input=json.dumps(self.material))

    def run_create(self, success=True):
        self.cli('workflow', 'validate', 'operation-database-create')
        return reply(self.cli('workflow', 'run', 'operation-database-create', '--input', json.dumps(self.public), success=success).stdout)

    def test_mysql_proves_before_changes_and_grants_before_create_with_native_fanout(self):
        run = self.run_create()
        self.assertEqual([x['action'] for x in self.calls()], ['sql_probe_user', 'sql_probe_databases', 'sql_create_user', 'sql_authenticate', 'mysql_grant', 'mysql_grant', 'mysql_grant', 'sql_create_database', 'sql_create_database'])
        self.assertEqual(self.outcome(run), {'backend': 'active'})
        records = reply(self.cli('data','query','modelName.startsWith("database-create-action-") && name == "run-exec-server"','--select','modelName').stdout)['results']
        self.assertEqual(len(set(records)), len(self.calls()))
        self.assert_private_absent(json.dumps(self.history(run)))

    def test_postgres_each_database_has_separate_create_revoke_and_schema_grant(self):
        self.public['intent'].update(driver='pgsql', locale='C.UTF-8', test_prefix=None)
        run = self.run_create()
        names = [x['action'] for x in self.calls()]
        self.assertNotIn('mysql_grant', names)
        for name in ['sql_create_database', 'postgres_revoke_database', 'postgres_revoke_schema', 'postgres_grant_schema']:
            self.assertEqual(names.count(name), 2)
        self.assertEqual(self.outcome(run), {'backend': 'active'})

    def test_valkey_checks_index_and_user_before_set_then_save(self):
        self.public['intent'].update(driver='redis', databases=[], container=None, test_prefix=None, index=15, patterns=['proof:*'], allow=['@list'])
        run = self.run_create()
        self.assertEqual([x['action'] for x in self.calls()], ['valkey_index', 'valkey_user', 'valkey_set_user', 'valkey_save'])
        self.assertEqual(self.outcome(run), {'backend': 'active'})

    def test_refusal_before_mutation_and_index_code_survives_failed_child(self):
        self.fail('sql_probe_databases', 'database.name_conflict')
        run = self.run_create(False)
        self.assertEqual([x['action'] for x in self.calls()], ['sql_probe_user', 'sql_probe_databases'])
        self.assertEqual(self.outcome(run), {'error_code': 'database.name_conflict'})

    def test_failed_foreach_child_blocks_later_mutations_then_resumes_natively(self):
        # Official e459 forwards new-model globals through serve, but not local CLI resume.
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
                self.fail('mysql_grant')
                run=reply(self.cli('workflow','run','operation-database-create','--input',json.dumps(self.public),*flags,success=False).stdout)
                self.assertNotIn('sql_create_database',[x['action'] for x in self.calls()])
                self.fail('')
                self.cli('workflow','resume','operation-database-create','--run',run['id'],*flags)
                self.assertEqual(self.outcome(run),{'backend':'active'})
            finally:
                serve.terminate()
                try:serve.wait(timeout=10)
                except subprocess.TimeoutExpired:serve.kill();serve.wait(timeout=5)

    def test_terminal_delivery_resume_after_later_run_does_not_repeat_effects(self):
        marker = self.repo / 'delivery-failed'
        marker.touch()
        self.workflows['workflow-operation-database-create.yaml']['jobs'][-1]['steps'].append({'name': 'fixture_delivery', 'dependsOn': [{'step': 'accept_result', 'condition': {'type': 'succeeded'}}], 'task': {'type': 'behavior', 'behaviorType': 'command/shell', 'behaviorName': 'fixture-delivery-${{ run.id }}', 'methodName': 'execute', 'inputs': {'run': f'test ! -e "{marker}"'}}})
        self.save()
        first = self.run_create(False)
        marker.unlink()
        self.public['operation_id'] = 18
        self.run_create()
        before = self.calls_file.read_text()
        self.cli('workflow', 'resume', 'operation-database-create', '--run', first['id'])
        self.assertEqual(self.calls_file.read_text(), before)
        self.assertEqual(self.outcome(first), {'backend': 'active'})


if __name__ == '__main__':
    unittest.main()
