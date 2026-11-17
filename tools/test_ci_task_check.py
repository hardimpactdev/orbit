"""bin/task-check selects, labels, limits and reports its parts as docs/reference/implementation-loop.md states.

These cases need no vendor folder, so CI runs them. tools/test_task_check.py holds the real failures, which need a
seeded checkout."""
from contextlib import redirect_stdout
import io
import json
import os
from pathlib import Path
import runpy
import shlex
import shutil
import signal
import subprocess
import tempfile
import time
import tomllib
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
check = runpy.run_path(str(ROOT / 'bin/task-check'))
Part = check['Part']
JOBS = {job: False for job in check['PEST_JOBS']}
GATEWAY_TEST = 'apps/gateway/tests/Feature/InstanceDestroyRouteHoldReleaseTest.php'
IDENTITY = {'GIT_AUTHOR_NAME': 'test', 'GIT_AUTHOR_EMAIL': 'test@orbit.invalid',
            'GIT_COMMITTER_NAME': 'test', 'GIT_COMMITTER_EMAIL': 'test@orbit.invalid'}


def pest_plan(jobs, paths, fixtures=()):
    with patch.object(check['contract'], 'changed_fixtures', return_value=list(fixtures)):
        return {name: (files, status, detail) for name, _project, files, status, detail
                in check['pest_plan']({**JOBS, **jobs}, paths, 'BASE')}


GATEWAY_PINS = ['apps/gateway/tests/Feature/Domain/Nodes/GatewayRuntimeFilesTest.php',
                'apps/gateway/tests/Feature/Domain/Proof/ProofRunCopiesTest.php',
                'apps/gateway/tests/Feature/Extensions/Conn/ConnThreadStartTest.php',
                'apps/gateway/tests/Feature/Http/CollectionAccessContractTest.php',
                'apps/gateway/tests/Feature/InstanceNodeServiceTargetsTest.php',
                'apps/gateway/tests/Feature/NestedFleetHostingTest.php']
GATEWAY_PIN_NAMES = ('GatewayRuntimeFilesTest, NestedFleetHostingTest, ConnThreadStartTest, ProofRunCopiesTest, '
                     'InstanceNodeServiceTargetsTest, CollectionAccessContractTest')


class PestSelectionTest(unittest.TestCase):
    def test_a_selected_job_runs_its_changed_tests_and_pin_tests_and_leaves_its_suite_to_ci(self):
        plan = pest_plan({'gateway-tests': True}, [GATEWAY_TEST, 'apps/gateway/app/Domain/Tasks/TaskCopyTurn.php'])
        self.assertEqual(plan['pest apps/gateway'], (sorted([GATEWAY_TEST, *GATEWAY_PINS]), 'run',
                                                     f'1 changed or contract-mapped, 6 pin test files: {GATEWAY_PIN_NAMES}'))
        self.assertEqual(plan['pest apps/gateway suite'], ([], 'skipped', 'CI runs the full suite'))

    def test_a_change_to_app_code_alone_runs_the_pin_tests_of_its_selected_job(self):
        plan = pest_plan({'gateway-tests': True}, ['apps/gateway/app/Domain/Tasks/TaskCopyTurn.php'])
        self.assertEqual(plan['pest apps/gateway'], (GATEWAY_PINS, 'run',
                                                     f'0 changed or contract-mapped, 6 pin test files: {GATEWAY_PIN_NAMES}'))
        self.assertEqual(plan['pest apps/cli'], ([], 'not selected', 'CI does not select the cli-tests job'))

    def test_a_changed_pin_test_runs_once(self):
        plan = pest_plan({'gateway-tests': True}, [GATEWAY_PINS[0]])
        self.assertEqual(plan['pest apps/gateway'], (GATEWAY_PINS, 'run',
                                                     f'0 changed or contract-mapped, 6 pin test files: {GATEWAY_PIN_NAMES}'))

    def test_the_cli_pin_test_comes_with_the_cli_job(self):
        plan = pest_plan({'cli-tests': True}, [])
        self.assertEqual(plan['pest apps/cli'], (['apps/cli/tests/Feature/CommandSurfaceTest.php'], 'run',
                                                 '0 changed or contract-mapped, 1 pin test files: CommandSurfaceTest'))
        self.assertEqual(plan['pest apps/gateway'], ([], 'not selected', 'CI does not select the gateway-tests job'))

    def test_every_pin_test_file_exists_in_a_project_of_a_pest_job(self):
        for project, files in check['PIN_TESTS'].items():
            self.assertIn(project, check['PEST_JOBS'].values())
            for file in files:
                self.assertTrue((ROOT / project / file).is_file(), f'{project}/{file}')

    def test_a_selected_suite_without_a_test_to_run_is_skipped_not_unselected(self):
        plan = pest_plan({'docs': True}, [])
        self.assertNotIn('pest apps/docs', plan)
        self.assertEqual(plan['pest apps/docs suite'], ([], 'skipped', 'CI runs the full suite'))

    def test_a_job_ci_does_not_select_is_not_selected_and_adds_no_pin_test(self):
        plan = pest_plan({}, [GATEWAY_TEST, 'apps/gateway/app/Domain/Tasks/TaskCopyTurn.php'])
        self.assertEqual(plan['pest apps/gateway'], ([], 'not selected', 'CI does not select the gateway-tests job'))
        self.assertEqual(plan['pest apps/cli'], ([], 'not selected', 'CI does not select the cli-tests job'))
        self.assertNotIn('pest apps/gateway suite', plan)

    def test_the_sdk_job_runs_the_whole_sdk_suite(self):
        self.assertEqual(pest_plan({'sdk-tests': True}, [])['pest packages/php-sdk'], (None, 'run', 'the whole suite'))

    def test_a_changed_sdk_fixture_runs_the_cli_tests_that_bin_cli_contract_names(self):
        plan = pest_plan({'cli-tests': True}, ['packages/php-sdk/fixtures/nodes/node-list/default.json'],
                         fixtures=['nodes/node-list/default'])
        files, status, _detail = plan['pest apps/cli']
        self.assertEqual(status, 'run')
        self.assertEqual(files, sorted([*(str(path.relative_to(ROOT)) for path in check['contract'].replay_tests('nodes/node-list/default')),
                                        'apps/cli/tests/Feature/CommandSurfaceTest.php']))
        self.assertIn('apps/cli/tests/Feature/Nodes/NodeContractTest.php', files)

    def test_the_pest_line_names_its_changed_and_pin_test_files(self):
        detail = '0 changed or contract-mapped, 1 pin test files: CommandSurfaceTest'
        with patch.dict(check['pest'].__globals__, {'timed': lambda name, *_args, **_kwargs: Part(name, 'passed', 3.0)}):
            part = check['pest']('pest apps/cli', 'apps/cli', ['apps/cli/tests/Feature/CommandSurfaceTest.php'], detail,
                                 time.monotonic() + 30)
        self.assertEqual((part.status, part.detail), ('passed', detail))


class PhpstanSelectionTest(unittest.TestCase):
    def test_only_changed_files_inside_the_analysed_paths_are_analysed(self):
        paths = ['apps/gateway/app/Domain/Tasks/TaskCopyTurn.php', GATEWAY_TEST, 'tools/phpstan/NoInlineVarOverrideRule.php',
                 'packages/php-sdk/src/Requests/Tasks/CancelTaskRequest.php']
        self.assertEqual(check['phpstan_files'](paths, 'apps/gateway'),
                         ['apps/gateway/app/Domain/Tasks/TaskCopyTurn.php', 'tools/phpstan/NoInlineVarOverrideRule.php'])

    def test_the_docs_app_has_no_static_job(self):
        self.assertNotIn('apps/docs', check['STATIC_JOBS'].values())


class BudgetTest(unittest.TestCase):
    def test_the_budget_stops_a_fixed_part_and_names_it(self):
        started = time.monotonic()
        part = check['timed']('docs-lint', [['sleep', '30']], started + 1)
        self.assertLess(time.monotonic() - started, 10)
        self.assertEqual(part.status, 'skipped')
        self.assertEqual(part.detail, 'budget: stopped after 1s')

    def test_a_part_without_time_left_does_not_start(self):
        part = check['timed']('check-flows', [['false']], time.monotonic() - 1)
        self.assertEqual((part.status, part.detail), ('skipped', 'budget: not started, no time left'))

    def test_a_failure_within_the_budget_fails(self):
        part = check['timed']('pint apps/cli', [['true'], ['false']], time.monotonic() + 30)
        self.assertEqual((part.status, part.detail), ('FAILED', 'exit 1: false'))

    def test_a_unit_whose_host_program_is_missing_is_named_and_does_not_run(self):
        budget, table, _defaults = check['units'].configuration()
        runtime, load = 'swamp/tests/ansible_instance_host_runtime_test.ts', 'swamp/tests/gateway_swamp_load_test.ts'
        # The repository declares the programs in the fixture metadata, not this command.
        self.assertEqual(table[runtime]['host_programs'], ['zip', 'unzip'])
        plan = {'shards': [{'units': [f'{runtime}#0/2', load]}], 'self_hosted_shards': [], 'budget': budget,
                'units': {f'{runtime}#0/2': {'declared_seconds': 60}, load: {'declared_seconds': 60}}}
        present = {'unzip': '/usr/bin/unzip', 'jq': '/usr/bin/jq'}
        with patch.object(check['shutil'], 'which', side_effect=present.get), \
                patch.dict(check['fixtures'].__globals__, {'run': self.fail}):
            part = check['fixtures'](plan, time.monotonic() + 5, table, [])
        self.assertEqual(part.status, 'skipped')
        self.assertEqual(part.detail, f'1 not runnable here: zip missing in this copy ({runtime}#0/2); '
                                      f'deferred to CI: 1 units, 60s declared: {load}')

    def test_the_schedules_fixture_is_named_not_runnable_in_a_copy_without_jq(self):
        budget, table, _defaults = check['units'].configuration()
        schedules = 'swamp/tests/swamp_node_schedules_test.ts'
        # Its release load script exits 127 in 4 of its 8 cases without jq.
        self.assertEqual(table[schedules]['host_programs'], ['jq', 'timeout'])
        plan = {'shards': [{'units': [schedules]}], 'self_hosted_shards': [], 'budget': budget,
                'units': {schedules: {'declared_seconds': 7}}}
        with patch.object(check['shutil'], 'which', side_effect={'timeout': '/usr/bin/timeout'}.get), \
                patch.dict(check['fixtures'].__globals__, {'run': self.fail}):
            part = check['fixtures'](plan, time.monotonic() + 5, table, [])
        self.assertEqual(part.status, 'skipped')
        self.assertEqual(part.detail, f'1 not runnable here: jq missing in this copy ({schedules})')


class DirectFixtureTest(unittest.TestCase):
    """The check finds the directly touched fixtures with the input mapping of bin/ci-units, the planner's own."""

    def test_a_fixture_file_its_input_and_its_metadata_touch_it_directly(self):
        _budget, table, _defaults = check['units'].configuration()
        fleet = 'swamp/tests/ansible_instance_fleet_test.ts'
        units = check['units']
        self.assertIn(fleet, units.direct_files([fleet], table))
        self.assertIn(fleet, units.direct_files([f'.github/ci/fixtures/{fleet}.toml'], table))
        exact = next((file, pattern) for file, fixture in table.items() for pattern in fixture['inputs'] if not any(c in pattern for c in '*?{['))
        self.assertIn(exact[0], units.direct_files([exact[1]], table))
        # The machinery selects the canary set, not a fixture directly.
        self.assertEqual(units.direct_files(['bin/ci-units', 'docs/reference/tasks.md'], table), set())


class FixtureRunTest(unittest.TestCase):
    UNITS = ['swamp/tests/a_test.ts', 'swamp/tests/b_test.ts', 'swamp/tests/c_test.ts']
    # b_test.ts declares a script input, so a change to it touches b directly.
    TABLE = {'swamp/tests/a_test.ts': {'inputs': []}, 'swamp/tests/b_test.ts': {'inputs': ['swamp/scripts/b.sh']},
             'swamp/tests/c_test.ts': {'inputs': []}}

    def plan(self, seconds=80, costs=None):
        budget, _table, _defaults = check['units'].configuration()
        costs = costs or dict.fromkeys(self.UNITS, seconds)
        return {'shards': [{'units': self.UNITS}], 'self_hosted_shards': [], 'budget': budget,
                'units': {unit: {'declared_seconds': costs[unit]} for unit in self.UNITS}}

    def fixtures(self, plan, left, code, status, output='', workers=1, paths=()):
        calls = []

        def run(argv, cwd=None, env=None, timeout=None):
            calls.append(argv)
            units = json.loads(argv[argv.index('--units') + 1])
            statuses = status if isinstance(status, dict) else dict.fromkeys(units, status)
            Path(argv[argv.index('--times') + 1]).write_text(json.dumps([{'unit': unit, 'status': statuses.get(unit, 'passed')} for unit in units]))
            return code, output

        with patch.dict(check['fixtures'].__globals__, {'run': run, 'fixture_workers': lambda: workers}):
            return check['fixtures'](plan, time.monotonic() + left, self.TABLE, list(paths)), calls

    def ran(self, calls):
        return json.loads(calls[0][calls[0].index('--units') + 1])

    def test_the_units_the_change_touches_directly_run_first(self):
        costs = {'swamp/tests/a_test.ts': 5, 'swamp/tests/b_test.ts': 60, 'swamp/tests/c_test.ts': 70}
        _part, calls = self.fixtures(self.plan(costs=costs), 500, 0, 'passed', paths=['swamp/tests/c_test.ts', 'swamp/scripts/b.sh'])
        self.assertEqual(self.ran(calls), ['swamp/tests/b_test.ts', 'swamp/tests/c_test.ts', 'swamp/tests/a_test.ts'])
        # Without a direct change, the cheapest units run first.
        _part, calls = self.fixtures(self.plan(costs=costs), 500, 0, 'passed')
        self.assertEqual(self.ran(calls), self.UNITS)

    def test_the_other_units_that_fit_run_and_the_rest_is_deferred_to_ci_without_failing(self):
        costs = {'swamp/tests/a_test.ts': 70, 'swamp/tests/b_test.ts': 60, 'swamp/tests/c_test.ts': 5}
        part, calls = self.fixtures(self.plan(costs=costs), 95, 0, 'passed', paths=['swamp/tests/a_test.ts'])
        self.assertEqual(self.ran(calls), ['swamp/tests/a_test.ts', 'swamp/tests/c_test.ts'])
        self.assertEqual((part.status, part.detail), ('passed', '2 of 3 planned units passed; '
                                                      'deferred to CI: 1 units, 60s declared: swamp/tests/b_test.ts'))
        self.assertEqual(check['report']([part], 'a' * 40, 1)[-1],
                         'Task check PASSED with incomplete coverage (budget exhausted; CI must finish) against aaaaaaaaaaaa in 1.0s')

    def test_a_unit_that_ran_and_failed_fails_the_check(self):
        part, _calls = self.fixtures(self.plan(), 110, 1, {'swamp/tests/a_test.ts': 'FAILED'}, 'a failed')
        self.assertEqual((part.status, part.output), ('FAILED', 'a failed'))
        self.assertIn('0 of 3 planned units passed; failed: swamp/tests/a_test.ts; deferred to CI: 2 units, 160s declared', part.detail)

    def test_a_failed_tool_setup_fails_and_is_no_deferral(self):
        part, _calls = self.fixtures(self.plan(10), 200, 1, 'NOT RUN', 'Fixture tool setup FAILED; every selected case is NOT RUN')
        self.assertEqual(part.status, 'FAILED')
        self.assertIn('not run: ' + ', '.join(self.UNITS), part.detail)
        self.assertNotIn('deferred', part.detail)
        self.assertIn('Fixture tool setup FAILED', part.output)

    def test_units_the_checks_own_timeout_stops_are_deferred_to_ci(self):
        part, _calls = self.fixtures(self.plan(10), 200, None, 'NOT RUN')
        self.assertEqual(part.status, 'skipped')
        self.assertIn('deferred to CI: 3 units, 30s declared: ' + ', '.join(self.UNITS), part.detail)
        part, _calls = self.fixtures(self.plan(10), 200, 1, 'CANCELLED')
        self.assertEqual(part.status, 'skipped')

    def test_units_pack_into_and_run_with_the_check_process_count(self):
        # 100 s left for three 80 s units: one worker admits one, four workers admit three.
        part, calls = self.fixtures(self.plan(), 110, 0, 'passed', workers=1)
        self.assertEqual(self.ran(calls), ['swamp/tests/a_test.ts'])
        self.assertEqual(calls[0][calls[0].index('--workers') + 1], '1')
        self.assertIn('deferred to CI: 2 units, 160s declared: swamp/tests/b_test.ts, swamp/tests/c_test.ts', part.detail)
        _part, calls = self.fixtures(self.plan(), 110, 0, 'passed', workers=4)
        self.assertEqual(len(self.ran(calls)), 3)
        self.assertEqual(calls[0][calls[0].index('--workers') + 1], '4')

    def test_the_fixture_workers_follow_orbit_check_processes(self):
        with patch.dict(os.environ, {'ORBIT_CHECK_PROCESSES': '1'}):
            self.assertEqual(check['fixture_workers'](), 1)

    def test_a_capacity_row_of_the_ci_plan_is_named(self):
        _budget, table, _defaults = check['units'].configuration()
        plan = {'shards': [], 'self_hosted_shards': [], 'unmapped': [],
                'capacity': [{'file': 'swamp/tests/maintenance_resources_test.ts', 'seconds': 900, 'self_hosted': False, 'shards': 3, 'workers': 12},
                             {'unplaced': ['swamp/tests/maintenance_resources_test.ts']}]}
        self.assertEqual(check['capacity_errors'](plan, table),
                         ['CAPACITY: `swamp/tests/maintenance_resources_test.ts` needs 900 declared seconds, 3 shards / 12 workers, '
                          'above the pull request shard limit. Required checks will fail.',
                          'CAPACITY: 1 selected units do not fit the shard limit and are not run: '
                          '["swamp/tests/maintenance_resources_test.ts"]. Required checks will fail.'])
        self.assertEqual(check['capacity_errors']({**plan, 'capacity': []}, table), [])


class CapacityPreflightTest(unittest.TestCase):
    def test_real_host_input_plan_refuses_overflow_before_any_optional_check(self):
        units = check['units']
        budget, table, defaults = units.configuration()
        # Reproduce the host_updates selection of #3908 through the real planner, with no free shard.
        budget = {**budget, 'runner_shards': 0}
        # Keep the real host_updates registry entry; unrelated mandatory reservations are tested by the pool suite.
        table = {name: fixture for name, fixture in table.items() if name.endswith('/ansible_instance_host_updates_test.ts')}
        plan = units.plan(['swamp/ansible/tasks/instance-host.yml'], budget, table, defaults)
        unplaced = units.unplaced_units(plan)
        self.assertTrue(any('ansible_instance_host_updates_test.ts' in unit for unit in unplaced))
        jobs = {**JOBS, 'docs': False, 'api-reference': False, 'swamp': False}
        for only in ('ci-plan', 'pest', 'fixtures'):
            with self.subTest(only=only):
                output = io.StringIO()
                stubs = {'changes': lambda _root, _base: (True, 'a' * 40, 'origin/main', ['swamp/ansible/tasks/instance-host.yml']),
                         'candidate_head': lambda _committed: 'HEAD',
                         'ci_plan': lambda _base, _head: (jobs, plan, ''),
                         'pest_plan': lambda *_args: self.fail('Pest started before capacity refusal'),
                         'fixtures': lambda *_args: self.fail('Fixtures started before capacity refusal')}
                with patch.dict(check['main'].__globals__, stubs), redirect_stdout(output):
                    code = check['main'](['--only', only, '--budget', '0'])
                self.assertEqual(code, 1)
                self.assertIn('fixture-capacity: FAILED', output.getvalue())
                self.assertIn('ansible_instance_host_updates_test.ts', output.getvalue())

    def test_named_gap_is_not_an_unplaceable_required_selection(self):
        plan = {'shards': [], 'self_hosted_shards': [], 'unmapped': [],
                'not_run': [{'file': 'swamp/gap.py', 'units': ['swamp/gap.py'], 'reason': 'capacity gap per ADR 0262'}]}
        jobs = {**JOBS, 'docs': False, 'api-reference': False, 'swamp': False}
        stubs = {'changes': lambda _root, _base: (True, 'a' * 40, 'origin/main', []),
                 'candidate_head': lambda _committed: 'HEAD', 'ci_plan': lambda _base, _head: (jobs, plan, '')}
        with patch.dict(check['main'].__globals__, stubs), redirect_stdout(io.StringIO()):
            self.assertEqual(check['main'](['--only', 'ci-plan']), 0)


class FixturePopulationTest(unittest.TestCase):
    def test_a_planned_fixture_is_checked_even_when_its_own_file_did_not_change(self):
        fixtures = {'swamp/tests/maintenance_resources_test.ts': {'cases': ['a', 'b']}, 'swamp/tests/frankenphp_proxy_test.ts': {'cases': ['a']},
                    'swamp/tests/unplanned_test.ts': {'cases': ['a']}, 'swamp/tests/instance_clone_test.py': {'cases': ['test_a']}}
        plan = {'shards': [{'units': ['swamp/tests/maintenance_resources_test.ts#0/2', 'swamp/tests/instance_clone_test.py']}],
                'self_hosted_shards': [{'units': ['swamp/tests/frankenphp_proxy_test.ts']}]}
        units = check['units']
        with patch.object(units, 'configuration', return_value=({}, fixtures, [])), \
                patch.object(units, 'collect', return_value={'cases': ['a', 'b', 'c']}) as collect:
            part = check['fixture_population'](plan, time.monotonic() + 60)
        # CI compares a Deno fixture's registered cases in its PR units, hosted and self-hosted; the table load checks a Python fixture.
        self.assertEqual([call.args[0] for call in collect.call_args_list],
                         ['swamp/tests/maintenance_resources_test.ts', 'swamp/tests/frankenphp_proxy_test.ts'])
        self.assertEqual(part.status, 'FAILED')
        self.assertIn('not stored: ["c"]', part.output)


class CollectionTimeoutTest(unittest.TestCase):
    def population(self, error):
        fixtures = {'swamp/tests/maintenance_resources_test.ts': {'cases': ['a']}}
        plan = {'shards': [{'units': ['swamp/tests/maintenance_resources_test.ts']}], 'self_hosted_shards': []}
        units = check['units']
        with patch.object(units, 'configuration', return_value=({}, fixtures, [])), patch.object(units, 'collect', side_effect=error):
            return check['fixture_population'](plan, time.monotonic() + 60)

    def test_a_collection_timeout_is_a_named_budget_skip(self):
        part = self.population(subprocess.TimeoutExpired(['deno'], 15))
        self.assertEqual(part.status, 'skipped')
        self.assertIn('budget skipped: swamp/tests/maintenance_resources_test.ts (collection over 15s)', part.detail)

    def test_a_collection_error_fails(self):
        part = self.population(subprocess.CalledProcessError(1, ['deno']))
        self.assertEqual(part.status, 'FAILED')
        self.assertIn('collection failed', part.output)


class StartCommitTest(unittest.TestCase):
    def setUp(self):
        self.repo = Path(tempfile.mkdtemp(prefix='task-check-base-'))
        self.addCleanup(shutil.rmtree, self.repo, ignore_errors=True)
        self.git('init', '--quiet', '--initial-branch=main')

    def git(self, *args):
        return subprocess.run(['git', '-C', str(self.repo), *args], check=True, capture_output=True, text=True,
                              env={**os.environ, **IDENTITY}).stdout.strip()

    def commit(self, name):
        (self.repo / name).write_text(name)
        self.git('add', name)
        self.git('commit', '--quiet', '-m', name)
        return self.git('rev-parse', 'HEAD')

    def test_sigterm_removes_the_candidate_worktree(self):
        head = self.commit('first')
        previous = signal.getsignal(signal.SIGTERM)
        self.addCleanup(signal.signal, signal.SIGTERM, previous)
        check['exit_on_sigterm']()
        with patch.dict(check['candidate_checkout'].__wrapped__.__globals__, {'ROOT': self.repo}):
            with self.assertRaises(SystemExit):
                with check['candidate_checkout'](head, False) as worktree:
                    self.assertTrue((worktree / 'first').is_file())
                    os.kill(os.getpid(), signal.SIGTERM)
                    time.sleep(5)
        self.assertFalse(worktree.parent.exists())
        self.assertEqual(self.git('worktree', 'list', '--porcelain').count('worktree '), 1)
        self.assertFalse((self.repo / '.git/worktrees').exists() and any((self.repo / '.git/worktrees').iterdir()))

    def test_a_branch_at_its_start_starts_at_the_seed(self):
        seed = self.commit('seed')
        self.git('update-ref', 'refs/remotes/origin/main', seed)
        self.git('checkout', '--quiet', '-b', 'task')
        self.commit('work')
        (self.repo / 'untracked').write_text('')
        self.assertEqual(check['changes'](self.repo, None), (False, seed, 'origin/main', ['untracked', 'work']))

    def newer_main(self):
        """A copy whose task branch did work on the seed, then a newer main fetched into origin/main from a bundle."""
        seed = self.commit('seed')
        self.git('update-ref', 'refs/remotes/origin/main', seed)
        self.git('checkout', '--quiet', '-b', 'task')
        self.commit('work')
        self.git('checkout', '--quiet', '--detach', 'main')
        newer = self.commit('newer')
        self.git('update-ref', 'refs/remotes/origin/main', newer)
        self.git('checkout', '--quiet', 'task')
        return seed, newer

    def test_a_branch_that_merged_a_newer_main_starts_at_that_main(self):
        _seed, newer = self.newer_main()
        self.git('merge', '--quiet', '--no-edit', 'origin/main')
        self.assertEqual(check['changes'](self.repo, None), (True, newer, 'origin/main', ['work']))

    def test_a_branch_rebased_on_a_newer_main_starts_at_that_main(self):
        _seed, newer = self.newer_main()
        self.git('rebase', '--quiet', 'origin/main')
        self.assertEqual(check['changes'](self.repo, None), (True, newer, 'origin/main', ['work']))

    def test_head_on_a_main_ahead_of_origin_main_counts_its_own_commits(self):
        seed = self.commit('seed')
        self.git('update-ref', 'refs/remotes/origin/main', seed)
        self.commit('unpushed')
        self.assertEqual(check['changes'](self.repo, None), (True, seed, 'origin/main', ['unpushed']))

    def test_without_origin_main_the_start_commit_is_the_merge_base_with_main(self):
        seed = self.commit('seed')
        self.git('checkout', '--quiet', '-b', 'task')
        self.commit('work')
        self.assertEqual(check['changes'](self.repo, None), (True, seed, 'main', ['work']))

    def test_an_explicit_start_commit_is_used_as_given(self):
        first = self.commit('first')
        self.commit('seed')
        self.git('checkout', '--quiet', '-b', 'task')
        self.commit('work')
        self.assertEqual(check['changes'](self.repo, first), (True, first, first, ['seed', 'work']))


class ReportTest(unittest.TestCase):
    def test_one_line_per_part_then_the_result(self):
        parts = [Part('ci-plan', 'passed', 0.52, 'jobs: docs'),
                 Part('phpstan apps/gateway', 'skipped', 120.0, 'budget: stopped after 120s'),
                 Part('pest apps/gateway suite', 'skipped', 0, 'CI runs the full suite'),
                 Part('pest packages/php-sdk', 'FAILED', 1.04, 'exit 1: pest; the whole suite', 'output'),
                 Part('pest apps/cli', 'not selected', 0, 'CI does not select the cli-tests job')]
        lines = check['report'](parts, '0123456789abcdef', 130.04)
        self.assertEqual(lines[:7], ['', 'Task check summary',
                                     '  passed       ci-plan (0.5s; jobs: docs)',
                                     '  skipped      phpstan apps/gateway (120.0s; budget: stopped after 120s)',
                                     '  skipped      pest apps/gateway suite (0.0s; CI runs the full suite)',
                                     '  FAILED       pest packages/php-sdk (1.0s; exit 1: pest; the whole suite)',
                                     '  not selected pest apps/cli (0.0s; CI does not select the cli-tests job)'])
        self.assertEqual(lines[7:9], ['Last lines of pest packages/php-sdk:', 'output'])
        self.assertTrue(lines[9].startswith('Not in this check: '))
        self.assertEqual(lines[-1], 'Task check FAILED against 0123456789ab in 130.0s')
        self.assertEqual(check['report'](parts[:3], '0123456789abcdef', 1)[-1],
                         'Task check PASSED with incomplete coverage (budget exhausted; CI must finish) against 0123456789ab in 1.0s')

    def test_budget_deferrals_and_stops_cannot_read_as_full_coverage(self):
        for detail in ('budget: stopped after 40s', 'budget: not started, no time left',
                       '1 of 2 planned fixtures collected; budget skipped: b.ts',
                       '1 of 2 planned units passed; deferred to CI: 1 units, 90s declared: b.ts'):
            with self.subTest(detail=detail):
                lines = check['report']([Part('fixtures', 'passed', 1, detail)], 'a' * 40, 1)
                self.assertIn('PASSED with incomplete coverage (budget exhausted; CI must finish)', lines[-1])
        lines = check['report']([Part('pest apps/gateway suite', 'skipped', 0, 'CI runs the full suite')], 'a' * 40, 1)
        self.assertIn('Task check PASSED against', lines[-1])


    def test_the_summary_stays_within_its_bound_and_names_every_part(self):
        # Check 21615: a fixtures line of every budget-skipped unit was longer than the stored 16 KiB tail.
        skipped = 'budget skipped: ' + ', '.join(f'swamp/tests/unit_{index}_test.ts#0/2' for index in range(800))
        failures = '\n'.join(f'FAILED case {index} ' + 'x' * 500 for index in range(400))
        parts = [Part('ci-plan', 'passed', 1.0)]
        parts += [Part(f'pest project-{index}', 'FAILED', 40.0, 'exit 1', failures) for index in range(6)]
        parts.append(Part('fixtures', 'skipped', 200.0, skipped))
        lines = check['report'](parts, '0123456789abcdef', 206.6)
        self.assertLessEqual(len('\n'.join(lines).encode()) + 1, check['SUMMARY_BYTES'])
        self.assertTrue(all(len(line.encode()) <= check['LINE_BYTES'] for line in lines))
        summary = lines[2:2 + len(parts)]
        self.assertEqual([line[15:].split(' (')[0] for line in summary], [part.name for part in parts])
        self.assertTrue(summary[-1].startswith('  skipped      fixtures (200.0s; budget skipped: '))
        self.assertTrue(summary[-1].endswith(f'… ({len(f"  skipped      fixtures (200.0s; {skipped})".encode())} bytes)'))
        for index in range(6):
            block = lines.index(f'Last lines of pest project-{index}:')
            # Each failed part shows its newest lines, up to its share of the bound.
            self.assertTrue(lines[block + 1].startswith('FAILED case '))
        self.assertTrue(lines[lines.index('Last lines of pest project-1:') - 1].startswith('FAILED case 399 '))
        self.assertEqual(lines[-2:], [check['NOT_IN_CHECK'], 'Task check FAILED against 0123456789ab in 206.6s'])

    def test_every_part_output_is_bounded_whatever_made_it(self):
        # Such as an error message or the stale-fixture and capacity rows, which no command printed.
        part = Part('fixture-population', 'FAILED', 0, 'the fixture table does not load',
                    'x' * 5000 + '\n' + '\n'.join(f'row {index}' for index in range(200)))
        rows = part.output.splitlines()
        self.assertEqual((rows[0], rows[-1], len(rows)), ('…', 'row 199', check['OUTPUT_LINES'] + 1))
        self.assertTrue(all(len(row.encode()) <= check['LINE_BYTES'] for row in rows))
        self.assertTrue(Part('ci-plan', 'FAILED', 0, '', 'y' * 5000).output.endswith('… (5000 bytes)'))
        self.assertEqual(Part('ci-plan', 'passed').output, '')

    def test_a_very_long_output_line_keeps_its_start_and_names_its_size(self):
        text = 'first\n' + 'é' * 10000 + '\nlast\n'
        rows = check['tail'](text).splitlines()
        self.assertEqual(rows[0], 'first')
        self.assertEqual(rows[2], 'last')
        self.assertTrue(rows[1].endswith('… (20000 bytes)'))
        self.assertLessEqual(len(rows[1].encode()), check['LINE_BYTES'])
        self.assertTrue(rows[1].startswith('é' * 100))


class CiPolicyTest(unittest.TestCase):
    """Expensive checks follow their inputs, without skipping unconditional budget and capacity checks."""

    def policy(self, commands, seconds=30):
        with patch.dict(check['ci_policy'].__globals__, {'CI_POLICY': commands}):
            return check['ci_policy'](time.monotonic() + seconds)

    def test_the_part_runs_the_commands_of_cis_policy_job(self):
        step = check['ci_job']('ci-policy')
        self.assertIn('    name: CI policy\n', step)
        commands = [shlex.split(line.strip()) for line in step.splitlines() if line.strip().startswith('python3 -m unittest discover')]
        self.assertEqual(check['CI_POLICY'], commands)
        self.assertEqual(len(commands), 2)

    def test_a_docs_input_runs_policy_tests(self):
        ran = []
        jobs = {**JOBS, 'docs': False, 'api-reference': False, 'swamp': False}
        stubs = {'changes': lambda _root, _base: (True, 'a' * 40, 'origin/main', ['docs/reference/tasks.md']),
                 'candidate_head': lambda _committed: 'HEAD', 'ci_plan': lambda _base, _head: (jobs, {'shards': [], 'self_hosted_shards': [], 'unmapped': []}, ''),
                 'run': lambda argv, *_args, **_kwargs: ran.append(argv) or (0, '')}
        output = io.StringIO()
        with patch.dict(check['main'].__globals__, stubs), redirect_stdout(output):
            code = check['main'](['--only', 'ci-policy'])
        self.assertEqual(ran, check['CI_POLICY'])
        self.assertEqual(code, 0)
        self.assertIn('  passed       ci-policy (', output.getvalue())

    def test_expensive_checks_follow_tooling_docs_and_api_inputs_not_the_pr_docs_job(self):
        cases = [([], []), (['packages/php-sdk/tests/Unit/Requests/Tasks/TaskRequestsTest.php'], []),
                 (['apps/web/src/main.ts'], []),
                 (['bin/task-check'], ['check-flows', 'docs-lint', 'ci-policy']),
                 (['tools/test_ci_units.py'], ['check-flows', 'docs-lint', 'ci-policy']),
                 (['.github/ci/checks.toml'], ['check-flows', 'docs-lint', 'ci-policy']),
                 (['docs/reference/tasks.md'], ['check-flows', 'docs-lint', 'ci-policy']),
                 (['apps/docs/app/Documentation/DocsImpact.php'], ['check-flows', 'docs-lint', 'ci-policy']),
                 (['swamp/workflows/workflow-operation-instance-create.yaml'], ['check-flows', 'docs-lint', 'ci-policy']),
                 (['apps/agent/src/commands/ci_runner.rs'], ['ci-policy']),
                 (['apps/pi-server/src/main.ts'], ['ci-policy']),
                 (['.node-version'], ['ci-policy']),
                 (['apps/gateway/app/Support/ValidatedData.php'], ['check-flows', 'docs-lint', 'ci-policy']),
                 (['swamp/extensions/behaviors/operations/node/ci_host_spec.ts'], ['ci-policy']),
                 (['apps/gateway/resources/ci-runner/hold.py'], ['check-flows', 'docs-lint', 'ci-policy']),
                 (['apps/gateway/resources/machine-software/recipe.json'], ['check-flows', 'docs-lint', 'ci-policy']),
                 (['apps/gateway/app/Domain/Tasks/direct-operations.php'], ['check-flows', 'docs-lint', 'ci-policy']),
                 (['packages/php-sdk/src/Requests/Tasks/CancelTaskRequest.php'], ['check-flows', 'docs-lint'])]
        for paths, expected in cases:
            with self.subTest(paths=paths):
                ran = []
                jobs = {**JOBS, 'docs': True, 'api-reference': False, 'swamp': False}
                stubs = {'changes': lambda _root, _base: (True, 'a' * 40, 'origin/main', paths),
                         'candidate_head': lambda _committed: 'HEAD',
                         'ci_plan': lambda _base, _head: (jobs, {'shards': [], 'self_hosted_shards': [], 'unmapped': []}, ''),
                         'timed': lambda name, *_args, **_kwargs: ran.append(name) or Part(name, 'passed'),
                         'ci_policy': lambda _deadline: ran.append('ci-policy') or Part('ci-policy', 'passed')}
                with patch.dict(check['main'].__globals__, stubs), redirect_stdout(io.StringIO()):
                    code = check['main'](['--only', 'ci-policy,docs-lint,check-flows'])
                self.assertEqual(code, 0)
                self.assertEqual(ran, expected)

    def test_a_selected_part_passes_or_fails_with_its_command(self):
        self.assertEqual(self.policy([['true'], ['true']]).status, 'passed')
        part = self.policy([['true'], ['false']])
        self.assertEqual((part.status, part.detail), ('FAILED', 'exit 1: false'))

    def test_the_budget_stops_it_and_names_it(self):
        started = time.monotonic()
        part = self.policy([['sleep', '30']], seconds=1)
        self.assertLess(time.monotonic() - started, 10)
        self.assertEqual((part.status, part.detail), ('skipped', 'budget: stopped after 1s'))

    def test_it_runs_before_pest_and_the_fixtures(self):
        order = []
        jobs = {**JOBS, 'docs': False, 'api-reference': False, 'swamp': False}
        stubs = {'changes': lambda _root, _base: (True, 'a' * 40, 'origin/main', ['docs/reference/tasks.md']),
                 'candidate_head': lambda _committed: 'HEAD', 'ci_plan': lambda _base, _head: (jobs, {'shards': [], 'self_hosted_shards': [], 'unmapped': []}, ''),
                 'ci_policy': lambda _deadline: order.append('ci-policy') or Part('ci-policy', 'passed'),
                 'fixture_population': lambda _plan, _deadline, _table: order.append('fixture-population') or Part('fixture-population', 'passed'),
                 'pest_plan': lambda _jobs, _paths, _base: order.append('pest') or [],
                 'fixtures': lambda _plan, _deadline, _table, _paths: order.append('fixtures') or Part('fixtures', 'passed'),
                 'capacity_errors': lambda _plan, _table: []}
        output = io.StringIO()
        with patch.dict(check['main'].__globals__, stubs), redirect_stdout(output):
            check['main'](['--only', 'fixtures,pest,fixture-population,ci-policy'])
        self.assertEqual(order, ['ci-policy', 'fixture-population', 'pest', 'fixtures'])
        # The run starts by naming CI's per-unit time caps.
        self.assertEqual(output.getvalue().splitlines()[1], check['ci_caps']())


class CiLimitTest(unittest.TestCase):
    """Pest uses CI's override or its enforced project default, and the check names that effective cap."""

    def argv(self, project):
        calls = []
        with patch.dict(check['pest'].__globals__, {'timed': lambda name, commands, *_args, **_kwargs: calls.extend(commands) or Part(name, 'passed')}):
            check['pest'](f'pest {project}', project, [], '', time.monotonic() + 30)
        return calls[0]

    def test_the_gateway_pest_part_uses_cis_five_second_limit(self):
        self.assertEqual(check['ci_time_limit']('gateway-tests'), 5)
        self.assertIn('--default-time-limit=5', self.argv('apps/gateway'))

    def test_every_pest_part_passes_exactly_the_limit_of_its_ci_job(self):
        for job, project in check['PEST_JOBS'].items():
            with self.subTest(job=job):
                block = check['ci_job'](job)
                ci = [flag for line in block.splitlines() if 'vendor/bin/pest' in line or 'composer test' in line
                      for flag in line.split() if flag.startswith('--default-time-limit=')]
                if project == 'apps/gateway':
                    self.assertEqual(ci, [])
                    ci = ['--default-time-limit=5']
                elif project == 'apps/cli':
                    self.assertEqual(ci, [])
                    ci = ['--default-time-limit=2']
                self.assertEqual([flag for flag in self.argv(project) if flag.startswith('--default-time-limit=')], ci)

    def test_the_limit_is_read_from_the_workflow(self):
        workflow = Path(tempfile.mkdtemp(prefix='task-check-ci-')) / 'ci.yml'
        self.addCleanup(shutil.rmtree, workflow.parent, ignore_errors=True)
        workflow.write_text((ROOT / '.github/workflows/ci.yml').read_text().replace(
            'vendor/bin/pest --parallel --processes=4 ', 'vendor/bin/pest --parallel --processes=4 --default-time-limit=7 '))
        with patch.dict(check['ci_time_limit'].__globals__, {'CI_WORKFLOW': workflow}):
            self.assertIn('--default-time-limit=7', self.argv('apps/gateway'))
            self.assertNotIn('--default-time-limit=7', self.argv('apps/cli'))

    def deliverable_limit(self, directory):
        output = io.StringIO()
        with redirect_stdout(output):
            self.assertEqual(check['main'](['--ci-time-limit', directory]), 0)
        return output.getvalue()

    def test_a_deliverable_asks_for_cis_limit_of_the_pest_project_that_holds_its_directory(self):
        # Check 2579: a deliverable's own Pest run got phpunit.xml's 2 s on a loaded copy, where CI's 5 s holds.
        self.assertEqual(self.deliverable_limit('apps/gateway'), 'ci-time-limit: 5\n')
        self.assertEqual(self.deliverable_limit('apps/gateway/tests/Feature'), 'ci-time-limit: 5\n')
        self.assertEqual(self.deliverable_limit('./apps/gateway/'), 'ci-time-limit: 5\n')

    def test_a_project_ci_passes_no_limit_to_and_a_non_pest_directory_answer_none(self):
        for directory in ('packages/php-sdk', '.', 'swamp', 'apps/gateway-other'):
            with self.subTest(directory=directory):
                self.assertEqual(self.deliverable_limit(directory), 'ci-time-limit: none\n')
        self.assertEqual(self.deliverable_limit('apps/cli'), 'ci-time-limit: 2\n')

    def test_a_directory_outside_the_checkout_is_refused_not_answered_with_no_limit(self):
        for directory in ('/srv/workspace/apps/gateway', '../apps/gateway', 'apps/../../x'):
            with self.subTest(directory=directory), redirect_stdout(io.StringIO()), patch('sys.stderr', io.StringIO()):
                with self.assertRaises(SystemExit) as stopped:
                    check['main'](['--ci-time-limit', directory])
                self.assertEqual(stopped.exception.code, 2)

    def test_the_deliverable_limit_is_read_from_the_workflow(self):
        workflow = Path(tempfile.mkdtemp(prefix='task-check-ci-')) / 'ci.yml'
        self.addCleanup(shutil.rmtree, workflow.parent, ignore_errors=True)
        workflow.write_text((ROOT / '.github/workflows/ci.yml').read_text().replace(
            'vendor/bin/pest --parallel --processes=4 ', 'vendor/bin/pest --parallel --processes=4 --default-time-limit=7 '))
        with patch.dict(check['ci_time_limit'].__globals__, {'CI_WORKFLOW': workflow}):
            self.assertEqual(self.deliverable_limit('apps/gateway'), 'ci-time-limit: 7\n')

    def test_the_project_default_is_read_without_a_workflow_override(self):
        with tempfile.TemporaryDirectory() as scratch:
            project = Path(scratch) / 'apps/gateway'
            project.mkdir(parents=True)
            config = project / 'phpunit.xml'
            config.write_text('<phpunit enforceTimeLimit="true" defaultTimeLimit="9"/>')
            with patch.dict(check['ci_time_limit'].__globals__, {'ROOT': Path(scratch)}):
                self.assertEqual(check['ci_time_limit']('gateway-tests'), 9)
                config.write_text('<phpunit enforceTimeLimit="false" defaultTimeLimit="9"/>')
                self.assertIsNone(check['ci_time_limit']('gateway-tests'))

    def test_the_limit_lookup_runs_no_check(self):
        started = time.monotonic()
        result = subprocess.run([str(ROOT / 'bin/task-check'), '--ci-time-limit', 'apps/gateway'], capture_output=True,
                                text=True, timeout=30, cwd=tempfile.gettempdir())
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, 'ci-time-limit: 5\n', ''))
        self.assertLess(time.monotonic() - started, 10)

    def test_the_check_names_cis_per_unit_cap(self):
        budget = tomllib.loads((ROOT / '.github/ci/checks.toml').read_text())['budget']
        caps = check['ci_caps']()
        self.assertIn(f"declares at most {budget['shard_seconds']}s and is stopped at {budget['unit_ceiling_seconds']}s", caps)
        self.assertIn('apps/gateway 5s', caps)


if __name__ == '__main__':
    unittest.main()
