"""Actual native credential-reset workflow with disposable HTTP/effect injection."""
import json
from pathlib import Path
import shutil
import sys
import unittest

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class MetricsCredentialsResetNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operations/_lib')
        node = self.repo / 'extensions/behaviors/operations/node'
        node.mkdir()
        shutil.copy(ROOT / 'extensions/behaviors/operations/node/system_command.ts', node)
        target = self.repo / 'extensions/behaviors/operations/integrations'
        target.mkdir()
        for name in ('metrics_credentials_reset.ts', 'metrics_credentials_reset_native_fixture.ts', 'metrics_runtime_actions.ts', 'metrics_system.ts'):
            shutil.copy(ROOT / 'extensions/behaviors/operations/integrations' / name, target)
        source = target / 'metrics_credentials_reset.ts'
        source.write_text('import { http, invoke } from "./metrics_credentials_reset_native_fixture.ts";\n' + source.read_text().replace(
            'export const behavior = metricsCredentialsReset();', 'export const behavior = metricsCredentialsReset(() => http, invoke);'))
        self.workflow = ROOT / 'workflows/workflow-operation-metrics-credentials-reset.yaml'
        self.write_workflow(placed=False)
        self.native_env = {'METRICS_RESET_ACTIVE': 'private-native-active-sentinel', 'METRICS_RESET_PENDING': 'private-native-pending-sentinel'}
        self.secrets = tuple(self.native_env.values())
        self.state = {'bound': True, 'loseCompletion': False, 'calls': [], 'actions': [], 'applied': False, 'operation': {
            'id': 17, 'kind': 'metrics.credentials_reset', 'node_id': 17, 'status': 'queued', 'result': None, 'cancel_requested': False,
            'input': {'address': '10.44.0.17', 'agent_sha256': 'a' * 64}}}
        self.inputs = json.dumps({'operation_id': 17, 'node_id': 17, 'kind': 'metrics.credentials_reset'})
        self.save()

    def write_workflow(self, placed):
        text = self.workflow.read_text()
        if not placed:
            text = '\n'.join(line for line in text.splitlines() if not line.lstrip().startswith(('target:', 'labels:'))) + '\n'
        (self.repo / 'workflows' / self.workflow.name).write_text(text)

    def save(self):
        (self.repo / 'native-websocket.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / 'native-websocket.json').read_text())

    def run_reset(self, success=True):
        self.cli('workflow', 'validate', 'operation-metrics-credentials-reset')
        result = self.cli('workflow', 'run', 'operation-metrics-credentials-reset', '--input', self.inputs, success=success)
        return json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']

    def test_native_reset_changes_once_and_projects_only_public_result(self):
        self.run_reset()
        state = self.read()
        self.assertEqual(state['actions'], ['verify', 'apply', 'verify'])
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['operation']['result'], {'reset': True})

    def test_native_resume_verifies_an_already_applied_pending_password(self):
        self.state['applied'] = True
        self.save()
        self.run_reset()
        self.assertEqual(self.read()['actions'], ['verify'])

    def test_native_verification_failure_records_no_success(self):
        self.state['unverified'] = True
        self.save()
        self.run_reset(success=False)
        state = self.read()
        self.assertEqual(state['operation']['status'], 'failed')
        self.assertEqual(state['operation']['result'], {'error_code': 'metrics.credentials_reset_unverified'})

    def test_native_cancel_avoids_private_material_and_effects(self):
        self.state['operation']['cancel_requested'] = True
        self.save()
        self.run_reset()
        self.assertEqual(self.read()['actions'], [])
        self.assertEqual(self.read()['operation']['status'], 'cancelled')
        self.assertFalse(any('private-material' in call for call in self.read()['calls']))

    def test_placement_evaluates_to_exact_admitted_node(self):
        self.write_workflow(placed=True)
        result = self.cli('workflow', 'evaluate', 'operation-metrics-credentials-reset', '--input', self.inputs)
        evaluated = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]
        self.assertEqual(evaluated['jobs'][0]['steps'][0]['target'], 'orbit-node-17')
        self.assertEqual(evaluated['jobs'][0]['steps'][0]['labels'], {'node': '17'})
        self.assertEqual(self.read()['actions'], [])


if __name__ == '__main__':
    unittest.main()
