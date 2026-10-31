"""Disposable CE 3.2.1 + Gateway SQLite/TLS + exact placed native workers.

Run with ANALYTICS_TEST_GATEWAY_ROOT, SWAMP_BIN and DENO_BIN. Private values
originate only in PHP memory and encrypted fixture settings. No fleet connection.
"""
import base64
import http.client
import http.server
import json
import os
from pathlib import Path
import shutil
import socket
import ssl
import subprocess
import sys
import threading
import time
import unittest
import urllib.request
import uuid
import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


def free_port():
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        return listener.getsockname()[1]


class AnalyticsRealHttp(NativeTestCase):
    native_timeout = 80

    @unittest.skipUnless(os.environ.get('ANALYTICS_TEST_GATEWAY_ROOT'), 'requires isolated Gateway checkout')
    def test_enable_disable_with_actual_provider_and_shared_private_source(self):
        gateway = Path(os.environ['ANALYTICS_TEST_GATEWAY_ROOT'])
        scratch = self.repo
        family = ROOT / 'extensions/behaviors/operations/analytics'
        for name in ('storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions',
                     'storage/framework/views', 'orbit-home/ca', 'host-home', 'host-state'):
            (scratch / name).mkdir(parents=True, exist_ok=True)
        (scratch / 'gateway.sqlite').touch()
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/analytics', 'operations/routes', '_lib')
        shutil.copy(ROOT / 'extensions/behaviors/native_host_method.ts', scratch / 'extensions/behaviors/native_host_method.ts')
        serve_port, php_port, tls_port, peer_port, backend_port = [free_port() for _ in range(5)]
        backend = 'orbit-analytics-canary-' + uuid.uuid4().hex[:12]
        network = backend + '-net'
        cert, key, leaf = scratch / 'cert.pem', scratch / 'key.pem', scratch / 'leaf.pem'
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:4096', '-nodes', '-keyout', str(scratch / 'ca.key'),
            '-out', str(cert), '-days', '1', '-subj', '/CN=Isolated Analytics fixture root',
            '-addext', 'basicConstraints=critical,CA:TRUE', '-addext', 'keyUsage=critical,keyCertSign,cRLSign'],
            check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=15)
        subprocess.run(['openssl', 'req', '-newkey', 'rsa:2048', '-nodes', '-keyout', str(key),
            '-out', str(scratch / 'leaf.csr'), '-subj', '/CN=swamp.orbit'],
            check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=15)
        (scratch / 'leaf.ext').write_text('basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\n'
            'extendedKeyUsage=serverAuth\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid,issuer\n'
            'subjectAltName=DNS:swamp.orbit,DNS:localhost,IP:127.0.0.1\n')
        subprocess.run(['openssl', 'x509', '-req', '-in', str(scratch / 'leaf.csr'), '-CA', str(cert),
            '-CAkey', str(scratch / 'ca.key'), '-CAcreateserial', '-out', str(leaf), '-days', '1', '-extfile', str(scratch / 'leaf.ext')],
            check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=15)
        shutil.copy(cert, scratch / 'orbit-home/ca/root.pem')
        shutil.copy(scratch / 'ca.key', scratch / 'orbit-home/ca/root.key')
        (scratch / 'agent-delivery.json').write_text(json.dumps({'commit': 'a' * 40, 'version': '0.5.0', 'artifacts': {'linux-x86_64': {'sha256': 'a' * 64}}}))
        self.native_env.update(SWAMP_HOME=str(scratch / 'native-home'), APP_ENV='testing', APP_DEBUG='false',
            APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(), DB_CONNECTION='sqlite',
            DB_DATABASE=str(scratch / 'gateway.sqlite'), DATABASE_URL='', DB_URL='', CACHE_STORE='array',
            SESSION_DRIVER='array', QUEUE_CONNECTION='sync', BROADCAST_CONNECTION='log', MAIL_MAILER='array',
            APP_CONFIG_CACHE=str(scratch / 'config.php'), APP_ROUTES_CACHE=str(scratch / 'routes.php'),
            APP_SERVICES_CACHE=str(scratch / 'services.php'), APP_PACKAGES_CACHE=str(scratch / 'packages.php'),
            ORBIT_HOME=str(scratch / 'orbit-home'), ANALYTICS_TEST_SCRATCH=str(scratch),
            ANALYTICS_TEST_GATEWAY_ROOT=str(gateway), ANALYTICS_TEST_SWAMP_PORT=str(serve_port),
            ANALYTICS_TEST_BACKEND=backend, ANALYTICS_TEST_NETWORK=network, ANALYTICS_TEST_BACKEND_PORT=str(backend_port),
            SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1')
        env = dict(os.environ, **self.native_env)
        for name in ('SWAMP_SERVER_URL', 'SWAMP_SERVE_URL', 'SWAMP_SERVER_TOKEN', 'SWAMP_SERVER_TOKEN_FILE', 'SWAMP_REPO_DIR', 'SWAMP_CA_CERT'):
            env.pop(name, None)
        helper = family / 'tests/gateway.php'

        def php(mode):
            output = subprocess.run(['php', str(helper), mode], cwd=gateway, env=env, text=True, capture_output=True, timeout=80)
            try:
                result = json.loads(output.stdout)
            except ValueError:
                self.fail('Isolated Gateway returned invalid public fixture result')
            self.assertEqual(output.returncode, 0, result)
            return result

        def docker(*args, check=True):
            return subprocess.run(['docker', *args], text=True, capture_output=True, check=check, timeout=50)

        processes, logs, proxies, containers = [], [], [], []
        initialized = False
        try:
            php('migrate'); php('init'); initialized = True
            docker('network', 'create', '--internal', network)
            for name, image, options in (
                (backend + '-pg', 'postgres:16-alpine', ['--network-alias', 'postgres', '-e', 'POSTGRES_HOST_AUTH_METHOD=trust']),
                (backend + '-ch', 'clickhouse/clickhouse-server:24.12-alpine', ['--network-alias', 'clickhouse', '-e', 'CLICKHOUSE_SKIP_USER_SETUP=1']),
            ):
                docker('run', '-d', '--name', name, '--network', network, *options, image)
                containers.append(name)
            until = time.monotonic() + 30
            while docker('exec', containers[0], 'pg_isready', '-U', 'postgres', check=False).returncode:
                self.assertLess(time.monotonic(), until, 'Disposable PostgreSQL readiness'); time.sleep(.2)
            containers.append(backend)
            php('backend-start')
            backend_address = docker('inspect', backend, '--format', '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}').stdout.strip()
            backend_url = f'http://{backend_address}:8000'
            env['NO_PROXY'] = env['no_proxy'] = '127.0.0.1,localhost,' + backend_address
            until = time.monotonic() + 100
            while True:
                try:
                    urllib.request.build_opener(urllib.request.ProxyHandler({})).open(backend_url + '/api/health', timeout=1).close()
                    break
                except OSError:
                    if time.monotonic() >= until:
                        # Provider errors may contain private data: retain them only for the final scan.
                        (scratch / 'provider.log').write_text(docker('exec', backend, 'cat', '/tmp/provider.log', check=False).stdout)
                        self.fail('Disposable Plausible readiness failed; protected provider log retained for scan')
                    time.sleep(.3)
            php('backend-init')
            print(json.dumps({'actual_plausible_ready': True, 'scratch': str(scratch)}), flush=True)
            self.prepare_native(scratch, cert, tls_port, peer_port, backend_url)
            for label, args, cwd in (
                ('serve', [os.environ.get('SWAMP_BIN', 'swamp'), 'serve', '--host', '127.0.0.1', '--port', str(serve_port),
                    '--cert-file', str(leaf), '--key-file', str(key), '--auth-mode', 'none', '--remote-only', '--no-schedule', '--dispatch-env-allow=,'], scratch),
                ('gateway', ['php', '-S', f'127.0.0.1:{php_port}', str(helper)], gateway),
            ):
                log = open(scratch / (label + '.log'), 'wb'); logs.append(log)
                processes.append(subprocess.Popen(args, cwd=cwd, env=env, stdout=log, stderr=subprocess.STDOUT))

            def relay(participant):
                class Relay(http.server.BaseHTTPRequestHandler):
                    protocol_version = 'HTTP/1.1'
                    def log_message(self, *args): pass
                    def forward(self):
                        connection = http.client.HTTPConnection('127.0.0.1', php_port, timeout=20)
                        body = self.rfile.read(int(self.headers.get('Content-Length', 0)))
                        headers = dict(self.headers)
                        headers['X-Analytics-Fixture-Participant'] = str(participant)
                        connection.request(self.command, self.path, body, headers)
                        response = connection.getresponse(); content = response.read()
                        self.send_response(response.status)
                        for name, value in response.getheaders():
                            if name.lower() not in ('connection', 'transfer-encoding', 'content-length'):
                                self.send_header(name, value)
                        self.send_header('Content-Length', str(len(content))); self.end_headers()
                        self.wfile.write(content); connection.close()
                    do_GET = do_POST = do_PUT = do_DELETE = forward
                return Relay
            for port, node in ((tls_port, 209), (peer_port, 210)):
                proxy = http.server.ThreadingHTTPServer(('127.0.0.1', port), relay(node))
                tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); tls.load_cert_chain(leaf, key)
                proxy.socket = tls.wrap_socket(proxy.socket, server_side=True)
                threading.Thread(target=proxy.serve_forever, daemon=True).start(); proxies.append(proxy)
            context = ssl.create_default_context(cafile=str(cert))
            until = time.monotonic() + 30
            while True:
                try:
                    urllib.request.urlopen(f'https://127.0.0.1:{serve_port}/health', context=context, timeout=1).close()
                    urllib.request.urlopen(f'https://127.0.0.1:{tls_port}/up', context=context, timeout=1).close()
                    break
                except OSError:
                    if processes[0].poll() is not None:
                        php('scan')
                        self.fail('Isolated serve failed: ' + (scratch / 'serve.log').read_text()[-5000:])
                    self.assertLess(time.monotonic(), until, 'Isolated TLS services readiness'); time.sleep(.1)
            flags = ('--server', f'wss://127.0.0.1:{serve_port}', '--ca-cert', str(cert))
            for node in (209, 210):
                token = json.loads(self.cli('worker', 'token', 'create', 'orbit-node-' + str(node), '--duration', '1h', *flags).stdout)['token']
                enrollment = scratch / ('enrollment-' + str(node)); enrollment.write_text(token); enrollment.chmod(0o600)
                log = open(scratch / ('worker-' + str(node) + '.log'), 'wb'); logs.append(log)
                processes.append(subprocess.Popen([os.environ.get('SWAMP_BIN', 'swamp'), 'worker', 'connect',
                    f'wss://127.0.0.1:{serve_port}', '--token-file', str(enrollment), '--ca-cert', str(cert),
                    '--cache-dir', str(scratch / ('worker-cache-' + str(node))), '--label', 'node=' + str(node), '--no-telemetry'],
                    cwd=scratch, env=dict(env, SWAMP_HOME=str(scratch / ('worker-home-' + str(node))),
                        SWAMP_CONFIG_DIR=str(scratch / ('worker-config-' + str(node)))), stdout=log, stderr=subprocess.STDOUT))
            until = time.monotonic() + 30
            while len([w for w in json.loads(self.cli('worker', 'list', *flags).stdout)['workers']
                       if w.get('tokenName') in ('orbit-node-209', 'orbit-node-210') and w.get('status') == 'idle']) != 2:
                self.assertLess(time.monotonic(), until, 'Exact fixture worker placement'); time.sleep(.1)
            admitted = []
            for mode in ('enable', 'disable'):
                operation = php(mode); admitted.append(operation)
                self.assertTrue(operation['run_id'])
                until = time.monotonic() + 65
                while True:
                    history = json.loads(self.cli('workflow', 'history', 'get', operation['run_id'], *flags).stdout)
                    state = php('inspect')
                    if history.get('status') in ('failed', 'completed', 'succeeded', 'cancelled'):
                        break
                    self.assertLess(time.monotonic(), until, state); time.sleep(.2)
                self.assertEqual(state['operations'][-1]['status'], 'succeeded', {'state': state, 'provider_custody': php('backend-observe') if state['operations'][-1]['status'] != 'succeeded' else {}, 'errors': [(job['name'], step.get('error')) for job in history.get('jobs', []) for step in job['steps'] if step.get('error')], 'provider': (scratch / 'host-state/provider.jsonl').read_text() if (scratch / 'host-state/provider.jsonl').exists() else ''})
                if mode == 'enable':
                    self.assertTrue(state['binding_present']); self.assertEqual(state['tracking_count'], 1)
                    self.assertTrue(php('backend-manual-goal')['manual_goal_added'])
                    event = urllib.request.Request(backend_url + '/api/event',
                        data=json.dumps({'name': 'pageview', 'url': 'https://analytics-canary.example.test/',
                            'domain': 'analytics-canary.example.test'}).encode(),
                        headers={'Content-Type': 'application/json', 'User-Agent': 'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0',
                            'X-Forwarded-For': '203.0.113.7'})
                    until = time.monotonic() + 65
                    while True:
                        with urllib.request.build_opener(urllib.request.ProxyHandler({})).open(event, timeout=5) as response:
                            self.assertEqual(response.status, 202)
                            if response.headers.get('x-plausible-dropped') is None: break
                        self.assertLess(time.monotonic(), until, php('backend-ingest-observe'))
                        time.sleep(1)
                    until = time.monotonic() + 20
                    while True:
                        history_rows = int(docker('exec', backend + '-ch', 'clickhouse-client', '--query',
                            'SELECT count() FROM plausible_events_db.events_v2').stdout.strip())
                        if history_rows: break
                        self.assertLess(time.monotonic(), until, 'Disposable provider pageview ingestion'); time.sleep(.5)
                else:
                    self.assertFalse(state['binding_present']); self.assertEqual(state['tracking_count'], 0)
            retained = php('backend-inspect')
            self.assertTrue(retained['site_retained']); self.assertTrue(retained['manual_goal_retained'])
            self.assertEqual(int(docker('exec', backend + '-ch', 'clickhouse-client', '--query',
                'SELECT count() FROM plausible_events_db.events_v2').stdout.strip()), history_rows)
            requests = [json.loads(row) for row in (scratch / 'http.jsonl').read_text().splitlines()]
            private = [row for row in requests if row['path'].endswith('/private-material')]
            self.assertEqual(len(private), 1)
            self.assertFalse(any(row['path'].endswith(('/primitive', '/secret-output')) for row in requests))
            self.assertTrue(all(row['status'] == 200 and row['bound'] and row['caller'] == '127.0.0.1'
                and row['run_id'] == admitted[0]['run_id'] and 'no-store' in row['cache_control'] for row in private))
            self.assertTrue(any(row['caller'] == '127.0.0.210' and row['status'] == 200 for row in requests))
            for process in reversed(processes): process.terminate(); process.wait(timeout=5)
            for log in logs: log.close()
            (scratch / 'provider.log').write_text(docker('exec', backend, 'cat', '/tmp/provider.log', check=False).stdout)
            proof = php('scan')
            proof.update(operations=admitted, actual_plausible_ce='3.2.1', provider_site_and_manual_goal_retained=True, provider_history_rows_retained=history_rows,
                real_gateway_https=True, exact_worker_placement=True,
                limits='private tracking route; loopback caller mapping; disposable CA custody; scratch Caddy/DNS/LAN seams; public Ingress and firewall unexercised; no production or fleet effects')
            print(json.dumps(proof), flush=True)
        finally:
            for proxy in proxies: proxy.shutdown(); proxy.server_close()
            for process in reversed(processes):
                if process.poll() is None:
                    process.terminate()
                    try: process.wait(timeout=5)
                    except subprocess.TimeoutExpired: process.kill(); process.wait(timeout=5)
            for log in logs: log.close()
            subprocess.run(['sudo', '-n', 'chown', '-R', f'{os.getuid()}:{os.getgid()}', str(scratch / 'host-state')], check=True, capture_output=True, timeout=15)
            try:
                if initialized: php('scan')
            finally:
                for container in reversed(containers): docker('rm', '-f', '-v', container, check=False)
                docker('network', 'rm', network, check=False)

    def prepare_native(self, scratch, cert, root_port, participant_port, backend_port):
        self.copy_behaviors('operations/certificates')
        for mode in ('enable', 'disable'):
            source = ROOT / 'workflows' / ('workflow-operation-analytics-' + mode + '-direct.yaml')
            workflow = yaml.safe_load(source.read_text())
            for job in workflow['jobs']:
                for step in job['steps']:
                    self.assertIn('target', step)
                    self.assertIn('node', step['labels'])
                    task = step['task']
                    port = root_port if task['behaviorType'] in ('@nckrtl/analytics-lifecycle', '@nckrtl/certificates') else participant_port
                    task['globalArgs'] = {'gatewayUrl': f'https://127.0.0.1:{port}', 'caFile': str(cert)}
            (scratch / 'workflows' / source.name).write_text(yaml.safe_dump(workflow, sort_keys=False))
        base = scratch / 'extensions/behaviors/operations'
        lifecycle = base / 'analytics/lifecycle.ts'
        text = lifecycle.read_text()
        text = 'import { fixtureProvider } from "./tests/effects.ts";\n' + text
        text = text.replace('export const behavior = analyticsLifecycle();', 'export const behavior = analyticsLifecycle(undefined, fixtureProvider);')
        lifecycle.write_text(text)
        # Run the shipped local certificate program with only host paths, trust-store mutation,
        # caddy group and reload substituted. OpenSSL generation/signature/publish/inspect/remove stay real.
        system = base / 'certificates/system.ts'
        text = system.read_text().replace("P('/etc/caddy/orbit-certificates')", "P(" + repr(str(scratch / 'host-state/certificates')) + ")")
        start = text.index('def trust(root, expected, cert):')
        end = text.index('def valid(', start)
        text = text[:start] + "def trust(root, expected, cert):\n    if digest(root) != expected: raise ValueError('certificate.leaf_failed')\n    run('openssl','verify','-CAfile',root,cert)\n" + text[end:]
        text = text.replace("grp.getgrnam('caddy').gr_gid", "pwd.getpwnam(data['user']).pw_gid")
        text = text.replace("if subprocess.run(['systemctl','is-active','--quiet','caddy'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode==0:", "if False:")
        system.write_text(text)
        # Canonical publication still does its fresh GET, binding check, digest checks and model lock.
        # Only machine-global Caddy/DNS/firewall effects write disposable scratch state instead.
        caddy = base / 'routes/caddy.ts'
        text = caddy.read_text().replace('import { runLocalProgram } from "./local-program.ts";', 'import { fixtureCaddySystem as runLocalProgram } from "../analytics/tests/effects.ts";')
        caddy.write_text(text)
        dns = base / 'routes/dns.ts'
        text = 'import { fixtureDns } from "../analytics/tests/effects.ts";\n' + dns.read_text()
        text = text.replace('invoke = runPrivateDns', 'invoke = fixtureDns')
        dns.write_text(text)
        publication = base / 'routes/publication.ts'
        text = 'import { fixtureDns } from "../analytics/tests/effects.ts";\n' + publication.read_text()
        text = text.replace('invokeDns = runPrivateDns', 'invokeDns = fixtureDns')
        publication.write_text(text)
        hosts = base / 'analytics/hosts.ts'
        text = 'import { fixtureLan } from "./tests/effects.ts";\n' + hosts.read_text()
        text = text.replace('export const behavior = analyticsCertificates();', 'export const behavior = analyticsCertificates(undefined, undefined, undefined, fixtureLan);')
        hosts.write_text(text)
        certificates = base / 'certificates/mod.ts'
        text = 'import { fixtureCustody } from "../analytics/tests/effects.ts";\n' + certificates.read_text()
        certificates.write_text(text.replace('custody(ctx)', 'fixtureCustody()'))
        effects = base / 'analytics/tests/effects.ts'
        text = effects.read_text().replace('__BACKEND_URL__', backend_port).replace('__SCRATCH__', str(scratch))
        effects.write_text(text)


if __name__ == '__main__': unittest.main()
