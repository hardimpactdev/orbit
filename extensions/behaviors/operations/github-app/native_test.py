"""Shipped GitHub App DAGs with synthetic callback-local credentials; no live GitHub."""
import json
from pathlib import Path
import secrets
import shutil
import sys
import unittest

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class GitHubAppNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operations/_lib', 'operations/github-app')
        destination = self.repo / 'extensions/behaviors/operations/github-app'
        module = destination / 'mod.ts'
        module.write_text('import {fixtureHttp, fixtureFetch} from "./native_fixture.ts";\n' +
                          module.read_text().replace('export const behavior = githubApp();',
                                                     'export const behavior = githubApp((args) => new GatewayOperation(fixtureHttp, args), fixtureFetch);'))
        failure = destination.parent / 'failure.ts'
        shutil.copy(ROOT / 'extensions/behaviors/operations/failure.ts', failure)
        failure.write_text('import {fixtureHttp} from "./github-app/native_fixture.ts";\n' +
                          failure.read_text().replace('export const behavior = nativeFailure();',
                                                     'export const behavior = nativeFailure(() => fixtureHttp);'))
        for mode in ['register', 'sync']:
            name = 'workflow-operation-github-app-' + mode + '.yaml'
            (self.repo / 'workflows' / name).write_text('\n'.join(
                line for line in (ROOT / 'workflows' / name).read_text().splitlines()
                if not line.startswith(('target:', 'labels:'))) + '\n')
        code = secrets.token_hex(16)
        pem = '-----BEGIN PRIVATE KEY-----\n' + secrets.token_hex(64) + '\n-----END PRIVATE KEY-----'
        jwt = secrets.token_hex(8) + '.' + secrets.token_hex(16) + '.' + secrets.token_hex(32)
        self.native_env = {'ORBIT_APP_FIXTURE_CODE': code, 'ORBIT_APP_FIXTURE_PEM': pem,
                           'ORBIT_APP_FIXTURE_JWT': jwt}
        self.secrets = (code, pem, jwt)

    def run_app(self, mode, reject=False):
        self.state = {'operation': {'id': 5, 'kind': 'github.app_' + mode, 'node_id': 14,
                       'status': 'queued', 'cancel_requested': False, 'result': None,
                       'input': {'registration_id': 'a' * 64} if mode == 'register' else {'app_id': 12}},
                      'calls': [], 'credentialStores': 0, 'githubCalls': 0, 'reject': reject}
        self.path = self.repo / 'github-app-fixture.json'
        self.path.write_text(json.dumps(self.state))
        workflow = 'operation-github-app-' + mode
        self.cli('workflow', 'validate', workflow)
        result = self.cli('workflow', 'run', workflow, '--input',
                         json.dumps({'operation_id': 5, 'node_id': 14, 'kind': 'github.app_' + mode}),
                         success=not reject)
        return json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']

    def test_registration_private_code_and_key_never_enter_native_history(self):
        self.run_app('register')
        state = json.loads(self.path.read_text())
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['operation']['result'], {'registered': True})
        self.assertEqual((state['githubCalls'], state['credentialStores']), (1, 1))
        self.assert_private_absent()

    def test_sync_projects_public_installations_without_app_jwt(self):
        self.run_app('sync')
        state = json.loads(self.path.read_text())
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['operation']['result']['installations'][0]['id'], 17)
        self.assertEqual((state['githubCalls'], state['credentialStores']), (1, 0))
        self.assert_private_absent()

    def test_failed_exchange_is_redacted_and_terminal_resume_does_not_exchange_again(self):
        root = self.run_app('register', reject=True)
        state = json.loads(self.path.read_text())
        self.assertEqual(state['operation']['status'], 'failed')
        self.assertEqual(state['operation']['result'], {'error_code': 'operation.native_outcome_unknown'})
        self.cli('workflow', 'resume', 'operation-github-app-register', '--run', root, success=False)
        state = json.loads(self.path.read_text())
        self.assertEqual((state['githubCalls'], state['credentialStores']), (1, 0))
        self.assert_private_absent()


if __name__ == '__main__':
    unittest.main()
