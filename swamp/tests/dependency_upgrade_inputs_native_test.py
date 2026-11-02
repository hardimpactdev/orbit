"""Run the real Git-to-inventory-to-plan chain on a disposable local repository."""
import json
import shutil
import subprocess

import yaml
from native_harness import ROOT, NativeTestCase


class DependencyUpgradeInputsNativeTest(NativeTestCase):
    def test_real_fetch_sha_scan_and_plan_use_the_same_commit(self):
        source = self.repo / 'source'
        source.mkdir()
        def git(*args):
            return subprocess.check_output(['git', '-C', str(source), *args], text=True).strip()
        git('init', '-q', '-b', 'main')
        (source / 'composer.json').write_text('{"require":{}}\n')
        git('add', '.')
        git('-c', 'user.name=fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'empty inventory')
        expected = git('rev-parse', 'HEAD')
        (source / 'composer.json').write_text('{"require":{"never/lookup":"^99"}}\n')
        behavior = self.repo / 'extensions/behaviors'
        (behavior / '_lib').mkdir(parents=True)
        shutil.copy(ROOT / 'extensions/behaviors/orbit_deps.ts', behavior)
        for name in ('inventory.ts', 'versions.ts'):
            shutil.copy(ROOT / 'extensions/behaviors/_lib' / name, behavior / '_lib')
        models = self.repo / 'models'
        shutil.copy(ROOT / 'models/@nckrtl/orbit-deps/orbit-deps.yaml', models / 'orbit-deps.yaml')
        flow = yaml.safe_load((ROOT / 'workflows/workflow-dependency-upgrade.yaml').read_text())
        for job in flow['jobs']:
            self.assertEqual(job.pop('labels'), {'role': 'swamp'})
            for step in job['steps']:
                self.assertEqual(step.pop('labels'), {'role': 'swamp'})
        (self.repo / 'workflows/workflow-dependency-upgrade.yaml').write_text(yaml.safe_dump(flow, sort_keys=False))
        git('remote', 'add', 'origin', str(source))
        inputs = self.repo / 'inputs.json'
        inputs.write_text(json.dumps({'checkoutDir': str(source), 'branch': 'main'}))
        self.cli('workflow', 'validate', 'dependency-upgrade')
        result = json.loads(self.cli('workflow', 'run', 'dependency-upgrade', '--input-file', str(inputs)).stdout)
        self.assertEqual(result['status'], 'succeeded')
        run = json.loads(self.cli('workflow', 'history', 'get', result['id']).stdout)
        steps = {step['name']: step for job in run['jobs'] for step in job['steps']}
        self.assertEqual(steps['head']['outputs']['stdout'].strip(), expected)
        self.assertEqual(steps['scan']['outputs']['commit'], expected)
        self.assertEqual(steps['plan']['outputs']['commit'], expected)
        self.assertEqual(steps['plan']['outputs']['counts']['packages'], 0)
        self.assertEqual(steps['approve-majors']['status'], 'skipped')
        self.assertIn('never/lookup', (source / 'composer.json').read_text())
