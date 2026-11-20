"""Fixture costs retain measured headroom, complete populations and bounded units."""
import json
import math
import runpy
import shutil
import unittest
from pathlib import Path
from unittest.mock import patch

from test_ci_units import ROOT, names, units


class FixtureCostEvidenceTest(unittest.TestCase):
    def test_every_workspace_case_is_retained_in_a_bounded_whole_file(self):
        evidence = json.loads((ROOT / 'tools/tests/ci-workspace-cost-evidence.json').read_text())['files']
        budget, fixtures, defaults = units.configuration()
        result = units.plan(['swamp/tests/_support/task_instance_workspace.py'], budget, fixtures, defaults)
        self.assertCountEqual(names(result), [*evidence, "swamp/tests/task_copy_publish_test.py"])
        self.assertFalse(result.get('capacity'))
        seen = []
        for file, measured in evidence.items():
            with self.subTest(file=file):
                current = units.collect(file)
                self.assertCountEqual(current['cases'], measured['case_seconds'])
                seen.extend(current['cases'])
                entry = fixtures[file]
                self.assertFalse(units.sliceable(entry))
                self.assertFalse(entry.get('self_hosted'))
                for case, seconds in measured['case_seconds'].items():
                    self.assertGreaterEqual(entry['case_costs'][case], math.ceil(seconds * 2.1) + 1)
                self.assertEqual(entry['cost'], sum(entry['case_costs'].values()))
                self.assertEqual(units.units_for(entry, budget), [(file, entry['cost'])])
                self.assertLessEqual(entry['cost'] + budget['slice_startup_seconds'], 90)
        self.assertEqual(len(seen), 72)
        self.assertEqual(len(set(seen)), 72)
        publish = fixtures['swamp/tests/task_copy_publish_test.py']
        current = units.collect(publish['file'])
        self.assertEqual(publish['cases'], current['cases'])
        self.assertEqual(len(current['cases']), 25)
        self.assertFalse(set(seen) & set(current['cases']))
        self.assertLessEqual(publish['cost'] + budget['slice_startup_seconds'], 90)
        harness = runpy.run_path(str(ROOT / 'swamp/tests/_support/task_instance_workspace.py'))
        for name in ('WorkspaceHarness', 'CheckHarness'):
            self.assertEqual(unittest.defaultTestLoader.getTestCaseNames(harness[name]), [])

    def test_registered_python_fixture_runs_without_a_gate_listing(self):
        file = 'swamp/tests/task_instance_workspace_export_test.py'
        # Model an author adding a fixture and its registry entry only.
        steps = units.fixture_steps()
        steps.pop(file)
        with patch.object(units, 'fixture_steps', return_value=steps):
            budget, fixtures, defaults = units.configuration()
            result = units.plan([file], budget, fixtures, defaults)
        self.assertEqual(names(result), [file])
        self.assertFalse(result.get('capacity'))
        step = units.unit_step(file, fixtures, steps, Path('/collections'))
        self.assertEqual(step.argv, ['python3', '-I', 'tests/task_instance_workspace_export_test.py'])
        self.assertEqual(step.env['ANSIBLE_COLLECTIONS_PATH'], '/collections')

    def test_each_copy_turn_case_has_its_own_bounded_unit_with_hosted_headroom(self):
        file = 'swamp/tests/ansible_task_copy_turn_test.ts'
        evidence = json.loads((ROOT / 'tools/tests/ci-task-copy-turn-costs.json').read_text())
        budget, fixtures, defaults = units.configuration()
        fixture = fixtures[file]
        self.assertEqual(set(fixture['cases']), set(evidence['completed_max_seconds']) | set(evidence['local_completed_seconds']) | set(evidence['estimates']))
        self.assertFalse(fixture.get('self_hosted'))
        self.assertEqual(fixture['cost'], sum(fixture['case_costs'].values()))
        for case, seconds in evidence['completed_max_seconds'].items():
            self.assertGreaterEqual(fixture['case_costs'][case], math.ceil(seconds * 1.25) + 1, case)
        for case, seconds in evidence['local_completed_seconds'].items():
            self.assertGreaterEqual(fixture['case_costs'][case], math.ceil(seconds * 1.25) + 1, case)
        for case, cost in evidence['estimates'].items():
            self.assertGreaterEqual(fixture['case_costs'][case], cost, case)
            self.assertGreater(cost, evidence['censored_seconds'][case])
        planned = units.plan([file], budget, fixtures, defaults)
        self.assertFalse(planned.get('capacity'))
        actual = []
        for unit, cost in units.units_for(fixture, budget):
            cases = units.case_names(unit, fixtures)
            self.assertEqual(len(cases), 1)
            self.assertLessEqual(cost, budget['shard_seconds'])
            self.assertIn(unit, names(planned))
            actual.extend(cases)
        self.assertCountEqual(actual, fixture['cases'])

    def test_host_acl_cases_fit_shared_inputs_without_capacity_debt(self):
        file = 'swamp/tests/ansible_instance_host_acl_test.ts'
        evidence = json.loads((ROOT / 'tools/tests/ci-host-acl-cost-evidence.json').read_text())
        budget, fixtures, defaults = units.configuration()
        fixture = fixtures[file]
        self.assertNotIn(file, budget['capacity_gap'])
        self.assertCountEqual(fixture['cases'], evidence['case_seconds'])
        for name, seconds in evidence['case_seconds'].items():
            self.assertEqual(fixture['case_costs'][name], math.ceil(seconds * evidence['estimate_factor']) + 1)
        self.assertEqual(fixture['cost'], sum(fixture['case_costs'].values()))
        expected = units.units_for(fixture, budget)
        self.assertTrue(all(cost <= budget['shard_seconds'] for _, cost in expected))
        self.assertCountEqual(fixture['cases'], [case for unit, _ in expected for case in units.case_names(unit, fixtures)])
        for path in (file, 'swamp/ansible/tasks/instance-host.yml', 'swamp/ansible/tasks/instance-network-check.yml',
                     'swamp/ansible/tasks/instance-container-files.yml', 'swamp/ansible/tasks/task-container-host-prepare.yml'):
            with self.subTest(path=path):
                planned = units.plan([path], budget, fixtures, defaults)
                self.assertFalse(planned.get('capacity'))
                actual = [unit for unit in units.planned_units(planned) if unit.partition('#')[0] == file]
                self.assertCountEqual(actual, [unit for unit, _ in expected])

    def test_scoped_host_cases_cost_their_largest_time_on_the_self_hosted_pool(self):
        evidence = json.loads((ROOT / 'tools/tests/ci-host-pool-cost-evidence.json').read_text())
        budget, fixtures, _ = units.configuration()
        self.assertCountEqual(evidence['files'], [f'swamp/tests/ansible_instance_{part}_test.ts'
                                                  for part in ('host_runtime', 'host_browser', 'host_updates', 't3_restart')])
        for file, measured in evidence['files'].items():
            with self.subTest(file=file):
                fixture = fixtures[file]
                self.assertTrue(fixture['self_hosted'])
                self.assertNotIn(file, budget['capacity_gap'])
                self.assertGreaterEqual(len(measured['runs']), 5)
                self.assertEqual(fixture['case_costs'], {name: math.ceil(seconds) for name, seconds in measured['case_seconds'].items()})
                self.assertEqual(fixture['cost'], sum(fixture['case_costs'].values()))

    def test_host_cold_build_stays_named_debt_while_the_complete_repeat_is_admitted(self):
        file = 'swamp/tests/ansible_instance_host_test.ts'
        evidence = json.loads((ROOT / 'tools/tests/ci-host-cost-evidence.json').read_text())
        budget, fixtures, defaults = units.configuration()
        fixture = fixtures[file]
        for name, seconds in evidence['case_seconds'].items():
            self.assertEqual(fixture['case_costs'][name], math.ceil(seconds * evidence['estimate_factor']) + 1)
        self.assertEqual(fixture['cost'], sum(fixture['case_costs'].values()))
        planned = units.plan([file], budget, fixtures, defaults)
        self.assertFalse(planned.get('capacity'))
        debt = [unit for row in planned.get('not_run', []) for unit in row['units'] if unit.partition('#')[0] == file]
        self.assertEqual([case for unit in debt for case in units.case_names(unit, fixtures)],
                         ['a build-list Node gets the Instance project, bridge, folders and image'])
        admitted = [unit for unit in units.planned_units(planned) if unit.partition('#')[0] == file]
        self.assertCountEqual([case for unit in admitted + debt for case in units.case_names(unit, fixtures)], fixture['cases'])

    @unittest.skipUnless(shutil.which('deno'), 'Live Deno registration is also checked by the fixture gate')
    def test_host_cost_populations_match_live_registration(self):
        _, fixtures, _ = units.configuration()
        for file in ('swamp/tests/ansible_instance_host_acl_test.ts', 'swamp/tests/ansible_instance_host_test.ts'):
            with self.subTest(file=file):
                self.assertEqual(fixtures[file]['cases'], units.collect(file)['cases'])

    def test_split_container_cases_keep_measured_costs_and_explicit_remaining_debt(self):
        file = 'swamp/tests/ansible_instance_container_test.ts'
        evidence = json.loads((ROOT / 'tools/tests/ci-container-split-costs.json').read_text())
        budget, fixtures, _ = units.configuration()
        fixture = fixtures[file]
        self.assertTrue(fixture['self_hosted'])
        self.assertEqual(len(fixture['cases']), evidence['registered_case_count'])
        self.assertEqual(fixture['slices'], len(fixture['cases']))
        self.assertEqual(fixture['cost'], sum(fixture['case_costs'].values()))
        for case, seconds in evidence['case_seconds'].items():
            with self.subTest(case=case):
                self.assertIn(case, fixture['cases'])
                self.assertEqual(fixture['case_costs'][case], math.ceil(seconds * 2.1) + 1)
        actual, oversized = [], []
        for unit, cost in units.units_for(fixture, budget):
            cases = units.case_names(unit, fixtures)
            self.assertEqual(len(cases), 1)
            actual.extend(cases)
            if cost > budget['shard_seconds']:
                oversized.extend(cases)
        self.assertCountEqual(actual, fixture['cases'])
        self.assertCountEqual(oversized, evidence['oversized_cases'])
        self.assertIn(file, budget['capacity_gap'])

    def test_renewal_costs_keep_all_cases_in_bounded_self_hosted_units(self):
        file = 'swamp/tests/swamp_worker_renewal_test.ts'
        evidence = json.loads((ROOT / 'tools/tests/ci-worker-renewal-costs.json').read_text())
        config, inventory = units.registry.load(ROOT)
        fixture = next(f for f in config['fixture'] if f['file'] == file)
        self.assertTrue(fixture['self_hosted'])
        self.assertEqual(set(inventory[file]['cases']), set(evidence['completed_max_seconds']) | set(evidence['estimates']))
        self.assertEqual(fixture['cost'], sum(fixture['case_costs'].values()))
        for name, seconds in evidence['completed_max_seconds'].items():
            self.assertGreaterEqual(fixture['case_costs'][name], math.ceil(seconds * 1.25) + 1)
        for name, cost in evidence['estimates'].items():
            self.assertGreaterEqual(fixture['case_costs'][name], cost)
        budget, fixtures, _ = units.configuration()
        planned = units.units_for(fixtures[file], budget)
        self.assertGreater(len(planned), 1)
        self.assertCountEqual(inventory[file]['cases'], [case for unit, _ in planned for case in units.case_names(unit, fixtures)])
        self.assertTrue(all(cost <= budget['shard_seconds'] for _, cost in planned))


if __name__ == '__main__':
    unittest.main()
