"""Exercise the actual direct registry workflow with a disposable API boundary."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[4]
SWAMP = os.environ.get('SWAMP_BIN', shutil.which('swamp') or 'swamp')

class WebSocketDirectNative(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='websocket-direct-')
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        shutil.copy(ROOT / '.swamp.yaml', self.repo)
        for family in ['automatic-deploy', 'operation-runner', 'operations/_lib']:
            shutil.copytree(ROOT / 'extensions/behaviors' / family, self.repo / 'extensions/behaviors' / family)
        target = self.repo / 'extensions/behaviors/operations/integrations'
        target.mkdir()
        for name in ['websocket_direct.ts', 'websocket_direct_native_fixture.ts']:
            shutil.copy(ROOT / 'extensions/behaviors/operations/integrations' / name, target)
        module = target / 'websocket_direct.ts'
        module.write_text('import { fixtureHttp } from "./websocket_direct_native_fixture.ts";\n' + module.read_text().replace(
            'export const behavior = websocketRegistry();', 'export const behavior = websocketRegistry(() => fixtureHttp);'))
        workflow = (ROOT / 'workflows/workflow-operation-websocket-registry.yaml').read_text()
        self.assertIn('target: swamp209-migration-a', workflow)
        self.assertIn('labels: { node: "209" }', workflow)
        self.assertNotIn('queueTimeout:', workflow)
        (self.repo / 'workflows').mkdir()
        # Placement is removed only in this disposable fixture.
        (self.repo / 'workflows/workflow-operation-websocket-registry.yaml').write_text('\n'.join(
            line for line in workflow.splitlines() if not line.strip().startswith(('target:', 'labels:'))) + '\n')
        self.state = {'bound': True, 'loseCompletion': False, 'calls': [], 'registryReads': 0, 'operation': {
            'id': 71, 'kind': 'websocket.sync', 'node_id': 14, 'status': 'queued', 'result': None, 'cancel_requested': False,
            'input': {'registry_operation_id': 'b075eb23-7ac4-4487-80d1-57a038a92676', 'epoch': 'epoch-1', 'revision': 7}}}
        self.save()
    def save(self): (self.repo / 'native-websocket.json').write_text(json.dumps(self.state))
    def read(self): return json.loads((self.repo / 'native-websocket.json').read_text())
    def cli(self, *args, success=True):
        env = dict(os.environ, SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1')
        for key in ['SWAMP_SERVER_URL', 'SWAMP_SERVE_URL', 'SWAMP_SERVER_TOKEN', 'SWAMP_SERVER_TOKEN_FILE', 'SWAMP_REPO_DIR', 'SWAMP_CA_CERT']:
            env.pop(key, None)
        result = subprocess.run([SWAMP, *args, '--json'], cwd=self.repo, env=env, capture_output=True, text=True, timeout=60)
        self.assertEqual(result.returncode == 0, success, result.stdout + result.stderr)
        return result
    def run_workflow(self, success=True):
        result = self.cli('workflow', 'run', 'operation-websocket-registry', '--input', 'operation_id=71', '--input', 'node_id=14', '--input', 'kind=' + self.state['operation']['kind'], success=success)
        self.run_id = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']
    def test_actual_workflow_confirms_without_any_gateway_execution(self):
        self.run_workflow()
        state = self.read()
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['operation']['result'], {'outcome': 'succeeded', 'data': {'backend': 'applied'}})
        self.assertEqual(state['registryReads'], 1)
        self.assertFalse(any('/primitive' in path or 'claim' in path or 'receipt' in path for path in state['calls']))
    def test_actual_workflow_confirms_app_create_without_replaying_registry_mutation(self):
        self.state['operation']['kind'] = 'websocket.app_create'; self.save()
        self.run_workflow()
        self.assertEqual(self.read()['operation']['status'], 'succeeded')
        self.assertEqual(self.read()['registryReads'], 1)
        self.assertFalse(any('/websocket-apps' in path for path in self.read()['calls']))
    def test_unbound_or_foreign_run_reads_no_registry_and_can_resume_after_binding(self):
        self.state['bound'] = False; self.save()
        self.run_workflow(success=False)
        self.state = self.read(); self.assertEqual(self.state['registryReads'], 0)
        self.state['bound'] = True; self.save()
        self.cli('workflow', 'resume', 'operation-websocket-registry', '--run', self.run_id, '--from', 'confirm')
        self.assertEqual(self.read()['operation']['status'], 'succeeded')
    def test_lost_completion_does_not_repeat_registry_confirmation(self):
        self.state['loseCompletion'] = True; self.save()
        self.run_workflow(success=False)
        self.cli('workflow', 'resume', 'operation-websocket-registry', '--run', self.run_id, '--from', 'confirm')
        self.assertEqual(self.read()['registryReads'], 1)

if __name__ == '__main__': unittest.main()
