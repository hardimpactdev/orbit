"""Actual native remote delivery, vault env and command outputs; no Gateway or host effects."""
import base64
import hashlib
import json
import os
import shutil
import socket
import subprocess
import time
import unittest
import urllib.request
from pathlib import Path

from native_harness import ROOT, NativeTestCase


def reply(raw):
    for index, character in enumerate(raw):
        if character in '{[':
            try:
                return json.loads(raw[index:])
            except ValueError:
                pass
    raise AssertionError('Native JSON missing')


class BootstrapScriptNativeTest(NativeTestCase):
    native_timeout = 120

    def test_remote_assets_vault_environment_and_terminal_outputs(self):
        family = 'operations/node-bootstrap'
        self.copy_behaviors(family)
        assets = self.repo / 'extensions/behaviors' / family
        fixtures = self.repo / 'fixture-bin'
        shutil.copytree(ROOT / 'tests/fixtures/bootstrap-script', fixtures)
        for path in fixtures.iterdir():
            path.chmod(0o700)
        # Replace executable constants only in the disposable copy. All native dispatch,
        # asset bundling, vault evaluation, shell and shipped script logic remain real.
        for name, executable in [('scan_host_key.py', 'ssh-keyscan.py'), ('probe_host.py', 'ssh.py')]:
            path = assets / 'scripts' / name
            source = path.read_text().replace("'/usr/bin/ssh-keyscan'", repr(str(fixtures / executable)))
            source = source.replace("'/usr/bin/ssh'", repr(str(fixtures / executable)))
            path.write_text(source)
        shutil.copy(ROOT / 'workflows/workflow-operation-node-bootstrap-probe.yaml', self.repo / 'workflows')
        self.identity = 'synthetic-remote-key-' + os.urandom(16).hex()
        self.secrets = (self.identity,)
        self.native_env = {'FIXTURE_KEY_SHA256': hashlib.sha256(self.identity.encode()).hexdigest()}
        self.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        self.cli('vault', 'put', 'orbit-control-inputs', 'bootstrap-identity-node-209', input=self.identity)
        self.cli('access', 'token', 'mint', 'fixture-admin', '--principal', 'user:fixture')
        admin = self.repo / 'admin'
        admin.write_text(reply(self.cli('access', 'token', 'reveal', 'fixture-admin', '--yes').stdout)['token'])
        admin.chmod(0o600)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        url = f'ws://127.0.0.1:{port}'
        cli = os.environ.get('SWAMP_BIN', 'swamp')
        env = dict(os.environ, **self.native_env, SWAMP_NO_TELEMETRY='1', SWAMP_NO_UPDATE_CHECK='1')
        for key in ('SWAMP_SERVER_URL', 'SWAMP_SERVE_URL', 'SWAMP_SERVER_TOKEN', 'SWAMP_SERVER_TOKEN_FILE',
                    'SWAMP_REPO_DIR', 'SWAMP_CA_CERT', 'SWAMP_EXTENSIONS_DIR'):
            env.pop(key, None)
        serve = worker = None
        with (self.repo / 'serve.log').open('w') as log, (self.repo / 'worker.log').open('w') as worker_log:
            try:
                serve = subprocess.Popen([cli, 'serve', '--host', '127.0.0.1', '--port', str(port),
                                          '--auth-mode', 'token', '--admins', 'user:fixture', '--remote-only'],
                                         cwd=self.repo, env=env, stdout=log, stderr=log)
                for _ in range(100):
                    try:
                        urllib.request.urlopen(f'http://127.0.0.1:{port}/ready', timeout=1).close()
                        break
                    except OSError:
                        self.assertIsNone(serve.poll(), (self.repo / 'serve.log').read_text()[-2000:])
                        time.sleep(.1)
                flags = ('--server', url, '--token-file', str(admin))
                enrollment = self.repo / 'enrollment'
                enrollment.write_text(reply(self.cli('worker', 'token', 'create', 'orbit-node-209',
                                                    '--duration', '10m', *flags).stdout)['token'])
                enrollment.chmod(0o600)
                self.cli('access', 'token', 'mint', 'fixture-worker', '--principal', 'worker:orbit-node-209', *flags)
                token = self.repo / 'worker-server'
                token.write_text(reply(self.cli('access', 'token', 'reveal', 'fixture-worker', '--yes').stdout)['token'])
                token.chmod(0o600)
                worker = subprocess.Popen([cli, 'worker', 'connect', url, '--token-file', str(enrollment),
                                           '--server-token-file', str(token), '--cache-dir', str(self.repo / 'worker-cache'),
                                           '--label', 'node=209'], cwd=self.repo, env=env, stdout=worker_log, stderr=worker_log)
                fingerprint = 'SHA256:' + base64.b64encode(hashlib.sha256(b'public-host-key').digest()).decode().rstrip('=')
                public = {'operation_id': 7001, 'node_id': 209, 'kind': 'node.bootstrap_probe',
                          'intent': {'bootstrap': {'node_id': 23, 'platform': 'linux', 'expected_architecture': 'x86_64',
                                                  'target': {'node_id': 23, 'host': 'dev.example', 'port': 2222,
                                                             'user': 'candidate', 'fingerprint': fingerprint}}},
                          'execution_secrets': {'bootstrap_identity': {'vault': 'orbit-control-inputs',
                                                                       'key': 'bootstrap-identity-node-209'}}}
                result = reply(self.cli('workflow', 'run', 'operation-node-bootstrap-probe',
                                        '--input', json.dumps(public), *flags).stdout)
                self.assertEqual(result['status'], 'succeeded', result)
                history = reply(self.cli('workflow', 'history', 'get', result['id'], *flags).stdout)
                self.assertEqual(history['inputs'], public)
                steps = history['jobs'][0]['steps']
                self.assertEqual([step['name'] for step in steps], ['assets', 'scan-host-key', 'probe-host'])
                self.assertTrue(all(step['status'] == 'succeeded' for step in steps))
                outputs = steps[-1]['outputs']
                self.assertEqual(json.loads(outputs['stdout']), {'probed': True, 'architecture': 'x86_64',
                    'host_type': 'ssh-ed25519', 'public_key': base64.b64encode(b'public-host-key').decode(),
                    'host_key_fingerprint': fingerprint})
                self.assertEqual(outputs['exitCode'], 0)
                # The behavior's additionalFiles must have reached the actual worker cache.
                self.assertIn('worker-cache', steps[0]['outputs']['probe_script'])
                self.assertTrue(Path(steps[0]['outputs']['probe_script']).is_file())
                self.assert_private_absent(json.dumps(history))
                evidence = os.environ.get('BOOTSTRAP_NATIVE_EVIDENCE')
                if evidence:
                    Path(evidence).write_text(json.dumps(history, indent=2) + '\n')
                # A bad immutable fingerprint fails at the public scan. The next step
                # is skipped even when its referenced vault key does not exist.
                public['operation_id'] = 7002
                public['intent']['bootstrap']['target']['fingerprint'] = 'SHA256:' + 'A' * 43
                public['execution_secrets']['bootstrap_identity']['key'] = 'bootstrap-identity-node-210'
                failed = reply(self.cli('workflow', 'run', 'operation-node-bootstrap-probe',
                                        '--input', json.dumps(public), *flags, success=False).stdout)
                failure = reply(self.cli('workflow', 'history', 'get', failed['id'], *flags).stdout)
                self.assertEqual(failure['status'], 'failed')
                self.assertEqual(failure['jobs'][0]['steps'][1]['status'], 'failed')
                self.assertEqual(failure['jobs'][0]['steps'][2]['status'], 'skipped')
            finally:
                for process in (worker, serve):
                    if process is not None:
                        process.terminate()
                        try:
                            process.wait(timeout=10)
                        except subprocess.TimeoutExpired:
                            process.kill()
                            process.wait(timeout=5)


if __name__ == '__main__':
    unittest.main()
