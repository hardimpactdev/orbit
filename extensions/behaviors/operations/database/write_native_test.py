"""Real gatewayHttp privacy/recovery on native; only SQL is injected."""
import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import ssl
import subprocess
import sys
import threading
import unittest
import uuid
from urllib.parse import parse_qs, urlparse

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class WriteNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/database')
        marker = uuid.uuid4().hex
        self.material = {'password': 'password-' + marker, 'username': 'owner-' + marker,
                         'dsn': 'pgsql:host=127.0.0.1;dbname=' + marker,
                         'sql': 'UPDATE private_' + marker + ' SET value = 17'}
        self.secrets = tuple(self.material.values())
        self.operation = {'id': 17, 'kind': 'database.write', 'node_id': 14, 'status': 'queued',
                          'input': {'driver': 'pgsql'}, 'result': None, 'cancel_requested': False}
        self.effects = self.private_reads = 0
        self.run_id = None
        self.lose_projection = self.uncertain = False
        fixture = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_args): pass

            def respond(self, status, body):
                encoded = json.dumps(body).encode()
                self.send_response(status)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Cache-Control', 'no-store, private')
                self.send_header('Content-Length', str(len(encoded)))
                self.end_headers()
                self.wfile.write(encoded)

            def do_GET(self):
                parsed = urlparse(self.path)
                run_id = parse_qs(parsed.query)['run_id'][0]
                uuid.UUID(run_id)
                if fixture.run_id is None: fixture.run_id = run_id
                fixture.assertEqual(run_id, fixture.run_id)
                if parsed.path.endswith('/private-material'):
                    fixture.private_reads += 1
                    self.respond(200, {'data': fixture.material})
                else:
                    fixture.assertEqual(parsed.path, '/api/v1/direct-operations/17')
                    self.respond(200, {'data': fixture.operation})

            def do_POST(self):
                body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                if self.path == '/fixture-effect':
                    fixture.assertEqual(body, fixture.material)
                    fixture.effects += 1
                    self.respond(503 if fixture.uncertain else 200,
                                 {'private': fixture.material['sql']} if fixture.uncertain else
                                 {'row_count': 1, 'truncated': False, 'rows': [{'private': fixture.material['sql']}]})
                    return
                fixture.assertEqual(self.path, '/api/v1/direct-operations/17/result')
                fixture.assertEqual(body['run_id'], fixture.run_id)
                fixture.assertFalse(any(secret in json.dumps(body) for secret in fixture.secrets))
                if body['status'] == 'succeeded' and fixture.lose_projection:
                    fixture.lose_projection = False
                    self.respond(503, {'error': {'code': 'fixture.lost_projection'}})
                    return
                fixture.operation['status'] = body['status']
                fixture.operation['result'] = body.get('result')
                self.respond(200, {'data': fixture.operation})

        cert, key = self.repo / 'fixture.crt', self.repo / 'fixture.key'
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
                        '-keyout', str(key), '-out', str(cert), '-days', '1',
                        '-subj', '/CN=127.0.0.1', '-addext', 'subjectAltName=IP:127.0.0.1'],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=10)
        leaf, leaf_key, csr = self.repo / 'server.crt', self.repo / 'server.key', self.repo / 'server.csr'
        extensions = self.repo / 'server.ext'
        extensions.write_text('basicConstraints=critical,CA:FALSE\nsubjectAltName=IP:127.0.0.1\nextendedKeyUsage=serverAuth\n')
        for command in [
            ['openssl', 'req', '-new', '-newkey', 'rsa:2048', '-nodes', '-keyout', str(leaf_key),
             '-out', str(csr), '-subj', '/CN=127.0.0.1'],
            ['openssl', 'x509', '-req', '-in', str(csr), '-CA', str(cert), '-CAkey', str(key),
             '-set_serial', '1', '-out', str(leaf), '-days', '1', '-extfile', str(extensions)],
        ]:
            subprocess.run(command, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=10)
        server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(leaf, leaf_key)
        server.socket = context.wrap_socket(server.socket, server_side=True)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        url = 'https://127.0.0.1:' + str(server.server_port)
        self.native_env.update(ORBIT_WRITE_FIXTURE_URL=url, ORBIT_WRITE_FIXTURE_CA=str(cert), HTTP_PROXY="", HTTPS_PROXY="", ALL_PROXY="", NO_PROXY="127.0.0.1,localhost")
        globals_file = self.repo / 'extensions/behaviors/operation-runner/mod.ts'
        globals_file.write_text(globals_file.read_text().replace('https://gateway.orbit', url)
                                .replace('/usr/local/share/ca-certificates/orbit-root.crt', str(cert)))
        module = self.repo / 'extensions/behaviors/operations/database/write.ts'
        module.write_text('import { fixturePdo, fixtureHttp } from "./write_native_fixture.ts";\n' + module.read_text()
                          .replace('...databaseWrite()', '...databaseWrite(() => fixtureHttp(), undefined, fixturePdo)'))
        effect_module = self.repo / 'extensions/behaviors/operations/database/write_native_fixture.ts'
        effect_module.write_text(effect_module.read_text().replace('Deno.env.get("ORBIT_WRITE_FIXTURE_URL")!', json.dumps(url)).replace('Deno.env.get("ORBIT_WRITE_FIXTURE_CA")!', json.dumps(str(cert))))
        workflow = ROOT / 'workflows/workflow-operation-database-write.yaml'
        source = workflow.read_text()
        self.assertIn('"orbit-node-" + string(inputs.node_id)', source)
        self.assertNotIn('migration', source)
        (self.repo / 'workflows' / workflow.name).write_text('\n'.join(
            line for line in source.splitlines() if not line.startswith(('target:', 'labels:'))) + '\n')

    def run_write(self, success):
        self.cli('workflow', 'validate', 'operation-database-write')
        result = self.cli('workflow', 'run', 'operation-database-write', '--input', 'operation_id=17',
                          '--input', 'node_id=14', '--input', 'kind=database.write', success=success)
        if self.operation['status'] == 'queued' and self.operation['node_id'] == 14:
            self.fail(str((self.run_id, self.private_reads, self.effects)) + result.stdout + result.stderr)
        return json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']

    def test_saved_native_output_resumes_projection_without_repeating_private_sql(self):
        self.lose_projection = True
        run_id = self.run_write(False)
        self.assertEqual(self.operation['status'], 'running')
        self.assertEqual(self.effects, 1)
        self.cli('workflow', 'resume', 'operation-database-write', '--run', run_id)
        self.assertEqual(self.effects, 1)
        self.assertEqual(self.private_reads, 1)
        self.assertEqual(self.operation['status'], 'succeeded')
        self.assertEqual(self.operation['result'], {'backend': 'active', 'row_count': 1, 'truncated': False})

    def test_uncertain_effect_refuses_resume_before_another_private_read_or_write(self):
        self.uncertain = True
        run_id = self.run_write(False)
        self.uncertain = False
        self.cli('workflow', 'resume', 'operation-database-write', '--run', run_id, success=False)
        self.assertEqual(self.effects, 1)
        self.assertEqual(self.private_reads, 1)

    def test_foreign_node_refuses_before_private_material_or_effect(self):
        self.operation['node_id'] = 17
        self.run_write(False)
        self.assertEqual(self.private_reads, 0)
        self.assertEqual(self.effects, 0)


if __name__ == '__main__': unittest.main()
