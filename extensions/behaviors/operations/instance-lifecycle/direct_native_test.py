"""Direct rename DAG and recovery with native Swamp and a fixture Node action.

This runs locally without remote placement; deployment proof must verify the
exact Node worker and Gateway start-response / first-read ordering.
"""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[4]
SWAMP = os.environ.get('SWAMP_BIN', shutil.which('swamp') or 'swamp')


class DirectRenameNative(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='orbit-lifecycle-direct-')
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        shutil.copy(ROOT / '.swamp.yaml', self.repo)
        behavior = self.repo / 'extensions/behaviors'
        for family in ['automatic-deploy', 'operation-runner', 'operations/_lib']:
            shutil.copytree(ROOT / 'extensions/behaviors' / family, behavior / family)
        dest = behavior / 'operations/instance-lifecycle'
        dest.mkdir()
        for name in ['direct.ts', 'direct_native_fixture.ts']:
            shutil.copy(ROOT / 'extensions/behaviors/operations/instance-lifecycle' / name, dest / name)
        module = dest / 'direct.ts'
        module.write_text('import {fixtureHttp, fixtureInspect} from "./direct_native_fixture.ts";\n' + module.read_text().replace('...lifecycleDirect(),', '...lifecycleDirect(() => fixtureHttp, fixtureInspect),'))
        (self.repo / 'workflows').mkdir()
        source = ROOT / 'workflows/workflow-operation-instance-lifecycle-direct.yaml'
        (self.repo / 'workflows' / source.name).write_text('\n'.join(line for line in source.read_text().replace('methodName: bindingCanary', 'methodName: rename').splitlines() if not line.startswith(('target:', 'labels:')))+'\n')
        self.state = {'bound': True, 'calls': [], 'inspections': 0,
                      'operation': {'id': 17, 'kind': 'instance.rename', 'node_id': 9,
                                    'status': 'queued', 'result': None, 'cancel_requested': False,
                                    'input': {'agent_sha256': 'a' * 64, 'repository_identity': 'github.com/example/app', 'branch': 'feature',
                                              'source': {'repository': 'https://github.com/example/app.git', 'checkout': '/srv/apps/app/proof',
                                                         'root': '/srv/apps/app', 'user': 'orbit', 'group': 'orbit', 'prepare_id': None,
                                                         'seed_repository': None, 'verify_origin': False, 'require_idle': True}}},
                      'agent': {'ok': True, 'data': {'origin': 'git@github.com:example/app.git', 'branch': 'feature', 'commit': 'b' * 40}}}
        self.save()

    def save(self):
        (self.repo / 'direct-lifecycle-fixture.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / 'direct-lifecycle-fixture.json').read_text())

    def cli(self, *args, success=True):
        env = dict(os.environ, SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1')
        for key in ('SWAMP_SERVER_URL', 'SWAMP_SERVE_URL', 'SWAMP_SERVER_TOKEN', 'SWAMP_SERVER_TOKEN_FILE', 'SWAMP_REPO_DIR', 'SWAMP_CA_CERT'):
            env.pop(key, None)
        result = subprocess.run([SWAMP, *args, '--json'], cwd=self.repo, env=env, text=True, capture_output=True, timeout=60)
        if success:
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        else:
            self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
        return result

    def run_workflow(self, success=True):
        self.cli('workflow', 'validate', 'operation-instance-lifecycle-direct')
        result = self.cli('workflow', 'run', 'operation-instance-lifecycle-direct', '--input', 'operation_id=17', '--input', 'node_id=9', '--input', 'kind=instance.rename', success=success)
        self.run_id = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']
        if not success:
            self.cli('report', 'get', '@swamp/workflow-summary', '--workflow', 'operation-instance-lifecycle-direct')
        return result

    def test_direct_branch_recording_uses_only_shared_read_and_result(self):
        self.run_workflow()
        state = self.read()
        self.assertEqual(state['inspections'], 1)
        self.assertEqual(state['operation']['result'], {'source_verified': True, 'branch': 'feature'})
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['calls'], [f'/api/v1/direct-operations/17?run_id={self.run_id}', '/api/v1/direct-operations/17/result', '/api/v1/direct-operations/17/result'])

    def test_lost_terminal_reply_resumes_without_repeating_inspection(self):
        self.state['loseCompletion'] = True
        self.save()
        self.run_workflow(success=False)
        self.cli('workflow', 'resume', 'operation-instance-lifecycle-direct', '--run', self.run_id, '--from', 'inspect')
        self.assertEqual(self.read()['inspections'], 1)
        self.assertEqual(self.read()['operation']['status'], 'succeeded')

    def test_foreign_binding_first_read_never_reaches_the_node_action(self):
        self.state['bound'] = False
        self.save()
        self.run_workflow(success=False)
        self.assertEqual(self.read()['inspections'], 0)
        self.assertEqual(self.read()['operation']['status'], 'queued')

    def test_migration_canary_projects_a_refusal_without_a_local_action(self):
        workflow = self.repo / 'workflows/workflow-operation-instance-lifecycle-direct.yaml'
        workflow.write_text(workflow.read_text().replace('methodName: rename', 'methodName: bindingCanary'))
        self.run_workflow(success=False)
        self.assertEqual(self.read()['inspections'], 0)
        self.assertEqual(self.read()['operation']['status'], 'failed')
        self.assertEqual(self.read()['operation']['result'], {'error_code': 'instance.node_action_unavailable'})

    def test_cancelled_intent_does_not_inspect(self):
        self.state['operation']['cancel_requested'] = True
        self.save()
        self.run_workflow()
        self.assertEqual(self.read()['inspections'], 0)
        self.assertEqual(self.read()['operation']['status'], 'cancelled')


if __name__ == '__main__':
    unittest.main()
