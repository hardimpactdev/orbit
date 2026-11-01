"""Unmodified official SSH, private vault stdin and native result-only resume."""

import json
import os
import pwd
import shutil
import sqlite3
import sys
import tempfile
import unittest
import uuid
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


def reply(raw):
    return json.JSONDecoder().raw_decode(raw.lstrip())[0]


class WriteNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        domain = tempfile.TemporaryDirectory(prefix='database-write-domain-')
        self.addCleanup(domain.cleanup)
        self.database = Path(domain.name) / 'proof.sqlite'
        with sqlite3.connect(self.database) as database:
            database.execute('CREATE TABLE proof(value INTEGER)')
            database.execute('INSERT INTO proof VALUES (0)')
        self.material = {'password': '', 'username': None, 'dsn': '',
                         'sql': 'UPDATE proof SET value=value+1 /* private-'+uuid.uuid4().hex+' */'}
        self.secrets = (self.material['sql'],)
        self.public = {'operation_id': 17, 'node_id': 9, 'kind': 'database.write',
                       'intent': {'driver': 'sqlite', 'connection_id': 47,
                                  'owner': pwd.getpwuid(os.geteuid()).pw_name,
                                  'path': str(self.database), 'ssh': {'host': 'fixture', 'user': pwd.getpwuid(os.getuid()).pw_name,
                                      'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}},
                       'execution_secrets': {'database_write': {'vault': 'orbit-control-inputs',
                                                               'key': 'database-write-intent-17'}}}
        package = ROOT / '.swamp/pulled-extensions/@swamp/ssh'
        if not package.is_dir():
            raise RuntimeError('Pull the pinned official @swamp/ssh package before this test.')
        destination = self.repo / '.swamp/pulled-extensions/@swamp/ssh'
        destination.parent.mkdir(parents=True)
        shutil.copytree(package, destination)
        inventory = json.loads((ROOT / 'extensions/models/upstream_extensions.json').read_text())
        index = self.repo / 'extensions/models/upstream_extensions.json'
        index.parent.mkdir(parents=True)
        index.write_text(json.dumps({'@swamp/ssh': inventory['@swamp/ssh']}))
        self.calls = self.repo / 'ssh-calls.jsonl'
        self.lose_reply = self.repo / 'lose-ssh-reply'
        identity = self.repo / 'existing-key'
        identity.write_text('fixture identity: not used to authenticate')
        identity.chmod(0o600)
        fake = self.repo / 'fixture-ssh'
        fake.write_text('#!' + sys.executable + '\n' + '''import json,shlex,subprocess,sys
from pathlib import Path
if '-M' in sys.argv or '-O' in sys.argv: sys.exit(0)
with Path(CALLS).open('a') as log: log.write(json.dumps(sys.argv[1:])+'\\n')
result=subprocess.run(shlex.split(sys.argv[-1]),input=sys.stdin.buffer.read(),capture_output=True,check=False)
if Path(LOSS).exists(): sys.exit(255)
sys.stdout.buffer.write(result.stdout)
sys.stderr.buffer.write(result.stderr)
sys.exit(result.returncode)
'''.replace('CALLS', repr(str(self.calls))).replace('LOSS', repr(str(self.lose_reply))))
        fake.chmod(0o700)
        self.workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-database-write.yaml').read_text())
        # Local effects only. Checked-in steps retain exact placement.
        for job in self.workflow['jobs']:
            self.assertEqual(job.pop('target'), 'orbit-node-209')
            self.assertNotIn('labels', job)
            for step in job['steps']:
                self.assertEqual(step.pop('target'), 'orbit-node-209')
                self.assertNotIn('labels', step)
                if step['task'].get('behaviorType') == '@swamp/ssh':
                    step['task']['globalArgs']['sshBinary'] = str(fake)
                    step['task']['globalArgs']['transport']['identityFile'] = str(identity)
        self.save_workflow()
        self.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        self.put_material()

    def save_workflow(self):
        (self.repo / 'workflows/workflow-operation-database-write.yaml').write_text(
            yaml.safe_dump(self.workflow, sort_keys=False))

    def put_material(self):
        self.cli('vault', 'put', 'orbit-control-inputs', 'database-write-intent-17',
                 '--force', input=json.dumps(self.material))

    def value(self):
        with sqlite3.connect(self.database) as database:
            return database.execute('SELECT value FROM proof').fetchone()[0]

    def run_write(self, success=True):
        self.cli('workflow', 'validate', 'operation-database-write')
        return reply(self.cli('workflow', 'run', 'operation-database-write',
                              '--input', json.dumps(self.public), success=success).stdout)

    def history(self, run):
        return reply(self.cli('workflow', 'history', 'get', run['id']).stdout)

    def test_actual_sqlite_command_and_safe_result_without_gateway_calls(self):
        run = self.run_write()
        self.assertEqual(run['status'], 'succeeded')
        history = self.history(run)
        self.assertEqual(history['inputs'], self.public)
        steps = {step['name']: step for step in history['jobs'][0]['steps']}
        self.assertEqual(steps['sql']['status'], 'skipped')
        self.assertEqual(steps['sqlite']['status'], 'succeeded')
        self.assertEqual(json.loads(steps['result']['outputs']['stdout']),
                         {'backend': 'active', 'row_count': 1, 'truncated': False})
        self.assertEqual(self.value(), 1)
        self.assert_private_absent(json.dumps(history), self.calls.read_text())

    def test_result_resume_reuses_completed_write_and_does_not_repeat_sql(self):
        fail = self.repo / 'fail-result'
        fail.touch()
        self.workflow['jobs'][0]['steps'].append({
            'name': 'fixture_delivery',
            'dependsOn': [{'step': 'accept_result', 'condition': {'type': 'succeeded'}}],
            'task': {'type': 'behavior', 'behaviorType': 'command/shell',
                     'behaviorName': 'fixture-delivery-${{ run.id }}', 'methodName': 'execute',
                     'inputs': {'run': f'test ! -e "{fail}"'}}})
        self.save_workflow()
        run = self.run_write(success=False)
        self.assertEqual(self.value(), 1)
        fail.unlink()
        resumed = reply(self.cli('workflow', 'resume', 'operation-database-write',
                                 '--run', run['id']).stdout)
        self.assertEqual(resumed['status'], 'succeeded')
        self.assertEqual(self.value(), 1)

    def test_saved_action_refusal_cannot_be_retried_by_changing_vault_material(self):
        self.material['sql'] = 'UPDATE proof SET value=99; DROP TABLE proof;'
        self.secrets = (self.material['sql'],)
        self.put_material()
        run = self.run_write(success=False)
        history = self.history(run)
        steps = {step['name']: step for step in history['jobs'][0]['steps']}
        self.assertEqual(steps['sqlite']['status'], 'succeeded')
        self.assertEqual(steps['sqlite']['outputs']['exitCode'], 1)
        self.assertEqual(json.loads(steps['result']['outputs']['stdout']),
                         {'error_code': 'database.query_failed'})
        self.material['sql'] = 'UPDATE proof SET value=99'
        self.put_material()
        self.cli('workflow', 'resume', 'operation-database-write', '--run', run['id'], success=False)
        self.assertEqual(self.value(), 0)

    def test_absent_vault_key_fails_without_writing_or_reporting_success(self):
        self.public['execution_secrets']['database_write']['key'] = 'database-write-intent-18'
        run = self.run_write(success=False)
        self.assertEqual(run['status'], 'failed')
        self.assertEqual(self.value(), 0)

    def test_pdo_refusal_skips_sqlite_without_evaluating_its_optional_fields(self):
        self.public['intent']['driver'] = 'pgsql'
        del self.public['intent']['owner']
        del self.public['intent']['path']
        self.material.update(dsn='pgsql:host=127.0.0.1;port=1;dbname=unreachable',
                             username='private-fixture-user', password='private-fixture-password')
        self.secrets += ('private-fixture-user', 'private-fixture-password')
        self.put_material()
        run = self.run_write(success=False)
        steps = {step['name']: step for step in self.history(run)['jobs'][0]['steps']}
        self.assertEqual(steps['sql']['status'], 'succeeded')
        self.assertEqual(steps['sqlite']['status'], 'skipped')
        self.assertEqual(json.loads(steps['result']['outputs']['stdout']),
                         {'error_code': 'database.query_failed'})
        self.assertEqual(self.value(), 0)
        self.assert_private_absent(self.calls.read_text())

    def test_lost_ssh_reply_after_effect_cannot_repeat_sql_on_resume(self):
        self.lose_reply.touch()
        run = self.run_write(success=False)
        self.assertEqual(self.value(), 1)
        steps = {step['name']: step for step in self.history(run)['jobs'][0]['steps']}
        self.assertEqual(steps['sqlite']['status'], 'failed')
        self.assertEqual(json.loads(steps['result']['outputs']['stdout']),
                         {'error_code': 'database.write_outcome_unknown'})
        self.lose_reply.unlink()
        self.cli('workflow', 'resume', 'operation-database-write', '--run', run['id'], success=False)
        self.assertEqual(self.value(), 1)
        self.assertEqual(len(self.calls.read_text().splitlines()), 1)


if __name__ == '__main__':
    unittest.main()
