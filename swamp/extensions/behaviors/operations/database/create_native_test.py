"""Native database-create composition, guards, fan-out, private inputs and resume."""

import json
import shutil
import sys
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


def reply(raw):
    return json.JSONDecoder().raw_decode(raw.lstrip())[0]


class CreateNative(NativeTestCase):
    native_timeout = 180

    def setUp(self):
        super().setUp()
        shutil.copytree(ROOT / 'extensions/behaviors/operations/database-server-commands', self.repo / 'extensions/behaviors/operations/database-server-commands', ignore=shutil.ignore_patterns('__pycache__', '*.pyc'))
        target = self.repo / 'extensions/behaviors/operations/database'
        target.mkdir()
        shutil.copy(ROOT / 'extensions/behaviors/operations/database/create_input.ts', target)
        source = ROOT / '.swamp/pulled-extensions/@swamp/ssh'
        dest = self.repo / '.swamp/pulled-extensions/@swamp/ssh'
        dest.parent.mkdir(parents=True)
        shutil.copytree(source, dest)
        index = self.repo / 'extensions/models/upstream_extensions.json'
        index.parent.mkdir(parents=True)
        index.write_text(json.dumps({'@swamp/ssh': json.loads((ROOT / 'extensions/models/upstream_extensions.json').read_text())['@swamp/ssh']}))
        self.state = self.repo / 'state.json'
        self.calls_file = self.repo / 'calls.jsonl'
        self.calls_file.touch()
        self.state.write_text(json.dumps({'fail': '', 'code': 'database.server_admin_failed'}))
        fake = self.repo / 'fixture-ssh'
        fake.write_text('#!' + sys.executable + '\n' + r'''import json,os,shlex,sys
from pathlib import Path
if '-M' in sys.argv or '-O' in sys.argv:sys.exit(0)
args=shlex.split(sys.argv[-1]);scripts=[Path(a).stem for a in args if a.endswith('.py')]
if not scripts:sys.exit(0)
m=json.load(sys.stdin);assert m['password'] not in repr(args) and m['payload']['password'] not in repr(args)
v=json.loads(Path(STATE).read_text());action=scripts[0]
fd=os.open(CALLS,os.O_WRONLY|os.O_APPEND);os.write(fd,(json.dumps({'action':action,'item':json.loads(args[-1])})+'\n').encode());os.close(fd)
if v['fail']==action:print(json.dumps({'error_code':v['code']}));sys.exit(1)
print('{"ready":true}')
'''.replace('STATE', repr(str(self.state))).replace('CALLS', repr(str(self.calls_file))))
        fake.chmod(0o700)
        scp = self.repo / 'fixture-scp'
        scp.write_text('#!/bin/sh\nexit 0\n')
        scp.chmod(0o700)
        key = self.repo / 'fixture-key'
        key.write_text('fixture key')
        key.chmod(0o600)
        self.workflows = {}
        for name in ['workflow-operation-database-create.yaml', 'workflow-database-create-action.yaml']:
            value = yaml.safe_load((ROOT / 'workflows' / name).read_text())
            for job in value['jobs']:
                job.pop('target')
                self.assertNotIn('labels', job)
                for step in job['steps']:
                    self.assertEqual(step.pop('target'), 'orbit-node-209')
                    self.assertNotIn('labels', step)
                    if step['task'].get('behaviorType') == '@swamp/ssh':
                        args = step['task']['globalArgs']
                        args['sshBinary'] = str(fake)
                        args['scpBinary'] = str(scp)
                        args['transport']['identityFile'] = str(key)
            self.workflows[name] = value
        self.save()
        self.material = {'password': 'private-create-admin-sentinel', 'payload': {'password': 'a' * 40, 'salt': 'YWFhYWFhYWFhYWFhYWFhYQ=='}}
        self.secrets = (self.material['password'], self.material['payload']['password'])
        self.public = {'operation_id': 17, 'node_id': 9, 'kind': 'database.create', 'intent': {'repair': False, 'driver': 'mysql', 'username': 'proof_owner', 'databases': ['proof_main', 'proof_test'], 'test_prefix': 'proof_test', 'locale': None, 'host': '10.44.0.9', 'port': 3306, 'container': 'orbit-process-42-proof', 'index': None, 'patterns': [], 'allow': [], 'ssh': {'host': 'fixture', 'user': 'orbit', 'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}}, 'execution_secrets': {'database_create': {'vault': 'orbit-control-inputs', 'key': 'database-create-intent-17'}}}
        self.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        self.cli('vault', 'put', 'orbit-control-inputs', 'database-create-intent-17', input=json.dumps(self.material))

    def save(self):
        for name, value in self.workflows.items():
            (self.repo / 'workflows' / name).write_text(yaml.safe_dump(value, sort_keys=False))

    def run_create(self, success=True):
        self.cli('workflow', 'validate', 'operation-database-create')
        return reply(self.cli('workflow', 'run', 'operation-database-create', '--input', json.dumps(self.public), success=success).stdout)

    def history(self, run):
        return reply(self.cli('workflow', 'history', 'get', run['id']).stdout)

    def outcome(self, run):
        step = next(s for s in self.history(run)['jobs'][-1]['steps'] if s['name'] == 'result')
        return json.loads(step['outputs']['stdout'])

    def calls(self):
        return [json.loads(line) for line in self.calls_file.read_text().splitlines()]

    def fail(self, action, code='database.server_admin_failed'):
        state = json.loads(self.state.read_text())
        state.update(fail=action, code=code)
        self.state.write_text(json.dumps(state))

    def test_mysql_proves_before_changes_and_grants_before_create_with_native_fanout(self):
        run = self.run_create()
        self.assertEqual([x['action'] for x in self.calls()], ['sql_probe_user', 'sql_probe_databases', 'sql_create_user', 'sql_authenticate', 'mysql_grant', 'mysql_grant', 'mysql_grant', 'sql_create_database', 'sql_create_database'])
        self.assertEqual(self.outcome(run), {'backend': 'active'})
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
        self.fail('mysql_grant')
        run = self.run_create(False)
        self.assertNotIn('sql_create_database', [x['action'] for x in self.calls()])
        self.fail('')
        self.cli('workflow', 'resume', 'operation-database-create', '--run', run['id'])
        self.assertEqual(self.outcome(run), {'backend': 'active'})

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
