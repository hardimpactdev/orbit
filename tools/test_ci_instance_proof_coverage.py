"""Execution changes must not look proven when the lifecycle has no real case."""
import contextlib
import importlib.machinery
import importlib.util
import io
import os
from pathlib import Path
import json
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parent.parent
loader = importlib.machinery.SourceFileLoader('instance_plan', str(ROOT / 'bin/ci-build-plan'))
spec = importlib.util.spec_from_loader(loader.name, loader)
plan = importlib.util.module_from_spec(spec)
loader.exec_module(plan)
COMMAND = 'instance:create'
FILE = 'swamp/proof/instance/create.cases.yaml'
PATH = 'swamp/scripts/instance_clone.py'
MAPPING = f'** none: no cases\n{PATH} {COMMAND}\n{FILE} {COMMAND}\n'
REAL = '''command: instance:create
chart: instance/container/create
target: app-dev
sources: [gateway]
baseline: [operations]
cases:
  - id: clone-runtime
    batch: fresh
    covers: [main]
    summary: Create a clone through its real Operation.
    act:
      - cli: {run: [instance:create, fixture, clone], expect: {exit: 0, status: succeeded}}
    expect: []
'''


class InstanceExecutionProofTest(unittest.TestCase):
    def read(self, before=None, after=None, mapping=MAPPING, old_mapping=None):
        def read(ref, path):
            if path == plan.SELECTION:
                return (old_mapping if old_mapping is not None else mapping) if ref == 'base' else mapping
            if path.endswith('batches.yaml'):
                return 'batches: {fresh: {}}'
            return (before if ref == 'base' else after) if path == FILE else None
        return read

    def assess(self, before=None, after=None, mapping=MAPPING, old_mapping=None, paths=(PATH,)):
        read = self.read(before, after, mapping, old_mapping)
        coverage = plan.instance_execution_coverage(paths, mapping, read, tracked_paths=[PATH])
        selected = plan.proof_cases(paths, mapping, read, [FILE] if after is not None else [], coverage)
        return coverage, selected

    def test_missing_cases_warn_without_allocating_proof(self):
        coverage, selected = self.assess()
        self.assertEqual(selected, [])
        with tempfile.TemporaryDirectory() as directory:
            summary = Path(directory) / 'summary'
            output = io.StringIO()
            with patch.dict(os.environ, {'GITHUB_STEP_SUMMARY': str(summary)}), contextlib.redirect_stderr(output):
                plan.report_instance_execution_coverage(coverage, selected)
            self.assertIn('::warning title=Instance execution proof coverage::no real proof case covers these executed files: ' + PATH, output.getvalue())
            self.assertIn(PATH, summary.read_text())
            self.assertNotIn('::error', output.getvalue())

    def test_refusals_empty_cases_and_known_failures_do_not_activate(self):
        for case in (REAL.replace('exit: 0, status: succeeded', 'exit: 1, code: http.404, request: none'),
                     REAL.split('cases:')[0] + 'cases: []\n',
                     REAL.replace('    act:', '    fails_today: fixture unavailable\n    act:'),
                     REAL.replace('instance:create, fixture', 'instance:show, fixture')):
            with self.subTest(case=case):
                coverage, selected = self.assess(after=case)
                self.assertFalse(coverage[0]['enforced'])
                self.assertEqual(selected, [{'command': COMMAND, 'file': FILE, 'only': [], 'reasons': {}}])

    def test_first_real_case_activates_only_its_command(self):
        mapping = MAPPING.replace(f'{PATH} {COMMAND}', f'{PATH} {COMMAND} instance:destroy')
        coverage, selected = self.assess(after=REAL, mapping=mapping)
        self.assertEqual([(c['command'], c['enforced']) for c in coverage], [(COMMAND, True), ('instance:destroy', False)])
        self.assertEqual(selected[0]['only'], ['clone-runtime'])
        with contextlib.redirect_stderr(io.StringIO()) as output:
            plan.report_instance_execution_coverage(coverage, selected)
        self.assertNotIn('(instance:create)', output.getvalue())
        self.assertIn('(instance:destroy)', output.getvalue())

    def test_removed_last_case_or_mapping_cannot_disable_guard(self):
        for after in (None, REAL.split('cases:')[0] + 'cases: []\n', REAL.replace('exit: 0, status: succeeded', 'exit: 1, code: http.404')):
            with self.subTest(after=after):
                coverage, selected = self.assess(before=REAL, after=after)
                with contextlib.redirect_stderr(io.StringIO()), self.assertRaisesRegex(ValueError, 'no real proof case'):
                    plan.report_instance_execution_coverage(coverage, selected)
        for mapping in ('** skip\n', MAPPING + f'{PATH} skip\n'):
            coverage, selected = self.assess(before=REAL, after=REAL, mapping=mapping, old_mapping=MAPPING)
            with contextlib.redirect_stderr(io.StringIO()), self.assertRaisesRegex(ValueError, 'no real proof case'):
                plan.report_instance_execution_coverage(coverage, selected)

    def test_case_only_removal_is_guarded_but_comment_only_edit_is_not(self):
        coverage, selected = self.assess(before=REAL, after=None, paths=[FILE])
        with contextlib.redirect_stderr(io.StringIO()), self.assertRaises(ValueError):
            plan.report_instance_execution_coverage(coverage, selected)
        coverage, selected = self.assess(before=REAL, after=REAL + '\n# wording\n', paths=[FILE])
        # No execution changed and the real case still exists: do not force a rerun for comments.
        self.assertEqual(coverage, [])

    def test_real_case_missing_from_selected_ids_is_an_error(self):
        coverage, _ = self.assess(after=REAL)
        for selected in ([], None, [{'file': FILE, 'only': ['invalid-id']}], [{'file': FILE, 'only': []}]):
            with contextlib.redirect_stderr(io.StringIO()), self.assertRaises(ValueError):
                plan.report_instance_execution_coverage(coverage, selected)

    def test_mapping_only_removal_is_rejected_before_a_later_execution_edit(self):
        for mapping in ('** skip\n', MAPPING + f'{PATH} skip\n'):
            # Step one cannot establish a new base without ownership.
            coverage, selected = self.assess(before=REAL, after=REAL, mapping=mapping,
                                             old_mapping=MAPPING, paths=[plan.SELECTION])
            self.assertEqual(coverage[0]['missing_mappings'], [PATH])
            with contextlib.redirect_stderr(io.StringIO()), self.assertRaises(ValueError):
                plan.report_instance_execution_coverage(coverage, selected)
            # The next execution edit still compares against the last accepted base.
            coverage, selected = self.assess(before=REAL, after=REAL, mapping=mapping,
                                             old_mapping=MAPPING, paths=[PATH, plan.SELECTION])
            with contextlib.redirect_stderr(io.StringIO()), self.assertRaises(ValueError):
                plan.report_instance_execution_coverage(coverage, selected)

    def test_clone_candidate_has_no_create_ownership(self):
        mapping = (ROOT / plan.SELECTION).read_text()
        coverage = plan.instance_execution_coverage(['swamp/workflows/workflow-instance-clone-candidate.yaml'],
                                                    mapping, self.read(after=REAL, mapping=mapping))
        self.assertEqual([item['command'] for item in coverage], ['instance:clone'])
        self.assertFalse(coverage[0]['enforced'])

    def test_incident_and_each_lifecycle_entry_warn_today(self):
        mapping = (ROOT / plan.SELECTION).read_text()
        def read(ref, path):
            p = ROOT / path
            return p.read_text() if p.exists() else None
        paths = [PATH, 'swamp/scripts/instance_clone_guest.py',
                 'swamp/extensions/behaviors/operations/instance-dev-command/assets.ts']
        paths += [f'swamp/workflows/workflow-operation-instance-{c}.yaml' for c in ('create', 'clone', 'migrate', 'destroy')]
        coverage = plan.instance_execution_coverage(paths, mapping, read)
        self.assertEqual({c['command'] for c in coverage}, set(plan.INSTANCE_COMMANDS))
        self.assertEqual(set(paths), {p for c in coverage for p in c['paths']})
        self.assertFalse(any(c['enforced'] for c in coverage))
        files = [str(p.relative_to(ROOT)) for p in (ROOT / 'swamp/proof').glob('*/*.cases.yaml')]
        selected = plan.proof_cases(paths, mapping, read, files, coverage)
        self.assertEqual({entry['command'] for entry in selected}, {'instance:migrate', 'instance:destroy'})
        self.assertTrue(all(entry['only'] == [] for entry in selected))

    def test_documentation_and_fixtures_do_not_warn(self):
        mapping = (ROOT / plan.SELECTION).read_text()
        read = self.read(mapping=mapping)
        paths = ['docs/cli/instance/create.mdx', 'swamp/tests/instance_clone_test.py',
                 'swamp/extensions/behaviors/operations/instance-dev-command/result_test.ts',
                 'swamp/ansible/tasks/instance-container-phase.md']
        self.assertEqual(plan.instance_execution_coverage(paths, mapping, read), [])

    def test_cli_publishes_warning_summary_and_skips_empty_proof(self):
        with tempfile.TemporaryDirectory() as directory:
            checkout = Path(directory)
            def git(*args):
                return subprocess.check_output(['git', *args], cwd=checkout, text=True).strip()
            git('init', '-q')
            git('config', 'user.name', 'Fixture')
            git('config', 'user.email', 'fixture@example.test')
            mapping = checkout / plan.SELECTION
            mapping.parent.mkdir(parents=True)
            mapping.write_text(MAPPING)
            git('add', '.')
            git('commit', '-qm', 'base')
            changed = checkout / PATH
            changed.parent.mkdir(parents=True)
            changed.write_text('print("new clone behavior")\n')
            git('add', '.')
            git('commit', '-qm', 'change execution')
            summary = checkout / 'summary'
            output = checkout / 'output'
            result = subprocess.run([sys.executable, str(ROOT / 'bin/ci-build-plan'), '--base', 'HEAD^',
                                     '--head', 'HEAD', '--proof-cases', '--github-output', str(output)],
                                    cwd=checkout, env={**os.environ, 'GITHUB_STEP_SUMMARY': str(summary)},
                                    text=True, capture_output=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertFalse(json.loads(result.stdout)['proof_run'])
            self.assertIn('::warning title=Instance execution proof coverage::', result.stderr)
            self.assertIn(PATH, summary.read_text())
            self.assertIn('proof_run=false', output.read_text())


if __name__ == '__main__':
    unittest.main()
