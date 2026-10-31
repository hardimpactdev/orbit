"""Native apply/project recovery with the real gatewayHttp transport and whole-scratch scan."""
import base64
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import secrets
import string
from pathlib import Path
import subprocess
import sys
import threading
import unittest

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class CreateNative(NativeTestCase):
    kind = 'database.create'
    workflow = 'operation-database-create'
    module = 'create'
    factory = 'databaseCreate'

    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/database')
        self.password = 'synthetic-private-admin-create-' + secrets.token_hex(12)
        self.owner_password = ''.join(secrets.choice(string.ascii_letters + string.digits) for _ in range(40))
        self.secrets = (self.password, self.owner_password, base64.b64encode(self.password.encode()).decode(), base64.b64encode(self.owner_password.encode()).decode())
        self.operation = {'id': 17, 'kind': self.kind, 'node_id': 209, 'status': 'queued', 'result': None,
            'cancel_requested': False, 'input': {'repair': False, 'driver': 'pgsql', 'username': 'proof',
            'databases': ['proof'], 'test_prefix': None, 'locale': None, 'host': '10.44.0.9', 'port': 5434,
            'container': 'orbit-process-42-proof', 'agent_sha256': 'a' * 64, 'index': None, 'patterns': [], 'allow': []}}
        self.lose_projection = False
        self.paths = []
        fixture = self

        class Gateway(BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def do_GET(self):
                fixture.paths.append(self.path)
                self.send_response(200)
                self.send_header('Cache-Control', 'no-store, private')
                self.end_headers()
                data = {'password': fixture.password, 'payload': {'password': fixture.owner_password, 'salt': 'YWFhYWFhYWFhYWFhYWFhYQ=='}} if '/private-material?' in self.path else fixture.operation
                self.wfile.write(json.dumps({'data': data}).encode())

            def do_POST(self):
                fixture.paths.append(self.path)
                data = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
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
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', str(self.repo / 'fixture.key'), '-out', str(cert), '-days', '1', '-subj', '/CN=fixture'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=10)
        self.native_env.update(DATABASE_CREATE_FIXTURE_URL=f'http://127.0.0.1:{server.server_port}', DATABASE_CREATE_FIXTURE_CA=str(cert))
        module = self.repo / f'extensions/behaviors/operations/database/{self.module}.ts'
        module.write_text('import { gatewayHttp } from "../../automatic-deploy/gateway.ts";\nimport { fixtureInvoke } from "./create_native_fixture.ts";\n' + module.read_text().replace(f'...{self.factory}()', f'...{self.factory}(() => gatewayHttp(Deno.env.get("DATABASE_CREATE_FIXTURE_URL")!, Deno.env.get("DATABASE_CREATE_FIXTURE_CA")!), fixtureInvoke)'))
        (module.parent / 'create_native_fixture.ts').write_text('''import type { NodeCall } from "../_lib/node-agent.ts";
export async function fixtureInvoke(call: NodeCall) {
  await Deno.writeTextFile("invocations", call.mutates ? "effect\\n" : "read\\n", { append: true });
  if (Deno.env.get("DATABASE_CREATE_THROW") === "1") throw new Error(String(call.input.password));
  return { output: String(call.input.sql).includes("SCHEMA_NAME=\'proof_source\'") ? "proof_source\\n" : "" };
}
''')
        workflow = ROOT / f'workflows/workflow-{self.workflow}.yaml'
        (self.repo / 'workflows' / workflow.name).write_text('\n'.join(line for line in workflow.read_text().splitlines() if not line.startswith(('target:', 'labels:'))) + '\n')

    def start(self, success=True):
        self.cli('workflow', 'validate', self.workflow)
        return self.cli('workflow', 'run', self.workflow, '--input', 'operation_id=17', '--input', 'node_id=209', '--input', f'kind={self.kind}', success=success)

    def test_real_http_private_material_and_effect_output_never_enter_scratch(self):
        self.start()
        self.assertEqual(self.operation['status'], 'succeeded')
        self.assertEqual(self.operation['result'], {'backend': 'active'})
        self.assertEqual(sum('/private-material?' in p for p in self.paths), 1)
        self.assert_private_absent()

    def test_native_resume_projects_saved_effect_without_repeating_sql(self):
        self.lose_projection = True
        result = self.start(False)
        before = (self.repo / 'invocations').read_bytes()
        run_id = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']
        self.cli('workflow', 'resume', self.workflow, '--run', run_id)
        self.assertEqual((self.repo / 'invocations').read_bytes(), before)
        self.assertEqual(self.operation['status'], 'succeeded')
        self.assert_private_absent()

    def test_agent_private_exception_is_bounded_in_native_failure(self):
        self.native_env['DATABASE_CREATE_THROW'] = '1'
        self.start(False)
        self.assertEqual(self.operation['status'], 'running')
        self.assert_private_absent()


if __name__ == '__main__':
    unittest.main()
