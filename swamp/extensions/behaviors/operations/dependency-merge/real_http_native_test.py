"""Isolated Gateway/TLS/private source + exact placed worker + disposable GitHub HTTP.

Run SWAMP_BIN=/tmp/swamp-1602a617 DEPENDENCY_MERGE_TEST_GATEWAY_ROOT=... python3 this_file.py.
The copied behavior substitutes only dev configuration and fixed external HTTP URLs.
"""
import base64
import http.client
import http.server
import json
import os
from pathlib import Path
import socket
import ssl
import subprocess
import sys
import threading
import time
import unittest

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


def free_port():
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        return listener.getsockname()[1]


class DependencyMergeRealHttp(NativeTestCase):
    native_timeout = 50

    @unittest.skipUnless(os.environ.get('DEPENDENCY_MERGE_TEST_GATEWAY_ROOT'), 'requires isolated Gateway checkout')
    def test_lost_squash_reply_resumes_same_root_without_second_put(self):
        gateway = Path(os.environ['DEPENDENCY_MERGE_TEST_GATEWAY_ROOT'])
        scratch = self.repo
        family = ROOT / 'extensions/behaviors/operations/dependency-merge'
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/dependency-merge', '_lib')
        (scratch / 'extensions/behaviors/native_host_method.ts').write_text((ROOT / 'extensions/behaviors/native_host_method.ts').read_text())
        for name in ('storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views'):
            (scratch / name).mkdir(parents=True, exist_ok=True)
        (scratch / 'gateway.sqlite').touch()
        serve_port, php_port, tls_port = [free_port() for _ in range(3)]
        cert, key = scratch / 'cert.pem', scratch / 'key.pem'
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
            '-keyout', str(scratch / 'ca.key'), '-out', str(cert), '-days', '1',
            '-subj', '/CN=Isolated dependency fixture root', '-addext', 'basicConstraints=critical,CA:TRUE',
            '-addext', 'keyUsage=critical,keyCertSign,cRLSign'],
            check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=15)
        subprocess.run(['openssl', 'req', '-newkey', 'rsa:2048', '-nodes', '-keyout', str(key),
            '-out', str(scratch / 'leaf.csr'), '-subj', '/CN=swamp.orbit'],
            check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=15)
        extensions = scratch / 'leaf.ext'
        extensions.write_text('basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\n'
            'extendedKeyUsage=serverAuth\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid,issuer\n'
            'subjectAltName=DNS:swamp.orbit,DNS:localhost,IP:127.0.0.1\n')
        leaf = scratch / 'leaf.pem'
        subprocess.run(['openssl', 'x509', '-req', '-in', str(scratch / 'leaf.csr'), '-CA', str(cert),
            '-CAkey', str(scratch / 'ca.key'), '-CAcreateserial', '-out', str(leaf),
            '-days', '1', '-extfile', str(extensions)],
            check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=15)
        configuration = {'projectId': 20, 'enabled': True, 'repository': 'acme/widgets',
            'defaultBranch': 'main', 'smokeWorkflow': '.github/workflows/production-smoke.yml'}
        seam = scratch / 'extensions/behaviors/_test/http.ts'
        seam.parent.mkdir(exist_ok=True)
        seam.write_text('''export const fixtureConfiguration = async () => ({ok:true});
export const fixtureQuery = async () => [''' + json.dumps(configuration) + '''];
export const fixtureGithub: typeof fetch = async (url, init) => {
  const parsed = new URL(String(url));
  if (!(parsed.origin === "https://api.github.com" || (parsed.origin === "https://repo.packagist.org" && parsed.pathname === "/p2/acme/library.json"))) throw new Error("Fixture external origin refused");
  const client = Deno.createHttpClient({caCerts:[await Deno.readTextFile(''' + json.dumps(str(cert)) + ''')]});
  try {
    const response = await fetch(''' + json.dumps(f'https://127.0.0.1:{tls_port}/fixture/github') + ''' + parsed.pathname + parsed.search,
      {...init, client, redirect:"error"});
    const bytes = await response.arrayBuffer();
    return new Response(response.status === 204 ? null : bytes, {status:response.status, headers:response.headers});
  } finally { client.close(); }
};
''')
        source = scratch / 'extensions/behaviors/operations/dependency-merge/mod.ts'
        text = source.read_text().replace('import { callWorkerModel } from "../../_lib/worker_model.ts";',
            'import { fixtureGithub, fixtureQuery as queryWorkerData, fixtureConfiguration as callWorkerModel } from "../../_test/http.ts";')
        source.write_text(text.replace('import { queryWorkerData } from "../../_lib/worker_query.ts";', '')
            .replace('...dependencyMerge()', f'...dependencyMerge((ctx) => gatewayHttp("https://127.0.0.1:{tls_port}", {json.dumps(str(cert))}, ctx.signal), fixtureGithub)'))
        workflow = ROOT / 'workflows/workflow-operation-dependency-merge.yaml'
        (scratch / 'workflows' / workflow.name).write_text(workflow.read_text())
        # Evidence is public; tokens originate only inside the Gateway's protected settings.
        evidence = subprocess.run([os.environ.get('DENO_BIN', 'deno'), 'eval',
            f'import {{policyFixture,publicPr}} from {json.dumps(str(family / "policy_fixture.ts"))};console.log(JSON.stringify(policyFixture(publicPr())));'],
            text=True, capture_output=True, check=True, timeout=20)
        rows = json.loads(evidence.stdout)
        def package(version, sha):
            return {'name': 'acme/library', 'version': version, 'type': 'library',
                'dist': {'type': 'zip', 'url': 'https://api.github.com/repos/acme/library/zipball/' + sha,
                         'reference': sha, 'shasum': ''}}
        old, current = package('1.2.3', 'c' * 40), package('1.2.4', 'd' * 40)
        def contents(path, value):
            raw = json.dumps(value, separators=(',', ':')).encode()
            return {'type': 'file', 'path': path, 'encoding': 'base64', 'size': len(raw),
                'content': base64.b64encode(raw).decode()}
        for commit, item in [('a' * 40, current), ('b' * 40, old)]:
            rows[f'/repos/acme/widgets/contents/composer.lock?ref={commit}'] = contents(
                'composer.lock', {'packages': [item], 'packages-dev': []})
            rows[f'/repos/acme/widgets/contents/composer.json?ref={commit}'] = contents(
                'composer.json', {'require': {'acme/library': '^1.2'}})
        rows['/p2/acme/library.json'] = {'packages': {'acme/library': [current]}}
        (scratch / 'evidence.json').write_text(json.dumps(rows))
        (scratch / 'github.json').write_text(json.dumps({'writes': 0, 'reads': 0, 'recovery_reads': 0, 'revocations': 0, 'registry_reads': 0, 'merged': False}))
        self.native_env.update(SWAMP_HOME=str(scratch / 'native-home'), APP_ENV='testing', APP_DEBUG='false',
            APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(),
            DB_CONNECTION='sqlite', DB_DATABASE=str(scratch / 'gateway.sqlite'), DATABASE_URL='', DB_URL='',
            CACHE_STORE='array', SESSION_DRIVER='array', QUEUE_CONNECTION='sync', BROADCAST_CONNECTION='log',
            MAIL_MAILER='array', APP_CONFIG_CACHE=str(scratch / 'config.php'), APP_ROUTES_CACHE=str(scratch / 'routes.php'),
            APP_SERVICES_CACHE=str(scratch / 'services.php'), APP_PACKAGES_CACHE=str(scratch / 'packages.php'),
            ORBIT_HOME=str(scratch / 'orbit-home'), DEPENDENCY_MERGE_TEST_SCRATCH=str(scratch),
            DEPENDENCY_MERGE_TEST_GATEWAY_ROOT=str(gateway), DEPENDENCY_MERGE_TEST_SWAMP_PORT=str(serve_port),
            SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1')
        env = dict(os.environ, **self.native_env)
        for name in ('SWAMP_SERVER_URL', 'SWAMP_SERVE_URL', 'SWAMP_SERVER_TOKEN', 'SWAMP_SERVER_TOKEN_FILE', 'SWAMP_REPO_DIR', 'SWAMP_CA_CERT'):
            env.pop(name, None)
        helper = family / 'tests/gateway.php'

        def php(mode):
            output = subprocess.run(['php', str(helper), mode], cwd=gateway, env=env,
                text=True, capture_output=True, timeout=50)
            try:
                result = json.loads(output.stdout)
            except ValueError:
                self.fail('Isolated Gateway result invalid: ' + output.stdout[-1000:])
            self.assertEqual(output.returncode, 0, result)
            return result

        processes, logs = [], []
        proxy = None
        initialized = False
        try:
            php('migrate')
            php('init')
            initialized = True
            for label, args, cwd in (
                ('serve', [os.environ.get('SWAMP_BIN', 'swamp'), 'serve', '--host', '127.0.0.1', '--port', str(serve_port),
                    '--cert-file', str(leaf), '--key-file', str(key), '--auth-mode', 'none', '--remote-only', '--no-schedule', '--dispatch-env-allow=,'], scratch),
                ('gateway', ['php', '-S', f'127.0.0.1:{php_port}', str(helper)], gateway),
            ):
                log = open(scratch / (label + '.log'), 'wb'); logs.append(log)
                processes.append(subprocess.Popen(args, cwd=cwd, env=env, stdout=log, stderr=subprocess.STDOUT))

            class Relay(http.server.BaseHTTPRequestHandler):
                protocol_version = 'HTTP/1.1'
                def log_message(self, *args):
                    pass
                def forward(self):
                    connection = http.client.HTTPConnection('127.0.0.1', php_port, timeout=10)
                    body = self.rfile.read(int(self.headers.get('Content-Length', 0)))
                    connection.request(self.command, self.path, body, dict(self.headers))
                    response = connection.getresponse()
                    content = response.read()
                    self.send_response(response.status)
                    for name, value in response.getheaders():
                        if name.lower() not in ('connection', 'transfer-encoding', 'content-length'):
                            self.send_header(name, value)
                    self.send_header('Content-Length', str(len(content)))
                    self.end_headers()
                    self.wfile.write(content)
                    connection.close()
                do_GET = do_POST = do_PUT = do_DELETE = forward

            proxy = http.server.ThreadingHTTPServer(('127.0.0.1', tls_port), Relay)
            tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); tls.load_cert_chain(leaf, key)
            proxy.socket = tls.wrap_socket(proxy.socket, server_side=True)
            threading.Thread(target=proxy.serve_forever, daemon=True).start()
            context = ssl.create_default_context(cafile=str(cert))
            import urllib.request
            until = time.monotonic() + 25
            while True:
                try:
                    urllib.request.urlopen(f'https://127.0.0.1:{serve_port}/health', context=context, timeout=1).close()
                    urllib.request.urlopen(f'https://127.0.0.1:{tls_port}/up', context=context, timeout=1).close()
                    break
                except OSError:
                    self.assertLess(time.monotonic(), until, 'Isolated service readiness')
                    time.sleep(.1)
            flags = ('--server', f'wss://127.0.0.1:{serve_port}', '--ca-cert', str(cert))
            token = json.loads(self.cli('worker', 'token', 'create', 'orbit-node-209', '--duration', '1h', *flags).stdout)['token']
            enrollment = scratch / 'enrollment'; enrollment.write_text(token); enrollment.chmod(0o600)
            log = open(scratch / 'worker.log', 'wb'); logs.append(log)
            worker_env = dict(env, SWAMP_HOME=str(scratch / 'worker-home'), SWAMP_CONFIG_DIR=str(scratch / 'worker-config'))
            processes.append(subprocess.Popen([os.environ.get('SWAMP_BIN', 'swamp'), 'worker', 'connect',
                f'wss://127.0.0.1:{serve_port}', '--token-file', str(enrollment), '--ca-cert', str(cert),
                '--cache-dir', str(scratch / 'worker-cache'), '--label', 'node=209', '--no-telemetry'],
                cwd=scratch, env=worker_env, stdout=log, stderr=subprocess.STDOUT))
            until = time.monotonic() + 25
            while not any(w.get('tokenName') == 'orbit-node-209' and w.get('status') == 'idle'
                for w in json.loads(self.cli('worker', 'list', *flags).stdout)['workers']):
                self.assertLess(time.monotonic(), until, 'Exact dev worker enrollment'); time.sleep(.1)
            start = time.monotonic()
            admitted = php('admit')
            self.assertTrue(admitted['run_id'])
            until = time.monotonic() + 35
            while True:
                history = json.loads(self.cli('workflow', 'history', 'get', admitted['run_id'], *flags).stdout)
                if history.get('status') == 'failed': break
                self.assertLess(time.monotonic(), until, history); time.sleep(.1)
            first = php('inspect')
            self.assertEqual(first['github']['writes'], 1, {'first': first, 'history': history})
            self.assertEqual(first['status'], 'running', first)
            self.cli('workflow', 'resume', 'operation-dependency-merge', '--run', admitted['run_id'], *flags)
            outcome = php('inspect')
            self.assertEqual(outcome['status'], 'succeeded', outcome)
            self.assertEqual(outcome['run_id'], admitted['run_id'])
            self.assertEqual(outcome['operation_count'], 1)
            self.assertEqual(outcome['github']['writes'], 1)
            self.assertEqual(outcome['github']['registry_reads'], 1)
            self.assertEqual(outcome['github']['recovery_reads'], 1)
            self.assertEqual(outcome['github']['revocations'], 8)
            self.assertEqual(outcome['result'], {'merged': True, 'head_sha': 'a' * 40, 'merge_sha': 'b' * 40})
            requests = [json.loads(row) for row in (scratch / 'http.jsonl').read_text().splitlines()]
            private = [row for row in requests if row['path'].endswith('/private-material')]
            self.assertEqual(len(private), 2)
            self.assertTrue(all(row['status'] == 200 and row['bound'] and row['run_id'] == admitted['run_id']
                and 'no-store' in row['cache_control'] for row in private))
            self.assertTrue(all(row['bound'] and row['run_id'] == admitted['run_id'] for row in requests if 'direct-operations/' in row['path']))
            for process in reversed(processes): process.terminate(); process.wait(timeout=5)
            for log in logs: log.close()
            proof = php('scan')
            proof.update(operation_id=admitted['id'], run_id=admitted['run_id'], status='succeeded',
                kind='tasks.dependency_merge', dependency_update='acme/library 1.2.3 -> 1.2.4', packagist_attestation=True, squash_puts=1, same_root_recovery=True, real_gateway_https=True,
                exact_worker_placement=True, total_ms=round((time.monotonic() - start) * 1000),
                scope='isolated SQLite/dev worker; disposable GitHub HTTPS and configuration seams; no live fleet or repository')
            print(json.dumps(proof))
        finally:
            if proxy: proxy.shutdown(); proxy.server_close()
            for process in reversed(processes):
                if process.poll() is None:
                    process.terminate()
                    try: process.wait(timeout=5)
                    except subprocess.TimeoutExpired: process.kill(); process.wait(timeout=5)
            for log in logs: log.close()
            if initialized: php('scan')


if __name__ == '__main__':
    unittest.main()
