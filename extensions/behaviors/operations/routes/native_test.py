"""Run the real parent/dispatch/Route DAG and recover in a new Swamp process."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[4]
SWAMP = os.environ.get('SWAMP_BIN', shutil.which('swamp') or 'swamp')


class RouteNative(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='orbit-routes-native-')
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        shutil.copy(ROOT / '.swamp.yaml', self.repo)
        behavior = self.repo / 'extensions/behaviors'
        for family in ['automatic-deploy', 'operation-runner', 'operations/routes']:
            shutil.copytree(ROOT / 'extensions/behaviors' / family, behavior / family)
        for family, factory, relative in [('operation-runner', 'operationRunner', '../operations/routes/native_fixture.ts'), ('operations/routes', 'routes', './native_fixture.ts')]:
            module = behavior / family / 'mod.ts'
            module.write_text('import { fixtureHttp } from "' + relative + '";\n' + module.read_text().replace('...' + factory + '()', '...' + factory + '(() => fixtureHttp)'))
        (self.repo / 'workflows').mkdir()
        for name in ['operation-runner', 'operation-dispatch', 'operation-routes']:
            path = ROOT / ('workflows/workflow-' + name + '.yaml')
            source = '\n'.join(line for line in path.read_text().splitlines()
                               if not line.startswith(('labels:', 'queueTimeout:'))) + '\n'
            (self.repo / 'workflows' / path.name).write_text(source)
        self.state = {'operation': {'id': 17, 'kind': 'route.caddy_converge', 'node_id': 2, 'status': 'queued', 'cancel_requested': False, 'input': {'reason': 'private dev proof'}, 'steps': [], 'step_results': {}}, 'prepared': False, 'runs': 0, 'receipts': {}, 'receiptReads': 0, 'invocations': [], 'completions': []}

    def save(self):
        (self.repo / 'native-routes.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / 'native-routes.json').read_text())

    def swamp(self, *args, success=True):
        result = subprocess.run([SWAMP, *args, '--json'], cwd=self.repo, capture_output=True, text=True, timeout=45,
            env={**os.environ, 'SWAMP_NO_UPDATE_CHECK': '1', 'SWAMP_NO_TELEMETRY': '1'})
        if success:
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        else:
            self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
        return result

    def test_admitted_caddy_operation_runs_through_parent_and_dispatch(self):
        self.save()
        self.swamp('workflow', 'validate', 'operation-routes')
        self.swamp('workflow', 'run', 'operation-runner')
        state = self.read()
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['runs'], 1)
        self.assertEqual([s['name'] for s in state['operation']['steps']], ['prepare', 'caddy-publish'])

    def test_lost_node_reply_is_read_from_target_receipt_after_process_restart(self):
        self.state['loseRunReply'] = True
        self.save()
        self.swamp('workflow', 'run', 'operation-runner', success=False)
        self.assertEqual(self.read()['runs'], 1)
        self.assertEqual(self.read()['completions'], [])
        self.swamp('workflow', 'resume', 'operation-routes', '--from', 'execute')
        state = self.read()
        self.assertEqual(state['runs'], 1)
        self.assertEqual(len(state['invocations']), 1)
        self.assertEqual(state['receiptReads'], 1)
        self.assertEqual(state['operation']['status'], 'succeeded')

    def test_private_dns_completion_reply_loss_replays_completion_without_publication(self):
        self.state['operation']['kind'] = 'route.private_dns_publish'
        self.state['loseCompleteReply'] = True
        self.save()
        self.swamp('workflow', 'run', 'operation-runner', success=False)
        self.assertEqual(self.read()['runs'], 1)
        self.swamp('workflow', 'resume', 'operation-routes', '--from', 'execute')
        state = self.read()
        self.assertEqual(state['runs'], 1)
        self.assertEqual(len(state['invocations']), 1)
        self.assertEqual(len(state['completions']), 1)
        self.assertEqual(state['operation']['status'], 'succeeded')


if __name__ == '__main__':
    unittest.main()
