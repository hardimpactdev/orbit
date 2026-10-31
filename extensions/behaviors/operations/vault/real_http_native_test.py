"""Disposable Vaultwarden + isolated Gateway + real TLS + placed native worker.

No fleet connection, production settings, or plaintext fixture credentials.
Run with SWAMP_BIN and VAULT_TEST_GATEWAY_ROOT set to the reviewed checkouts.
"""
import base64
import json
import os
from pathlib import Path
import pwd
import socket
import socketserver
import ssl
import subprocess
import sys
import threading
import time
import unittest
import urllib.request

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

IMAGE = 'vaultwarden/server@sha256:84fd8a47f58d79a1ad824c27be0a9492750c0fa5216b35c749863093bfa3c3d7'
WORKER_IMAGE = 'orbit-lane23-vault-worker:1602a617'


def free_port():
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        return listener.getsockname()[1]


class VaultRealHttp(NativeTestCase):
    native_timeout = 40

    @unittest.skipUnless(os.environ.get('VAULT_TEST_GATEWAY_ROOT'), 'requires an isolated Gateway checkout')
    def test_real_operation_keeps_private_material_out_of_native_scratch(self):
        action = os.environ.get('VAULT_TEST_ACTION', 'sync')
        self.assertIn(action, ('sync', 'setup', 'teardown'))
        shared_refusal = action == 'teardown' and os.environ.get('VAULT_TEST_SHARED_ITEM') == '1' and os.environ.get('VAULT_TEST_KEEP_ITEMS') != '1'
        expected_status = 'failed' if shared_refusal else 'succeeded'
        backend = {'sync': 'synced', 'setup': 'configured', 'teardown': 'absent'}[action]
        expected_items = 2 if shared_refusal else 1 if action == 'sync' else 0
        gateway = Path(os.environ['VAULT_TEST_GATEWAY_ROOT'])
        scratch = self.repo
        (scratch / 'worker-tmp').mkdir()
        (scratch / 'gateway.sqlite').touch()
        for name in ('logs', 'framework/cache/data', 'framework/sessions', 'framework/views'):
            (scratch / 'storage' / name).mkdir(parents=True, exist_ok=True)
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/vault')
        backend_port, serve_port, php_port, tls_port, backend_tls_port = [free_port() for _ in range(5)]
        fixture_host = 'vault-fixture.orbit'
        cert, key = scratch / 'cert.pem', scratch / 'key.pem'
        leaf = scratch / 'leaf.pem'
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
                        '-keyout', str(scratch / 'ca.key'), '-out', str(cert), '-days', '1',
                        '-subj', '/CN=Isolated Vault fixture root', '-addext', 'basicConstraints=critical,CA:TRUE',
                        '-addext', 'keyUsage=critical,keyCertSign,cRLSign'],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=15)
        subprocess.run(['openssl', 'req', '-newkey', 'rsa:2048', '-nodes', '-keyout', str(key),
                        '-out', str(scratch / 'leaf.csr'), '-subj', '/CN=swamp.orbit'],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=15)
        extensions = scratch / 'leaf.ext'
        extensions.write_text('basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\n'
                              'extendedKeyUsage=serverAuth\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid,issuer\n'
                              'subjectAltName=DNS:swamp.orbit,DNS:localhost,DNS:vault-fixture.orbit,IP:127.0.0.1\n')
        subprocess.run(['openssl', 'x509', '-req', '-in', str(scratch / 'leaf.csr'), '-CA', str(cert),
                        '-CAkey', str(scratch / 'ca.key'), '-CAcreateserial', '-out', str(leaf),
                        '-days', '1', '-extfile', str(extensions)],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=15)
        context = ssl.create_default_context(cafile=str(cert))
        source = (ROOT / 'workflows/workflow-operation-vault-direct.yaml').read_text()
        source = source.replace('            caFile: /usr/local/share/ca-certificates/orbit-root.crt',
                                f'            caFile: {cert}\n            gatewayUrl: https://127.0.0.1:{tls_port}')
        (scratch / 'workflows/workflow-operation-vault-direct.yaml').write_text(source)
        self.native_env.update(SWAMP_HOME=str(scratch / 'native-home'),
            APP_ENV='testing', APP_DEBUG='false', APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(),
            DB_CONNECTION='sqlite', DB_DATABASE=str(scratch / 'gateway.sqlite'), DATABASE_URL='', DB_URL='',
            CACHE_STORE='array', SESSION_DRIVER='array', QUEUE_CONNECTION='sync', BROADCAST_CONNECTION='log',
            MAIL_MAILER='array', APP_CONFIG_CACHE=str(scratch / 'config.php'),
            APP_ROUTES_CACHE=str(scratch / 'routes.php'), APP_SERVICES_CACHE=str(scratch / 'services.php'),
            APP_PACKAGES_CACHE=str(scratch / 'packages.php'), ORBIT_HOME=str(scratch / 'orbit-home'),
            VAULT_TEST_SCRATCH=str(scratch), VAULT_TEST_GATEWAY_ROOT=str(gateway),
            VAULT_TEST_ACTION=action,
            VAULT_TEST_SWAMP_PORT=str(serve_port),
            VAULT_TEST_BACKEND_SEED_URL=f'http://127.0.0.1:{backend_port}',
            VAULT_TEST_BACKEND_URL=f'https://{fixture_host}:{backend_tls_port}',
            SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1')
        env = dict(os.environ, **self.native_env)
        for name in ('SWAMP_SERVER_URL', 'SWAMP_SERVE_URL', 'SWAMP_SERVER_TOKEN',
                     'SWAMP_SERVER_TOKEN_FILE', 'SWAMP_REPO_DIR', 'SWAMP_CA_CERT'):
            env.pop(name, None)
        helper = ROOT / 'extensions/behaviors/operations/vault/tests/gateway.php'

        def php(mode):
            result = subprocess.run(['php', str(helper), mode], cwd=gateway, env=env,
                                    capture_output=True, text=True, timeout=50)
            try:
                value = json.loads(result.stdout)
            except ValueError:
                diagnostics = []
                for line in result.stdout.splitlines():
                    try:
                        row = json.loads(line)
                        if isinstance(row, dict) and row.get('refused') is True:
                            diagnostics.append({key: row[key] for key in ('phase', 'category', 'file', 'line', 'code', 'type') if key in row})
                    except ValueError:
                        pass
                self.fail('Isolated Gateway omitted its result: ' + json.dumps(diagnostics))
            self.assertEqual(result.returncode, 0, value)
            return value

        name = 'orbit-lane23-vault-' + scratch.name.removeprefix('orbit-native-')
        processes, logs = [], []
        proxies = []
        seeded = scanned = False
        # Backend data stays in the disposable server's tmpfs, never in captured scratch.
        try:
            started = subprocess.run(['docker', 'run', '--detach', '--rm', '--name', name,
                '--user', '65534:65534', '--cap-drop=ALL', '--security-opt=no-new-privileges:true', '--read-only',
                '--tmpfs', '/data:rw,noexec,nosuid,size=64m,uid=65534,gid=65534,mode=0700',
                '--publish', f'127.0.0.1:{backend_port}:8080', '--env', 'ROCKET_PORT=8080',
                '--env', 'SIGNUPS_ALLOWED=true', '--env', 'SIGNUPS_VERIFY=false', IMAGE],
                text=True, capture_output=True, timeout=20)
            self.assertEqual(started.returncode, 0, 'Disposable unprivileged Vaultwarden refused')

            def ready(url, tls=None):
                until = time.monotonic() + 25
                while True:
                    try:
                        with urllib.request.urlopen(url, context=tls, timeout=1) as response:
                            self.assertEqual(response.status, 200)
                        return
                    except OSError:
                        self.assertLess(time.monotonic(), until, 'Disposable service readiness')
                        time.sleep(.05)

            ready(f'http://127.0.0.1:{backend_port}/alive')
            php('migrate')
            php('init')
            seeded = True
            for label, arguments, cwd in (
                ('serve', [os.environ.get('SWAMP_BIN', 'swamp'), 'serve', '--host', '127.0.0.1',
                    '--port', str(serve_port), '--cert-file', str(leaf), '--key-file', str(key),
                    '--auth-mode', 'none', '--remote-only', '--no-schedule', '--dispatch-env-allow=,'], scratch),
                ('gateway', ['php', '-S', f'127.0.0.1:{php_port}', str(helper)], gateway),
            ):
                log = open(scratch / (label + '.log'), 'wb'); logs.append(log)
                processes.append(subprocess.Popen(arguments, cwd=cwd, env=env, stdout=log, stderr=subprocess.STDOUT))

            class Relay(socketserver.BaseRequestHandler):
                def handle(self):
                    # Transparent TLS termination; no HTTP/body inspection or logging.
                    import select
                    with socket.create_connection(('127.0.0.1', self.server.backend_port), timeout=5) as backend:
                        while True:
                            available, _, _ = select.select([self.request, backend], [], [], 5)
                            if not available:
                                return
                            for origin in available:
                                block = origin.recv(65536)
                                if not block:
                                    if origin is backend:
                                        # HTTP bodies delimited by EOF require TLS close_notify.
                                        try:
                                            self.request.settimeout(5)
                                            self.request.unwrap()
                                        except (OSError, ssl.SSLError):
                                            pass
                                    return
                                (backend if origin is self.request else self.request).sendall(block)

            class RelayServer(socketserver.ThreadingTCPServer):
                allow_reuse_address = True
                daemon_threads = True

            server_context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
            server_context.load_cert_chain(leaf, key)
            for address, listen_port, destination_port in (
                ('127.0.0.1', tls_port, php_port), ('127.0.0.1', backend_tls_port, backend_port),
            ):
                proxy = RelayServer((address, listen_port), Relay)
                proxy.backend_port = destination_port
                proxy.socket = server_context.wrap_socket(proxy.socket, server_side=True)
                proxies.append(proxy)
                threading.Thread(target=proxy.serve_forever, daemon=True).start()
            ready(f'https://127.0.0.1:{serve_port}/health', context)
            ready(f'https://127.0.0.1:{tls_port}/up', context)
            ready(f'https://127.0.0.1:{backend_tls_port}/alive', context)
            flags = ('--server', f'wss://127.0.0.1:{serve_port}', '--ca-cert', str(cert))
            token = json.loads(self.cli('worker', 'token', 'create', 'orbit-node-9', '--duration', '1h', *flags).stdout)['token']
            enrollment = scratch / 'enrollment'
            enrollment.write_text(token); enrollment.chmod(0o600)
            worker_log = open(scratch / 'worker.log', 'wb'); logs.append(worker_log)
            processes.append(subprocess.Popen(['docker', 'run', '--rm', '--name', name + '-worker',
                '--network', 'host', '--add-host', fixture_host + ':127.0.0.1',
                '--user', f'{os.getuid()}:{os.getgid()}', '--cap-drop=ALL',
                '--security-opt=no-new-privileges:true', '--read-only',
                '--mount', f'type=bind,src={scratch},dst={scratch}', '--workdir', str(scratch),
                '--env', 'SWAMP_CONFIG_DIR=' + str(scratch / 'worker-config'),
                '--env', 'SWAMP_HOME=' + str(scratch / 'worker-home'),
                '--env', 'TMPDIR=' + str(scratch / 'worker-tmp'),
                '--env', 'DENO_DIR=' + str(scratch / 'worker-deno'),
                '--env', 'SWAMP_NO_UPDATE_CHECK=1', '--env', 'SWAMP_NO_TELEMETRY=1',
                '--entrypoint', '/usr/local/bin/swamp', WORKER_IMAGE, 'worker', 'connect',
                f'wss://127.0.0.1:{serve_port}', '--token-file', str(enrollment), '--ca-cert', str(cert),
                '--cache-dir', str(scratch / 'worker-cache'), '--label', 'node=9', '--no-telemetry'],
                cwd=scratch, env=env, stdout=worker_log, stderr=subprocess.STDOUT))
            until = time.monotonic() + 25
            while True:
                workers = json.loads(self.cli('worker', 'list', *flags).stdout)['workers']
                if any(row.get('tokenName') == 'orbit-node-9' and row.get('status') == 'idle' for row in workers):
                    break
                self.assertLess(time.monotonic(), until, 'Exact placed dev worker enrollment')
                time.sleep(.1)
            start = time.monotonic()
            admitted = php('admit')
            self.assertEqual(admitted['writer'], 'direct')
            self.assertTrue(admitted['run_id'])
            admission_ms = round((time.monotonic() - start) * 1000)
            until = time.monotonic() + 35
            diagnosed = False
            while True:
                outcome = php('inspect')
                if outcome['status'] in ('succeeded', 'failed', 'cancelled'):
                    break
                if not diagnosed and time.monotonic() - start > 5 and outcome['status'] == 'queued':
                    diagnosed = True
                    diagnostic = php('diagnose')
                    if diagnostic.get('history', {}).get('status') in ('failed', 'cancelled'):
                        self.fail('Existing dev native root failed: ' + json.dumps(diagnostic))
                if time.monotonic() >= until:
                    self.fail('Existing dev Operation completion: ' + json.dumps(php('diagnose')))
                time.sleep(.1)
            self.assertEqual(outcome['status'], expected_status, outcome)
            if shared_refusal:
                self.assertEqual(outcome['error_code'], 'vault.item_shared')
            else:
                self.assertEqual(outcome['result']['data']['backend'], backend)
            self.assertEqual(outcome['items'], expected_items)
            self.assertEqual(outcome['configured'], shared_refusal or action != 'teardown')
            self.assertEqual(outcome['configuration_matches_fixture'], shared_refusal or action != 'teardown')
            remote = php('verify-backend') if action == 'teardown' else {}
            # Projection can finish before the native run; observe this same root.
            until = time.monotonic() + 5
            while True:
                native_status = php('diagnose')['history']['status']
                if native_status in ('succeeded', 'failed', 'cancelled'):
                    break
                self.assertLess(time.monotonic(), until, 'Existing native root completion')
                time.sleep(.05)
            self.assertEqual(native_status, expected_status)
            requests = [json.loads(line) for line in (scratch / 'http.jsonl').read_text().splitlines()]
            private_reads = [row for row in requests if row['path'].endswith('/private-material') and row['status'] == 200]
            self.assertTrue(private_reads)
            self.assertTrue(all(row['bound'] for row in private_reads))
            self.assertTrue(all('no-store' in row['cache_control'] for row in private_reads))
            stopped = subprocess.run(['docker', 'stop', '--time', '5', name + '-worker'],
                                     capture_output=True, timeout=10)
            self.assertEqual(stopped.returncode, 0, 'Disposable worker stop refused')
            for proxy in proxies:
                proxy.shutdown(); proxy.server_close()
            proxies.clear()
            for process in reversed(processes):
                process.terminate(); process.wait(timeout=5)
            for log in logs:
                log.close()
            proof = php('scan')
            scanned = True
            proof.update(kind='vault.' + action, node_id=9, user=pwd.getpwuid(os.getuid()).pw_name,
                         operation_id=admitted['id'], run_id=admitted['run_id'], admission_ms=admission_ms,
                         total_ms=round((time.monotonic() - start) * 1000), status=expected_status,
                         backend=None if shared_refusal else backend, items=expected_items,
                         real_gateway_https=True, exact_worker_placement=True,
                         remote=remote,
                         scope='isolated dev; not live fleet workflow/grant proof')
            print(json.dumps(proof))
        finally:
            subprocess.run(['docker', 'rm', '--force', name + '-worker'], stdout=subprocess.DEVNULL,
                           stderr=subprocess.DEVNULL, timeout=15)
            for proxy in proxies:
                proxy.shutdown(); proxy.server_close()
            for process in reversed(processes):
                if process.poll() is None:
                    process.terminate()
                    try:
                        process.wait(timeout=5)
                    except subprocess.TimeoutExpired:
                        process.kill(); process.wait(timeout=5)
            for log in logs:
                log.close()
            try:
                if seeded and not scanned:
                    php('scan')
            finally:
                subprocess.run(['docker', 'rm', '--force', name], stdout=subprocess.DEVNULL,
                               stderr=subprocess.DEVNULL, timeout=15)


if __name__ == '__main__':
    unittest.main()
