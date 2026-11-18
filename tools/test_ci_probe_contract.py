"""The host-profile caller proof runs before merge even when other fixtures fill the plan."""
import json
import unittest

from test_ci_delivery import delivery, needs_for
from test_ci_units import ROOT, fixture, names, units

FILE = 'swamp/tests/instance_host_probe_contract_test.py'
CASE = 'test_the_shipped_host_profile_passes_the_probe_guard_before_start'
INPUTS = ('swamp/ansible/instance_nested.py', 'swamp/ansible/tasks/instance-network-nic-check.yml',
          'swamp/ansible/tasks/instance-host.yml', 'swamp/ansible/tasks/task-container-host-prepare.yml')


class HostProbeAdmissionTest(unittest.TestCase):
    def test_each_caller_and_guard_change_reserves_the_live_contract_under_pressure(self):
        budget, fixtures, defaults = units.configuration()
        contract = fixtures[FILE]
        self.assertEqual(units.collect(FILE)['cases'], contract['cases'])
        self.assertEqual(contract['cases'], [CASE])
        self.assertEqual(contract['mandatory_cases'], {path: [CASE] for path in INPUTS})
        self.assertLessEqual(contract['cost'], 15)
        self.assertFalse(contract['slices'])
        slots = budget['pr_shards'] * budget['shard_workers'] * (budget['shard_seconds'] // 15)
        for path in INPUTS:
            with self.subTest(path=path):
                actual = units.plan([path], budget, fixtures, defaults)
                self.assertIn(FILE, names(actual))
                self.assertFalse(actual.get('capacity'))
                # Earlier ordinary work exactly fills every lane without reservation.
                pressure = {f'swamp/pressure{i}.py': fixture(f'swamp/pressure{i}.py', 15, [path]) for i in range(slots)}
                result = units.plan([path], budget, pressure | {FILE: contract}, [])
                self.assertIn(FILE, names(result))
                self.assertNotIn(FILE, units.unplaced_units(result))
                self.assertEqual(result['mandatory'], [{'file': FILE, 'cases': [CASE], 'units': [FILE]}])

    def test_unfittable_contract_fails_required_instead_of_deferring_silently(self):
        budget, fixtures, _ = units.configuration()
        budget = {**budget, 'pr_shards': 1, 'shard_workers': 1, 'shard_seconds': fixtures[FILE]['cost'] - 1}
        result = units.plan([INPUTS[0]], budget, {FILE: fixtures[FILE]}, [])
        self.assertTrue(result['capacity'][0]['mandatory'])
        needs = needs_for()
        needs['changes']['outputs']['fixture_capacity'] = json.dumps(result['capacity'])
        with self.assertRaisesRegex(SystemExit, 'Required fixture capacity exceeded'):
            delivery.validate(needs)


if __name__ == '__main__':
    unittest.main()
