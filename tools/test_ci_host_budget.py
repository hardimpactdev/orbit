"""The fleet seed caller cannot disappear into its file's capacity gap."""
import unittest

from test_ci_units import fixture, units

FILE = 'swamp/tests/ansible_instance_container_test.ts'
SEED = 'a nested-fleet default retakes its seed at the same commit and refuses a Club key on its disk'
INPUTS = ('swamp/ansible/tasks/instance-fleet.yml', 'swamp/ansible/tasks/instance-container-phase.yml')


class FleetSeedAdmissionTest(unittest.TestCase):
    def test_seed_and_failure_contracts_reserve_capacity_before_gap_units(self):
        budget, fixtures, _ = units.configuration()
        contract = fixtures[FILE]
        for path in INPUTS:
            pressure = {f'swamp/pressure{i}.ts': fixture(f'swamp/pressure{i}.ts', 140, [path], self_hosted=True,
                                                       cases=['slow'], slices=False)
                        for i in range(20)}
            result = units.plan([path], budget, pressure | {FILE: contract}, [])
            required = result['mandatory'][0]
            self.assertIn(SEED, required['cases'])
            self.assertEqual(4, len(required['cases']))
            self.assertTrue(set(required['units']) <= set(units.planned_units(result)))
            self.assertTrue(result.get('capacity'), 'nonmandatory pressure must fail instead of evicting the seed')

    def test_a_seed_contract_that_cannot_fit_is_a_required_capacity_failure(self):
        budget, fixtures, _ = units.configuration()
        result = units.plan([INPUTS[0]], {**budget, 'shard_seconds': 10}, {FILE: fixtures[FILE]}, [])
        self.assertIn(SEED, result['capacity'][0]['mandatory'][0]['cases'])


if __name__ == '__main__':
    unittest.main()
