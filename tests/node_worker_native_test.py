"""Pinned Swamp integration: no fleet connections or printed credential output.
Run: SWAMP_BIN=/path/to/pinned/swamp python3 -m unittest discover -s tests -p node_worker_native_test.py
"""
import json
import http.server
import ssl
import threading
import os
import pwd
import shutil
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.request


@unittest.skipUnless(os.environ.get('SWAMP_BIN'), 'requires the pinned native Swamp binary')
class NativeWorkerTest(unittest.TestCase):
    def test_lifecycle_globals_reach_native_plan_and_converge_guards(self):
        with tempfile.TemporaryDirectory(prefix='orbit-worker-global-') as directory:
            root = Path(directory)
            env = {key: value for key, value in os.environ.items() if not key.startswith('SWAMP_')}
            env.update(SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1', SWAMP_HOME=str(root / 'home'),
                       SWAMP_CONFIG_DIR=os.environ.get('SWAMP_CONFIG_DIR', str(Path.home() / '.config/swamp')),
                       SWAMP_API_KEY_FILE=os.environ.get('SWAMP_API_KEY_FILE', str(Path.home() / '.config/swamp/api-key')))
            def command(*args):
                return subprocess.run([os.environ['SWAMP_BIN'], *args, '--json'], cwd=root, env=env,
                                      capture_output=True, text=True, timeout=30)
            self.assertEqual(command('init', str(root)).returncode, 0)
            source = Path(__file__).resolve().parents[1] / 'extensions/behaviors'
            for name in ('node-worker', 'automatic-deploy'):
                shutil.copytree(source / name, root / 'extensions/behaviors' / name)
            requests = []
            class Gateway(http.server.BaseHTTPRequestHandler):
                def log_message(self, *_args):
                    pass
                def do_GET(self):
                    requests.append(self.path)
                    # Both guards refuse before native token commands or any write.
                    data = {'id': 9, 'user': 'nckrtl', 'status': 'active', 'platform': 'linux',
                            'swamp_worker_selected': False}
                    self.send_response(200)
                    self.send_header('Content-Type', 'application/json')
                    self.end_headers()
                    self.wfile.write(json.dumps({'data': data}).encode())
            server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Gateway)
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            try:
                created = command('behavior', 'create', '@nckrtl/node-worker-lifecycle', 'fixture-worker')
                self.assertEqual(created.returncode, 0, 'native lifecycle scaffold')
                model = json.loads(created.stdout)
                path = Path(model['path'])
                definition = {
                    'id': model['id'], 'type': '@nckrtl/node-worker-lifecycle', 'typeVersion': model['version'],
                    'name': 'fixture-worker', 'version': 1,
                    'globalArguments': {'gatewayUrl': f'http://127.0.0.1:{server.server_port}',
                                        'caFile': ssl.get_default_verify_paths().cafile}}
                path.write_text(json.dumps(definition))
                for method, inputs, endpoint in [('plan', [], '/api/v1/nodes'),
                                                  ('converge', ['--input', 'node_id=9'], '/api/v1/nodes/9')]:
                    result = command('behavior', 'run', 'fixture-worker', method, *inputs)
                    self.assertNotEqual(result.returncode, 0, 'fixture must refuse before credentials')
                    self.assertIn(endpoint, requests, 'native method must accept merged globals and reach its guard')
                    self.assertNotIn('Unrecognized keys', result.stdout + result.stderr)
            finally:
                server.shutdown()
                server.server_close()
                thread.join(timeout=5)

    def test_single_machine_crash_restart_and_revoke_by_name(self):
        with tempfile.TemporaryDirectory(prefix='orbit-native-worker-') as directory:
            root = Path(directory)
            env = {key: value for key, value in os.environ.items() if not key.startswith('SWAMP_')}
            env.update(SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1', SWAMP_HOME=str(root / 'home'),
                       SWAMP_CONFIG_DIR=os.environ.get('SWAMP_CONFIG_DIR', str(Path.home() / '.config/swamp')),
                       SWAMP_API_KEY_FILE=os.environ.get('SWAMP_API_KEY_FILE', str(Path.home() / '.config/swamp/api-key')))
            binary = os.environ['SWAMP_BIN']

            def command(*arguments, success=True):
                result = subprocess.run([binary, *arguments, '--json'], cwd=root, env=env,
                                        capture_output=True, text=True, timeout=30)
                self.assertEqual(result.returncode == 0, success, 'native CLI result (diagnostics withheld)')
                if not success:
                    return
                # A first native model mint may emit an auto-created event before its run result.
                for index, char in enumerate(result.stdout):
                    if char not in '[{':
                        continue
                    try:
                        value, end = json.JSONDecoder().raw_decode(result.stdout[index:])
                        if not result.stdout[index + end:].strip():
                            return value
                    except ValueError:
                        pass
                self.fail('native CLI omitted its JSON result')

            def secret_file(name, value):
                path = root / name
                with open(path, 'x', opener=lambda p, flags: os.open(p, flags, 0o600)) as stream:
                    stream.write(value)
                return str(path)

            command('init', str(root))
            source = Path(__file__).resolve().parents[1]
            shutil.copytree(source / 'extensions/behaviors/node-worker', root / 'extensions/behaviors/node-worker')
            shutil.copy(source / 'workflows/workflow-node-worker-canary.yaml', root / 'workflows/workflow-node-worker-canary.yaml')
            identity_file = root / 'root-node-config.toml'
            identity_file.write_text("[swamp_worker]\nnode_id = 71\nuser = \"" + pwd.getpwuid(os.getuid()).pw_name + "\"\n")
            identity_file.chmod(0o644)
            subprocess.run(['/usr/bin/sudo', '-n', '/usr/bin/chown', '0:0', str(identity_file)], check=True, capture_output=True)
            canary_source = root / 'extensions/behaviors/node-worker/canary.ts'
            canary_source.write_text(canary_source.read_text().replace('"/etc/orbit/agent/config.toml"', json.dumps(str(identity_file))))

            command('workflow', 'validate', 'node-worker-canary')
            command('access', 'token', 'mint', 'fixture-admin', '--principal', 'user:fixture')
            admin = secret_file('admin', command('access', 'token', 'reveal', 'fixture-admin', '--yes')['token'])
            with socket.socket() as sock:
                sock.bind(('127.0.0.1', 0))
                port = sock.getsockname()[1]
            url = f'ws://127.0.0.1:{port}'
            flags = ('--server', url, '--token-file', admin)
            processes = []
            try:
                processes.append(subprocess.Popen([binary, 'serve', '--host', '127.0.0.1', '--port', str(port),
                                                    '--auth-mode', 'token', '--admins', 'user:fixture', '--dispatch-env-allow=,'],
                                                   cwd=root, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))
                deadline = time.monotonic() + 20
                while True:
                    try:
                        urllib.request.urlopen(f'http://127.0.0.1:{port}/ready', timeout=1).close()
                        break
                    except OSError:
                        self.assertLess(time.monotonic(), deadline, 'scratch serve readiness')
                        time.sleep(.1)
                for group in ('worker', 'access'):
                    missing = subprocess.run([binary, group, 'token', 'revoke', 'missing-node-token', *flags, '--json'],
                                             cwd=root, env=env, capture_output=True, text=True, timeout=30)
                    self.assertNotEqual(missing.returncode, 0)
                    errors = []
                    for index, char in enumerate(missing.stderr):
                        if char == '{':
                            try:
                                errors.append(json.JSONDecoder().raw_decode(missing.stderr[index:])[0])
                            except ValueError:
                                pass
                    code = group + '_token_revoke_failed'
                    kind = 'Enrollment' if group == 'worker' else 'Server'
                    message = f"Server reported {code}: {kind} token 'missing-node-token' not found. Use 'swamp {group} token list' to see existing tokens."
                    self.assertTrue(any(item.get('code') == code and item.get('error') == message for item in errors), 'native missing-token response')
                name = 'orbit-node-71'
                minted = command('worker', 'token', 'create', name, '--duration', '30d', *flags)['token']
                enrollment = secret_file('enrollment', minted)
                command('access', 'token', 'mint', name + '-access', '--principal', 'worker:' + name, *flags)
                access = secret_file('access', command('access', 'token', 'reveal', name + '-access', '--yes')['token'])
                worker_args = [binary, 'worker', 'connect', url, '--token-file', enrollment, '--server-token-file', access,
                               '--cache-dir', str(root / 'cache'), '--label', 'node=71', '--no-telemetry']
                worker_env = dict(env, SWAMP_CONFIG_DIR=str(root / 'worker-config'))
                worker_env.pop('SWAMP_API_KEY_FILE', None)
                for attempt in range(2):
                    worker = subprocess.Popen(worker_args, cwd=root, env=worker_env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                    processes.append(worker)
                    deadline = time.monotonic() + 20
                    while True:
                        workers = command('worker', 'list', *flags)['workers']
                        if any(item.get('tokenName') == name and item.get('status') == 'idle' for item in workers):
                            matched = next(item for item in workers if item.get('tokenName') == name)
                            self.assertEqual(matched['labels']['node'], '71')
                            self.assertEqual(matched['capacity'], 1)
                            break
                        self.assertIsNone(worker.poll(), 'native restart refused')
                        self.assertLess(time.monotonic(), deadline, 'native worker enrollment')
                        time.sleep(.1)
                    if attempt == 0:
                        canary = command('workflow', 'run', 'node-worker-canary', '--input', 'node_id=71', '--input', 'user=' + pwd.getpwuid(os.getuid()).pw_name, *flags)
                        self.assertEqual(canary['status'], 'succeeded')
                        self.assertTrue(any(artifact.get('name') == 'proof' for job in canary['jobs'] for step in job['steps'] for artifact in step.get('dataArtifacts', [])))
                        command('workflow', 'run', 'node-worker-canary', '--input', 'node_id=71', '--input', 'user=wrong-worker-account', *flags, success=False)
                        subprocess.run(['/usr/bin/sudo', '-n', '/usr/bin/tee', str(identity_file)], input=b'[swamp_worker]\nnode_id = 72\nuser = "nckrtl"\n', stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True)
                        command('workflow', 'run', 'node-worker-canary', '--input', 'node_id=71', '--input', 'user=' + pwd.getpwuid(os.getuid()).pw_name, *flags, success=False)

                    machine = (root / 'cache/machine-id').read_text()
                    if attempt == 0:
                        identity = machine
                        worker.kill()  # Exercise the restart after a crash, not only a clean drain.
                    else:
                        self.assertEqual(machine, identity)
                        worker.terminate()
                    worker.wait(timeout=10)
                    deadline = time.monotonic() + 20
                    while any(item.get('tokenName') == name for item in command('worker', 'list', *flags)['workers']):
                        self.assertLess(time.monotonic(), deadline, 'native worker disconnect')
                        time.sleep(.1)
                    if attempt == 0:
                        command('worker', 'token', 'revoke', name, *flags)
                        replacement = command('worker', 'token', 'create', name, '--duration', '30d', *flags)['token']
                        self.assertNotEqual(replacement, minted, 'native retry re-mints enrollment')
                        (root / 'enrollment').write_text(replacement)
                token = next(item for item in command('worker', 'token', 'list', *flags) if item['name'] == name)
                self.assertEqual(token['maxEnrollments'], 1)
                self.assertEqual(len(token['bindings']), 1)
                expired = command('worker', 'token', 'create', 'orbit-node-revoked', '--duration', '30d', *flags)['token']
                expired_file = secret_file('expired', expired)
                command('worker', 'token', 'revoke', 'orbit-node-revoked', *flags)
                expired_worker = subprocess.run([binary, 'worker', 'connect', url, '--token-file', expired_file,
                    '--server-token-file', access, '--cache-dir', str(root / 'expired-cache')], cwd=root,
                    env=worker_env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=10)
                self.assertNotEqual(expired_worker.returncode, 0, 'dead enrollment must exit for systemd StartLimit')
                revoked = command('access', 'token', 'revoke', name + '-access', *flags)
                self.assertEqual(revoked['state'], 'revoked')
                tokens = command('access', 'token', 'list', *flags)
                self.assertEqual(next(item for item in tokens if item['name'] == name + '-access')['state'], 'revoked')
                command('access', 'token', 'revoke', 'fixture-admin', *flags)
                command('worker', 'list', *flags, success=False)
            finally:
                for process in reversed(processes):
                    if process.poll() is None:
                        process.terminate()
                        try:
                            process.wait(timeout=10)
                        except subprocess.TimeoutExpired:
                            process.kill()
                            process.wait(timeout=5)
