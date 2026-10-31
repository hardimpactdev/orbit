"""Native DAG privacy proof with fake private HTTP and fake agent effects."""
import json
from pathlib import Path
import unittest
import sys

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class UserCreateNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/database')
        module = self.repo / 'extensions/behaviors/operations/database/user_create.ts'
        module.write_text('import { fixtureHttp, fixtureInvoke } from "./user_create_native_fixture.ts";\n' + module.read_text().replace('export const behavior = userCreate();', 'export const behavior = userCreate(() => fixtureHttp, fixtureInvoke);'))
        workflow = ROOT / 'workflows/workflow-operation-database-user-create.yaml'
        (self.repo / 'workflows' / workflow.name).write_text('\n'.join(
            line for line in workflow.read_text().splitlines()
            if not line.startswith(('target:', 'labels:'))) + '\n')
        self.password = 'private-root-native-sentinel'
        self.sql = "CREATE USER proof IDENTIFIED BY 'private-native-login-sentinel';"
        self.node_id = 209
        self.fixture = {'operation': {'id': 17, 'kind': 'database_user.create', 'node_id': 209,
            'status': 'queued', 'result': None, 'cancel_requested': False,
            'input': {'driver': 'mysql', 'container': 'orbit-process-42-proof', 'agent_sha256': 'a' * 64,
                      'username': 'proof', 'replication': False, 'known_user': False}},
            'material': {'password': self.password, 'sql': self.sql}, 'calls': [], 'effects': 0}

    def exercise(self, success):
        (self.repo / 'private-user-fixture.json').write_text(json.dumps(self.fixture))
        self.cli('workflow', 'validate', 'operation-database-user-create')
        run = self.cli('workflow', 'run', 'operation-database-user-create',
                       '--input', 'operation_id=17', '--input', f'node_id={self.node_id}',
                       '--input', 'kind=database_user.create', success=success)
        for private in [self.password, self.sql, 'private-native-login-sentinel']:
            self.assertNotIn(private, run.stdout + run.stderr)
            for path in (self.repo / '.swamp').rglob('*'):
                if path.is_file():
                    self.assertNotIn(private.encode(), path.read_bytes(), str(path))
        return json.loads((self.repo / 'private-user-fixture.json').read_text())

    def test_private_sql_and_native_output_never_enter_native_history(self):
        final = self.exercise(True)
        self.assertEqual(final['effects'], 1)
        self.assertEqual(final['operation']['result'], {'backend': 'active'})
        self.assertEqual(sum('/private-material?' in path for path in final['calls']), 1)
        self.assertTrue(all('/direct-operations/' in path for path in final['calls']))

    def test_parser_failure_drops_private_material_before_native_failure(self):
        self.fixture['material']['password'] = {self.password: self.sql}
        final = self.exercise(False)
        self.assertEqual(final['effects'], 0)

    def test_agent_exception_text_is_redacted_from_native_failure(self):
        self.fixture['failEffect'] = True
        final = self.exercise(False)
        self.assertEqual(final['effects'], 1)

    def test_default_native_resume_projects_the_saved_step_output_without_repeating_sql(self):
        self.fixture['loseProjection'] = True
        (self.repo / 'private-user-fixture.json').write_text(json.dumps(self.fixture))
        self.cli('workflow', 'validate', 'operation-database-user-create')
        result = self.cli('workflow', 'run', 'operation-database-user-create',
            '--input', 'operation_id=17', '--input', 'node_id=209',
            '--input', 'kind=database_user.create', success=False)
        run = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']
        first = json.loads((self.repo / 'private-user-fixture.json').read_text())
        self.assertEqual(first['effects'], 1)
        self.assertEqual(first['operation']['status'], 'running')
        self.cli('workflow', 'resume', 'operation-database-user-create', '--run', run)
        final = json.loads((self.repo / 'private-user-fixture.json').read_text())
        self.assertEqual(final['effects'], 1)
        self.assertEqual(final['operation']['status'], 'succeeded')

    def test_later_same_node_run_preserves_the_first_runs_output_for_resume(self):
        self.fixture['loseProjection'] = True
        file = self.repo / 'private-user-fixture.json'
        file.write_text(json.dumps(self.fixture))
        first = self.cli('workflow', 'run', 'operation-database-user-create',
            '--input', 'operation_id=17', '--input', 'node_id=209',
            '--input', 'kind=database_user.create', success=False)
        run = json.JSONDecoder().raw_decode(first.stdout.lstrip())[0]['id']
        first_state = json.loads(file.read_text())
        self.assertEqual(first_state['effects'], 1)
        self.assertEqual(first_state['operation']['status'], 'running')

        second_state = json.loads(json.dumps(self.fixture))
        second_state['operation']['id'] = 18
        second_state['loseProjection'] = False
        file.write_text(json.dumps(second_state))
        self.cli('workflow', 'run', 'operation-database-user-create',
            '--input', 'operation_id=18', '--input', 'node_id=209',
            '--input', 'kind=database_user.create')
        self.assertEqual(json.loads(file.read_text())['effects'], 1)

        file.write_text(json.dumps(first_state))
        self.cli('workflow', 'resume', 'operation-database-user-create', '--run', run)
        final = json.loads(file.read_text())
        self.assertEqual(final['effects'], 1)
        self.assertEqual(final['operation']['status'], 'succeeded')


if __name__ == '__main__':
    unittest.main()
