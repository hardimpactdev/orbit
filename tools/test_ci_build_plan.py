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
    def test_gateway_grant_manifest_runs_the_required_gateway_boot_check(self):
        paths = ['swamp/config/gateway-workflow-grants.json']
        self.assertEqual({'swamp', 'api-reference'}, self.selected(paths))
        self.assertEqual({'swamp', 'api-reference', 'web'}, self.selected(paths, True))

    def selected(self, paths, main=False):
        return {k for k, v in plan.select(paths, main)['jobs'].items() if v}

    def test_project_check_is_not_selected_by_ci(self):
        for paths in ([], ['README.md'], ['swamp/workflows/x.yaml'], ['apps/gateway/app/Thing.php']):
            self.assertNotIn('project-check', plan.select(paths)['jobs'], paths)

    def test_gateway_code_keeps_contracts_php_and_web_but_skips_cli(self):
        self.assertEqual({'php', 'gateway-static', 'api-reference', 'web'}, self.selected(['apps/gateway/app/Thing.php']))

    def test_every_gateway_path_selects_full_static_analysis(self):
        for path in ('apps/gateway/tests/Feature/ThingTest.php', 'apps/gateway/README.md',
                     'apps/gateway/config/app.php', 'apps/gateway/composer.lock'):
            for main in (False, True):
                with self.subTest(path=path, main=main):
                    self.assertIn('gateway-static', self.selected([path], main))

    def test_sdk_changes_build_both_consumers(self):
        self.assertEqual({'php', 'cli', 'api-reference', 'web'}, self.selected(['packages/php-sdk/src/Request.php']))

    def test_flow_charts_run_the_kind_coverage_and_step_name_checks(self):
        self.assertEqual({'docs', 'api-reference'}, self.selected(['docs/cli/firewall/allow.mmd']))
        self.assertEqual({'swamp', 'docs'}, self.selected(['swamp/workflows/workflow-operation-firewall-direct.yaml']))

    def test_docs_only_skips_code_builds(self):
        self.assertEqual({'docs'}, self.selected(['docs/reference/ci.md']))
        self.assertEqual({'docs'}, self.selected(['README.md']))

    def test_main_supplies_the_web_artifact_for_gateway_releases(self):
        self.assertEqual({'docs', 'web'}, self.selected(['docs/reference/ci.md'], main=True))

    def test_an_empty_diff_selects_no_builds_except_main_web(self):
        self.assertEqual(set(), self.selected(['']))
        self.assertEqual({'web'}, self.selected([], main=True))

    def test_shared_ci_changes_run_all_builds(self):
        self.assertEqual(set(plan.JOBS), self.selected(['.github/workflows/ci.yml']))
        self.assertEqual(set(plan.JOBS), self.selected(['bin/ci-build-plan']))

    def test_a_test_only_change_checks_syntax(self):
        self.assertEqual({'php', 'gateway-static'}, self.selected(['apps/gateway/tests/Feature/ThingTest.php']))

    def test_agent_selection_is_independent_of_the_build_jobs(self):
        self.assertTrue(plan.select(['apps/agent/src/main.rs'])['agent'])
        self.assertFalse(plan.select(['apps/gateway/app/Thing.php'])['agent'])
        self.assertFalse(plan.select(['.github/workflows/ci.yml'])['agent'])

    def test_gateway_grant_renderer_changes_require_native_serve_validation(self):
        for path in ('apps/gateway/app/Infrastructure/GatewayReleases/SwampGrantRenderer.php',
                     'apps/gateway/app/Support/ValidatedData.php', 'apps/gateway/bin/render-swamp-grants'):
            with self.subTest(path=path):
                self.assertIn('swamp', self.selected([path]))

    def test_swamp_only_skips_gateway_builds_and_release_on_prs(self):
        paths = ['swamp/extensions/example.ts', 'swamp/bin/effect.php', 'swamp/composer.json']
        self.assertEqual({'swamp'}, self.selected(paths))
        self.assertFalse(plan.select(paths)['gateway_release'])

    def test_proof_harness_changes_select_the_proof_checks_and_nothing_else_selects_them(self):
        self.assertEqual({'swamp', 'proof'}, self.selected(['swamp/proof/reset/phases.py']))
        self.assertEqual({'swamp', 'proof'}, self.selected(['swamp/proof/runner/steps.py']))
        self.assertIn('proof', self.selected(['tools/swamp-proof-guest/boot.py']))
        for paths in (['swamp/workflows/x.yaml'], ['swamp/extensions/example.ts'], ['apps/gateway/app/Thing.php'], ['README.md']):
            self.assertNotIn('proof', self.selected(paths))

    def test_swamp_only_main_commit_is_a_gateway_release_because_the_release_loads_swamp(self):
        paths = ['swamp/workflows/example.yaml']
        self.assertEqual({'swamp', 'docs', 'web'}, self.selected(paths, True))
        self.assertTrue(plan.select(paths, True)['gateway_release'])

    def test_mixed_change_keeps_gateway_artifacts_and_swamp_checks(self):
        paths = ['swamp/workflows/example.yaml', 'apps/gateway/app/Thing.php']
        self.assertEqual({'swamp', 'docs', 'php', 'gateway-static', 'api-reference', 'web'}, self.selected(paths, main=True))
        self.assertTrue(plan.select(paths, True)['gateway_release'])

    def test_shared_workflow_change_selects_swamp_checks(self):
        self.assertIn('swamp', self.selected(['.github/workflows/ci.yml']))

    def test_web_types_tool_keeps_the_contract_generator(self):
        self.assertEqual({'web','api-reference'}, self.selected(['apps/web/tools/api-types/package.json']))


    def proof_run(self, paths, selection=None):
        return plan.select(paths, selection=selection)['proof_run']

    def test_proof_run_follows_the_selection_list_the_gateway_reads(self):
        selection = SELECTION
        for path in ('swamp/extensions/behaviors/operations/firewall/x.ts', 'apps/gateway/app/Actions/Firewall/A.php',
                     'swamp/proof/reset/phases.py', 'bin/proof-fleet', 'swamp/proof/selection.txt'):
            with self.subTest(path=path):
                self.assertTrue(self.proof_run([path], selection))
        for path in ('docs/cli/firewall/allow.mmd', 'swamp/proof/firewall/allow.cases.md', 'README.md', 'apps/web/src/a.ts',
                     'swamp/workflows/workflow-vault-sync.yaml', 'swamp/proof/reset/README.md', 'bin/ci-build-plan'):
            with self.subTest(path=path):
                self.assertFalse(self.proof_run([path], selection))

    def test_the_last_matching_line_wins_and_one_path_is_enough(self):
        self.assertTrue(self.proof_run(['docs/a.md', 'swamp/proof/firewall/x.sh'], SELECTION))
        self.assertFalse(self.proof_run(['swamp/proof/firewall/x.md'], SELECTION))

    def test_an_unmapped_path_starts_the_job_so_the_gateway_refuses_it(self):
        self.assertTrue(self.proof_run(['newdir/file.txt'], SELECTION))

    def test_no_change_or_no_list_decides_as_the_gateway_does(self):
        self.assertFalse(self.proof_run([''], SELECTION))
        self.assertTrue(plan.proof_run(['docs/a.md'], None))

    def test_a_malformed_list_is_refused(self):
        for text in ('onlyglob', '* nonsense value', 'a/** none:'):
            with self.subTest(text=text):
                with self.assertRaises(ValueError):
                    plan.select(['a/b'], selection=text)

    def test_the_real_list_keeps_docs_charts_and_cases_out_and_the_job_outside_the_required_jobs(self):
        self.assertFalse(plan.select(['docs/cli/firewall/allow.mmd', 'docs/reference/ci.md'])['proof_run'])
        self.assertTrue(plan.select(['swamp/proof/selection.txt'])['proof_run'])
        self.assertNotIn('proof_run', plan.select(['swamp/proof/selection.txt'])['jobs'])
        self.assertNotIn('proof-run', plan.JOBS)


SELECTION = """
# comment
*                                  skip
docs/**                            skip
apps/**                            skip
bin/**                             skip
swamp/**                           none: no case yet
**/*.md                            skip
swamp/extensions/behaviors/operations/firewall/**   firewall:allow firewall:deny
swamp/proof/firewall/**            firewall:allow
swamp/proof/firewall/*.md          skip
apps/gateway/app/Actions/Firewall/**   firewall:allow
swamp/proof/selection.txt          *
swamp/proof/reset/**               *
swamp/proof/reset/*.md             skip
bin/proof-fleet                    *
"""


if __name__ == '__main__':
    unittest.main()
