"""Local checks only: real native validation plus intentional placement boundaries."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import yaml

REPO = Path(__file__).resolve().parents[1]
CLI = os.environ.get('SWAMP_BIN', 'swamp')
SHARED = ('maintenance-projects', 'horizon-new-dawn-detect', 'dependency-package',
          'dependency-routine', 'dependency-upgrade')


def placed_workflows():
    return (sorted(REPO.glob('workflows/workflow-project-*-detect.yaml'))
            + sorted(REPO.glob('workflows/workflow-project-*-routine.yaml'))
            + [REPO / f'workflows/workflow-{name}.yaml' for name in SHARED])


class HeavyWorkflowPlacementTest(unittest.TestCase):
    def test_heavy_methods_are_host_bound_and_native_coordinators_remain_local(self):
        for path in placed_workflows():
            workflow = yaml.safe_load(path.read_text())
            self.assertEqual(workflow['labels'], {'pool': 'migration', 'node': '209'}, path.name)
            self.assertEqual(workflow['queueTimeout'], 300)
            for job in workflow['jobs']:
                for step in job['steps']:
                    task = step['task']
                    if task.get('type') in ('workflow', 'assert', 'manual_approval'):
                        self.assertEqual(step.get('labels', job.get('labels')), {}, path.name)
                    if task.get('modelType') == '@nckrtl/native-host-method':
                        self.assertNotIn('labels', step)
                        self.assertNotIn('labels', job)
        horizon = yaml.safe_load((REPO / 'workflows/workflow-horizon-new-dawn-detect.yaml').read_text())
        self.assertEqual(horizon['jobs'][1]['target'], '${{ inputs.worker }}')
        self.assertEqual(horizon['jobs'][1]['labels'], {})
        for name in ('groups-driver', 'subtasks-driver', 'maintenance-workers',
                     'runtime-retention', 'dependency-results-prune'):
            path = REPO / f'workflows/workflow-{name}.yaml'
            workflow = yaml.safe_load(path.read_text())
            self.assertNotIn('labels', workflow, name)

    def test_pinned_native_loader_validates_the_shipped_workflows(self):
        with tempfile.TemporaryDirectory(prefix='heavy-workflow-validation-') as tmp:
            root = Path(tmp)
            shutil.copy(REPO / '.swamp.yaml', root)
            for directory in ('extensions', 'models', 'workflows'):
                shutil.copytree(REPO / directory, root / directory)
            # Use already installed dependencies. Never pull SSH or mutate a live repo.
            for package in ('git', 'software-factory', 'agent-runner'):
                source = REPO / '.swamp/pulled-extensions/@swamp' / package
                shutil.copytree(source, root / '.swamp/pulled-extensions/@swamp' / package)
            env = dict(os.environ, SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1')
            for key in ('SWAMP_SERVER_URL', 'SWAMP_SERVE_URL', 'SWAMP_SERVER_TOKEN',
                        'SWAMP_SERVER_TOKEN_FILE', 'SWAMP_REPO_DIR', 'SWAMP_CA_CERT'):
                env.pop(key, None)
            result = subprocess.run([CLI, 'workflow', 'validate', '--json'], cwd=root,
                                    env=env, capture_output=True, text=True, timeout=90)
            self.assertEqual(result.returncode, 0, result.stdout[-3000:] + result.stderr[-2000:])
            # Native bulk validation emits a stream of pretty-printed JSON values.
            remaining, reports = result.stdout.strip(), []
            while remaining:
                report, end = json.JSONDecoder().raw_decode(remaining)
                reports.append(report)
                remaining = remaining[end:].lstrip()
            summary = reports[-1]
            self.assertTrue(summary['passed'])
            self.assertEqual(summary['totalFailed'], 0)
            names = {item['workflowName'] for item in summary['workflows']}
            self.assertTrue({yaml.safe_load(p.read_text())['name'] for p in placed_workflows()} <= names)


if __name__ == '__main__':
    unittest.main()
