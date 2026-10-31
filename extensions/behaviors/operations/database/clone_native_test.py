"""One clone DAG with real native persistence and the shared local native harness."""
import base64
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
        failure = self.repo / 'extensions/behaviors/operations/failure.ts'
        failure.parent.mkdir(parents=True, exist_ok=True)
        failure.write_text((ROOT / 'extensions/behaviors/operations/failure.ts').read_text().replace('export const behavior = nativeFailure();', 'export const behavior = nativeFailure(() => gatewayHttp(Deno.env.get("CLONE_FIXTURE_URL")!, Deno.env.get("CLONE_FIXTURE_CA")!));'))
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
        fixture = self

        class Gateway(BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def do_GET(self):
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
                fixture.operation.update(status=data['status'], result=data.get('result'))
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
            'export const behavior = clone();', 'export const behavior = clone(() => gatewayHttp(Deno.env.get("CLONE_FIXTURE_URL")!, Deno.env.get("CLONE_FIXTURE_CA")!), fixtureSqlInvoke, undefined, fixtureSqliteInvoke);'))
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
        self.assertEqual(self.operation['error_code'], 'operation.native_failed')
        self.assert_private_absent()

    def test_sql_exception_does_not_leak_password(self):
        self.native_env['CLONE_THROW'] = '1'
        self.start(False)
        self.assertEqual(self.operation['status'], 'failed')
        self.assertEqual(self.operation['error_code'], 'operation.native_failed')
        self.assert_private_absent()


if __name__ == '__main__':
    unittest.main()
