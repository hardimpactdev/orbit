"""Validate the check table, tracked inputs, job timeouts and dependencies."""
import contextlib
import copy
import math
import tomllib
import yaml
import io
import json
import os
import subprocess
import tempfile
from pathlib import Path
from unittest.mock import patch
import unittest

from test_ci_build_plan import plan


class CheckTableTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.table, _, _ = plan.check_table()
        cls.files = subprocess.check_output(['git', 'ls-files'], cwd=plan.ROOT, text=True, timeout=60).splitlines()

    def test_check_names_defaults_and_budgets_are_known(self):
        checks = self.table['check']
        self.assertEqual(set(plan.JOBS) | {'swamp-' + name for name in plan.SWAMP_CHECKS}, set(checks))
        for name, check in checks.items():
            with self.subTest(check=name):
                self.assertIsInstance(check['cost'], int)
                self.assertGreater(check['cost'], 0)
                self.assertLessEqual(check['cost'], check['ceiling'])
                self.assertLessEqual(check['ceiling'], 240)
                self.assertEqual(240 if name in ('gateway-tests', 'cli-tests', 'agent') else 120, check['ceiling'])
        for root in self.table['roots'].values():
            self.assertLessEqual(set(root['default']), set(checks))
            self.assertLess(len(root['default']), len(plan.JOBS))

    def test_every_input_that_names_one_file_names_a_tracked_file(self):
        inputs = [self.table['all-checks'], self.table['ignored'],
                  *self.table['check'].values(), *self.table['roots'].values()]
        named = {glob.removeprefix('!') for entry in inputs for glob in entry['inputs'] if not set('*?') & set(glob)}
        self.assertEqual(set(), named - set(self.files), 'These inputs name a file that does not exist')

    def test_every_tracked_file_is_inside_a_declared_root(self):
        plan.check_source_roots(self.files)

    def test_exclusions_win_in_any_order_and_includes_do_not_share_exclusions_between_paths(self):
        for inputs in (['apps/**', '!**/*.md'], ['!**/*.md', 'apps/**']):
            rules = plan.input_rules(inputs)
            self.assertTrue(plan.matches('apps/gateway/a.php', rules))
            self.assertFalse(plan.matches('apps/gateway/a.md', rules))
        self.assertTrue(plan.matches('a/b/c', plan.input_rules(['a/**'])))
        self.assertFalse(plan.matches('a/b/c', plan.input_rules(['a/*'])))
        self.assertTrue(plan.select(['apps/gateway/a.php', 'swamp/docs/a.md'])['jobs']['gateway-tests'])

    def test_shared_inputs_select_every_check_once(self):
        for path in ('.github/workflows/ci.yml', '.github/actions/php/action.yml',
                     '.github/ci/checks.toml', 'bin/ci-build-plan'):
            with self.subTest(path=path):
                result = plan.select([path])
                self.assertTrue(all(result['jobs'].values()))
                self.assertTrue(all(result['swamp_checks'].values()))

    def test_unclaimed_tooling_selects_no_job_and_names_the_path(self):
        for path in ('bin/new-tool', 'tools/new-tool.py', '.github/new-config.yml', 'scripts/new-tool'):
            with self.subTest(path=path):
                selected, unclaimed = plan.selected_checks([path])
                self.assertEqual(set(), selected)
                self.assertEqual([(path, path.split('/')[0], [])], unclaimed)
        with self.assertRaisesRegex(ValueError, r'Add \[roots.*checks.toml'):
            plan.select(['new-root/file.py'])

    def test_unclaimed_paths_reach_the_action_summary_without_changing_json_outputs(self):
        with tempfile.TemporaryDirectory() as directory:
            summary = Path(directory) / 'summary'
            stdout, stderr = io.StringIO(), io.StringIO()
            with patch('sys.argv', ['ci-build-plan', '--base', 'HEAD^']), \
                    patch.object(plan.subprocess, 'check_output', return_value=b'tools/new.py\0'), \
                    patch.dict(os.environ, GITHUB_STEP_SUMMARY=str(summary)), \
                    contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
                plan.main()
            self.assertEqual({'jobs', 'swamp_checks', 'gateway_release', 'proof_run'},
                             set(json.loads(stdout.getvalue())))
            self.assertIn('tools/new.py; root tools defaults: none', summary.read_text())
            self.assertIn('tools/new.py', stderr.getvalue())


ROOT = plan.ROOT

# Infrastructure, not checks: explicit exceptions so new jobs cannot evade the table.
NON_CHECKS = {'changes', 'gateway-release', 'required', 'cli-release', 'proof-run'}
OTHER_DEPENDENCIES = {'required', 'cli-release', 'proof-run'}
PROOF_GATE_TIMEOUT = "${{ github.event_name == 'pull_request' && vars.ORBIT_SLOW_CHECKS_BLOCKING != 'false' && vars.ORBIT_PROOF_BLOCKING != 'false' && needs.changes.outputs.proof_run == 'true' && 195 || 2 }}"
FIXTURES_CONDITION = "${{ !inputs.changed-only && (github.event_name == 'schedule' || github.event_name == 'workflow_dispatch') }}"


def dependencies(job):
    needs = job.get('needs', [])
    return [needs] if isinstance(needs, str) else needs


def budget_errors(workflows, checks, fixture_budget):
    errors = []

    def reject(name, rule):
        errors.append(f'{name}: {rule}')

    def visit(file, inherited=(), caller=None, stack=()):
        if file in stack:
            reject(caller, 'reusable workflow cycle')
            return
        for name, job in workflows[file]['jobs'].items():
            label = f'{caller}/{name}' if caller else name
            # This job is unreachable from CI on a PR. Pin both ends of that guard.
            if file == 'swamp-shape.yml' and name == 'fixtures':
                if job.get('if') == FIXTURES_CONDITION and workflows['ci.yml']['jobs']['swamp'].get('with', {}).get('changed-only') is True:
                    continue
                reject(label, 'full fixtures must be unreachable from CI on a pull request')
            key = {'cli/build': 'cli', 'swamp/shape': 'swamp-shape',
                   'swamp/validate': 'swamp-validate', 'swamp/tests': 'swamp-tests'}.get(label, name)
            if caller is None and name in NON_CHECKS:
                expected = 195 if name == 'proof-run' else PROOF_GATE_TIMEOUT if name == 'required' else 2
            elif label == 'fixtures':
                if 'shard_ceiling_seconds' not in fixture_budget:
                    reject(label, 'no entry in .github/ci/checks.toml')
                    expected = None
                else:
                    expected = math.ceil(fixture_budget['shard_ceiling_seconds'] / 60)
            elif key not in checks:
                reject(label, 'no entry in .github/ci/checks.toml')
                expected = None
            else:
                expected = math.ceil(checks[key]['ceiling'] / 60)
            needs = [*inherited, *dependencies(job)]
            if not (caller is None and name in OTHER_DEPENDENCIES):
                wanted = [] if label == 'changes' else ['changes']
                if needs != wanted:
                    reject(label, f'dependencies must be {wanted}, got {needs}')
            if 'uses' in job:
                # Actions rejects timeout-minutes on a reusable-workflow caller.
                # Check its concrete jobs, carrying the caller's dependency with them.
                target = job['uses'].removeprefix('./.github/workflows/')
                if target not in workflows:
                    reject(label, f'unchecked reusable workflow {target}')
                else:
                    visit(target, needs, label, (*stack, file))
                continue
            limit = job.get('timeout-minutes')
            if limit is None:
                reject(label, 'missing timeout-minutes')
            elif label == 'required':
                if limit != expected:
                    reject(label, 'timeout must be 2 minutes, or 195 only for blocking selected proof')
            elif type(limit) is not int:
                reject(label, 'timeout-minutes must be an integer')
            else:
                if label != 'proof-run' and limit > 4:
                    reject(label, 'timeout-minutes exceeds 4 minutes')
                if expected is not None and limit != expected:
                    reject(label, f'timeout-minutes must equal ceiling rounded up: {expected}, got {limit}')

    visit('ci.yml')
    return errors


class CiBudgetTest(unittest.TestCase):
    def setUp(self):
        self.workflows = {name: yaml.safe_load((ROOT / '.github/workflows' / name).read_text())
                          for name in ('ci.yml', 'orbit-cli-binary.yml', 'swamp-shape.yml')}
        with (ROOT / '.github/ci/checks.toml').open('rb') as source:
            table = tomllib.load(source)
            self.checks = table['check']
            self.fixture_budget = table['budget']

    def test_every_reachable_job_obeys_its_table_and_dependency_budget(self):
        self.assertEqual([], budget_errors(self.workflows, self.checks, self.fixture_budget))

    def assert_rejected(self, workflows, name, rule, checks=None):
        errors = budget_errors(workflows, self.checks if checks is None else checks, self.fixture_budget)
        self.assertTrue(any(error.startswith(name + ':') and rule in error for error in errors), errors)

    def test_budget_rejects_missing_excessive_and_different_timeouts_in_both_workflow_levels(self):
        for file, job, label in (('ci.yml', 'docs', 'docs'), ('ci.yml', 'fixtures', 'fixtures'), ('orbit-cli-binary.yml', 'build', 'cli/build'),
                                 ('swamp-shape.yml', 'tests', 'swamp/tests')):
            for limit, rule in ((None, 'missing timeout'), (5, 'exceeds 4'), (3, 'must equal ceiling')):
                with self.subTest(job=label, timeout=limit):
                    changed = copy.deepcopy(self.workflows)
                    block = changed[file]['jobs'][job]
                    if limit is None:
                        block.pop('timeout-minutes')
                    else:
                        block['timeout-minutes'] = limit
                    self.assert_rejected(changed, label, rule)

    def test_new_jobs_need_table_entries_and_must_not_make_a_chain(self):
        for file, label in (('ci.yml', 'new-check'), ('swamp-shape.yml', 'swamp/new-check')):
            changed = copy.deepcopy(self.workflows)
            changed[file]['jobs']['new-check'] = {'runs-on': 'ubuntu-26.04', 'timeout-minutes': 2, 'needs': 'docs'}
            self.assert_rejected(changed, label, 'no entry')
            self.assert_rejected(changed, label, 'dependencies must be')
        for file, name, label in (('ci.yml', 'docs', 'docs'), ('ci.yml', 'swamp', 'swamp'),
                                  ('orbit-cli-binary.yml', 'build', 'cli/build')):
            changed = copy.deepcopy(self.workflows)
            changed[file]['jobs'][name]['needs'] = 'docs'
            self.assert_rejected(changed, label, 'dependencies must be')
        changed = copy.deepcopy(self.workflows)
        changed['ci.yml']['jobs']['docs'].pop('needs')
        self.assert_rejected(changed, 'docs', 'dependencies must be')

    def test_missing_table_entries_and_rounding_are_checked(self):
        checks = copy.deepcopy(self.checks)
        checks.pop('cli')
        self.assert_rejected(self.workflows, 'cli/build', 'no entry', checks)
        checks = copy.deepcopy(self.checks)
        checks['docs']['ceiling'] = 121
        changed = copy.deepcopy(self.workflows)
        changed['ci.yml']['jobs']['docs']['timeout-minutes'] = 3
        self.assertEqual([], budget_errors(changed, checks, self.fixture_budget))

    def test_fixture_ceiling_is_required_and_its_dependency_cannot_form_a_chain(self):
        self.assertEqual(240, self.fixture_budget['shard_ceiling_seconds'])
        self.assertLess(self.fixture_budget['shard_deadline_seconds'], self.fixture_budget['shard_ceiling_seconds'])
        errors = budget_errors(self.workflows, self.checks, {})
        self.assertIn('fixtures: no entry in .github/ci/checks.toml', errors)
        changed = copy.deepcopy(self.workflows)
        changed['ci.yml']['jobs']['fixtures']['needs'] = 'docs'
        self.assert_rejected(changed, 'fixtures', 'dependencies must be')

    def test_only_blocking_selected_proof_can_extend_the_gate(self):
        for name in NON_CHECKS:
            changed = copy.deepcopy(self.workflows)
            changed['ci.yml']['jobs'][name].pop('timeout-minutes')
            self.assert_rejected(changed, name, 'missing timeout')
        changed = copy.deepcopy(self.workflows)
        changed['ci.yml']['jobs']['required']['timeout-minutes'] = 195
        self.assert_rejected(changed, 'required', 'only for blocking selected proof')

    def test_full_fixtures_cannot_enter_the_pr_path(self):
        changed = copy.deepcopy(self.workflows)
        changed['swamp-shape.yml']['jobs']['fixtures'].pop('if')
        self.assert_rejected(changed, 'swamp/fixtures', 'must be unreachable')

    def test_checks_start_after_the_plan_and_never_recompute_scope(self):
        caller = self.workflows['ci.yml']['jobs']
        for name in set(self.checks) - {'swamp-' + part for part in ('shape', 'validate', 'tests')}:
            with self.subTest(job=name):
                self.assertEqual('changes', caller[name]['needs'])
                self.assertEqual("${{ toJSON(fromJSON(needs.changes.outputs.jobs)['" + name + "']) != 'false' }}", caller[name]['if'])
        for workflow in self.workflows.values():
            self.assertNotIn('ci-scope', str(workflow))
            self.assertNotIn('steps.scope', str(workflow))
        for name in ('php', 'docs'):
            checkout = caller[name]['steps'][0]
            self.assertEqual(0, checkout['with']['fetch-depth'], f'{name}: comparison history must remain available')
        self.assertFalse((ROOT / '.github/actions/ci-scope/action.yml').exists())
        self.assertNotIn('changed-only', self.workflows['orbit-cli-binary.yml'].get(True, {}).get('workflow_call', {}).get('inputs', {}))

    def test_swamp_receives_each_boolean_and_the_resolved_base_and_keeps_standalone_runs(self):
        caller = self.workflows['ci.yml']['jobs']
        self.assertEqual('${{ steps.plan.outputs.swamp_checks }}', caller['changes']['outputs']['swamp_checks'])
        for name in ('shape', 'validate', 'tests'):
            self.assertEqual('${{ fromJSON(needs.changes.outputs.swamp_checks).' + name + ' }}', caller['swamp']['with'][name])
            self.assertEqual('${{ !inputs.changed-only || inputs.' + name + ' }}', self.workflows['swamp-shape.yml']['jobs'][name]['if'])
        self.assertEqual('${{ needs.changes.outputs.swamp_base_ref }}', caller['swamp']['with']['base-ref'])
