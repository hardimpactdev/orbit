"""Effect tests for the exact official SSH action argv and private stdin."""

import json
import os
import pwd
import sqlite3
import subprocess
import tempfile
import unittest
from pathlib import Path

import yaml


class InlineWriteActionsTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='db-write-shape-')
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)
        self.path = self.directory / 'proof.sqlite'
        with sqlite3.connect(self.path) as database:
            database.execute('CREATE TABLE proof (value TEXT NOT NULL)')
            database.execute("INSERT INTO proof VALUES ('before')")
        self.owner = pwd.getpwuid(os.geteuid()).pw_name
        self.bodies = yaml.safe_load(
            (Path(__file__).resolve().parents[1] / 'workflows/workflow-operation-database-write.yaml').read_text())
        self.bodies = {step['name']: step['task']['inputs'] for step in self.bodies['jobs'][0]['steps'] if step['name'] in ('sql', 'sqlite')}

    def run_action(self, sql, action='sqlite', **overrides):
        variables = {
            'PATH': '/usr/bin:/bin',
            'ORBIT_DATABASE_DRIVER': 'mysql',
            'ORBIT_DATABASE_PATH': str(self.path),
            'ORBIT_DATABASE_OWNER': self.owner,
            'ORBIT_DATABASE_SQL': sql,
            'ORBIT_DATABASE_DSN': 'mysql:host=127.0.0.1;port=1;dbname=unused',
            'ORBIT_DATABASE_USERNAME': 'fixture',
            'ORBIT_DATABASE_PASSWORD': 'private-fixture-password',
            **overrides,
        }
        material = {key: variables.pop('ORBIT_DATABASE_'+source) for key, source in [('sql', 'SQL'), ('dsn', 'DSN'), ('username', 'USERNAME'), ('password', 'PASSWORD')]}
        if action == 'sqlite':
            material.update(dsn='', username=None, password='')
        result = subprocess.run(
            [variables.get({'${{ inputs.intent.driver }}': 'ORBIT_DATABASE_DRIVER', '${{ has(inputs.intent.owner) ? inputs.intent.owner : "" }}': 'ORBIT_DATABASE_OWNER', '${{ has(inputs.intent.path) ? inputs.intent.path : "" }}': 'ORBIT_DATABASE_PATH'}.get(value, ''), value) for value in self.bodies[action]['argv']],
            input=json.dumps(material), env=variables,
            text=True, capture_output=True, timeout=20, check=False)
        self.assertEqual(result.stderr, '')
        self.assertNotIn(sql, result.stdout)
        self.assertNotIn('private-fixture-password', result.stdout)
        return result.returncode, json.loads(result.stdout)

    def values(self):
        with sqlite3.connect(self.path) as database:
            return database.execute('SELECT value FROM proof').fetchall()

    def test_sqlite_write_commits_exact_statement_and_returns_count(self):
        status, output = self.run_action("UPDATE proof SET value='after'")
        self.assertEqual(status, 0)
        self.assertEqual(output, {
            'backend': 'active', 'row_count': 1, 'truncated': False})
        self.assertEqual(self.values(), [('after',)])

    def test_sqlite_returning_reports_final_count_without_exposing_rows(self):
        with sqlite3.connect(self.path) as database:
            database.execute('DELETE FROM proof')
            database.executemany('INSERT INTO proof VALUES (?)', [('before',)] * 600)
        status, output = self.run_action("UPDATE proof SET value='private-returned-value' RETURNING value")
        self.assertEqual(status, 0)
        self.assertEqual(output, {'backend': 'active', 'row_count': 600, 'truncated': True})
        self.assertNotIn('private-returned-value', json.dumps(output))

    def test_sqlite_multiple_statements_refused_without_first_effect(self):
        status, output = self.run_action(
            "UPDATE proof SET value='after'; DROP TABLE proof;")
        self.assertEqual(status, 1)
        self.assertEqual(output, {'error_code': 'database.query_failed'})
        self.assertEqual(self.values(), [('before',)])

    def test_sqlite_attach_refused_without_creating_another_database(self):
        other = self.directory / 'foreign.sqlite'
        status, output = self.run_action(f"ATTACH DATABASE '{other}' AS other")
        self.assertEqual(status, 1)
        self.assertEqual(output, {'error_code': 'database.query_failed'})
        self.assertFalse(other.exists())

    def test_sqlite_vacuum_into_refused_without_export_file(self):
        other = self.directory / 'leak.sqlite'
        status, output = self.run_action(f"VACUUM INTO '{other}'")
        self.assertEqual(status, 1)
        self.assertEqual(output, {'error_code': 'database.query_failed'})
        self.assertFalse(other.exists())

    def test_sqlite_symlink_database_refused(self):
        alias = self.directory / 'alias.sqlite'
        alias.symlink_to(self.path)
        status, output = self.run_action(
            "UPDATE proof SET value='after'", ORBIT_DATABASE_PATH=str(alias))
        self.assertEqual(status, 1)
        self.assertEqual(output, {'error_code': 'database.sqlite_unreadable'})
        self.assertEqual(self.values(), [('before',)])

    def test_sqlite_symlink_parent_refused(self):
        alias = self.directory / 'alias'
        alias.symlink_to(self.directory, target_is_directory=True)
        status, output = self.run_action(
            "UPDATE proof SET value='after'",
            ORBIT_DATABASE_PATH=str(alias / self.path.name))
        self.assertEqual(status, 1)
        self.assertEqual(output, {'error_code': 'database.sqlite_unreadable'})
        self.assertEqual(self.values(), [('before',)])

    def test_sqlite_missing_database_is_not_created(self):
        missing = self.directory / 'missing.sqlite'
        status, output = self.run_action(
            'CREATE TABLE unexpected (value TEXT)',
            ORBIT_DATABASE_PATH=str(missing))
        self.assertEqual(status, 1)
        self.assertEqual(output, {'error_code': 'database.sqlite_unreadable'})
        self.assertFalse(missing.exists())

    def test_sqlite_wrong_execution_account_refused_before_effect(self):
        status, output = self.run_action(
            "UPDATE proof SET value='after'", ORBIT_DATABASE_OWNER='root')
        self.assertEqual(status, 1)
        self.assertEqual(output, {'error_code': 'database.write_outcome_unknown'})
        self.assertEqual(self.values(), [('before',)])

    def test_sqlite_utf8_sql_size_cap_refuses_before_effect(self):
        statement = "UPDATE proof SET value='" + '\u00e9' * 8200 + "'"
        status, output = self.run_action(statement)
        self.assertEqual(status, 1)
        self.assertEqual(output, {'error_code': 'database.write_outcome_unknown'})
        self.assertEqual(self.values(), [('before',)])

    def test_pdo_wrong_driver_dsn_refused_before_connection(self):
        status, output = self.run_action(
            'DROP TABLE proof', action='sql', ORBIT_DATABASE_DSN='sqlite:'+str(self.path))
        self.assertEqual(status, 1)
        self.assertEqual(output, {'error_code': 'database.write_outcome_unknown'})
        self.assertEqual(self.values(), [('before',)])

    def test_pdo_connection_error_does_not_return_password_or_sql(self):
        status, output = self.run_action('SELECT 1', action='sql')
        self.assertEqual(status, 1)
        self.assertEqual(output, {'error_code': 'database.query_failed'})

    def test_fixed_action_programs_have_valid_syntax(self):
        program = self.bodies['sqlite']['argv'][8]
        compile(program, '<sqlite-write-action>', 'exec')
        checked = subprocess.run(['ruff', 'check', '--stdin-filename', 'database_write.py', '-'],
                                 input=program, capture_output=True, text=True, check=False)
        self.assertEqual(checked.returncode, 0, checked.stdout+checked.stderr)
        result = subprocess.run(['/usr/bin/php', '-l'], input='<?php\n'+self.bodies['sql']['argv'][6],
                                capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0, result.stdout+result.stderr)


if __name__ == '__main__':
    unittest.main()
