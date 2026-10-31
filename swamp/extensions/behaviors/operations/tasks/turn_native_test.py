"""Execute the workflow's actual inline scripts against Git and a Pi HTTP fixture."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


WORKFLOW = Path(__file__).resolve().parents[4] / 'workflows/workflow-operation-task-turn.yaml'
if os.environ.get('TASK_TURN_WORKFLOW_JSON'):
    DEFINITION = json.loads(Path(os.environ['TASK_TURN_WORKFLOW_JSON']).read_text())
else:
    import yaml
    DEFINITION = yaml.safe_load(WORKFLOW.read_text())
STEPS = {step['name']: step for step in DEFINITION['jobs'][0]['steps']}
TOKEN = 'synthetic-pi-test-token-with-at-least-32-characters'


class PiHandler(BaseHTTPRequestHandler):
    def log_message(self, *_args):
        pass

    def do_POST(self):
        self.server.calls.append((self.path, json.loads(self.rfile.read(int(self.headers['Content-Length'])))))
        self.respond({'accepted': True})

    def do_GET(self):
        if self.headers.get('Authorization') != 'Bearer ' + TOKEN:
            self.send_response(401)
            self.end_headers()
            return
        self.server.calls.append((self.path, None))
        self.send_response(200)
        self.end_headers()
        self.wfile.write((json.dumps({'state': 'working', 'turnId': 'older'}) + '\n').encode())
        if not self.server.disconnect:
            self.wfile.write((json.dumps({'state': 'done', 'turnId': 'operation-15', 'usage': {'total': 4}}) + '\n').encode())

    def respond(self, value):
        if self.headers.get('Authorization') != 'Bearer ' + TOKEN:
            self.send_response(401)
            self.end_headers()
            return
        self.send_response(200)
        self.end_headers()
        self.wfile.write(json.dumps(value).encode())


class TurnInlineScripts(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='task-turn-native-')
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        self.git('init', '-q')
        self.git('config', 'user.email', 'test@example.invalid')
        self.git('config', 'user.name', 'Task fixture')
        (self.repo / 'file').write_text('original\n')
        self.git('add', '.')
        self.git('commit', '-qm', 'Initial')
        self.head = self.git('rev-parse', 'HEAD')
        self.value = {'operation_id': 15, 'new_session': True, 'group_id': 10,
                      'instance_id': 12, 'node_id': 30, 'subtask': 11, 'thread_id': 14,
                      'checkout_path': str(self.repo), 'session_id': 'session-14',
                      'role': 'implementer', 'candidate': None, 'deliverables': [],
                      'analysis_evidence': None}

    def git(self, *args):
        return subprocess.check_output(['git', *args], cwd=self.repo, text=True, stderr=subprocess.DEVNULL).strip()

    def run_step(self, name, *, ok=True, **extra):
        run = STEPS[name]['task']['inputs']['run']
        result = subprocess.run(['bash', '-c', run], env={
            **os.environ, 'ORBIT_TURN_INPUT': json.dumps(self.value), **extra,
        }, text=True, capture_output=True, timeout=10)
        self.assertNotIn(TOKEN, result.stdout + result.stderr)
        self.assertEqual(result.returncode == 0, ok, result.stderr)
        return json.loads(result.stdout) if result.returncode == 0 and result.stdout.strip() else result

    def pi_server(self):
        server = ThreadingHTTPServer(('127.0.0.1', 0), PiHandler)
        server.calls, server.disconnect = [], False
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        return server, {'ORBIT_PI_TOKEN': TOKEN, 'ORBIT_PI_URL': 'http://127.0.0.1:' + str(server.server_port)}

    def test_receipt_helper_collects_real_repository_agent_receipt(self):
        before = self.run_step('snapshot_before')
        self.run_step('candidate', ORBIT_BEFORE=json.dumps(before))
        self.run_step('receipt_prepare')
        subprocess.run([str(self.repo / '.git/orbit/turn'), '--thread=14',
                        '--outcome=ready_for_review', '--summary=Implemented',
                        '--deliverable=done=evidence'], check=True)
        self.assertEqual(self.run_step('receipt_read'), {
            'thread': 14, 'outcome': 'ready_for_review', 'summary': 'Implemented',
            'deliverables': {'done': 'evidence'},
        })

    def test_moved_review_candidate_fails_before_any_pi_call(self):
        before = self.run_step('snapshot_before')
        self.value.update(role='reviewer', candidate='f' * 40)
        result = self.run_step('candidate', ok=False, ORBIT_BEFORE=json.dumps(before))
        self.assertIn('tasks.head_moved', result.stderr)

    def test_reviewer_diff_is_collected_then_exact_candidate_restored(self):
        self.value.update(role='reviewer', candidate=self.head)
        before = self.run_step('snapshot_before')
        (self.repo / 'file').write_text('review mutation\n')
        (self.repo / 'untracked').write_text('review extra\n')
        self.run_step('snapshot_after')
        difference = self.run_step('difference', ORBIT_BEFORE=json.dumps(before))
        self.assertEqual(difference['summary']['files'], 2)
        restored = self.run_step('restore')
        self.assertEqual(restored['head'], self.head)
        self.assertEqual((self.repo / 'file').read_text(), 'original\n')
        self.assertFalse((self.repo / 'untracked').exists())

    def test_pi_actions_are_separate_and_stream_ignores_another_turn(self):
        server, env = self.pi_server()
        self.run_step('session_create', **env, ORBIT_PI_BODY=json.dumps({'id': 'session-14'}))
        self.run_step('message_send', **env, ORBIT_PI_BODY=json.dumps({'key': 'operation-15', 'text': 'Do the task'}))
        terminal = self.run_step('observe', **env)
        self.assertEqual(terminal['turnId'], 'operation-15')
        self.assertEqual(len(server.calls), 3)
        self.run_step('interrupt', **env)
        self.assertEqual(server.calls[-1][0], '/sessions/session-14/interrupt')

    def test_disconnect_without_matching_terminal_event_fails_without_retry(self):
        server, env = self.pi_server()
        server.disconnect = True
        result = self.run_step('observe', ok=False, **env)
        self.assertIn('tasks.pi_completion_unconfirmed', result.stderr)
        self.assertEqual(len(server.calls), 1)

    def test_analysis_hash_and_entrypoint_are_bound_before_pi(self):
        entry = self.repo / 'bin/task-analyze'
        entry.parent.mkdir()
        entry.write_text('#!/bin/sh\nprintf report\n')
        entry.chmod(0o755)
        self.git('add', '.')
        self.git('commit', '-qm', 'Analyzer')
        self.git('remote', 'add', 'origin', 'https://github.com/acme/test.git')
        before = self.run_step('snapshot_before')
        content = '{"report":"safe"}'
        source = {'base': self.head, 'repository': 'https://github.com/acme/test.git', 'plan_sha256': 'd' * 64}
        provenance = {**source, **before, 'instance_id': 12, 'node_id': 30,
                      'branch': self.git('symbolic-ref', '--short', 'HEAD'),
                      'entrypoint_sha256': hashlib.sha256(entry.read_bytes()).hexdigest()}
        self.value['analysis_evidence'] = {'input': source, 'result': {
            'artifact': {'content': content, 'bytes': len(content), 'sha256': hashlib.sha256(content.encode()).hexdigest()},
            'provenance': provenance}}
        self.run_step('candidate', ORBIT_BEFORE=json.dumps(before))
        self.value['analysis_evidence']['result']['artifact']['content'] += 'tampered'
        self.run_step('candidate', ok=False, ORBIT_BEFORE=json.dumps(before))

    def test_receipt_symlink_cannot_escape_into_host_file(self):
        root = self.repo / '.git/orbit'
        root.mkdir()
        destination = self.repo / 'protected'
        destination.write_text('keep')
        (root / 'turn').symlink_to(destination)
        self.run_step('receipt_prepare', ok=False)
        self.assertEqual(destination.read_text(), 'keep')

    def test_every_inline_python_action_compiles(self):
        for name, step in STEPS.items():
            if step['task'].get('behaviorType') != 'command/shell':
                continue
            script = step['task']['inputs']['run']
            if script.startswith("python3 - <<'PYTHON'\n"):
                compile(script.split('\n', 1)[1].rsplit('\nPYTHON', 1)[0], name, 'exec')

class TurnNativeDag(unittest.TestCase):
    """Use the official scheduler; only Pi itself is a disposable fixture."""
    setUp = TurnInlineScripts.setUp
    git = TurnInlineScripts.git
    run_step = TurnInlineScripts.run_step
    pi_server = TurnInlineScripts.pi_server

    def test_whole_native_dag_returns_receipt_and_preserves_resume_send_key(self):
        import shutil
        server, _ = self.pi_server()
        native_temp = tempfile.TemporaryDirectory(prefix='task-turn-dag-')
        self.addCleanup(native_temp.cleanup)
        native = Path(native_temp.name)
        source = WORKFLOW.parents[1]
        shutil.copy(source / '.swamp.yaml', native)
        family = native / 'extensions/behaviors/operations'
        (family / 'tasks').mkdir(parents=True)
        (family / 'task-turn').mkdir()
        shutil.copy(source / 'extensions/behaviors/operations/tasks/turn.ts', family / 'tasks/turn.ts')
        shutil.copy(source / 'extensions/behaviors/operations/task-turn/mod.ts', family / 'task-turn/mod.ts')
        (native / 'workflows').mkdir()
        workflow = json.loads(json.dumps(DEFINITION))
        # Local native fixture; production retains an exact target on each step.
        for step in workflow['jobs'][0]['steps']:
            step.pop('target', None)
        (native / 'workflows/workflow-operation-task-turn.yaml').write_text(json.dumps(workflow))
        env = {k: v for k, v in os.environ.items() if not k.startswith(('SWAMP_SERVER', 'SWAMP_SERVE', 'SWAMP_REPO', 'SWAMP_EXTENSIONS', 'SWAMP_CA'))}
        env.update(SWAMP_NO_TELEMETRY='1', SWAMP_NO_UPDATE_CHECK='1')

        def cli(*args, input=None, success=True):
            reply = subprocess.run(['swamp', *args, '--json'], cwd=native, env=env,
                                   input=input, text=True, capture_output=True, timeout=60)
            self.assertNotIn(TOKEN, reply.stdout + reply.stderr)
            self.assertEqual(reply.returncode == 0, success, reply.stdout[-5000:] + reply.stderr[-2000:])
            return json.loads(reply.stdout)

        cli('vault', 'create', 'local_encryption', 'orbit-control-inputs', '--config', json.dumps({
            'base_dir': str(native / 'vault-data'), 'key_file': str(native / 'vault-key'), 'auto_generate': True}))
        cli('vault', 'put', 'orbit-control-inputs', 'task-vm.5.pi-token', input=TOKEN)
        self.value.update(prompt='Implement the fixture task', model='orbit-task-vm/gpt-6.1-sol',
                          effort='high', pi_host='127.0.0.1', pi_port=server.server_port)
        original = PiHandler.do_POST

        def write_receipt(handler):
            if handler.path.endswith('/messages'):
                (self.repo / '.git/orbit/receipt.json').write_text(json.dumps({
                    'thread': 14, 'outcome': 'ready_for_review', 'summary': 'Native fixture done', 'deliverables': {}}))
            original(handler)
        PiHandler.do_POST = write_receipt
        self.addCleanup(setattr, PiHandler, 'do_POST', original)
        args = {'operation_id': 15, 'node_id': 30, 'kind': 'tasks.turn', 'intent': self.value,
                'execution_secrets': {'pi_token': {'vault': 'orbit-control-inputs', 'key': 'task-vm.5.pi-token'}}}
        cli('workflow', 'validate', 'operation-task-turn')
        run = cli('workflow', 'run', 'operation-task-turn', '--input', json.dumps(args))
        self.assertEqual(run['status'], 'succeeded')
        history = cli('workflow', 'history', 'get', run['id'])
        job = next(job for job in history['jobs'] if job['name'] == 'turn')
        final = next(step for step in job['steps'] if step['name'] == 'result')
        value = json.loads(final['outputs']['stdout'])
        self.assertEqual(value['receipt']['outcome'], 'ready_for_review')
        self.assertEqual(value['state'], 'done')
        self.assertEqual([body['key'] for path, body in server.calls if path.endswith('/messages')], ['operation-15'])
        server.disconnect = True
        args['operation_id'] = 16
        failed = cli('workflow', 'run', 'operation-task-turn', '--input', json.dumps(args), success=False)
        history = cli('workflow', 'history', 'get', failed['id'])
        job = next(job for job in history['jobs'] if job['name'] == 'turn')
        final = next(step for step in job['steps'] if step['name'] == 'result')
        value = json.loads(final['outputs']['stdout'])
        self.assertEqual(value['error_code'], 'tasks.turn_failed')
        self.assertIsNone(value['receipt'])
        self.assertEqual(server.calls[-1][0], '/sessions/session-14/interrupt')


if __name__ == '__main__':
    unittest.main()
