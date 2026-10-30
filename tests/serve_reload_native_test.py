"""Pinned native reload contract; isolated loopback server and no admitted effects."""
import json
import os
from pathlib import Path
import socket
import subprocess
import time
import unittest
import urllib.request

from native_harness import NativeTestCase


@unittest.skipUnless(os.environ.get('SWAMP_BIN'), 'requires the pinned native Swamp binary')
class ServeReloadNativeTest(NativeTestCase):
    def test_reload_keeps_pid_and_refreshes_local_behavior_before_next_run(self):
        self.native_env = {
            'SWAMP_HOME': str(self.repo / 'home'),
            'SWAMP_CONFIG_DIR': os.environ.get('SWAMP_CONFIG_DIR', str(Path.home() / '.config/swamp')),
            'SWAMP_API_KEY_FILE': os.environ.get('SWAMP_API_KEY_FILE', str(Path.home() / '.config/swamp/api-key')),
        }
        # No production retention, credentials, schedules or server connection.
        (self.repo / '.swamp.yaml').write_text(
            'swampVersion: 20261028.205235.0-sha.1602a617\n'
            'repoId: 0ef253a3-8e75-4c0e-a680-e31936096a45\n'
            'telemetryDisabled: true\n')
        source = self.repo / 'extensions/behaviors/probe.ts'
        source.parent.mkdir(parents=True)

        def behavior(version):
            return ('import { z } from "npm:zod@4.4.3";\n'
                    'export const behavior = {type:"@nckrtl/reload-probe",version:"' + version
                    + '",globalArguments:z.object({}),resources:{},methods:{inspect:{'
                    'description:"Probe",arguments:z.object({}),'
                    'execute:async()=>({dataHandles:[]})}}};\n')

        def workflow(description):
            return ('id: f4b456ba-6e1e-4524-8e8e-b445f1d07ea4\nname: reload-probe\n'
                    'description: ' + description + '\nlabels: {pool: migration}\nvaults: []\njobs:\n'
                    '  - name: probe\n    steps:\n      - name: inspect\n        task:\n'
                    '          type: behavior\n          behaviorType: "@nckrtl/reload-probe"\n'
                    '          behaviorName: reload-probe\n          methodName: inspect\n')

        source.write_text(behavior('2026.10.30.1'))
        definition = self.repo / 'workflows/workflow-probe.yaml'
        definition.write_text(workflow('Before reload'))
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        env = {k: v for k, v in os.environ.items() if not k.startswith('SWAMP_')}
        env.update(self.native_env, SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1')
        log = (self.repo / 'serve.log').open('w+')
        proc = subprocess.Popen([
            os.environ['SWAMP_BIN'], 'serve', '--port', str(port), '--auth-mode', 'none',
            '--no-schedule', '--remote-only', '--hot-reload', '--repo-dir', str(self.repo),
        ], env=env, stdout=log, stderr=log)

        def cleanup():
            proc.terminate()
            try:
                proc.wait(timeout=15)
            except subprocess.TimeoutExpired:
                proc.kill()
                proc.wait()
            log.close()

        self.addCleanup(cleanup)
        deadline = time.monotonic() + 30
        while True:
            try:
                with urllib.request.urlopen(f'http://127.0.0.1:{port}/health', timeout=1):
                    break
            except Exception:
                if proc.poll() is not None or time.monotonic() >= deadline:
                    log.seek(0)
                    self.fail(log.read()[-5000:])
                time.sleep(.1)
        endpoint = ['--server', f'ws://127.0.0.1:{port}']

        def describe():
            return json.loads(self.cli('model', 'type', 'describe', '@nckrtl/reload-probe', *endpoint).stdout)

        self.assertEqual(describe()['version'], '2026.10.30.1')
        source.write_text(behavior('2026.10.30.2'))
        definition.write_text(workflow('After reload'))
        (source.parent / 'new.ts').write_text(behavior('2026.10.30.1').replace('@nckrtl/reload-probe', '@nckrtl/reload-added'))
        response = json.loads(self.cli('serve', 'reload', *endpoint).stdout)
        self.assertTrue(response['success'])
        self.assertEqual(response['errors'], [])
        visible = json.loads(self.cli('workflow', 'get', 'reload-probe', *endpoint).stdout)
        self.assertEqual(visible['description'], 'After reload')
        # Pinned reload refreshes pulled extensions, not repo-local model metadata.
        self.assertEqual(describe()['version'], '2026.10.30.1')
        # Only this disposable fixture exercises run dependency construction. The
        # missing workflow refuses before admission; no step or user effect runs.
        self.cli('workflow', 'run', 'nonexistent-reload-probe', *endpoint, success=False)
        self.assertEqual(describe()['version'], '2026.10.30.2')
        added = json.loads(self.cli('model', 'type', 'describe', '@nckrtl/reload-added', *endpoint).stdout)
        self.assertEqual(added['version'], '2026.10.30.1')
        self.assertIsNone(proc.poll())
        self.assertEqual(int((self.repo / '.swamp/serve.pid').read_text()), proc.pid)


if __name__ == '__main__':
    unittest.main()
