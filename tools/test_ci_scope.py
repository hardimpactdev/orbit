"""Run the shared scope action against real import and shallow event histories."""
import json
import os
import shutil
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path

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

    def scope(self, base, *, history=False, root=None, enabled=True, job="swamp"):
        root = root or self.root
        output = root / '.git/scope-output'
        output.write_text('')
        result = subprocess.run(['bash', '-e', '-o', 'pipefail', '-c', COMMAND], cwd=root,
            env=dict(os.environ, SCOPE_JOB=job, SCOPE_ENABLED=str(enabled).lower(),
                SWAMP_BASE=str(history).lower(), BASE=base, EVENT_SHA='HEAD', MAIN='false',
                GITHUB_OUTPUT=str(output)), capture_output=True, text=True, timeout=60, check=False)
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
                                capture_output=True, timeout=60, check=False)
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

    def test_swamp_subjobs_use_the_same_plan_and_unknown_roots_fail_the_action(self):
        (self.root / 'swamp/ansible').mkdir()
        (self.root / 'swamp/ansible/task-vm-runtime.yml').write_text('---\n')
        self.commit('Ansible only')
        self.assertEqual(self.scope(self.imported, job='swamp-shape')[1]['selected'], 'true')
        self.assertEqual(self.scope(self.imported, job='swamp-tests')[1]['selected'], 'true')
        self.assertEqual(self.scope(self.imported, job='swamp-validate')[1]['selected'], 'false')
        (self.root / 'apps/new').mkdir()
        (self.root / 'apps/new/source.ts').write_text('export {};\n')
        self.commit('Unknown source root')
        done, values = self.scope(self.imported)
        self.assertNotEqual(done.returncode, 0)
        self.assertNotIn('selected', values)
        self.assertIn('Unknown CI source root', done.stderr)

    def test_gateway_pest_scope_follows_cli_fixture_and_skips_docs(self):
        (self.root / 'docs').mkdir()
        (self.root / 'docs/example.md').write_text('Docs only\n')
        self.commit('Docs change')
        docs = self.git('rev-parse', 'HEAD')
        result, values = self.scope(self.imported, job='gateway-tests')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(values['selected'], 'false')
        fixture = self.root / 'apps/cli/tests/Fixtures/Console/command-consent.php'
        fixture.parent.mkdir(parents=True)
        fixture.write_text('<?php // the NodeMetrics child CLI fixture\n')
        self.commit('CLI consent fixture')
        result, values = self.scope(docs, job='gateway-tests')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(values['selected'], 'true')

    def test_bad_comparison_fails_and_zero_event_base_uses_parent(self):
        result, _ = self.scope('f' * 40)
        self.assertNotEqual(result.returncode, 0)
        result, values = self.scope('0' * 40, history=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(values['swamp-base-ref'], self.source)
        self.assertEqual(self.scope('', enabled=False)[1], {'selected': 'true'})

    def test_workflow_scopes_the_swamp_gate_before_toolchains(self):
        ci = (ROOT / '.github/workflows/ci.yml').read_text()
        swamp = ci.split('  swamp:\n', 1)[1].split('  gateway-release:', 1)[0]
        self.assertNotIn('needs:', swamp)
        self.assertIn('changed-only: true', swamp)
        source = (ROOT / '.github/workflows/swamp-shape.yml').read_text()
        self.assertIn('working-directory: ${{ github.workspace }}', ACTION)
        for name, end in [('shape', 'validate'), ('validate', 'tests'), ('tests', None)]:
            job = source.split('  ' + name + ':\n', 1)[1]
            if end:
                job = job.split('  ' + end + ':\n', 1)[0]
            self.assertLess(job.index('id: scope'), job.index('uses: denoland/setup-deno'))
            for step in job.split('    steps:\n', 1)[1].split('      - ')[2:]:
                if 'id: scope' in step:
                    continue
                self.assertIn("steps.scope.outputs.selected == 'true'", step)
        # Validation needs the Swamp credential, which Dependabot pull requests do not get; it runs only for Swamp
        # inputs, and fully on the nightly schedule.
        validate = source.split('  validate:\n', 1)[1].split('  tests:\n', 1)[0]
        scope = validate.split('      - uses: ./.github/actions/ci-scope\n', 1)[1].split('      - ', 1)[0]
        self.assertNotIn('if:', scope)
        self.assertIn("enabled: ${{ github.event_name != 'schedule' }}", scope)
        self.assertNotIn('inputs.changed-only', validate)
        shape = source.split('  shape:', 1)[1].split('  validate:', 1)[0]
        self.assertIn('fetch-depth: 0', shape)
        self.assertIn('steps.scope.outputs.swamp-base-ref', shape)
        self.assertIn('bin/swamp-shape-gate" --only shape --strict --base "$SHAPE_BASE"', shape)


if __name__ == '__main__':
    unittest.main()
