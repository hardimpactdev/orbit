"""Fast CI scope selection protects downstream consumers and explicit skips."""
import contextlib
import json
import subprocess
import tempfile
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
        self.assertEqual({'swamp', 'gateway-tests', 'api-reference'}, self.selected(paths))
        self.assertEqual({'swamp', 'gateway-tests', 'api-reference', 'web'}, self.selected(paths, True))

    def selected(self, paths, main=False):
        return {k for k, v in plan.select(paths, main)['jobs'].items() if v}

    def test_docs_only_starts_only_plan_docs_and_required_but_main_keeps_release_inputs(self):
        for path in ('docs/reference/tasks.md', 'README.md', '.agents/skills/example/SKILL.md'):
            with self.subTest(path=path):
                result = plan.select([path])
                self.assertEqual({'docs'}, {name for name, selected in result['jobs'].items() if selected})
                self.assertFalse(result['gateway_release'])
                self.assertFalse(result['proof_run'])
                self.assertTrue(plan.select([path], main=True)['gateway_release'])
        self.assertTrue(plan.select(['docs/reference/tasks.md', 'apps/gateway/app/Thing.php'])['gateway_release'])

    def test_docs_contracts_follow_docs_sources_check_tools_and_charts(self):
        for path in ('apps/docs/tests/Unit/CheckFlowsTest.php', 'apps/docs/tests/docs_flows_test.py',
                     'apps/docs/composer.lock', 'apps/docs/phpunit.xml', 'apps/docs/app/Example.php',
                     'bin/check-flows', 'bin/check-processes', 'bin/check-operation-kinds', 'bin/check-new-contract',
                     'docs/cli/instance/container/create.mmd', 'docs/reference/pipeline/4-pr-and-ci.mmd'):
            for main in (False, True):
                with self.subTest(path=path, main=main):
                    self.assertIn('docs', self.selected([path], main))
        self.assertNotIn('docs', self.selected(['apps/web/src/main.ts']))

    def test_project_check_is_not_selected_by_ci(self):
        for paths in ([], ['README.md'], ['swamp/workflows/x.yaml'], ['apps/gateway/app/Thing.php']):
            self.assertNotIn('project-check', plan.select(paths)['jobs'], paths)

    def test_gateway_code_keeps_contracts_php_and_web_but_skips_cli(self):
        self.assertEqual({'php', 'gateway-static', 'gateway-tests', 'api-reference', 'web'}, self.selected(['apps/gateway/app/Thing.php']))

    def test_every_gateway_path_selects_full_static_analysis(self):
        for path in ('apps/gateway/tests/Feature/ThingTest.php', 'apps/gateway/README.md',
                     'apps/gateway/config/app.php', 'apps/gateway/composer.lock'):
            for main in (False, True):
                with self.subTest(path=path, main=main):
                    self.assertIn('gateway-static', self.selected([path], main))

    def test_gateway_pest_follows_every_gateway_and_cross_project_input(self):
        for path in ('apps/gateway/.env.example', 'apps/gateway/phpunit.xml',
                     'apps/gateway/tests/Feature/Http/NodeMetricsTest.php',
                     'apps/cli/tests/Fixtures/Console/command-consent.php', 'apps/cli/app/Commands/Nodes/MetricsNodeCommand.php',
                     'apps/cli/composer.lock', 'packages/php-sdk/fixtures/node.json', 'packages/php-sdk/composer.json',
                     'swamp/workflows/workflow-operation-firewall-direct.yaml',
                     'swamp/extensions/behaviors/operations/firewall/firewall.ts',
                     'swamp/extensions/behaviors/_lib/operation.ts', 'swamp/deno.lock',
                     'swamp/proof/firewall/allow.cases.yaml', 'swamp/proof/selection.txt',
                     'swamp/config/gateway-workflow-grants.json', 'swamp/ansible/task-vm-host.yml',
                     'tools/swamp-proof-guest/ansible-recaps.php', 'docs/cli/firewall/allow.mmd',
                     'apps/gateway/app/Domain/Tasks/direct-operations.php',
                     'apps/gateway/resources/machine-software/recipe.json',
                     '.github/actions/php/action.yml'):
            for main in (False, True):
                with self.subTest(path=path, main=main):
                    self.assertTrue(plan.select([path], main)['jobs']['gateway-tests'])

    def test_cli_pest_follows_sources_docs_recorded_fixtures_and_release_helpers(self):
        for path in ('apps/cli/app/Commands/Instances/CreateInstanceCommand.php', 'apps/cli/composer.lock',
                     'apps/cli/tests/Feature/CommandSurfaceTest.php', 'apps/cli/tests/Fixtures/Console/command-consent.php',
                     'packages/php-sdk/src/Requests/Instances/CreateInstanceRequest.php',
                     'packages/php-sdk/fixtures/instances/instance-create/default.json',
                     'docs/cli/instance/create.mdx', 'docs/reference/cli-command-vocabulary.md',
                     'apps/gateway/routes/api/instances.php', 'apps/agent/scripts/source-commit',
                     'bin/orbit-build-cli-binary', 'bin/orbit-version', 'bin/orbit-cli-release-version',
                     'bin/orbit-cli-release-assets', '.github/actions/php/action.yml'):
            for main in (False, True):
                with self.subTest(path=path, main=main):
                    self.assertTrue(plan.select([path], main)['jobs']['cli-tests'])

    def test_cli_pest_skips_unrelated_gateway_docs_web_and_swamp_inputs(self):
        for path in ('apps/gateway/app/Thing.php', 'apps/gateway/tests/Feature/ThingTest.php',
                     'docs/reference/tasks.md', 'docs/cli/instance/create.mmd', 'apps/docs/composer.lock',
                     'apps/web/src/main.ts', 'swamp/workflows/workflow-example.yaml'):
            with self.subTest(path=path):
                self.assertFalse(plan.select([path])['jobs']['cli-tests'])

    def test_root_codex_configuration_selects_cli_pest_before_the_guidance_shortcut(self):
        for main in (False, True):
            with self.subTest(main=main):
                self.assertEqual({'docs', 'cli-tests'} | ({'web'} if main else set()),
                                 self.selected(['.codex/config.toml'], main))
        self.assertEqual({'docs'}, self.selected(['.codex/README.md']))

    def test_gateway_pest_skips_unrelated_docs_web_and_swamp_tools(self):
        for path in ('docs/reference/tasks.md', 'docs/cli/firewall/allow.mdx', 'apps/docs/composer.lock',
                     'apps/web/src/main.ts', 'apps/web/bun.lock', 'swamp/docs/swamp-shape.md',
                     'swamp/bin/check-swamp-base-policy.py', 'swamp/tests/shape_test.ts'):
            with self.subTest(path=path):
                self.assertFalse(plan.select([path])['jobs']['gateway-tests'])

    def test_sdk_changes_build_both_consumers(self):
        self.assertEqual({'php', 'gateway-tests', 'cli', 'cli-static', 'cli-tests', 'sdk-static', 'api-reference', 'web'}, self.selected(['packages/php-sdk/src/Request.php']))

    def test_a_composer_manifest_or_lock_selects_the_static_analysis_of_its_project(self):
        # Dependabot bumped laravel/boost in apps/cli/composer.lock and nothing analysed the CLI against it.
        for project, job in (('apps/cli', 'cli-static'), ('packages/php-sdk', 'sdk-static'), ('apps/e2e', 'e2e-static')):
            for name in ('composer.json', 'composer.lock', 'src/Thing.php', 'tests/ThingTest.php'):
                for main in (False, True):
                    with self.subTest(project=project, name=name, main=main):
                        self.assertIn(job, self.selected([f'{project}/{name}'], main))

    def test_the_static_analysis_of_one_project_is_not_selected_by_another(self):
        self.assertNotIn('cli-static', self.selected(['apps/e2e/composer.lock']))
        self.assertNotIn('cli-static', self.selected(['apps/gateway/composer.lock']))
        self.assertNotIn('sdk-static', self.selected(['apps/cli/composer.lock']))
        self.assertNotIn('e2e-static', self.selected(['apps/cli/composer.lock']))
        self.assertEqual(set(), self.selected(['docs/reference/ci.md']) & {'cli-static', 'sdk-static', 'e2e-static'})

    def test_the_sdk_selects_the_cli_analysis_because_the_cli_uses_it(self):
        self.assertIn('cli-static', self.selected(['packages/php-sdk/composer.lock']))

    def test_a_dependabot_bump_of_three_projects_selects_all_three_analyses(self):
        paths = ['apps/cli/composer.lock', 'apps/e2e/composer.lock', 'apps/gateway/composer.lock']
        self.assertTrue({'cli-static', 'e2e-static', 'gateway-static'} <= self.selected(paths))

    def test_flow_charts_run_the_kind_coverage_and_step_name_checks(self):
        self.assertEqual({'docs', 'gateway-tests', 'api-reference'}, self.selected(['docs/cli/firewall/allow.mmd']))
        self.assertEqual({'swamp', 'gateway-tests', 'docs'}, self.selected(['swamp/workflows/workflow-operation-firewall-direct.yaml']))

    def test_docs_only_skips_code_builds(self):
        self.assertEqual({'docs'}, self.selected(['docs/reference/ci.md']))
        self.assertEqual({'docs'}, self.selected(['README.md']))

    def test_main_supplies_the_web_artifact_for_gateway_releases(self):
        self.assertEqual({'docs', 'web'}, self.selected(['docs/reference/ci.md'], main=True))

    def test_an_empty_diff_selects_no_builds_except_main_web(self):
        self.assertEqual(set(), self.selected(['']))
        self.assertEqual({'web'}, self.selected([], main=True))

    def test_prose_and_known_tooling_do_not_select_unrelated_apps(self):
        for path in ('.agents/skills/example/SKILL.md', '.agents/skills/example/scripts/check.py',
                     'swamp/docs/swamp-shape.md', 'swamp/extensions/behaviors/operations/README.md', 'bin/docs-flows'):
            with self.subTest(path=path):
                self.assertEqual({'docs'}, self.selected([path]))
                self.assertEqual({'docs', 'web'}, self.selected([path], True))
        self.assertEqual({'proof'}, self.selected(['tools/swamp-proof-guest/terminal.py']))
        for path in ('bin/build-api', 'bin/docs-openapi', 'bin/mcp-tools'):
            with self.subTest(path=path):
                self.assertEqual({'gateway-tests', 'api-reference', 'web'}, self.selected([path]))
        self.assertEqual({'docs', 'gateway-tests', 'api-reference', 'web'}, self.selected(['bin/docs-navigation']))
        self.assertEqual(set(), self.selected(['tools/test_ci_build_plan.py']))

    def test_gateway_pest_selects_navigation_inputs_read_by_contract_generation(self):
        for path in ('docs/site.json', 'docs/navigation/reference.json'):
            for main in (False, True):
                with self.subTest(path=path, main=main):
                    self.assertEqual({'docs', 'gateway-tests'} | ({'web'} if main else set()),
                                     self.selected([path], main))

    def test_unknown_source_roots_fail_closed_even_for_docs_under_new_apps(self):
        for path in ('apps/new/src/main.ts', 'packages/new/src/lib.rs', 'apps/new/README.md',
                     'new-source/main.py', 'new-tool.py'):
            with self.subTest(path=path), self.assertRaisesRegex(ValueError, 'Unknown CI source root'):
                plan.select([path])
        # Desktop's paused coverage is explicit, rather than an unrecognised app silently omitted.
        self.assertNotIn('project-check', plan.select(['apps/desktop/src/main.ts'])['jobs'])

    def test_swamp_checks_follow_their_inputs(self):
        cases = {
            'swamp/docs/swamp-shape.md': set(),
            'swamp/config/swamp-shape-baseline.json': {'shape', 'tests'},
            'swamp/bin/bounded-phase-scripts.ts': {'shape', 'tests'},
            'swamp/bin/workflow-placement.ts': {'shape', 'tests'},
            'swamp/bin/swamp-shape-relocations.ts': {'shape', 'tests'},
            'swamp/ansible/task-vm-runtime.yml': {'shape', 'tests'},
            'swamp/workflows/workflow-operation-firewall-direct.yaml': {'shape', 'validate', 'tests'},
            'swamp/workflows/workflow-operation-instance-mail-update.yaml': {'shape', 'validate', 'tests'},
            'swamp/models/@nckrtl/orbit-gateway/orbit-gateway.yaml': {'shape', 'validate', 'tests'},
            'swamp/tests/writes-reviewed.json': {'shape', 'tests'},
            'swamp/tests/writes_declared_test.ts': {'shape', 'tests'},
            'swamp/config/serve-grants.json': {'shape', 'validate', 'tests'},
            'swamp/extensions/behaviors/node-worker/config.ts': {'shape', 'validate', 'tests'},
            'swamp/extensions/behaviors/_lib/public_output.ts': {'shape', 'validate', 'tests'},
            'swamp/deno.lock': {'shape', 'validate', 'tests'},
            'swamp/bin/check-swamp-base-policy.py': {'shape'},
            'swamp/bin/validate-swamp-workflows.py': {'shape', 'validate'},
            'swamp/proof/runner/steps.py': {'shape'},
            'bin/swamp-shape-gate': {'shape', 'validate'},
            'apps/gateway/app/Infrastructure/GatewayReleases/SwampGrantRenderer.php': {'shape', 'validate', 'tests'},
        }
        for path, expected in cases.items():
            for main in (False, True):
                with self.subTest(path=path, main=main):
                    result = plan.select([path], main)
                    checks = {name for name, value in result['swamp_checks'].items() if value}
                    self.assertEqual(expected, checks)
                    self.assertEqual(bool(expected), result['jobs']['swamp'])

    def test_shared_ci_changes_run_all_builds(self):
        self.assertEqual(set(plan.JOBS), self.selected(['.github/workflows/ci.yml']))
        self.assertEqual(set(plan.JOBS), self.selected(['bin/ci-build-plan']))

    def test_a_test_only_change_checks_syntax(self):
        self.assertEqual({'php', 'gateway-static', 'gateway-tests'}, self.selected(['apps/gateway/tests/Feature/ThingTest.php']))

    def test_pi_and_agent_sources_select_required_component_checks(self):
        for path in ('apps/pi-server/src/main.ts', 'apps/pi-server/tests/session.test.ts',
                     'apps/pi-server/package.json', 'apps/pi-server/bun.lock', 'apps/pi-server/patches/pi.patch'):
            with self.subTest(path=path):
                self.assertEqual({'pi'}, self.selected([path]))
        for path in ('apps/agent/src/main.rs', 'apps/agent/Cargo.toml', 'apps/agent/Cargo.lock',
                     'apps/agent/tests/binary_input.rs', 'apps/agent/crates/contract/src/lib.rs'):
            with self.subTest(path=path):
                self.assertEqual({'agent'}, self.selected([path]))
        for path in ('apps/gateway/extensions/documents/agent/src/lib.rs',
                     'apps/gateway/extensions/ai-router/agent/Cargo.toml'):
            self.assertIn('agent', self.selected([path]))
        for path in ('apps/web/src/main.ts', 'docs/reference/pi-server.md', 'apps/pi-server/README.md',
                     'apps/agent/AGENTS.md'):
            self.assertFalse(self.selected([path]) & {'pi', 'agent'}, path)

    def test_each_frozen_pin_file_runs_the_published_binary_contract_check(self):
        for name in ('frozen-manifest.json', 'frozen-commands.json', 'frozen-commands-source.json'):
            for main in (False, True):
                with self.subTest(name=name, main=main):
                    self.assertIn('php', self.selected([f'apps/gateway/resources/agent/{name}'], main))

    def test_the_rust_check_tracks_agent_sources_and_the_node_version(self):
        self.assertIn('agent', self.selected(['apps/agent/src/main.rs']))
        self.assertNotIn('agent', self.selected(['apps/gateway/app/Thing.php']))
        self.assertEqual({'web', 'pi', 'agent'}, self.selected(['.node-version']))

    def test_gateway_grant_renderer_changes_require_native_serve_validation(self):
        for path in ('apps/gateway/app/Infrastructure/GatewayReleases/SwampGrantRenderer.php',
                     'apps/gateway/app/Support/ValidatedData.php', 'apps/gateway/bin/render-swamp-grants'):
            with self.subTest(path=path):
                self.assertIn('swamp', self.selected([path]))

    def test_gateway_files_that_the_swamp_gate_reads_select_it(self):
        for path in ('apps/gateway/app/Domain/Tasks/direct-operations.php', 'apps/gateway/resources/agent/frozen-manifest.json',
                     'apps/gateway/resources/agent/frozen-commands.json', 'apps/gateway/app/Domain/Routes/probe.ts', 'apps/gateway/extensions/thing/job.yml'):
            with self.subTest(path=path):
                self.assertIn('swamp', self.selected([path]))

    def test_other_gateway_files_leave_the_swamp_gate_off(self):
        for path in ('apps/gateway/app/Actions/Thing.php', 'apps/gateway/app/Domain/Tasks/TaskPrepIntent.php', 'apps/gateway/tests/Feature/X.php', 'apps/gateway/resources/views/x.blade.php'):
            with self.subTest(path=path):
                self.assertNotIn('swamp', self.selected([path]))

    def test_swamp_only_skips_gateway_builds_and_release_on_prs(self):
        paths = ['swamp/extensions/example.ts', 'swamp/bin/effect.php', 'swamp/composer.json']
        self.assertEqual({'swamp'}, self.selected(paths))
        self.assertFalse(plan.select(paths)['gateway_release'])

    def test_proof_harness_changes_select_the_proof_checks_and_nothing_else_selects_them(self):
        self.assertEqual({'swamp', 'gateway-tests', 'proof'}, self.selected(['swamp/proof/runner/steps.py']))
        self.assertIn('proof', self.selected(['tools/swamp-proof-guest/terminal.py']))
        for paths in (['swamp/workflows/x.yaml'], ['swamp/extensions/example.ts'], ['apps/gateway/app/Thing.php'], ['README.md']):
            self.assertNotIn('proof', self.selected(paths))

    def test_swamp_only_main_commit_is_a_gateway_release_because_the_release_loads_swamp(self):
        paths = ['swamp/workflows/example.yaml']
        self.assertEqual({'swamp', 'gateway-tests', 'docs', 'web'}, self.selected(paths, True))
        self.assertTrue(plan.select(paths, True)['gateway_release'])

    def test_mixed_change_keeps_gateway_artifacts_and_swamp_checks(self):
        paths = ['swamp/workflows/example.yaml', 'apps/gateway/app/Thing.php']
        self.assertEqual({'swamp', 'docs', 'php', 'gateway-static', 'gateway-tests', 'api-reference', 'web'}, self.selected(paths, main=True))
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
                     'tools/swamp-proof-guest/terminal.py'):
            with self.subTest(path=path):
                self.assertTrue(self.proof_run([path], selection))
        for path in ('docs/cli/firewall/allow.mmd', 'docs/cli/firewall/allow.mdx', 'README.md', 'apps/web/src/a.ts',
                     'swamp/workflows/workflow-vault-sync.yaml', 'swamp/proof/firewall/README.md', 'bin/ci-build-plan',
                     'swamp/proof/selection.txt'):
            with self.subTest(path=path):
                self.assertFalse(self.proof_run([path], selection))

    def test_the_last_matching_line_wins_and_one_path_is_enough(self):
        self.assertTrue(self.proof_run(['docs/a.md', 'swamp/proof/firewall/x.sh'], SELECTION))
        self.assertFalse(self.proof_run(['swamp/proof/firewall/x.md'], SELECTION))

    def test_an_unmapped_path_starts_the_job_so_the_gateway_refuses_it(self):
        self.assertTrue(plan.proof_run(['newdir/file.txt'], SELECTION))

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
        self.assertFalse(plan.select(['swamp/proof/selection.txt'])['proof_run'])
        self.assertFalse(plan.select(['apps/gateway/app/Domain/Operations/OperationIntent.php'])['proof_run'])
        self.assertNotIn('proof_run', plan.select(['swamp/proof/selection.txt'])['jobs'])
        self.assertNotIn('proof-run', plan.JOBS)


class RegistryFastSelectionTest(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory()
        self.addCleanup(self.scratch.cleanup)
        self.root = Path(self.scratch.name)
        self.git('init', '-q')
        self.git('config', 'user.name', 'CI test')
        self.git('config', 'user.email', 'ci@example.test')
        self.table = self.root / plan.TABLE
        self.table.parent.mkdir(parents=True)
        self.initial = ('[budget]\ndefault_cost = 30\n[fixtures]\ndefault = []\n'
                        '[[fixture]]\nfile = "swamp/example.ts"\ncost = 40\n'
                        '[check.pi]\ninputs = ["apps/pi-server/**"]\ncost = 11\n'
                        '[check.docs]\ninputs = ["docs/**"]\ncost = 70\n'
                        '[check.swamp]\ninputs = []\ncost = 117\n')
        self.base = self.commit(self.initial)

    def git(self, *args):
        return subprocess.check_output(['git', *args], cwd=self.root, text=True,
                                       stderr=subprocess.PIPE, timeout=60).strip()

    def commit(self, text):
        self.table.write_text(text)
        self.git('add', '.')
        self.git('commit', '-qm', 'table')
        return self.git('rev-parse', 'HEAD')

    def run_plan(self, base=None, head='HEAD', main=False):
        output = self.root / '.git/plan-output'
        output.unlink(missing_ok=True)
        args = ['python3', loader.path, '--base', base or self.base, '--head', head,
                '--github-output', str(output)]
        if main:
            args.append('--main')
        result = json.loads(subprocess.check_output(args, cwd=self.root, text=True, timeout=60))
        emitted = dict(line.split('=', 1) for line in output.read_text().splitlines())
        self.assertEqual(result['jobs'], json.loads(emitted['jobs']))
        self.assertEqual(result['gateway_release'], json.loads(emitted['gateway_release']))
        return result

    def jobs(self, **args):
        return {name for name, enabled in self.run_plan(**args)['jobs'].items() if enabled}

    def test_fixture_metadata_selects_no_fast_jobs_or_pr_release_inputs(self):
        self.commit(self.initial.replace('cost = 40', 'cost = 45'))
        self.assertEqual(self.jobs(), set())
        self.assertFalse(self.run_plan()['gateway_release'])
        self.assertFalse(self.run_plan()['proof_run'])
        self.assertEqual(self.jobs(main=True), {'web'})
        self.assertTrue(self.run_plan(main=True)['gateway_release'])

    def test_each_changed_fast_check_selects_itself_including_removed_rules(self):
        for old, new, expected in (
            ('cost = 11\n', 'cost = 12\n', {'pi'}),
            ('apps/pi-server/**', 'apps/pi-server/src/**', {'pi'}),
            ('cost = 70', 'cost = 71', {'docs'}),
            ('[check.pi]\ninputs = ["apps/pi-server/**"]\ncost = 11\n', '', {'pi'}),
        ):
            with self.subTest(expected=expected, old=old):
                self.commit(self.initial.replace(old, new))
                self.assertEqual(self.jobs(), expected)
        self.commit(self.initial + '\n[check.cli-static]\ninputs = []\ncost = 5\n')
        self.assertEqual(self.jobs(), {'cli-static'})

    def test_aggregate_swamp_change_runs_its_three_checks(self):
        self.commit(self.initial.replace('cost = 117', 'cost = 118'))
        result = self.run_plan()
        self.assertEqual(self.jobs(), {'swamp'})
        self.assertTrue(all(result['swamp_checks'].values()))

    def test_shared_and_unknown_sections_select_all_fast_jobs(self):
        for text in (
            self.initial.replace('default_cost = 30', 'default_cost = 31'),
            self.initial.replace('default = []', 'default = ["swamp/example.ts"]'),
            self.initial + '\n[all-checks]\ninputs = []\n',
            self.initial + '\n[ignored]\ninputs = []\n',
            self.initial + '\n[roots.example]\ninputs = ["example/**"]\ndefault = []\n',
            self.initial + '\n[new-shared-rule]\nenabled = true\n',
        ):
            with self.subTest(text=text):
                self.commit(text)
                self.assertEqual(self.jobs(), set(plan.JOBS))

    def test_formatting_fixture_additions_and_removals_select_no_fast_jobs(self):
        for text in (
            self.initial + '\n# comment\n',
            self.initial.replace('cost = 40', 'cost=40'),
            self.initial.replace('swamp/example.ts', 'swamp/replacement.ts'),
            self.initial.replace('[[fixture]]\nfile = "swamp/example.ts"\ncost = 40\n', ''),
        ):
            with self.subTest(text=text):
                self.commit(text)
                self.assertEqual(self.jobs(), set())

    def test_other_files_keep_their_normal_selection_and_shared_machinery_runs_all(self):
        path = self.root / 'apps/pi-server/src/example.ts'
        path.parent.mkdir(parents=True)
        path.write_text('// source\n')
        self.commit(self.initial.replace('cost = 40', 'cost = 45'))
        self.assertEqual(self.jobs(), {'pi'})
        workflow = self.root / '.github/workflows/ci.yml'
        workflow.parent.mkdir(parents=True)
        workflow.write_text('# changed workflow\n')
        self.git('add', '.')
        self.git('commit', '-qm', 'workflow')
        self.assertEqual(self.jobs(), set(plan.JOBS))

    def test_swamp_only_change_plus_fixture_metadata_keeps_release_inputs_off(self):
        path = self.root / 'swamp/ansible/tasks/example.yml'
        path.parent.mkdir(parents=True)
        path.write_text('[]\n')
        self.commit(self.initial.replace('cost = 40', 'cost = 45'))
        expected = plan.select(['swamp/ansible/tasks/example.yml'])
        self.assertEqual(self.run_plan(), expected)

    def test_merge_base_excludes_main_only_fast_check_and_shared_rule_edits(self):
        self.git('checkout', '-qb', 'topic')
        topic = self.commit(self.initial.replace('cost = 40', 'cost = 45'))
        self.git('checkout', '-qb', 'main-side', self.base)
        main = self.commit(self.initial.replace('cost = 11', 'cost = 12')
                           .replace('default_cost = 30', 'default_cost = 31'))
        self.assertEqual(self.jobs(base=main, head=topic), set())

    def test_first_table_introduction_runs_shared_checks_and_bad_toml_fails(self):
        self.table.unlink()
        self.git('add', '-u')
        self.git('commit', '-qm', 'before table')
        missing = self.git('rev-parse', 'HEAD')
        self.commit(self.initial)
        self.assertEqual(self.jobs(base=missing), set(plan.JOBS))
        self.commit('[invalid')
        with contextlib.chdir(self.root), self.assertRaises(plan.tomllib.TOMLDecodeError):
            plan.registry_check_changes(self.base, 'HEAD')


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
swamp/proof/selection.txt          skip
tools/swamp-proof-guest/**         *
"""


if __name__ == '__main__':
    unittest.main()
