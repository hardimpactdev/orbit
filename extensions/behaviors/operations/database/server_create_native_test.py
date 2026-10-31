"""Server-create private environment uses REAL gatewayHttp; shared scratch/CLI harness."""
import base64
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from pathlib import Path
import secrets
import subprocess
import sys
import threading
import unittest

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class ServerCreateNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/database')
        self.password = 'private-server-initializer-' + secrets.token_hex(16)
        self.secrets = (self.password, base64.b64encode(self.password.encode()).decode())
        self.operation = {'id': 17, 'kind': 'database_server.create', 'node_id': 209,
            'status': 'queued', 'result': None, 'cancel_requested': False,
            'input': {'driver': 'mysql', 'process_id': 42, 'container': 'orbit-process-42-proof',
                'container_input': {'image': 'mysql:8.0', 'command': ['mysqld'], 'restart': 'unless-stopped',
                    'working_directory': None, 'ports': ['10.44.0.209:3306:3306'],
                    'mounts': [{'source': 'orbit-proof-data', 'target': '/var/lib/mysql', 'read_only': False}]},
                'spec': 'b' * 64, 'scrub_spec': 'c' * 64, 'agent_sha256': 'a' * 64,
                'initialize': True, 'validate_existing': False, 'was_active': False,
                'host': '10.44.0.209', 'port': 3306, 'default_open': False, 'volume': None}}
        self.lose_projection = False
        fixture = self

        class Gateway(BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def do_GET(self):
                self.send_response(200)
                self.send_header('Cache-Control', 'no-store, private')
                self.end_headers()
                data = {'password': fixture.password, 'environment': {'MYSQL_ROOT_PASSWORD': fixture.password},
                    'acl': '', 'payload': {}} if '/private-material?' in self.path else fixture.operation
                self.wfile.write(json.dumps({'data': data}).encode())

            def do_POST(self):
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
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout',
            str(self.repo / 'fixture.key'), '-out', str(cert), '-days', '1', '-subj', '/CN=fixture'],
            check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=10)
        self.native_env.update(SERVER_CREATE_FIXTURE_URL=f'http://127.0.0.1:{server.server_port}', SERVER_CREATE_FIXTURE_CA=str(cert))
        module = self.repo / 'extensions/behaviors/operations/database/server_create.ts'
        module.write_text('import { gatewayHttp } from "../../automatic-deploy/gateway.ts";\n'
            'import { fixtureInvoke } from "./server_create_native_fixture.ts";\n' + module.read_text().replace(
            '...serverCreate()', '...serverCreate(() => gatewayHttp(Deno.env.get("SERVER_CREATE_FIXTURE_URL")!, Deno.env.get("SERVER_CREATE_FIXTURE_CA")!), fixtureInvoke)'))
        (module.parent / 'server_create_native_fixture.ts').write_text('''import type { NodeCall } from "../_lib/node-agent.ts";
export async function fixtureInvoke(call: NodeCall) {
  await Deno.writeTextFile("effects", "effect\\n", { append: true });
  if (Deno.env.get("SERVER_CREATE_THROW") === "1") throw new Error(JSON.stringify(call.input));
  return {};
}
''')
        workflow = ROOT / 'workflows/workflow-operation-database-server-create.yaml'
        (self.repo / 'workflows' / workflow.name).write_text('\n'.join(line for line in workflow.read_text().splitlines()
            if not line.startswith(('target:', 'labels:'))) + '\n')

    def start(self, success=True):
        self.cli('workflow', 'validate', 'operation-database-server-create')
        return self.cli('workflow', 'run', 'operation-database-server-create', '--input', 'operation_id=17',
            '--input', 'node_id=209', '--input', 'kind=database_server.create', success=success)

    def test_initializer_and_environment_never_enter_native_scratch(self):
        self.start()
        self.assertEqual(self.operation['status'], 'succeeded')
        self.assertEqual(self.operation['result'], {'backend': 'active'})
        self.assert_private_absent()

    def test_resume_projects_saved_outcome_without_repeating_provisioning(self):
        self.lose_projection = True
        result = self.start(False)
        before = (self.repo / 'effects').read_bytes()
        run_id = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']
        self.cli('workflow', 'resume', 'operation-database-server-create', '--run', run_id)
        self.assertEqual((self.repo / 'effects').read_bytes(), before)
        self.assertEqual(self.operation['status'], 'succeeded')
        self.assert_private_absent()

    def test_agent_exception_does_not_leak_initializer(self):
        self.native_env['SERVER_CREATE_THROW'] = '1'
        self.start(False)
        self.assertEqual(self.operation['status'], 'running')
        self.assert_private_absent()


if __name__ == '__main__':
    unittest.main()
