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
