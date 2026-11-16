"""Hosted copy-turn work must fit its declared PR slices and stay complete after merge."""
import json
import math
import unittest
from unittest.mock import patch

from test_ci_full_set import full
from test_ci_units import ROOT, names, units

FILE = 'swamp/tests/ansible_task_copy_turn_test.ts'
EVIDENCE = ROOT / 'tools/tests/ci-task-copy-turn-costs.json'


class TaskCopyTurnCostTest(unittest.TestCase):
    def test_each_case_has_its_own_bounded_unit_with_hosted_headroom(self):
        evidence = json.loads(EVIDENCE.read_text())
        budget, fixtures, defaults = units.configuration()
        fixture = fixtures[FILE]
        self.assertEqual(set(fixture['cases']), set(evidence['completed_max_seconds']) | set(evidence['estimates']))
        self.assertFalse(fixture.get('self_hosted'))
        self.assertEqual(fixture['cost'], sum(fixture['case_costs'].values()))
        for case, seconds in evidence['completed_max_seconds'].items():
            self.assertGreaterEqual(fixture['case_costs'][case], math.ceil(seconds * 1.25) + 1, case)
        for case, cost in evidence['estimates'].items():
            self.assertGreaterEqual(fixture['case_costs'][case], cost, case)
            self.assertGreater(cost, evidence['censored_seconds'][case])
        planned = units.plan([FILE], budget, fixtures, defaults)
        self.assertFalse(planned.get('capacity'))
        actual = []
        for unit, cost in units.units_for(fixture, budget):
            cases = units.case_names(unit, fixtures)
            self.assertEqual(len(cases), 1)
            self.assertLessEqual(cost, budget['shard_seconds'])
            self.assertIn(unit, names(planned))
            actual.extend(cases)
        self.assertCountEqual(actual, fixture['cases'])

    def test_full_set_keeps_all_cases_and_does_not_run_the_540_second_whole_file(self):
        config, inventory = units.registry.load(ROOT)
        config['fixture'] = [entry for entry in config['fixture'] if entry['file'] == FILE]
        with patch.object(units, 'fixture_steps', return_value={}):
            plan = full.build_plan(config, inventory, lambda file: inventory[file], units)
        self.assertGreater(len(plan['units']), 1)
        self.assertCountEqual([case for unit in plan['units'] for case in unit['cases']], inventory[FILE]['cases'])
        self.assertTrue(all(unit['declared_seconds'] <= 300 for unit in plan['units']))


if __name__ == '__main__':
    unittest.main()
