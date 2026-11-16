"""Every renewal case gets a bounded unit, including cases censored by the old whole-file run."""
import json
import math
import unittest
from test_ci_units import ROOT, units

FILE = 'swamp/tests/swamp_worker_renewal_test.ts'


class WorkerRenewalCostTest(unittest.TestCase):
    def test_hosted_costs_keep_all_cases_in_bounded_self_hosted_units(self):
        evidence = json.loads((ROOT / 'tools/tests/ci-worker-renewal-costs.json').read_text())
        config, inventory = units.registry.load(ROOT)
        fixture = next(f for f in config['fixture'] if f['file'] == FILE)
        self.assertTrue(fixture['self_hosted'])
        self.assertEqual(set(inventory[FILE]['cases']), set(evidence['completed_max_seconds']) | set(evidence['estimates']))
        self.assertEqual(fixture['cost'], sum(fixture['case_costs'].values()))
        for name, seconds in evidence['completed_max_seconds'].items():
            self.assertGreaterEqual(fixture['case_costs'][name], math.ceil(seconds * 1.25) + 1)
        for name, cost in evidence['estimates'].items():
            self.assertGreaterEqual(fixture['case_costs'][name], cost)
        budget, fixtures, _ = units.configuration()
        planned = units.units_for(fixtures[FILE], budget)
        self.assertGreater(len(planned), 1)
        self.assertCountEqual(inventory[FILE]['cases'], [case for unit, _ in planned for case in units.case_names(unit, fixtures)])
        self.assertTrue(all(cost <= budget['shard_seconds'] for _, cost in planned))

if __name__ == '__main__':
    unittest.main()
