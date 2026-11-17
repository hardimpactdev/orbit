"""Fixture costs retain measured headroom, complete populations and bounded units."""
import json
import math
import runpy
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
        self.assertEqual(len(seen), 68)
        self.assertEqual(len(set(seen)), 68)
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
