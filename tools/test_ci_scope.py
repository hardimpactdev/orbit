"""Run the shared scope action against real import and shallow event histories."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import textwrap
import unittest

ROOT = Path(__file__).resolve().parents[1]
ACTION = (ROOT / '.github/actions/ci-scope/action.yml').read_text()
COMMAND = textwrap.dedent(ACTION.split('      run: |\n', 1)[1])


class ScopeActionTest(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory(prefix='ci-scope-')
        self.addCleanup(self.scratch.cleanup)
        self.root = Path(self.scratch.name)
        self.git('init')
        (self.root / '.swamp.yaml').write_text('repoId: fixture\n')
        self.commit('Original Swamp')
        self.source = self.git('rev-parse', 'HEAD')
        self.git('checkout', '--orphan', 'orbit')
        self.git('rm', '-rf', '.')
        (self.root / 'bin').mkdir()
        shutil.copyfile(ROOT / 'bin/ci-build-plan', self.root / 'bin/ci-build-plan')
        (self.root / 'apps/gateway').mkdir(parents=True)
        (self.root / 'apps/gateway/example.php').write_text('<?php // base\n')
        self.commit('Orbit before import')
        self.before_import = self.git('rev-parse', 'HEAD')
        self.git('read-tree', '--prefix=swamp/', self.source)
        (self.root / 'swamp').mkdir()
        (self.root / 'swamp/import-source.json').write_text(json.dumps({'shape_base': self.source}))
        self.git('add', '.')
        imported = self.git('commit-tree', self.git('write-tree'), '-p', self.before_import,
                            '-p', self.source, '-m', 'Import')
        self.git('reset', '--hard', imported)
        self.imported = imported

    def git(self, *args):
        return subprocess.check_output(['git', '-c', 'user.name=Fixture', '-c',
            'user.email=fixture@example.com', *args], cwd=self.root,
            stderr=subprocess.DEVNULL, timeout=60).decode().strip()

    def commit(self, message):
        self.git('add', '.')
        self.git('commit', '-m', message)

    def scope(self, base, *, history=False, root=None, enabled=True):
        root = root or self.root
        output = root / '.git/scope-output'
        output.write_text('')
        result = subprocess.run(['bash', '-e', '-o', 'pipefail', '-c', COMMAND], cwd=root,
            env=dict(os.environ, SCOPE_JOB='swamp', SCOPE_ENABLED=str(enabled).lower(),
                SWAMP_BASE=str(history).lower(), BASE=base, EVENT_SHA='HEAD', MAIN='false',
                GITHUB_OUTPUT=str(output)), capture_output=True, text=True, timeout=60)
        values = dict(line.split('=', 1) for line in output.read_text().splitlines())
        return result, values

    def test_initial_import_and_later_change_keep_actual_frozen_comparison(self):
        result, values = self.scope(self.before_import, history=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(values, {'selected': 'true', 'swamp-base-ref': self.source})
        (self.root / 'swamp/workflow.yaml').write_text('name: next\n')
        self.commit('Later Swamp change')
        result, values = self.scope(self.imported, history=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(values, {'selected': 'true', 'swamp-base-ref': self.imported})

    def test_shallow_validation_selects_whole_event_without_resolving_import_history(self):
        shallow = self.root / 'shallow'
        self.git('clone', '--depth=1', self.root.as_uri(), str(shallow))
        result, values = self.scope(self.before_import, root=shallow)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(values, {'selected': 'true', 'swamp-base-ref': ''})
        absent = subprocess.run(['git', 'cat-file', '-e', self.source], cwd=shallow,
                                capture_output=True, timeout=60)
        self.assertNotEqual(absent.returncode, 0)

    def test_gateway_only_skips_swamp_and_mixed_event_keeps_it(self):
        (self.root / 'apps/gateway/example.php').write_text('<?php // changed\n')
        self.commit('Gateway change')
        gateway = self.git('rev-parse', 'HEAD')
        result, values = self.scope(self.imported, history=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(values['selected'], 'false')
        (self.root / 'swamp/workflow.yaml').write_text('name: mixed\n')
        self.commit('Swamp tip')
        self.assertEqual(self.scope(self.imported)[1]['selected'], 'true')
        self.assertEqual(self.scope(gateway)[1]['selected'], 'true')

    def test_bad_comparison_fails_and_zero_event_base_uses_parent(self):
        result, _ = self.scope('f' * 40)
        self.assertNotEqual(result.returncode, 0)
        result, values = self.scope('0' * 40, history=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(values['swamp-base-ref'], self.source)
        self.assertEqual(self.scope('', enabled=False)[1], {'selected': 'true'})

    def test_workflow_starts_swamp_without_selector_and_scopes_before_toolchains(self):
        ci = (ROOT / '.github/workflows/ci.yml').read_text()
        swamp = ci.split('  swamp:\n', 1)[1].split('  gateway-release:', 1)[0]
        self.assertNotIn('needs:', swamp)
        self.assertIn('changed-only: true', swamp)
        source = (ROOT / '.github/workflows/swamp-shape.yml').read_text()
        self.assertEqual(source, (ROOT / 'swamp/.github/workflows/swamp-shape.yml').read_text())
        self.assertIn('working-directory: ${{ github.workspace }}', ACTION)
        for name, end in [('shape', 'validate'), ('validate', 'tests'), ('test-shards', None)]:
            job = source.split('  ' + name + ':\n', 1)[1]
            if end:
                job = job.split('  ' + end + ':\n', 1)[0]
            self.assertLess(job.index('id: scope'), job.index('uses: denoland/setup-deno'))
            for step in job.split('    steps:\n', 1)[1].split('      - ')[2:]:
                if 'id: scope' in step:
                    continue
                self.assertIn("steps.scope.outputs.selected == 'true'", step)
        shape = source.split('  shape:', 1)[1].split('  validate:', 1)[0]
        self.assertIn('fetch-depth: 0', shape)
        self.assertIn('steps.scope.outputs.swamp-base-ref', shape)
        self.assertIn('check-swamp-base-policy.py --base "$base"', shape)


if __name__ == '__main__':
    unittest.main()
