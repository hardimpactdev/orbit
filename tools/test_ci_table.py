"""Validate the check table against the plan and the tracked tree."""
import contextlib
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

    def test_every_glob_matches_a_tracked_file_or_is_explicitly_optional(self):
        inputs = [self.table['all-checks'], self.table['ignored'],
                  *self.table['check'].values(), *self.table['roots'].values()]
        globs = {glob.removeprefix('!') for entry in inputs for glob in entry['inputs']}
        globs.update(self.table['check']['agent']['delivery_excludes'])
        self.assertLessEqual(set(self.table.get('optional', {})), globs)
        for glob in sorted(globs):
            with self.subTest(glob=glob):
                matches = any(plan.selection_regex(glob).fullmatch(path) for path in self.files)
                self.assertTrue(matches or self.table.get('optional', {}).get(glob, {}).get('optional') is True,
                                f'{glob} matches no tracked file; mark it optional = true if intentional')

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

    def test_shared_inputs_select_every_check_once_without_agent_delivery(self):
        for path in ('.github/workflows/ci.yml', '.github/actions/php/action.yml',
                     '.github/ci/checks.toml', 'bin/ci-build-plan'):
            with self.subTest(path=path):
                result = plan.select([path])
                self.assertTrue(all(result['jobs'].values()))
                self.assertTrue(all(result['swamp_checks'].values()))
                self.assertFalse(result['agent'])

    def test_unclaimed_tooling_uses_only_the_root_static_default_and_names_the_path(self):
        for path in ('bin/new-tool', 'tools/new-tool.py', '.github/new-config.yml', 'scripts/new-tool'):
            with self.subTest(path=path):
                selected, unclaimed = plan.selected_checks([path])
                self.assertEqual({'php'}, selected)
                self.assertEqual([(path, path.split('/')[0], ['php'])], unclaimed)
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
            self.assertEqual({'jobs', 'swamp_checks', 'agent', 'gateway_release', 'proof_run'},
                             set(json.loads(stdout.getvalue())))
            self.assertIn('tools/new.py; root tools defaults: php', summary.read_text())
            self.assertIn('tools/new.py', stderr.getvalue())
