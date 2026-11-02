"""Actual native read DAG with official SSH, vault delivery and real SQLite script."""

import json
import os
import pwd
import shutil
import sqlite3
import sys
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


def reply(raw):
    return json.JSONDecoder().raw_decode(raw.lstrip())[0]


class ReadNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/database-read-commands')
        source = ROOT / '.swamp/pulled-extensions/@swamp/ssh'
        dest = self.repo / '.swamp/pulled-extensions/@swamp/ssh'
        dest.parent.mkdir(parents=True)
        shutil.copytree(source, dest)
        index = self.repo / 'extensions/models/upstream_extensions.json'
        index.parent.mkdir(parents=True)
        index.write_text(json.dumps({'@swamp/ssh': json.loads((ROOT / 'extensions/models/upstream_extensions.json').read_text())['@swamp/ssh']}))
        self.state = self.repo / 'state.json'
        self.state.write_text(json.dumps({'calls': 0, 'transport_fail': False, 'invalid_output': False}))
        self.database = self.repo / 'proof.sqlite'
        with sqlite3.connect(self.database) as db:
            db.execute('CREATE TABLE proof(id INTEGER PRIMARY KEY, value TEXT NOT NULL)')
            db.execute("INSERT INTO proof VALUES(47,'read-proof')")
        fake = self.repo / 'fixture-ssh'
        fake.write_text('#!' + sys.executable + '\n' + r'''import json,shlex,subprocess,sys
from pathlib import Path
if '-M' in sys.argv or '-O' in sys.argv:sys.exit(0)
args=shlex.split(sys.argv[-1])
if not any(a.endswith('read_sqlite.py') or a.endswith('read_pdo.php') for a in args):sys.exit(0)
path=Path(STATE);state=json.loads(path.read_text());state['calls']+=1;path.write_text(json.dumps(state))
material=json.load(sys.stdin);assert material['sql'] not in repr(args)
if material['password']:assert material['password'] not in repr(args)
if state['transport_fail']:sys.exit(255)
if state['invalid_output']:print('invalid output');sys.exit(0)
if any(a.endswith('read_pdo.php') for a in args):print('{"columns":["value"],"rows":[{"value":1}],"row_count":1,"truncated":false}');sys.exit(0)
args[args.index(next(a for a in args if a.endswith('read_sqlite.py')))]=SCRIPT
result=subprocess.run(args,input=json.dumps(material),text=True,capture_output=True)
sys.stdout.write(result.stdout);sys.stderr.write(result.stderr);sys.exit(result.returncode)
'''.replace('STATE', repr(str(self.state))).replace('SCRIPT', repr(str(ROOT / 'extensions/behaviors/operations/database-read-commands/scripts/read_sqlite.py'))))
        fake.chmod(0o700)
        scp = self.repo / 'fixture-scp'
        scp.write_text('#!/bin/sh\nexit 0\n')
        scp.chmod(0o700)
        key = self.repo / 'fixture-key'
        key.write_text('fixture key')
        key.chmod(0o600)
        self.workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-database-read.yaml').read_text())
        for job in self.workflow['jobs']:
            self.assertEqual(job.pop('labels'), {'role': 'swamp'})
            self.assertNotIn('labels', job)
            for step in job['steps']:
                self.assertEqual(step.pop('labels'), {'role': 'swamp'})
                self.assertNotIn('labels', step)
                if step['task'].get('behaviorType') == '@swamp/ssh':
                    args = step['task']['globalArgs']
                    args['sshBinary'] = str(fake)
                    args['scpBinary'] = str(scp)
                    args['transport']['identityFile'] = str(key)
        self.save()
        self.material = {'sql': 'SELECT id,value FROM proof ORDER BY id', 'dsn': '', 'username': None, 'password': ''}
        self.secrets = (self.material['sql'],)
        self.public = {'operation_id': 17, 'node_id': 9, 'kind': 'database.read', 'intent': {'connection_id': 47, 'driver': 'sqlite', 'owner': pwd.getpwuid(os.geteuid()).pw_name, 'path': str(self.database), 'mode': 'query', 'table': '', 'ssh': {'host': 'fixture', 'user': 'orbit', 'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}}, 'execution_secrets': {'database_read': {'vault': 'orbit-control-inputs', 'key': 'database-read-intent-17'}}}
        self.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        self.put()

    def put(self):
        self.cli('vault', 'put', 'orbit-control-inputs', 'database-read-intent-17', '--force', input=json.dumps(self.material))

    def save(self):
        (self.repo / 'workflows/workflow-operation-database-read.yaml').write_text(yaml.safe_dump(self.workflow, sort_keys=False))

    def run_read(self, success=True):
        self.cli('workflow', 'validate', 'operation-database-read')
        return reply(self.cli('workflow', 'run', 'operation-database-read', '--input', json.dumps(self.public), success=success).stdout)

    def history(self, run):
        return reply(self.cli('workflow', 'history', 'get', run['id']).stdout)

    def outcome(self, run):
        step = next(s for s in self.history(run)['jobs'][0]['steps'] if s['name'] == 'result')
        return json.loads(step['outputs']['stdout'])

    def state_update(self, **fields):
        state = json.loads(self.state.read_text())
        state.update(fields)
        self.state.write_text(json.dumps(state))

    def test_real_sqlite_query_and_private_history(self):
        run = self.run_read()
        self.assertEqual(self.outcome(run), {'columns': ['id', 'value'], 'rows': [{'id': 47, 'value': 'read-proof'}], 'row_count': 1, 'truncated': False})
        self.assertEqual(json.loads(self.state.read_text())['calls'], 1)
        self.assert_private_absent(json.dumps(self.history(run)))

    def test_sqlite_tables_schema_describe_and_missing_table(self):
        for mode, table in [('tables', ''), ('schema', ''), ('describe', 'proof'), ('describe', 'missing')]:
            self.public['intent'].update(mode=mode, table=table)
            run = self.run_read(table != 'missing')
            result = self.outcome(run)
            if mode == 'tables':
                self.assertEqual(result, {'tables': ['proof']})
            elif table == 'missing':
                self.assertEqual(result, {'error_code': 'database.table_missing'})
            elif mode == 'schema':
                self.assertEqual(result['tables'][0]['name'], 'proof')
            else:
                self.assertEqual(result['table'], 'proof')
                self.assertEqual(result['columns'][0]['name'], 'id')

    def test_pdo_branch_does_not_resolve_absent_sqlite_inputs(self):
        for driver in ['mysql', 'pgsql']:
            self.public['intent'].update(driver=driver)
            self.public['intent'].pop('owner', None)
            self.public['intent'].pop('path', None)
            self.material.update(dsn=driver + ':host=fixture', username='fixture', password='private-read-password')
            self.secrets = (self.material['sql'], self.material['password'])
            self.put()
            self.assertEqual(self.outcome(self.run_read())['row_count'], 1)

    def test_failed_transport_and_invalid_output_fail_bounded(self):
        for state in ['transport_fail', 'invalid_output']:
            self.state_update(**{**{'transport_fail': False, 'invalid_output': False}, state: True})
            run = self.run_read(False)
            self.assertEqual(self.outcome(run), {'error_code': 'database.query_failed'})

    def test_terminal_resume_after_later_run_does_not_repeat_query(self):
        marker = self.repo / 'delivery-failed'
        marker.touch()
        self.workflow['jobs'][0]['steps'].append({'name': 'fixture_delivery', 'dependsOn': [{'step': 'accept_result', 'condition': {'type': 'succeeded'}}], 'task': {'type': 'behavior', 'behaviorType': 'command/shell', 'behaviorName': 'fixture-delivery-${{ run.id }}', 'methodName': 'execute', 'inputs': {'run': f'test ! -e "{marker}"'}}})
        self.save()
        first = self.run_read(False)
        marker.unlink()
        self.public['operation_id'] = 18
        self.run_read()
        before = self.state.read_text()
        self.cli('workflow', 'resume', 'operation-database-read', '--run', first['id'])
        self.assertEqual(self.state.read_text(), before)
        self.assertEqual(self.outcome(first)['rows'][0]['id'], 47)


if __name__ == '__main__':
    unittest.main()
