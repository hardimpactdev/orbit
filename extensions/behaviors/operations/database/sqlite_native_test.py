"""One native DAG for SQLite file transfer, failure cleanup and projection resume."""
import json
import os
from pathlib import Path
import shutil
import sys
import unittest
import uuid
import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class SqliteNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        failure = self.repo / 'extensions/behaviors/operations/failure.ts'
        failure.parent.mkdir(parents=True, exist_ok=True)
        failure.write_text('import { fixtureHttp } from "./database/sqlite_native_fixture.ts";\n' + (ROOT / 'extensions/behaviors/operations/failure.ts').read_text().replace('export const behavior = nativeFailure();', 'export const behavior = nativeFailure(() => fixtureHttp);'))
        self.copy_behaviors('automatic-deploy', 'operations/_lib')
        destination = self.repo / 'extensions/behaviors/operations/database'
        destination.mkdir(parents=True)
        for name in ('sqlite_actions.ts', 'sqlite_native.ts', 'sqlite_native_fixture.ts', 'sqlite_local.ts', 'sqlite_program.ts'):
            shutil.copy(ROOT / 'extensions/behaviors/operations/database' / name, destination)
        module = destination / 'sqlite_native.ts'
        module.write_text('import { fixtureHttp, fixtureInvoke, fixtureActions, fixtureEngineFailure } from "./sqlite_native_fixture.ts";\n' + module.read_text().replace('export const behavior = sqliteNative();', '''export const behavior = sqliteNative(() => fixtureHttp, fixtureInvoke, fixtureActions);
const exportStep = behavior.methods.export.execute;
behavior.methods.export.execute = async (...args: Parameters<typeof exportStep>) => {
  await fixtureEngineFailure("export");
  return await exportStep(...args);
};
const installStep = behavior.methods.install.execute;
behavior.methods.install.execute = async (...args: Parameters<typeof installStep>) => {
  await fixtureEngineFailure("install");
  return await installStep(...args);
};
const cleanupStep = behavior.methods.cleanupSource.execute;
behavior.methods.cleanupSource.execute = async (...args: Parameters<typeof cleanupStep>) => {
  await fixtureEngineFailure("cleanup");
  return await cleanupStep(...args);
};'''))
        for source in (ROOT / 'workflows').glob('workflow-operation-database-sqlite-*.yaml'):
            workflow = yaml.safe_load(source.read_text())
            for job in workflow['jobs']:
                job.pop('target', None)
                job.pop('labels', None)
                for step in job['steps']:
                    step.pop('target', None)
                    step.pop('labels', None)
            (self.repo / 'workflows' / source.name).write_text(yaml.safe_dump(workflow, sort_keys=False))
        identity = uuid.uuid4().hex * 2
        user = os.environ.get('USER', 'nckrtl')
        self.snapshot = '/tmp/orbit-sqlite-' + identity + '.sqlite'
        self.incoming = '/tmp/orbit-sqlite-' + identity[:16] + '-' + identity[16:32]
        self.addCleanup(lambda: Path(self.snapshot).unlink(missing_ok=True))
        self.addCleanup(lambda: Path(self.incoming).unlink(missing_ok=True))
        self.state = {'operation': {'id': 17, 'kind': 'database.sqlite_seed', 'node_id': 17,
            'cancel_requested': False, 'status': 'queued', 'result': None, 'input': {
                'source': {'variant': 'seed', 'node_id': 12, 'transport_user': user, 'target_instance_id': 2, 'base': '/home/source',
                    'source_path': '/home/source/db.sqlite', 'source_user': user, 'operation': identity,
                    'state_dir': '/var/lib/orbit/app-instance-sqlite-seeds/source-1-target-2', 'snapshot_path': self.snapshot},
                'target': {'node_id': 17, 'transport_user': user,
                    'target_instance_id': 2, 'base': '/home/target', 'target_user': user, 'operation': identity,
                    'state_dir': '/var/lib/orbit/app-instance-sqlite-seeds/target-2',
                    'environment': 'development', 'relative_path': 'db.sqlite'}}},
            'incoming': self.incoming, 'calls': [], 'commands': [], 'exports': 0, 'prepares': 0,
            'installs': 0, 'cleanups': 0, 'targetCleanups': 0}

    def save(self):
        (self.repo / 'sqlite-native-state.json').write_text(json.dumps(self.state))

    def state_after(self):
        return json.loads((self.repo / 'sqlite-native-state.json').read_text())

    def run_kind(self, kind='seed', success=True):
        self.state['operation']['kind'] = 'database.sqlite_' + kind
        if kind == 'export':
            self.state['operation']['node_id'] = 12
            self.state['operation']['input'].pop('target')
        self.save()
        workflow = 'operation-database-sqlite-' + kind.replace('_', '-')
        self.cli('workflow', 'validate', workflow)
        result = self.cli('workflow', 'run', workflow, '--input', 'operation_id=17', '--input',
            'node_id=' + str(self.state['operation']['node_id']), '--input', 'kind=database.sqlite_' + kind, success=success)
        self.run_id = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']
        sentinel = b'private-db-sentinel'
        self.assertNotIn(sentinel.decode(), result.stdout + result.stderr)
        for path in (self.repo / '.swamp').rglob('*'):
            if path.is_file() and path.name != 'raw':
                self.assertNotIn(sentinel, path.read_bytes(), str(path))
            elif path.is_file() and path.name == 'raw' and sentinel in path.read_bytes():
                self.assertTrue((path.parent / 'metadata.yaml').exists())
                self.assertIn('application/vnd.sqlite3', (path.parent / 'metadata.yaml').read_text())
        return self.state_after()

    def test_seed_streams_one_snapshot_and_cleans_source(self):
        final = self.run_kind()
        self.assertEqual([final[k] for k in ('exports', 'prepares', 'installs', 'cleanups')], [1, 1, 1, 1])
        self.assertEqual(final['operation']['result'], {'backend': 'active'})
        self.assertTrue(all('/direct-operations/' in path for path in final['calls']))
        self.assertFalse(any(term in path for path in final['calls'] for term in ('children', 'heartbeat', 'receipts', 'claim', 'private-material')))
        self.assertLessEqual(final['operation']['started_at'], final['operation']['finished_at'])

    def test_initial_cancellation_projects_without_file_or_cleanup_effects(self):
        self.state['operation']['cancel_requested'] = True
        final = self.run_kind()
        self.assertEqual(final['operation']['status'], 'cancelled')
        self.assertEqual(final['operation']['result'], {})
        self.assertEqual(final['commands'], [])
        self.assertEqual([final[k] for k in ('exports', 'prepares', 'installs', 'cleanups')], [0, 0, 0, 0])

    def test_failed_export_skips_target_and_cleans_source(self):
        self.state['failExport'] = True
        final = self.run_kind(success=False)
        self.assertEqual([final[k] for k in ('exports', 'prepares', 'installs', 'cleanups')], [1, 0, 0, 1])
        self.assertEqual(final['operation']['status'], 'failed')

    def test_failed_install_cleans_source_and_redacts_native_diagnostics(self):
        self.state['failInstall'] = True
        final = self.run_kind(success=False)
        self.assertEqual([final[k] for k in ('installs', 'cleanups')], [1, 1])
        self.assertEqual(final['operation']['status'], 'failed')

    def test_fresh_cancellation_after_export_skips_target_but_cleans_source(self):
        self.state['cancelAfterExport'] = True
        final = self.run_kind()
        self.assertEqual(final['operation']['status'], 'cancelled')
        self.assertEqual(final['operation']['result'], {})
        self.assertEqual([final[k] for k in ('exports', 'prepares', 'installs', 'cleanups')], [1, 0, 0, 1])
        self.assertFalse(Path(self.snapshot).exists())

    def test_fresh_cancellation_after_prepare_abandons_incoming_without_install(self):
        self.state['cancelAfterPrepare'] = True
        final = self.run_kind()
        self.assertEqual(final['operation']['status'], 'cancelled')
        self.assertEqual([final[k] for k in ('exports', 'prepares', 'installs', 'targetCleanups', 'cleanups')], [1, 1, 0, 1, 1])
        self.assertFalse(Path(self.snapshot).exists())
        self.assertFalse(Path(self.incoming).exists())

    def test_cancellation_after_install_does_not_hide_the_completed_effect(self):
        self.state['cancelAfterInstall'] = True
        final = self.run_kind()
        self.assertEqual(final['operation']['status'], 'succeeded')
        self.assertEqual([final[k] for k in ('installs', 'cleanups')], [1, 1])

    def test_engine_failure_with_skipped_outcomes_projects_one_failure_after_cleanup(self):
        self.state['engineFailure'] = 'install'
        final = self.run_kind(success=False)
        self.assertEqual([final[k] for k in ('exports', 'prepares', 'installs', 'cleanups')], [1, 1, 0, 1])
        self.assertEqual(final['operation']['status'], 'failed')
        self.assertEqual(final.get('terminalCallbacks'), 1)
        self.assertFalse(any(path.endswith('/failure') for path in final['calls']))

    def test_engine_export_failure_does_not_dereference_missing_target_outcomes(self):
        self.state['engineFailure'] = 'export'
        final = self.run_kind(success=False)
        self.assertEqual([final[k] for k in ('exports', 'prepares', 'installs')], [0, 0, 0])
        self.assertEqual(final['operation']['status'], 'failed')
        self.assertEqual(final.get('terminalCallbacks'), 1)
        self.assertFalse(any(path.endswith('/failure') for path in final['calls']))

    def test_source_cleanup_failure_cannot_project_success(self):
        self.state['failCleanup'] = True
        final = self.run_kind(success=False)
        self.assertEqual(final['operation']['status'], 'failed')
        self.assertEqual(final['operation']['result'], {'error_code': 'database.sqlite_cleanup_source_failed'})

    def test_engine_cleanup_failure_projects_missing_outcome_as_failed(self):
        self.state['engineFailure'] = 'cleanup'
        final = self.run_kind(success=False)
        self.assertEqual(final['operation']['status'], 'failed')
        self.assertEqual(final['operation']['result'], {'error_code': 'database.sqlite_native_failed'})
        self.assertEqual(final.get('terminalCallbacks'), 1)

    def test_foreign_selected_source_node_refuses_before_file_effects(self):
        path = self.repo / 'workflows/workflow-operation-database-sqlite-seed.yaml'
        workflow = yaml.safe_load(path.read_text())
        snapshot = next(job for job in workflow['jobs'] if job['name'] == 'snapshot')
        snapshot['steps'][0]['task']['inputs']['selected_node_id'] = 99
        path.write_text(yaml.safe_dump(workflow, sort_keys=False))
        final = self.run_kind(success=False)
        self.assertEqual([final[k] for k in ('exports', 'prepares', 'installs', 'cleanups')], [0, 0, 0, 0])
        self.assertEqual(final['operation']['status'], 'failed')

    def test_replaced_source_descriptor_refuses_before_file_effects(self):
        path = self.repo / 'workflows/workflow-operation-database-sqlite-seed.yaml'
        workflow = yaml.safe_load(path.read_text())
        snapshot = next(job for job in workflow['jobs'] if job['name'] == 'snapshot')
        source = dict(self.state['operation']['input']['source'], source_path='/home/source/other.sqlite')
        snapshot['steps'][0]['task']['inputs']['source'] = source
        path.write_text(yaml.safe_dump(workflow, sort_keys=False))
        final = self.run_kind(success=False)
        self.assertEqual([final[k] for k in ('exports', 'prepares', 'installs', 'cleanups')], [0, 0, 0, 0])
        self.assertEqual(final['operation']['status'], 'failed')

    def test_foreign_selected_target_node_refuses_before_target_effects(self):
        path = self.repo / 'workflows/workflow-operation-database-sqlite-seed.yaml'
        workflow = yaml.safe_load(path.read_text())
        prepare = next(job for job in workflow['jobs'] if job['name'] == 'prepare')
        prepare['steps'][0]['task']['inputs']['selected_node_id'] = 99
        path.write_text(yaml.safe_dump(workflow, sort_keys=False))
        final = self.run_kind(success=False)
        self.assertEqual([final[k] for k in ('exports', 'prepares', 'installs', 'cleanups')], [1, 0, 0, 1])
        self.assertEqual(final['operation']['status'], 'failed')

    def test_terminal_root_refuses_admission_without_file_effects(self):
        self.state['operation']['status'] = 'succeeded'
        final = self.run_kind(success=False)
        self.assertEqual(final['commands'], [])

    def test_resume_after_terminal_engine_failure_cannot_start_the_skipped_install(self):
        self.state['engineFailure'] = 'install'
        self.run_kind(success=False)
        self.state = self.state_after()
        self.state.pop('engineFailure')
        self.save()
        self.cli('workflow', 'resume', 'operation-database-sqlite-seed', '--run', self.run_id, success=False)
        final = self.state_after()
        self.assertEqual(final['installs'], 0)
        self.assertEqual(final['operation']['status'], 'failed')

    def test_previously_installed_seed_completes_without_receiving_or_installing_again(self):
        self.state['preparedComplete'] = True
        final = self.run_kind()
        self.assertEqual(final['operation']['status'], 'succeeded')
        self.assertEqual([final[k] for k in ('exports', 'prepares', 'installs', 'cleanups')], [1, 1, 0, 1])

    def test_terminal_projection_resume_does_not_repeat_native_effects(self):
        self.state['loseProjection'] = True
        self.run_kind(success=False)
        self.cli('workflow', 'resume', 'operation-database-sqlite-seed', '--run', self.run_id)
        final = self.state_after()
        self.assertEqual([final[k] for k in ('exports', 'installs', 'cleanups')], [1, 1, 1])
        self.assertEqual(final['operation']['status'], 'succeeded')

    def test_export_is_one_run_with_no_target(self):
        final = self.run_kind('export')
        self.assertEqual([final[k] for k in ('exports', 'installs', 'cleanups')], [1, 0, 1])
        self.assertGreaterEqual(final['operation']['result']['bytes'], 16)
        self.assertEqual(len(final['operation']['result']['sha256']), 64)

    def test_abandon_cleans_target_then_source_without_export(self):
        final = self.run_kind('seed_abandon')
        self.assertEqual([final[k] for k in ('exports', 'targetCleanups', 'cleanups')], [0, 1, 1])
        self.assertEqual(final['commands'][0]['action'], 'seedTarget')
        self.assertEqual(final['commands'][1]['action'], 'seedSource')
        self.assertEqual(final['operation']['result'], {'backend': 'absent'})


if __name__ == '__main__':
    unittest.main()
