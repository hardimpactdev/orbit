"""bin/task-check selects, labels, limits and reports its parts as docs/reference/implementation-loop.md states.

These cases need no vendor folder, so CI runs them. tools/test_task_check.py holds the real failures, which need a
seeded checkout."""
import json
import os
from pathlib import Path
import runpy
import shutil
import signal
import subprocess
import tempfile
import time
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


class PestSelectionTest(unittest.TestCase):
    def test_a_selected_job_runs_its_changed_tests_and_leaves_its_suite_to_ci(self):
        plan = pest_plan({'gateway-tests': True}, [GATEWAY_TEST, 'apps/gateway/app/Domain/Tasks/TaskCopyTurn.php'])
        self.assertEqual(plan['pest apps/gateway'], ([GATEWAY_TEST], 'run', '1 changed or contract-mapped test files'))
        self.assertEqual(plan['pest apps/gateway suite'], ([], 'skipped', 'CI runs the full suite'))

    def test_a_changed_class_selects_no_test_by_its_name(self):
        plan = pest_plan({'gateway-tests': True}, ['apps/gateway/app/Domain/Tasks/TaskCopyTurn.php'])
        self.assertNotIn('pest apps/gateway', plan)

    def test_a_selected_suite_without_a_test_to_run_is_skipped_not_unselected(self):
        for job, project in (('gateway-tests', 'apps/gateway'), ('cli-tests', 'apps/cli'), ('docs', 'apps/docs')):
            plan = pest_plan({job: True}, [])
            self.assertNotIn(f'pest {project}', plan)
            self.assertEqual(plan[f'pest {project} suite'], ([], 'skipped', 'CI runs the full suite'))

    def test_a_job_ci_does_not_select_is_not_selected(self):
        plan = pest_plan({}, [GATEWAY_TEST])
        self.assertEqual(plan['pest apps/gateway'], ([], 'not selected', 'CI does not select the gateway-tests job'))
        self.assertNotIn('pest apps/gateway suite', plan)

    def test_the_sdk_job_runs_the_whole_sdk_suite(self):
        self.assertEqual(pest_plan({'sdk-tests': True}, [])['pest packages/php-sdk'], (None, 'run', 'the whole suite'))

    def test_a_changed_sdk_fixture_runs_the_cli_tests_that_bin_cli_contract_names(self):
        plan = pest_plan({'cli-tests': True}, ['packages/php-sdk/fixtures/nodes/node-list/default.json'],
                         fixtures=['nodes/node-list/default'])
        files, status, _detail = plan['pest apps/cli']
        self.assertEqual(status, 'run')
        self.assertEqual(files, sorted(str(path.relative_to(ROOT)) for path in check['contract'].replay_tests('nodes/node-list/default')))
        self.assertIn('apps/cli/tests/Feature/Nodes/NodeContractTest.php', files)


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

    def test_a_unit_over_the_budget_is_named_and_does_not_run(self):
        budget, _fixtures, _defaults = check['units'].configuration()
        unit = 'swamp/tests/maintenance_resources_test.ts'
        plan = {'shards': [{'units': [unit]}], 'deferred': [], 'units': {unit: {'declared_seconds': 60}}, 'budget': budget}
        part = check['fixtures'](plan, time.monotonic() + 5, {})
        self.assertEqual((part.status, part.detail), ('skipped', f'budget skipped: {unit} (budget: declared 60s)'))

    def test_a_unit_whose_host_program_is_missing_is_named_and_does_not_run(self):
        budget, table, _defaults = check['units'].configuration()
        runtime, load = 'swamp/tests/ansible_instance_host_runtime_test.ts', 'swamp/tests/gateway_swamp_load_test.ts'
        # The repository declares the programs in the fixture metadata, not this command.
        self.assertEqual(table[runtime]['host_programs'], ['zip', 'unzip'])
        plan = {'shards': [{'units': [f'{runtime}#0/2', load]}], 'deferred': [], 'budget': budget,
                'units': {f'{runtime}#0/2': {'declared_seconds': 60}, load: {'declared_seconds': 60}}}
        present = {'unzip': '/usr/bin/unzip', 'jq': '/usr/bin/jq'}
        with patch.object(check['shutil'], 'which', side_effect=present.get), \
                patch.dict(check['fixtures'].__globals__, {'run': self.fail}):
            part = check['fixtures'](plan, time.monotonic() + 5, table)
        self.assertEqual(part.status, 'skipped')
        self.assertEqual(part.detail, f'1 not runnable here: zip missing in this copy ({runtime}#0/2); budget skipped: {load} (budget: declared 60s)')

    def test_the_schedules_fixture_is_named_not_runnable_in_a_copy_without_jq(self):
        budget, table, _defaults = check['units'].configuration()
        schedules = 'swamp/tests/swamp_node_schedules_test.ts'
        # Its release load script exits 127 in 4 of its 8 cases without jq.
        self.assertEqual(table[schedules]['host_programs'], ['jq', 'timeout'])
        plan = {'shards': [{'units': [schedules]}], 'deferred': [], 'budget': budget,
                'units': {schedules: {'declared_seconds': 7}}}
        with patch.object(check['shutil'], 'which', side_effect={'timeout': '/usr/bin/timeout'}.get), \
                patch.dict(check['fixtures'].__globals__, {'run': self.fail}):
            part = check['fixtures'](plan, time.monotonic() + 5, table)
        self.assertEqual(part.status, 'skipped')
        self.assertEqual(part.detail, f'1 not runnable here: jq missing in this copy ({schedules})')


class FixtureRunTest(unittest.TestCase):
    UNITS = ['swamp/tests/a_test.ts', 'swamp/tests/b_test.ts', 'swamp/tests/c_test.ts']

    def plan(self, seconds=80):
        budget, _table, _defaults = check['units'].configuration()
        return {'shards': [{'units': self.UNITS}], 'deferred': [], 'budget': budget,
                'units': {unit: {'declared_seconds': seconds} for unit in self.UNITS}}

    def fixtures(self, plan, left, code, status, output='', workers=1):
        calls = []

        def run(argv, cwd=None, env=None, timeout=None):
            calls.append(argv)
            units = json.loads(argv[argv.index('--units') + 1])
            Path(argv[argv.index('--times') + 1]).write_text(json.dumps([{'unit': unit, 'status': status} for unit in units]))
            return code, output

        with patch.dict(check['fixtures'].__globals__, {'run': run, 'fixture_workers': lambda: workers}):
            return check['fixtures'](plan, time.monotonic() + left, {}), calls

    def test_a_failed_tool_setup_fails_and_is_no_budget_skip(self):
        part, _calls = self.fixtures(self.plan(10), 200, 1, 'NOT RUN', 'Fixture tool setup FAILED; every selected case is NOT RUN')
        self.assertEqual(part.status, 'FAILED')
        self.assertIn('not run: ' + ', '.join(self.UNITS), part.detail)
        self.assertNotIn('budget skipped', part.detail)
        self.assertIn('Fixture tool setup FAILED', part.output)

    def test_only_the_checks_own_timeout_stops_units_as_a_budget_skip(self):
        part, _calls = self.fixtures(self.plan(10), 200, None, 'NOT RUN')
        self.assertEqual(part.status, 'skipped')
        self.assertIn('budget skipped: swamp/tests/a_test.ts (stopped at the end of the budget)', part.detail)
        part, _calls = self.fixtures(self.plan(10), 200, 1, 'CANCELLED')
        self.assertEqual(part.status, 'skipped')

    def test_units_pack_into_and_run_with_the_check_process_count(self):
        # 100 s left for three 80 s units: one worker admits one, four workers admit three.
        part, calls = self.fixtures(self.plan(), 110, 0, 'passed', workers=1)
        self.assertEqual(json.loads(calls[0][calls[0].index('--units') + 1]), ['swamp/tests/a_test.ts'])
        self.assertEqual(calls[0][calls[0].index('--workers') + 1], '1')
        self.assertIn('swamp/tests/b_test.ts (budget: declared 80s)', part.detail)
        _part, calls = self.fixtures(self.plan(), 110, 0, 'passed', workers=4)
        self.assertEqual(len(json.loads(calls[0][calls[0].index('--units') + 1])), 3)
        self.assertEqual(calls[0][calls[0].index('--workers') + 1], '4')

    def test_the_fixture_workers_follow_orbit_check_processes(self):
        with patch.dict(os.environ, {'ORBIT_CHECK_PROCESSES': '1'}):
            self.assertEqual(check['fixture_workers'](), 1)

    def test_a_capacity_row_of_the_ci_plan_is_named(self):
        _budget, table, _defaults = check['units'].configuration()
        plan = {'shards': [], 'deferred': [], 'unmapped': [],
                'capacity': [{'file': 'swamp/tests/maintenance_resources_test.ts', 'seconds': 900, 'shards': 3, 'workers': 12}]}
        self.assertEqual(check['capacity_errors'](plan, table),
                         ['CAPACITY: `swamp/tests/maintenance_resources_test.ts` needs 900 declared seconds, 3 shards / 12 workers, '
                          'above the pull request shard limit. Required checks will fail.'])
        self.assertEqual(check['capacity_errors']({**plan, 'capacity': []}, table), [])


class FixturePopulationTest(unittest.TestCase):
    def test_a_planned_fixture_is_checked_even_when_its_own_file_did_not_change(self):
        fixtures = {'swamp/tests/maintenance_resources_test.ts': {'cases': ['a', 'b']}, 'swamp/tests/deferred_test.ts': {'cases': ['a']},
                    'swamp/tests/instance_clone_test.py': {'cases': ['test_a']}}
        plan = {'shards': [{'units': ['swamp/tests/maintenance_resources_test.ts#0/2', 'swamp/tests/instance_clone_test.py']}],
                'deferred': ['swamp/tests/maintenance_resources_test.ts#1/2', 'swamp/tests/deferred_test.ts']}
        units = check['units']
        with patch.object(units, 'configuration', return_value=({}, fixtures, [])), \
                patch.object(units, 'collect', return_value={'cases': ['a', 'b', 'c']}) as collect:
            part = check['fixture_population'](plan, time.monotonic() + 60)
        # CI compares a Deno fixture's registered cases in its PR units; the table load checks a Python fixture, and the main
        # full set collects a deferred fixture's cases live.
        collect.assert_called_once_with('swamp/tests/maintenance_resources_test.ts')
        self.assertEqual(part.status, 'FAILED')
        self.assertIn('not stored: ["c"]', part.output)


class CollectionTimeoutTest(unittest.TestCase):
    def population(self, error):
        fixtures = {'swamp/tests/maintenance_resources_test.ts': {'cases': ['a']}}
        plan = {'shards': [{'units': ['swamp/tests/maintenance_resources_test.ts']}], 'deferred': []}
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
        self.assertEqual(check['report'](parts[:3], '0123456789abcdef', 1)[-1], 'Task check PASSED against 0123456789ab in 1.0s')


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

if __name__ == '__main__':
    unittest.main()
