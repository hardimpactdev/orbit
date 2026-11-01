"""Proof regressions use real SQLite files; they are not topology receipts."""
import copy
import importlib.util
import json
import os
import shlex
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('create_parity', ROOT / 'parity.py')
parity = importlib.util.module_from_spec(spec)
spec.loader.exec_module(parity)
fixture = json.loads((ROOT / 'database-migration.json').read_text())
command = next(arg.removeprefix('--command=') for step in fixture['prepare'] for arg in step['argv'] if arg.startswith('--command='))


class DatabaseBeforeSetup(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.target = self.root / 'feature'
        self.source = self.root / 'default'
        for path in (self.target, self.source):
            (path / 'database').mkdir(parents=True)
        self.path = self.target / 'database/database.sqlite'

    def migrate(self, path=None):
        return subprocess.run(shlex.split(command), cwd=self.target, env=dict(os.environ, DB_CONNECTION='sqlite',
                              DB_DATABASE=str(path or self.path), PROOF_PUBLIC_MARKER='database-monorepo'), capture_output=True, text=True, check=False)

    def seed(self, path):
        with sqlite3.connect(path) as connection:
            connection.execute('CREATE TABLE orbit_create_seed (marker TEXT NOT NULL)')
            connection.execute('INSERT INTO orbit_create_seed VALUES (?)', ('owned-source',))

    def test_variants_use_the_runner_fresh_local_origin_and_main(self):
        fixtures = [json.loads(path.read_text()) for path in ROOT.glob('*.json')]
        for value in fixtures:
            project = next(step['argv'] for step in value['prepare'] if step['argv'][1] == 'project:create')
            self.assertEqual(project[4], '${proof.repo.url}')
            self.assertIn('--default-branch=main', project)
            self.assertEqual(value['envelope']['repository'], '${proof.repo.url}')
            self.assertEqual(value['envelope']['default_branch'], 'main')
            self.assertNotIn('github.com', json.dumps(value))

    def test_project_command_is_the_reviewed_migration(self):
        self.assertEqual(shlex.split(command), ['python3', '-c', (ROOT / 'migration.py').read_text()])

    def test_before_request_helper_resolves_from_the_staged_repository_root(self):
        argv = fixture['before_request'][0]['argv']
        self.assertEqual(argv[:2], ['python3', '-I'])
        self.assertEqual((ROOT.parents[2] / argv[2]).resolve(), (ROOT / 'seed_sqlite.py').resolve())
        self.assertTrue((ROOT.parents[2] / argv[2]).is_file())

    def test_setup_before_database_fails_without_creating_a_database(self):
        answer = self.migrate()
        self.assertNotEqual(answer.returncode, 0)
        self.assertIn('database-not-created-before-setup', answer.stderr)
        self.assertFalse(self.path.exists())

    def test_setup_without_the_nested_application_environment_cannot_migrate(self):
        self.seed(self.path)
        before = self.path.read_bytes()
        answer = subprocess.run(shlex.split(command), cwd=self.target,
                                env=dict(os.environ, DB_CONNECTION='sqlite', DB_DATABASE=str(self.path), PROOF_PUBLIC_MARKER='wrong'),
                                capture_output=True, text=True, check=False)
        self.assertNotEqual(answer.returncode, 0)
        self.assertIn('nested-application-environment-not-loaded', answer.stderr)
        self.assertEqual(self.path.read_bytes(), before)

    def test_an_empty_database_does_not_impersonate_a_finished_copy(self):
        with sqlite3.connect(self.path):
            pass
        self.assertNotEqual(self.migrate().returncode, 0)
        with sqlite3.connect(self.path) as connection:
            self.assertEqual(connection.execute("SELECT name FROM sqlite_master WHERE name='orbit_create_migrations'").fetchall(), [])

    def test_setup_never_migrates_the_default_instead_of_its_new_database(self):
        source = self.source / 'database/database.sqlite'
        self.seed(source)
        before = source.read_bytes()
        answer = self.migrate(source)
        self.assertNotEqual(answer.returncode, 0)
        self.assertIn('database-outside-owned-checkout', answer.stderr)
        self.assertEqual(source.read_bytes(), before)

    def test_database_copy_then_setup_applies_a_real_migration_and_keeps_the_default(self):
        source = self.source / 'database/database.sqlite'
        self.seed(source)
        before = source.read_bytes()
        shutil.copyfile(source, self.path)
        answer = self.migrate()
        self.assertEqual(answer.returncode, 0, answer.stderr)
        self.assertEqual(answer.stdout, 'migration-ok\n')
        self.assertEqual(source.read_bytes(), before)
        checked = subprocess.run([sys.executable, '-c', parity.DATABASE_CHECK, str(self.target), str(self.source)],
                                 capture_output=True, text=True, check=True)
        self.assertEqual(json.loads(checked.stdout), {'copied_database_migrated': True, 'source_database_preserved': True})

    def test_readback_refuses_a_missing_target_without_creating_it(self):
        checked = subprocess.run([sys.executable, '-c', parity.DATABASE_CHECK, str(self.target), str(self.source)],
                                 capture_output=True, text=True, check=False)
        self.assertNotEqual(checked.returncode, 0)
        self.assertFalse(self.path.exists())

    def test_readback_catches_an_incorrectly_migrated_default(self):
        source = self.source / 'database/database.sqlite'
        self.seed(source)
        shutil.copyfile(source, self.path)
        self.assertEqual(self.migrate().returncode, 0)
        with sqlite3.connect(source) as db:
            db.execute('CREATE TABLE orbit_create_migrations (name TEXT PRIMARY KEY)')
        checked = subprocess.run([sys.executable, '-c', parity.DATABASE_CHECK, str(self.target), str(self.source)],
                                 capture_output=True, text=True, check=False)
        self.assertNotEqual(checked.returncode, 0)
        self.assertIn('proof-source-database-was-migrated', checked.stderr)


class SeedPreparation(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        spec = importlib.util.spec_from_file_location('create_seed', ROOT / 'seed_sqlite.py')
        self.seed = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.seed)

    def run_seed(self, path):
        return subprocess.run([sys.executable, '-c', self.seed.SEED, str(path)],
                              capture_output=True, text=True, check=False)

    def test_preparation_is_confined_to_the_returned_default_checkout(self):
        wrong = self.root / 'feature'
        wrong.mkdir()
        answer = self.run_seed(wrong)
        self.assertNotEqual(answer.returncode, 0)
        self.assertFalse((wrong / 'database').exists())
        owned = self.root / 'default'
        owned.mkdir()
        answer = self.run_seed(owned)
        self.assertEqual(answer.returncode, 0, answer.stderr)
        self.assertEqual(json.loads(answer.stdout), {'ready': True})

    def test_preparation_never_overwrites_an_existing_database(self):
        owned = self.root / 'default'
        owned.mkdir()
        self.assertEqual(self.run_seed(owned).returncode, 0)
        path = owned / 'database/database.sqlite'
        before = path.read_bytes()
        self.assertNotEqual(self.run_seed(owned).returncode, 0)
        self.assertEqual(path.read_bytes(), before)


class SetupExecution(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        shutil.copytree(ROOT / 'repository', self.root, dirs_exist_ok=True)
        self.marker = self.root / '.orbit-proof-setup'
        self.commit = 'a' * 40

    def check(self):
        return subprocess.run([sys.executable, '-c', parity.SETUP_CHECK, str(self.root), self.commit],
                              capture_output=True, text=True, check=False)

    def test_a_reported_success_without_setup_execution_fails(self):
        checked = self.check()
        self.assertNotEqual(checked.returncode, 0)
        self.assertIn('proof-setup-marker-missing', checked.stderr)

    def test_wrong_commit_and_symlink_cannot_supply_setup_evidence(self):
        self.marker.write_text('b' * 40 + '\n')
        self.assertNotEqual(self.check().returncode, 0)
        self.marker.unlink()
        elsewhere = self.root / 'other'
        elsewhere.write_text(self.commit + '\n')
        self.marker.symlink_to(elsewhere)
        self.assertNotEqual(self.check().returncode, 0)

    def test_actual_project_command_records_the_checked_out_commit(self):
        fixture = json.loads((ROOT / 'source-setup.json').read_text())
        setup = next(arg.removeprefix('--command=') for step in fixture['prepare'] for arg in step['argv'] if arg.startswith('--command='))
        subprocess.run(['git', 'init', '-q', str(self.root)], check=True, capture_output=True)
        subprocess.run(['git', 'add', '.'], cwd=self.root, check=True, capture_output=True)
        subprocess.run(['git', '-c', 'user.name=Proof', '-c', 'user.email=proof@example.test',
                        '-c', 'core.hooksPath=/dev/null', 'commit', '-qm', 'public fixture'],
                       cwd=self.root, check=True, capture_output=True)
        self.commit = subprocess.check_output(['git', 'rev-parse', '--verify', 'HEAD'], cwd=self.root, text=True).strip()
        executed = subprocess.run(['sh', '-c', setup], cwd=self.root, env=dict(os.environ, APP_ENV='development', PROOF_PUBLIC_MARKER='source-setup'),
                                  capture_output=True, text=True, check=False)
        self.assertEqual(executed.returncode, 0, executed.stderr)
        checked = self.check()
        self.assertEqual(checked.returncode, 0, checked.stderr)
        self.assertEqual(json.loads(checked.stdout), {'setup_executed': True, 'commit': self.commit})

    def test_failed_environment_check_does_not_record_setup_completion(self):
        fixture = json.loads((ROOT / 'source-setup.json').read_text())
        setup = next(arg.removeprefix('--command=') for step in fixture['prepare'] for arg in step['argv'] if arg.startswith('--command='))
        executed = subprocess.run(['sh', '-c', setup], cwd=self.root, env=dict(os.environ, APP_ENV='production', PROOF_PUBLIC_MARKER='source-setup'),
                                  capture_output=True, text=True, check=False)
        self.assertNotEqual(executed.returncode, 0)
        self.assertFalse(self.marker.exists())


class TerminalParity(unittest.TestCase):
    def setUp(self):
        result = {'ready': True, 'instance_id': 17,
                  'source': {'branch': 'proof-fixture', 'commit': 'a' * 40, 'php_version': None, 'laravel': False},
                  'environment_keys': ['APP_NAME', 'APP_ENV', 'APP_DEBUG', 'PROOF_PUBLIC_MARKER'], 'vp_environment': None}
        self.selected = {'ready': True, 'stdout': json.dumps(result)}
        self.operation = {'kind': 'instance.create', 'status': 'succeeded', 'target_id': 17,
                          'input': {'root': 'database-monorepo', 'branch_override': 'proof-fixture',
                                    'direct_provisioning': {'setup': {'steps': [{'name': 'migrate-owned-database'}]}}}}
        self.instance = {'id': 17, 'status': 'active', 'starting_commit': 'a' * 40, 'root': 'database-monorepo'}

    def test_unwrapped_outputs_and_the_exact_normal_operation_are_required(self):
        self.assertTrue(parity.validate(self.selected, self.operation, self.instance, 'database-migration')['ready'])
        with self.assertRaises(KeyError):
            parity.validate({'create': {'finish-result': self.selected}}, self.operation, self.instance, 'database-migration')

    def test_native_ready_does_not_replace_gateway_terminal_projection(self):
        for status in ('running', 'failed', 'cancelled'):
            operation = dict(self.operation, status=status)
            with self.assertRaisesRegex(RuntimeError, 'proof-create-operation-not-succeeded'):
                parity.validate(self.selected, operation, self.instance, 'database-migration')
        with self.assertRaisesRegex(RuntimeError, 'proof-create-instance-not-active'):
            parity.validate(self.selected, self.operation, dict(self.instance, status='reserved'), 'database-migration')

    def test_success_without_the_admitted_migration_is_not_a_parity_pass(self):
        operation = copy.deepcopy(self.operation)
        operation['input']['direct_provisioning']['setup']['steps'] = []
        with self.assertRaisesRegex(RuntimeError, 'proof-create-setup-list-mismatch'):
            parity.validate(self.selected, operation, self.instance, 'database-migration')

    def test_a_different_instance_or_commit_cannot_supply_the_proof(self):
        with self.assertRaisesRegex(RuntimeError, 'proof-create-target-mismatch'):
            parity.validate(self.selected, self.operation, dict(self.instance, id=18), 'database-migration')
        with self.assertRaisesRegex(RuntimeError, 'proof-create-source-projection-mismatch'):
            parity.validate(self.selected, self.operation, dict(self.instance, starting_commit='b' * 40), 'database-migration')

    def test_wrong_branch_or_application_root_cannot_supply_the_proof(self):
        with self.assertRaisesRegex(RuntimeError, 'proof-create-application-root-mismatch'):
            parity.validate(self.selected, self.operation, dict(self.instance, root='.'), 'database-migration')
        operation = copy.deepcopy(self.operation)
        operation['input']['branch_override'] = 'proof-other'
        with self.assertRaisesRegex(RuntimeError, 'proof-create-branch-mismatch'):
            parity.validate(self.selected, operation, self.instance, 'database-migration')


if __name__ == '__main__':
    unittest.main()
