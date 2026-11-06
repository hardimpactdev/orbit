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
SECRETS = (KEY, MANAGEMENT, ANCHOR)
SECRET_NAMES = ('model_key', 'model_management', 'model_anchor', 'management_headers', 'keys_', 'router_keys', 'listed',
                'access_token')


def playbook_command():
    for candidate in (Path(sys.executable).parent / 'ansible-playbook', shutil.which('ansible-playbook'),
                      '/opt/orbit/ansible/venv/bin/ansible-playbook'):
        if candidate and Path(candidate).exists():
            return str(candidate)
    return None


class Router:
    """A CLIProxyAPI stand-in: the management key list, and /v1/models for the listed keys.

    `fault` makes one call misbehave: anonymous (no key is accepted), revoked (a removed key still works),
    redirect, oversize, string (the list is a string), mapping.
    """

    def __init__(self, keys, fault=None):
        self.keys = list(keys)
        self.valid = set(keys)
        router = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def reply(self, code, body=b'{}', headers=()):
                self.send_response(code)
                self.send_header('Content-Type', 'application/json')
                for name, value in headers:
                    self.send_header(name, value)
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
                    if fault == 'redirect':
                        return self.reply(302, headers=[('Location', '/elsewhere')])
                    keys = {'oversize': ['x' * 64] * 20000, 'string': 'abc', 'mapping': {'a': 1}}.get(fault, router.keys)
                    return self.reply(200, json.dumps({'api-keys': keys}).encode())
                if self.path == '/v1/models':
                    anonymous = fault == 'anonymous' and not self.token()
                    return self.reply(200 if anonymous or self.token() in router.valid else 401)
                self.reply(404)

            def do_PATCH(self):
                if self.token() != MANAGEMENT:
                    return self.reply(401)
                body = self.body()
                if body['old'] not in router.keys:
                    router.keys.append(body['new'])
                router.valid.add(body['new'])
                self.reply(200)

            def do_PUT(self):
                if self.token() != MANAGEMENT:
                    return self.reply(401)
                router.keys[:] = self.body()
                if fault != 'revoked':
                    router.valid = set(router.keys)
                self.reply(200)

        self.server = HTTPServer(('127.0.0.1', 0), Handler)
        self.origin = f'http://127.0.0.1:{self.server.server_port}'

    def __enter__(self):
        threading.Thread(target=self.server.serve_forever, daemon=True).start()
        return self

    def __exit__(self, *args):
        self.server.shutdown()
        self.server.server_close()


def run(tag, origin, management=MANAGEMENT, key=KEY):
    with tempfile.TemporaryDirectory() as folder:
        intent = Path(folder) / 'intent.json'
        intent.write_text(json.dumps({'task_vm_id': 7, 'model_origin': origin}))
        result = subprocess.run(
            [playbook_command(), '-i', 'localhost,', '-e', '@' + str(intent), '--tags', tag, str(PLAYBOOK)],
            check=False, capture_output=True, text=True, timeout=120, cwd=folder,
            env={**os.environ, 'ANSIBLE_FORCE_COLOR': '0', 'ANSIBLE_CONFIG': str(ANSIBLE / 'ansible.cfg'),
                 'ORBIT_MODEL_KEY': key, 'ORBIT_MODEL_MANAGEMENT': management, 'ORBIT_MODEL_ANCHOR': ANCHOR})
    return result, result.stdout + result.stderr


def tasks_of(path):
    for task in yaml.safe_load(path.read_text()):
        yield from task.get('block', [task])


class TaskVmModelKeyTest(unittest.TestCase):
    def test_every_phase_is_one_tag_and_every_task_that_holds_a_secret_is_silent(self):
        play = yaml.safe_load(PLAYBOOK.read_text())[0]
        self.assertEqual({task['name']: task['tags'] for task in play['tasks']},
                         {'Check the inputs': ['always'], 'Anchor': ['anchor'], 'Issue': ['issue'], 'Revoke': ['revoke']})
        for task in play['tasks']:
            self.assertEqual(task['rescue'][0]['ansible.builtin.fail']['msg'], 'task_vm.model_key_failed')
        shared = [step for name in ('model-key-list.yml', 'model-key-access.yml') for step in tasks_of(ANSIBLE / 'tasks' / name)]
        for step in [*(step for task in play['tasks'] for step in task['block']), *shared]:
            if 'ansible.builtin.import_tasks' in step:
                continue
            if any(name in str(step) for name in SECRET_NAMES):
                self.assertIs(step.get('no_log'), True, step['name'])
        self.assertIs(play['module_defaults']['ansible.builtin.uri']['use_netrc'], False)

    @unittest.skipUnless(playbook_command(), 'ansible-playbook is not installed')
    def test_issue_and_revoke_keep_the_anchor_and_change_nothing_twice(self):
        with Router([]) as router:
            for tag, expected in (('anchor', [ANCHOR]), ('issue', [ANCHOR, KEY]), ('issue', [ANCHOR, KEY]),
                                  ('revoke', [ANCHOR]), ('revoke', [ANCHOR])):
                result, output = run(tag, router.origin)
                self.assertEqual(result.returncode, 0, output)
                self.assertEqual(router.keys, expected, tag)
                for secret in SECRETS:
                    self.assertNotIn(secret, output)

    @unittest.skipUnless(playbook_command(), 'ansible-playbook is not installed')
    def test_a_refused_call_reports_the_error_code_and_no_secret(self):
        with Router([ANCHOR]) as router:
            result, output = run('issue', router.origin, management='d' * 64)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('task_vm.model_key_failed', output)
            self.assertEqual(router.keys, [ANCHOR])
            for secret in (*SECRETS, 'd' * 64):
                self.assertNotIn(secret, output)

    @unittest.skipUnless(playbook_command(), 'ansible-playbook is not installed')
    def test_issue_needs_the_anchor_key_and_a_clean_input(self):
        with Router([]) as router:
            for tag, origin, key in (('issue', router.origin, KEY), ('anchor', 'http://user:pass@127.0.0.1:1', KEY),
                                     ('anchor', router.origin, ANCHOR), ('anchor', router.origin, 'short')):
                result, output = run(tag, origin, key=key)
                self.assertNotEqual(result.returncode, 0, (tag, origin))
                self.assertIn('task_vm.model_key_failed', output)
                self.assertEqual(router.keys, [])

    @unittest.skipUnless(playbook_command(), 'ansible-playbook is not installed')
    def test_a_router_that_answers_wrongly_fails_the_phase(self):
        cases = (('anchor', 'anonymous', []), ('issue', 'anonymous', [ANCHOR]), ('revoke', 'revoked', [ANCHOR, KEY]),
                 ('anchor', 'redirect', []), ('anchor', 'oversize', []), ('issue', 'string', [ANCHOR]),
                 ('revoke', 'mapping', [ANCHOR, KEY]))
        for tag, fault, keys in cases:
            with Router(keys, fault) as router:
                result, output = run(tag, router.origin)
                self.assertNotEqual(result.returncode, 0, (tag, fault, output))
                self.assertIn('task_vm.model_key_failed', output)
                for secret in SECRETS:
                    self.assertNotIn(secret, output)


if __name__ == '__main__':
    unittest.main()
