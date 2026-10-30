"""Pinned Swamp integration: no fleet connections or printed credential output.
Run: SWAMP_BIN=/path/to/pinned/swamp python3 -m unittest discover -s tests -p node_worker_native_test.py
"""
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.request


@unittest.skipUnless(os.environ.get('SWAMP_BIN'), 'requires the pinned native Swamp binary')
class NativeWorkerTest(unittest.TestCase):
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
            command('vault', 'create', 'local_encryption', 'operator-enrollment', '--config',
                    json.dumps({'auto_generate': True, 'key_file': str(root / 'operator.key')}))
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
                name = 'orbit-node-71'
                enrollment = secret_file('enrollment', command('worker', 'token', 'create', name, '--duration', '24h',
                                         '--max-enrollments', '1', '--vault', 'operator-enrollment', *flags)['token'])
                minted = command('model', 'method', 'run', '@swamp/server-token', 'mint', name + '-access',
                                 '--literal-inputs', '--input', json.dumps({'principalId': 'worker:' + name,
                                 'principalEmail': 'fixture@node.invalid', 'durationMs': 86400000,
                                 'vaultName': 'operator-enrollment'}), *flags)
                self.assertEqual(minted['status'], 'succeeded')
                access = secret_file('access', name + '-access.' + command('vault', 'read-secret',
                                     'operator-enrollment', 'server-token-' + name + '-access', '--yes', *flags)['value'])
                worker_args = [binary, 'worker', 'connect', url, '--token-file', enrollment, '--server-token-file', access,
                               '--cache-dir', str(root / 'cache'), '--concurrency=1', '--no-telemetry']
                worker_env = dict(env, SWAMP_CONFIG_DIR=str(root / 'worker-config'))
                worker_env.pop('SWAMP_API_KEY_FILE', None)
                for attempt in range(2):
                    worker = subprocess.Popen(worker_args, cwd=root, env=worker_env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                    processes.append(worker)
                    deadline = time.monotonic() + 20
                    while True:
                        workers = command('worker', 'list', *flags)['workers']
                        if any(item.get('tokenName') == name and item.get('status') == 'idle' for item in workers):
                            break
                        self.assertIsNone(worker.poll(), 'native restart refused')
                        self.assertLess(time.monotonic(), deadline, 'native worker enrollment')
                        time.sleep(.1)
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
                token = next(item for item in command('worker', 'token', 'list', *flags) if item['name'] == name)
                self.assertEqual(token['maxEnrollments'], 1)
                self.assertEqual(len(token['bindings']), 1)
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
