"""Execute the exact workflow Pi probes against an address other than localhost."""
import http.server
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import threading
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[1]


class PiProbeTest(unittest.TestCase):
    def test_runtime_and_resume_probe_the_captured_bind_address(self):
        seen = []

        class Handler(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                seen.append((self.path, self.headers.get('Authorization')))
                self.send_response(200 if self.path == '/capabilities' else 503)
                self.end_headers()

            def log_message(self, *args):
                pass

        server = http.server.HTTPServer(('127.0.0.2', 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            with tempfile.TemporaryDirectory() as directory:
                token = Path(directory) / 'token'
                token.write_text('guest-local-probe-secret')
                for workflow in ('task-vm-runtime', 'operation-task-vm-resume'):
                    value = yaml.safe_load((ROOT / 'workflows' / f'workflow-{workflow}.yaml').read_text())
                    step = next(step for job in value['jobs'] for step in job['steps'] if 'urllib.request' in str(step['task'].get('inputs', {}).get('argv', [])))
                    code = step['task']['inputs']['argv'][-1].replace('/home/orbit/.pi/agent/orbit-token', str(token))
                    url = f'http://127.0.0.2:{server.server_port}'
                    env = {**os.environ, 'no_proxy': '*', 'NO_PROXY': '*'}
                    for suffix, expected in (('', 0), ('/unavailable', 1)):
                        result = subprocess.run([sys.executable, '-c', code], input=json.dumps({'pi_url': url + suffix, 'pi_port': 1}), text=True, capture_output=True, env=env)
                        self.assertEqual(result.returncode, expected, result.stderr)
                        self.assertNotIn('guest-local-probe-secret', result.stdout + result.stderr)
                        if expected == 0:
                            self.assertEqual(json.loads(result.stdout), {'pi_ready': True})
                        else:
                            self.assertEqual(result.stderr.strip(), 'task_vm.resume_failed')
                self.assertEqual(len(seen), 4)
                self.assertTrue(all(auth == 'Bearer guest-local-probe-secret' for _, auth in seen))
        finally:
            server.shutdown()
            server.server_close()
            thread.join()
