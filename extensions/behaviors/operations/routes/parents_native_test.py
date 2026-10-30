"""Exercise real Swamp parent yields and re-entry across native process restarts."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[4]
SWAMP = os.environ.get('SWAMP_BIN', shutil.which('swamp') or 'swamp')


class RouteParentNative(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='orbit-route-parent-native-')
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        shutil.copy(ROOT / '.swamp.yaml', self.repo)
        behavior = self.repo / 'extensions/behaviors'
        for family in ['automatic-deploy', 'operation-runner', 'operations/routes']:
            shutil.copytree(ROOT / 'extensions/behaviors' / family, behavior / family)
        for family, factory, relative in [('operation-runner', 'operationDiscovery', '../operations/routes/parent_native_fixture.ts'), ('operations/routes', 'routes', './parent_native_fixture.ts')]:
            module = behavior / family / 'mod.ts'
            module.write_text('import { fixtureHttp } from "' + relative + '";\n' + module.read_text().replace('...' + factory + '()', '...' + factory + '(() => fixtureHttp)'))
        (self.repo / 'workflows').mkdir()
        for name in ['operation-routes']:
            path = ROOT / ('workflows/workflow-' + name + '.yaml')
            source = '\n'.join(line for line in path.read_text().splitlines()
                               if not line.startswith(('labels:', 'queueTimeout:'))) + '\n'
            (self.repo / 'workflows' / path.name).write_text(source)
        self.state = {'operations': {'17': {'id': 17, 'kind': 'route.withdraw', 'node_id': 2, 'input': {'route_id': 8},
            'status': 'queued', 'cancel_requested': False, 'steps': [], 'step_results': {}}},
            'nodeEffects': 0, 'childAdmissions': 0, 'waits': 0, 'phaseCommits': [], 'completions': []}

    def save(self):
        (self.repo / 'native-parent.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / 'native-parent.json').read_text())

    def swamp(self, *args, success=True):
        if args[:2] == ("workflow", "resume"):
            args = (*args[:3], "--run", self.last_run_id)
        result = subprocess.run([SWAMP, *args, '--json'], cwd=self.repo, capture_output=True, text=True, timeout=45,
            env={**os.environ, 'SWAMP_NO_UPDATE_CHECK': '1', 'SWAMP_NO_TELEMETRY': '1'})
        if args[:2] == ("workflow", "run"):
            value = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]
            self.last_run_id = value.get("id")
        if success:
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        else:
            self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)

    def finish(self):
        self.swamp('workflow', 'run', 'operation-routes')
        self.swamp('workflow', 'run', 'operation-routes')
        state = self.read()
        self.assertEqual(state['operations']['17']['status'], 'succeeded')
        self.assertEqual(state['nodeEffects'], 1)
        self.assertEqual(state['childAdmissions'], 1)
        self.assertEqual(state['phaseCommits'], ['dns', 'caddy', 'certificates', 'firewall', 'record'])

    def test_parent_yields_and_normal_runner_drives_its_child_then_reclaims_parent(self):
        self.save()
        self.swamp('workflow', 'run', 'operation-routes')
        state = self.read()
        self.assertEqual(state['operations']['17']['status'], 'waiting')
        self.assertEqual(state['completions'], [])
        self.finish()

    def test_lost_child_admission_reply_reuses_the_same_child_after_restart(self):
        self.state['loseChildReply'] = True
        self.save()
        self.swamp('workflow', 'run', 'operation-routes', success=False)
        self.assertEqual(self.read()['childAdmissions'], 1)
        self.swamp('workflow', 'resume', 'operation-routes')
        self.assertEqual(self.read()['operations']['17']['status'], 'waiting')
        self.finish()

    def test_lost_phase_commit_reply_reuses_proof_without_repeating_child_effects(self):
        self.state['loseCommitReply'] = True
        self.save()
        self.swamp('workflow', 'run', 'operation-routes')
        self.swamp('workflow', 'run', 'operation-routes')
        self.swamp('workflow', 'run', 'operation-routes', success=False)
        self.swamp('workflow', 'resume', 'operation-routes')
        state = self.read()
        self.assertEqual(state['operations']['17']['status'], 'succeeded')
        self.assertEqual(state['nodeEffects'], 1)
        self.assertEqual(state['phaseCommits'].count('caddy'), 1)


if __name__ == '__main__':
    unittest.main()
