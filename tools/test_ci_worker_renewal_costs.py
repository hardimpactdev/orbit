"""Every renewal case gets a bounded unit, including cases censored by the old whole-file run."""
import json
import math
import unittest
from unittest.mock import patch
from test_ci_full_set import full
from test_ci_units import ROOT, units

FILE = 'swamp/tests/swamp_worker_renewal_test.ts'


class WorkerRenewalCostTest(unittest.TestCase):
    def test_hosted_costs_keep_all_cases_in_bounded_after_merge_units(self):
        evidence = json.loads((ROOT / 'tools/tests/ci-worker-renewal-costs.json').read_text())
        config, inventory = units.registry.load(ROOT)
        fixture = next(f for f in config['fixture'] if f['file'] == FILE)
        self.assertTrue(fixture['after_merge'])
        self.assertEqual(set(inventory[FILE]['cases']), set(evidence['completed_max_seconds']) | set(evidence['estimates']))
        self.assertEqual(fixture['cost'], sum(fixture['case_costs'].values()))
        for name, seconds in evidence['completed_max_seconds'].items():
            self.assertGreaterEqual(fixture['case_costs'][name], math.ceil(seconds * 1.25) + 1)
        for name, cost in evidence['estimates'].items():
            self.assertGreaterEqual(fixture['case_costs'][name], cost)
        config['fixture'] = [fixture]
        with patch.object(units, 'fixture_steps', return_value={}):
            plan = full.build_plan(config, inventory, lambda file: inventory[file], units)
        self.assertGreater(len(plan['units']), 1)
        self.assertCountEqual(inventory[FILE]['cases'], [case for unit in plan['units'] for case in unit['cases']])
        self.assertTrue(all(unit['declared_seconds'] <= 300 for unit in plan['units']))


if __name__ == '__main__':
    unittest.main()
