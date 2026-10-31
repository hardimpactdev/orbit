"""One clone DAG with real native persistence and the shared local native harness."""
from contextlib import closing
import base64
import getpass
import sqlite3
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import yaml
from pathlib import Path
import secrets
import shutil
import subprocess
import sys
import threading
import unittest

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class CloneNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operations/_lib', 'operations/database')
        self.password = 'clone-private-' + secrets.token_hex(16)
        self.secrets = (self.password, base64.b64encode(self.password.encode()).decode())
        self.operation = {'id': 17, 'kind': 'database.clone', 'node_id': 209,
            'status': 'queued', 'result': None, 'cancel_requested': False,
            'input': {'variant': 'mysql', 'node_id': 209, 'source_node_id': 209, 'mysql': {
                'driver': 'mysql', 'repair': False, 'username': 'target_owner', 'databases': ['target', 'target_test'],
                'test_prefix': 'target_test', 'locale': None, 'host': '10.44.0.209', 'port': 3306,
                'container': 'orbit-process-42-proof',
                'index': None, 'patterns': [], 'allow': [], 'source_database': 'source_database'}}}
        self.lose_projection = False
        self.public_reads = 0
        self.cancel_at_read = None
        self.terminal_callbacks = 0
        fixture = self

        class Gateway(BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def do_GET(self):
                if '/private-material?' not in self.path:
                    fixture.public_reads += 1
                    if fixture.public_reads == fixture.cancel_at_read:
                        fixture.operation['cancel_requested'] = True
                self.send_response(200)
                self.send_header('Cache-Control', 'no-store, private')
                self.end_headers()
                data = {'password': fixture.password,
                    'payload': {'password': 'a' * 40, 'salt': 'YWFhYWFhYWFhYWFhYWFhYQ=='}} if '/private-material?' in self.path else fixture.operation
                self.wfile.write(json.dumps({'data': data}).encode())

            def do_POST(self):
                data = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                if self.path.endswith('/failure'):
                    fixture.operation.update(status='failed', result=None, error_code='operation.native_failed')
                    self.send_response(200)
                    self.end_headers()
                    self.wfile.write(json.dumps({'data': fixture.operation}).encode())
                    return
                if fixture.lose_projection and data['status'] == 'succeeded':
                    fixture.lose_projection = False
                    self.send_response(503)
                    self.end_headers()
                    self.wfile.write(b'{"error":{"code":"fixture.lost_projection"}}')
                    return
                if data['status'] != 'running': fixture.terminal_callbacks += 1
                fixture.operation.update(status=data['status'], result=data.get('result'), error_code=data.get('error_code'))
                self.send_response(200)
                self.end_headers()
                self.wfile.write(json.dumps({'data': fixture.operation}).encode())

        server = ThreadingHTTPServer(('127.0.0.1', 0), Gateway)
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        cert = self.repo / 'fixture.pem'
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout',
            str(self.repo / 'fixture.key'), '-out', str(cert), '-days', '1', '-subj', '/CN=fixture'],
            check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=10)
        self.native_env.update(CLONE_FIXTURE_URL=f'http://127.0.0.1:{server.server_port}', CLONE_FIXTURE_CA=str(cert))
        module = self.repo / 'extensions/behaviors/operations/database/clone.ts'
        # These are canonical shared system dependencies of the database behavior family.
        for relative in ('operations/processes-schedules/process-system.ts',
                         'operations/processes-schedules/system.ts', 'operations/node/system_command.ts'):
            target = self.repo / 'extensions/behaviors' / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy(ROOT / 'extensions/behaviors' / relative, target)
        module.write_text('import { fixtureSqlInvoke, fixtureSqliteInvoke } from "./clone_native_fixture.ts";\n' + module.read_text().replace(
            'export const behavior = clone();', '''const fixtureBehavior = clone(() => gatewayHttp(Deno.env.get("CLONE_FIXTURE_URL")!, Deno.env.get("CLONE_FIXTURE_CA")!), fixtureSqlInvoke, undefined, fixtureSqliteInvoke);
for (const name of ["export", "apply", "cleanup", "cleanupTarget"] as const) {
  const method = fixtureBehavior.methods[name], execute = method.execute;
  method.execute = async (...args: Parameters<typeof execute>) => {
    if (Deno.env.get("CLONE_ENGINE_FAILURE") === name) throw new Error("Fixture failed before outcome");
    return await execute(...args);
  };
}
export const behavior = fixtureBehavior;'''))
        workflow = ROOT / 'workflows/workflow-operation-database-clone.yaml'
        fixture_workflow = yaml.safe_load(workflow.read_text())
        fixture_workflow.pop('target', None)
        fixture_workflow.pop('labels', None)
        for job in fixture_workflow['jobs']:
            job.pop('target', None)
            job.pop('labels', None)
            for step in job['steps']:
                step.pop('target', None)
                step.pop('labels', None)
        (self.repo / 'workflows' / workflow.name).write_text(yaml.safe_dump(fixture_workflow, sort_keys=False))

    def start(self, success=True):
        self.cli('workflow', 'validate', 'operation-database-clone')
        return self.cli('workflow', 'run', 'operation-database-clone', '--input', 'operation_id=17',
            '--input', 'node_id=209', '--input', 'kind=database.clone', success=success)

    def test_clone_private_material_never_enters_native_scratch(self):
        self.start()
        self.assertEqual(self.operation['status'], 'succeeded')
        self.assertEqual(self.operation['result'], {'backend': 'active'})
        effects = (self.repo / 'effects').read_text().splitlines()
        self.assertEqual(effects.count('copy'), 1)
        self.assert_private_absent()

    def test_resume_projects_saved_outcome_without_repeating_reset_or_copy(self):
        self.lose_projection = True
        result = self.start(False)
        before = (self.repo / 'effects').read_bytes()
        run_id = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']
        self.cli('workflow', 'resume', 'operation-database-clone', '--run', run_id)
        self.assertEqual((self.repo / 'effects').read_bytes(), before)
        self.assertEqual(self.operation['status'], 'succeeded')
        self.assert_private_absent()

    def test_uncertain_copy_is_terminal_failed_without_another_effect(self):
        self.native_env['CLONE_FAIL_COPY_ONCE'] = '1'
        self.start(False)
        effects = (self.repo / 'effects').read_text().splitlines()
        self.assertEqual(effects.count('reset'), 1)
        self.assertEqual(effects.count('copy'), 1)
        self.assertEqual(self.operation['status'], 'failed')
        self.assertEqual(self.operation['error_code'], 'database.clone_native_failed')
        self.assert_private_absent()

    def test_sql_exception_does_not_leak_password(self):
        self.native_env['CLONE_THROW'] = '1'
        self.start(False)
        self.assertEqual(self.operation['status'], 'failed')
        self.assertEqual(self.operation['error_code'], 'database.clone_native_failed')
        self.assert_private_absent()


    def sqlite(self, remote=True, nested=False):
        self.native_env['CLONE_SQLITE'] = '1'
        source = self.repo / 'source'
        target = self.repo / 'target'
        source.mkdir(mode=0o700)
        target.mkdir(mode=0o700)
        relative = Path('not-created/proof.sqlite') if nested else Path('proof.sqlite')
        self.database = source / relative
        self.destination = target / relative
        self.database.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        with closing(sqlite3.connect(self.database)) as db:
            db.execute('CREATE TABLE proof(value TEXT)')
            db.execute("INSERT INTO proof VALUES ('native-clone-fixture')")
            db.commit()
        self.database.chmod(0o600)
        self.export_directory = Path('/tmp/orbit-sqlite-clone-' + secrets.token_hex(16))
        self.incoming = self.destination.parent / ('.orbit-sqlite-' + secrets.token_hex(16))
        self.addCleanup(lambda: shutil.rmtree(self.export_directory, ignore_errors=True))
        self.operation['input'] = {'variant': 'sqlite_remote' if remote else 'sqlite_local',
            'node_id': 209, 'source_node_id': 9 if remote else 209,
            'source': {'variant': 'clone', 'node_id': 9 if remote else 209,
                'transport_user': getpass.getuser(), 'checkout': str(source), 'source': str(self.database),
                'directory': str(self.export_directory)},
            'target': {'node_id': 209, 'transport_user': getpass.getuser(), 'checkout': str(target),
                'target': str(self.destination), 'incoming': str(self.incoming)}}

    def break_step(self, step):
        self.native_env['CLONE_ENGINE_FAILURE'] = step

    def test_remote_sqlite_copies_real_snapshot_and_cleans_both_scratch_locations(self):
        self.sqlite()
        self.start()
        with closing(sqlite3.connect(self.destination)) as db:
            self.assertEqual(db.execute('SELECT value FROM proof').fetchall(), [('native-clone-fixture',)])
        self.assertEqual(self.operation['status'], 'succeeded')
        self.assertFalse(self.export_directory.exists())
        self.assertFalse(self.incoming.exists())
        self.assertEqual(self.terminal_callbacks, 1)
        for path in (self.repo / '.swamp').rglob('*'):
            if path.is_file() and path.name != 'raw':
                self.assertNotIn(b'native-clone-fixture', path.read_bytes(), str(path))

    def test_local_sqlite_copies_without_a_remote_snapshot(self):
        self.sqlite(remote=False)
        self.start()
        self.assertTrue(self.destination.exists())
        self.assertEqual((self.repo / 'effects').read_text().splitlines(), ['sqlite-local'])

    def test_fresh_cancel_after_export_skips_target_copy_and_cleans_snapshot(self):
        self.sqlite()
        self.cancel_at_read = 3
        self.start()
        self.assertEqual(self.operation['status'], 'cancelled')
        self.assertFalse(self.destination.exists())
        self.assertFalse(self.export_directory.exists())
        self.assertFalse(self.incoming.exists())
        self.assertNotIn('sqlite-install', (self.repo / 'effects').read_text())

    def test_cancel_after_export_keeps_missing_target_parent_absent_and_projects_cancelled(self):
        self.sqlite(nested=True)
        self.cancel_at_read = 3
        self.start()
        self.assertEqual(self.operation['status'], 'cancelled')
        self.assertFalse(self.destination.parent.exists())
        self.assertFalse(self.export_directory.exists())
        self.assertFalse(self.incoming.exists())
        self.assertNotIn('sqlite-remove', (self.repo / 'effects').read_text())

    def test_engine_install_failure_projects_once_after_both_cleanups(self):
        self.sqlite()
        self.break_step('apply')
        self.start(False)
        self.assertEqual(self.operation['status'], 'failed')
        self.assertEqual(self.operation['error_code'], 'database.clone_native_failed')
        self.assertEqual(self.terminal_callbacks, 1)
        self.assertFalse(self.destination.exists())
        self.assertFalse(self.export_directory.exists())
        self.assertFalse(self.incoming.exists())

    def test_engine_export_failure_can_settle_without_missing_outcome_dereference(self):
        self.sqlite()
        self.break_step('export')
        self.start(False)
        self.assertEqual(self.operation['status'], 'failed')
        self.assertEqual(self.terminal_callbacks, 1)
        self.assertFalse(self.destination.exists())

    def test_source_cleanup_engine_failure_never_projects_a_completed_copy(self):
        self.sqlite()
        self.break_step('cleanup')
        self.start(False)
        self.assertEqual(self.operation['status'], 'failed')
        self.assertEqual(self.terminal_callbacks, 1)
        self.assertTrue(self.destination.exists())

    def test_target_cleanup_engine_failure_still_cleans_source_before_failure(self):
        self.sqlite()
        self.break_step('cleanupTarget')
        self.start(False)
        self.assertEqual(self.operation['status'], 'failed')
        self.assertEqual(self.terminal_callbacks, 1)
        self.assertFalse(self.export_directory.exists())

    def test_remote_projection_resume_does_not_repeat_export_or_install(self):
        self.sqlite()
        self.lose_projection = True
        result = self.start(False)
        before = (self.repo / 'effects').read_bytes()
        run_id = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']
        self.cli('workflow', 'resume', 'operation-database-clone', '--run', run_id)
        self.assertEqual((self.repo / 'effects').read_bytes(), before)
        self.assertEqual(self.operation['status'], 'succeeded')

    def test_resume_terminal_failure_never_repeats_uncertain_copy(self):
        self.native_env['CLONE_FAIL_COPY_ONCE'] = '1'
        result = self.start(False)
        before = (self.repo / 'effects').read_bytes()
        run_id = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']
        self.cli('workflow', 'resume', 'operation-database-clone', '--run', run_id, success=False)
        self.assertEqual((self.repo / 'effects').read_bytes(), before)
        self.assertEqual(self.operation['status'], 'failed')


if __name__ == '__main__':
    unittest.main()
