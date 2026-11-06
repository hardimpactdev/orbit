"""The model key playbook keeps its phase tags and talks to the router the way CLIProxyAPI expects."""
import json
import os
import shutil
import subprocess
import sys
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

import yaml

ANSIBLE = Path(__file__).resolve().parents[1] / 'ansible'
PLAYBOOK = ANSIBLE / 'task-vm-model-key.yml'
KEY, MANAGEMENT, ANCHOR = 'b' * 64, 'c' * 64, 'a' * 64


def playbook_command():
    for candidate in (Path(sys.executable).parent / 'ansible-playbook', shutil.which('ansible-playbook'),
                      '/opt/orbit/ansible/venv/bin/ansible-playbook'):
        if candidate and Path(candidate).exists():
            return str(candidate)
    return None


class Router:
    """A CLIProxyAPI stand-in: the management key list, and /v1/models for the listed keys."""

    def __init__(self, keys):
        self.keys = list(keys)
        router = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def reply(self, code, body=b'{}'):
                self.send_response(code)
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(body)

            def token(self):
                return self.headers.get('Authorization', '').removeprefix('Bearer ')

            def body(self):
                return json.loads(self.rfile.read(int(self.headers.get('Content-Length', 0))))

            def do_GET(self):
                if self.path == '/v0/management/api-keys':
                    if self.token() != MANAGEMENT:
                        return self.reply(401)
                    return self.reply(200, json.dumps({'api-keys': router.keys}).encode())
                self.reply(200 if self.path == '/v1/models' and self.token() in router.keys else 401)

            def do_PATCH(self):
                if self.token() != MANAGEMENT:
                    return self.reply(401)
                body = self.body()
                if body['old'] not in router.keys:
                    router.keys.append(body['new'])
                self.reply(200)

            def do_PUT(self):
                if self.token() != MANAGEMENT:
                    return self.reply(401)
                router.keys[:] = self.body()
                self.reply(200)

        self.server = HTTPServer(('127.0.0.1', 0), Handler)
        self.origin = f'http://127.0.0.1:{self.server.server_port}'

    def __enter__(self):
        threading.Thread(target=self.server.serve_forever, daemon=True).start()
        return self

    def __exit__(self, *args):
        self.server.shutdown()
        self.server.server_close()


def run(tag, origin, management=MANAGEMENT):
    with tempfile.TemporaryDirectory() as folder:
        intent = Path(folder) / 'intent.json'
        intent.write_text(json.dumps({'task_vm_id': 7, 'model_origin': origin}))
        result = subprocess.run(
            [playbook_command(), '-i', 'localhost,', '-e', '@' + str(intent), '--tags', tag, str(PLAYBOOK)],
            check=False, capture_output=True, text=True, timeout=120, cwd=folder,
            env={**os.environ, 'ANSIBLE_FORCE_COLOR': '0', 'ANSIBLE_CONFIG': str(ANSIBLE / 'ansible.cfg'),
                 'ORBIT_MODEL_KEY': KEY, 'ORBIT_MODEL_MANAGEMENT': management, 'ORBIT_MODEL_ANCHOR': ANCHOR})
    return result, result.stdout + result.stderr


class TaskVmModelKeyTest(unittest.TestCase):
    def test_every_phase_is_one_tag_and_every_secret_task_is_silent(self):
        play = yaml.safe_load(PLAYBOOK.read_text())[0]
        self.assertEqual({task['name']: task['tags'] for task in play['tasks']},
                         {'Check the inputs': ['always'], 'Anchor': ['anchor'], 'Issue': ['issue'], 'Revoke': ['revoke']})
        for task in play['tasks']:
            for step in task['block']:
                if 'headers' in str(step) and 'Authorization' in str(step) or 'model_key' in str(step) or 'keys_' in str(step):
                    self.assertIs(step.get('no_log'), True, step['name'])
            self.assertEqual(task['rescue'][0]['ansible.builtin.fail']['msg'], 'task_vm.model_key_failed')

    @unittest.skipUnless(playbook_command(), 'ansible-playbook is not installed')
    def test_issue_and_revoke_keep_the_anchor_and_change_nothing_twice(self):
        with Router([]) as router:
            for tag, expected in (('anchor', [ANCHOR]), ('issue', [ANCHOR, KEY]), ('issue', [ANCHOR, KEY]),
                                  ('revoke', [ANCHOR]), ('revoke', [ANCHOR])):
                result, output = run(tag, router.origin)
                self.assertEqual(result.returncode, 0, output)
                self.assertEqual(router.keys, expected, tag)
                for secret in (KEY, MANAGEMENT, ANCHOR):
                    self.assertNotIn(secret, output)

    @unittest.skipUnless(playbook_command(), 'ansible-playbook is not installed')
    def test_a_refused_call_reports_the_error_code_and_no_secret(self):
        with Router([ANCHOR]) as router:
            result, output = run('issue', router.origin, management='d' * 64)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('task_vm.model_key_failed', output)
            self.assertEqual(router.keys, [ANCHOR])
            for secret in (KEY, MANAGEMENT, ANCHOR, 'd' * 64):
                self.assertNotIn(secret, output)

    @unittest.skipUnless(playbook_command(), 'ansible-playbook is not installed')
    def test_issue_needs_the_anchor_key_and_an_origin_without_credentials(self):
        with Router([]) as router:
            result, output = run('issue', router.origin)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('task_vm.model_key_failed', output)
            self.assertEqual(router.keys, [])
            result, output = run('anchor', 'http://user:pass@127.0.0.1:1')
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('task_vm.model_key_failed', output)


if __name__ == '__main__':
    unittest.main()
