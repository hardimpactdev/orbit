"""Native credential DAG, real HTTP effects and official SSH schemas; no fleet."""

import base64
import copy
import hashlib
import json
import shutil
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from tests.native_harness import NativeTestCase


class MetricsCredentialsNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.active = 'fixture-active-"\\' + self.repo.name
        self.pending = 'fixture-pending-' + self.repo.name
        self.secret = json.dumps({'active_password': self.active, 'pending_password': self.pending})
        self.secrets = (self.active, self.pending, self.secret)
        self.current = self.active
        self.success_status = 200
        self.applied = 0
        self.fail_apply = False
        self.fail_confirm = False
        owner = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def authorized(self):
                return self.headers.get('Authorization') == 'Basic ' + base64.b64encode(('admin:' + owner.current).encode()).decode()

            def finish_reply(self, status):
                self.send_response(status)
                self.end_headers()
                self.wfile.write(b'{"public":true}')

            def do_GET(self):
                status = owner.success_status if self.authorized() else 401
                if owner.fail_confirm and owner.applied and status == 200:
                    status = 503
                self.finish_reply(status)

            def do_PUT(self):
                value = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                if owner.fail_apply:
                    self.finish_reply(503)
                elif self.authorized() and value['oldPassword'] == owner.current and value['newPassword'] == owner.pending:
                    owner.current = owner.pending
                    owner.applied += 1
                    self.finish_reply(200)
                else:
                    self.finish_reply(401)

        self.http = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        self.thread = threading.Thread(target=self.http.serve_forever, daemon=True)
        self.thread.start()
        self.addCleanup(self.http.server_close)
        self.addCleanup(self.http.shutdown)
        (self.repo / 'extensions/behaviors').mkdir(parents=True)
        self.state = self.repo / 'calls.json'
        self.state.write_text('[]')
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        fixture = self.repo / 'fixture-ssh'
        fixture.write_text('#!' + sys.executable + '\n' + r'''import json, shlex, subprocess, sys
from pathlib import Path
argv = shlex.split(sys.argv[-1])
path = Path(STATE)
calls = json.loads(path.read_text())
calls.append('apply' if any(v.endswith('/password') for v in argv) else 'confirm' if '--fail' in argv else 'probe')
path.write_text(json.dumps(calls))
result = subprocess.run(argv, input=sys.stdin.read(), text=True, capture_output=True)
sys.stdout.write(result.stdout)
sys.stderr.write(result.stderr)
sys.exit(result.returncode)
'''.replace('STATE', repr(str(self.state))))
        fixture.chmod(0o700)
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-metrics-credentials-reset.yaml').read_text())
        for step in workflow['jobs'][0]['steps']:
            self.assertEqual(step.pop('labels'), {'role': 'swamp'})
            step['task'] = copy.deepcopy(step['task'])
            if step['task'].get('behaviorType') == '@swamp/ssh':
                step['task']['globalArgs']['sshBinary'] = str(fixture)
                step['task']['inputs']['argv'] = [v.replace(':3000/', f':{self.http.server_port}/') if isinstance(v,str) else v for v in step['task']['inputs']['argv']]
        (self.repo / 'workflows/workflow-credentials.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        self.configs = {}
        quote = lambda v: '"' + v.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n').replace('\r', '\\r').replace('\t', '\\t') + '"'
        for action in ('probe', 'apply', 'confirm'):
            config = 'user = ' + quote('admin:' + (self.active if action == 'apply' else self.pending)) + '\n'
            if action == 'apply':
                body = json.dumps({'oldPassword': self.active, 'newPassword': self.pending, 'confirmNewPassword': self.pending})
                config += 'request = PUT\nheader = "Content-Type: application/json"\ndata = ' + quote(body) + '\n'
            self.configs[action] = config
            self.cli('vault', 'put', 'orbit-control-inputs', 'metrics-credentials-operation-12-' + action, input=config)

    def run_reset(self, success=True):
        ssh = {'identity_file':'/approved', 'target':{'host':'127.0.0.1','user':'orbit','port':22,'host_keys':['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND']}}
        inputs = {'operation_id':12,'node_id':9,'kind':'metrics.credentials_reset','intent':{'ssh_execution':ssh,'address':'127.0.0.1','pending_fingerprint':hashlib.sha256(self.pending.encode()).hexdigest()},'execution_secrets':{action:{'vault':'orbit-control-inputs','key':'metrics-credentials-operation-12-'+action} for action in ('probe','apply','confirm')}}
        started = json.loads(self.cli('workflow','run','operation-metrics-credentials-reset','--input',json.dumps(inputs),success=success).stdout)
        return json.loads(self.cli('workflow','history','get',started['id']).stdout)

    def test_changes_and_verifies_without_public_secret_bytes(self):
        history = self.run_reset()
        self.assertEqual(history['status'], 'succeeded')
        self.assertEqual(self.current, self.pending)
        self.assertEqual(self.applied, 1)
        self.assertEqual(json.loads(self.state.read_text()), ['probe','apply','confirm'])

    def test_already_applied_candidate_skips_the_write(self):
        self.current = self.pending
        self.assertEqual(self.run_reset()['status'], 'succeeded')
        self.assertEqual(self.applied, 0)
        self.assertEqual(json.loads(self.state.read_text()), ['probe','confirm'])

    def test_refused_write_has_no_confirmation_or_false_success(self):
        self.fail_apply = True
        self.assertEqual(self.run_reset(False)['status'], 'failed')
        self.assertEqual(self.current, self.active)
        self.assertEqual(json.loads(self.state.read_text()), ['probe','apply'])

    def test_fresh_run_after_uncertain_confirmation_observes_the_same_pending_password(self):
        self.fail_confirm = True
        self.assertEqual(self.run_reset(False)['status'], 'failed')
        self.assertEqual(self.applied, 1)
        self.fail_confirm = False
        self.assertEqual(self.run_reset()['status'], 'succeeded')
        self.assertEqual(self.applied, 1)

    def test_native_assert_rejects_an_unexpected_success_status(self):
        self.current, self.success_status = self.pending, 204
        self.assertEqual(self.run_reset(False)['status'], 'failed')
        self.assertEqual(self.applied, 0)
