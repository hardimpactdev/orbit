"""Actual native DAG against disposable Gateway and CLIProxyAPI HTTP endpoints."""
import base64
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from pathlib import Path
import shutil
import sys
import threading
import unittest

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class AccountsNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operations/_lib')
        destination = self.repo / 'extensions/behaviors/operations/ai-router'
        destination.mkdir()
        for name in ('accounts.ts', 'accounts_direct.ts'):
            shutil.copy(ROOT / 'extensions/behaviors/operations/ai-router' / name, destination)
        self.key = 'private-account-native-sentinel'
        self.secrets = (self.key, base64.b64encode(self.key.encode()).decode())
        self.state = {'disabled': False, 'writes': 0, 'private_reads': 0, 'deny': False}
        outer = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def reply(self, body, status=200):
                encoded = json.dumps(body).encode()
                self.send_response(status)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Content-Length', str(len(encoded)))
                self.end_headers()
                self.wfile.write(encoded)

            def do_GET(self):
                if self.path.startswith('/api/v1/direct-operations/71/private-material'):
                    outer.state['private_reads'] += 1
                    return self.reply({'data': {'router': {'management_key': outer.key}}})
                if self.path.startswith('/api/v1/direct-operations/71?'):
                    return self.reply({'data': outer.operation})
                if self.path == '/v0/management/auth-files' and self.headers.get('Authorization') == 'Bearer ' + outer.key:
                    if outer.state['deny']:
                        return self.reply({'error': outer.key}, 401)
                    return self.reply({'files': [{'name': 'disposable.json', 'auth_index': 'native-account', 'disabled': outer.state['disabled']}]})
                return self.reply({}, 404)

            def do_POST(self):
                data = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                if self.path != '/api/v1/direct-operations/71/result':
                    return self.reply({}, 404)
                outer.operation.update(data)
                return self.reply({'data': outer.operation})

            def do_PATCH(self):
                if self.path != '/v0/management/auth-files/status' or self.headers.get('Authorization') != 'Bearer ' + outer.key:
                    return self.reply({}, 403)
                data = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                if data != {'name': 'disposable.json', 'disabled': True}:
                    return self.reply({}, 422)
                outer.state['writes'] += 1
                outer.state['disabled'] = True
                return self.reply({'status': 'ok'})

        self.server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.addCleanup(self.server.server_close)
        self.addCleanup(self.server.shutdown)
        origin = 'http://127.0.0.1:' + str(self.server.server_port)
        self.operation = {'id': 71, 'kind': 'ai_router.account_update', 'node_id': 17, 'status': 'queued', 'result': None,
                          'cancel_requested': False, 'input': {'origin': origin, 'account': 'native-account', 'disabled': True}}
        source = destination / 'accounts_direct.ts'
        source.write_text(source.read_text().replace('export const behavior = {', '''const http: Http = async (method,path,body) => {
 const response=await fetch(Deno.env.get('ACCOUNT_NATIVE_ORIGIN')+path,{method,headers:{'Content-Type':'application/json'},...(body?{body:JSON.stringify(body)}:{})});
 return {status:response.status,body:await response.json()};
};
export const behavior = {''').replace('...aiRouterAccounts(),', '...aiRouterAccounts(() => http),'))
        # The normal root method reports its failure; this fixture does not inject a second host action.
        self.native_env = {'ACCOUNT_NATIVE_ORIGIN': origin}
        workflow = ROOT / 'workflows/workflow-operation-ai-router-account-update.yaml'
        self.workflow = workflow
        self.write_workflow(False)
        self.inputs = json.dumps({'operation_id': 71, 'node_id': 17, 'kind': 'ai_router.account_update'})

    def write_workflow(self, placed):
        source = self.workflow.read_text()
        if not placed:
            source = '\n'.join(line for line in source.splitlines() if not line.lstrip().startswith(('target:', 'labels:'))) + '\n'
        (self.repo / 'workflows' / self.workflow.name).write_text(source)

    def run_update(self):
        self.cli('workflow', 'validate', 'operation-ai-router-account-update')
        return self.cli('workflow', 'run', 'operation-ai-router-account-update', '--input', self.inputs)

    def test_actual_native_dag_updates_and_confirms_without_private_artifacts(self):
        self.run_update()
        self.assertEqual(self.state['writes'], 1)
        self.assertEqual(self.operation['status'], 'succeeded')
        self.assertEqual(self.operation['result'], {'account': 'native-account', 'disabled': True})

    def test_already_applied_state_does_not_repeat_the_patch(self):
        self.state['disabled'] = True
        self.run_update()
        self.assertEqual(self.state['writes'], 0)

    def test_cancelled_root_reads_no_management_material(self):
        self.operation['cancel_requested'] = True
        self.run_update()
        self.assertEqual(self.state['writes'], 0)
        self.assertEqual(self.state['private_reads'], 0)
        self.assertEqual(self.operation['status'], 'cancelled')

    def test_exact_placement_and_stable_behavior_identity(self):
        self.write_workflow(True)
        result = self.cli('workflow', 'evaluate', 'operation-ai-router-account-update', '--input', self.inputs)
        evaluated = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]
        step = evaluated['jobs'][0]['steps'][0]
        self.assertEqual(step['target'], 'orbit-node-17')
        self.assertEqual(step['labels'], {'node': '17'})
        self.assertIn('ai-router-accounts-node-17', json.dumps(step))


if __name__ == '__main__':
    unittest.main()
