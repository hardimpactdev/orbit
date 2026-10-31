"""Actual command/shell writes, vault privacy and native result-only resume."""

import json
import os
from pathlib import Path
import pwd
import sqlite3
import sys
import tempfile
import unittest
import uuid

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
                                  'path': str(self.database)},
                       'execution_secrets': {'database_write': {'vault': 'orbit-control-inputs',
                                                               'key': 'database-write-intent-17'}}}
        self.workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-database-write.yaml').read_text())
        # Local effects only. Checked-in steps retain exact placement.
        for job in self.workflow['jobs']:
            job.pop('target', None)
            job.pop('labels', None)
            for step in job['steps']:
                self.assertIn('inputs.node_id', step.pop('target'))
                self.assertIn('inputs.node_id', step.pop('labels')['node'])
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
        self.assert_private_absent(json.dumps(history))

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


if __name__ == '__main__':
    unittest.main()
