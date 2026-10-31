"""Fast CI scope selection protects downstream consumers and explicit skips."""
import importlib.machinery
import importlib.util
from pathlib import Path
import unittest

loader = importlib.machinery.SourceFileLoader('ci_build_plan', str(Path(__file__).resolve().parents[1] / 'bin/ci-build-plan'))
spec = importlib.util.spec_from_loader(loader.name, loader)
plan = importlib.util.module_from_spec(spec)
loader.exec_module(plan)


class BuildPlanTest(unittest.TestCase):
    def selected(self, paths, main=False):
        return {k for k, v in plan.select(paths, main)['jobs'].items() if v}

    def test_gateway_code_keeps_contracts_php_and_web_but_skips_cli(self):
        self.assertEqual({'php', 'api-reference', 'web'}, self.selected(['apps/gateway/app/Thing.php']))

    def test_sdk_changes_build_both_consumers(self):
        self.assertEqual({'php', 'cli', 'api-reference', 'web'}, self.selected(['packages/php-sdk/src/Request.php']))

    def test_docs_only_skips_code_builds(self):
        self.assertEqual({'docs'}, self.selected(['docs/reference/ci.md']))
        self.assertEqual({'docs'}, self.selected(['README.md']))

    def test_main_always_supplies_the_web_artifact(self):
        self.assertEqual({'docs', 'web'}, self.selected(['docs/reference/ci.md'], main=True))

    def test_an_empty_diff_selects_no_builds_except_main_web(self):
        self.assertEqual(set(), self.selected(['']))
        self.assertEqual({'web'}, self.selected([], main=True))

    def test_shared_ci_changes_run_all_builds(self):
        self.assertEqual(set(plan.JOBS), self.selected(['.github/workflows/ci.yml']))
        self.assertEqual(set(plan.JOBS), self.selected(['bin/ci-build-plan']))

    def test_a_test_only_change_checks_syntax(self):
        self.assertEqual({'php'}, self.selected(['apps/gateway/tests/Feature/ThingTest.php']))

    def test_agent_selection_is_independent_of_the_build_jobs(self):
        self.assertTrue(plan.select(['apps/agent/src/main.rs'])['agent'])
        self.assertFalse(plan.select(['apps/gateway/app/Thing.php'])['agent'])
        self.assertFalse(plan.select(['.github/workflows/ci.yml'])['agent'])

    def test_web_types_tool_keeps_the_contract_generator(self):
        self.assertEqual({'web','api-reference'}, self.selected(['apps/web/tools/api-types/package.json']))


if __name__ == '__main__':
    unittest.main()
