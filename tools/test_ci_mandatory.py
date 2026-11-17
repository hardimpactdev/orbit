"""Required boundary proofs survive fixture pressure or fail the Required gate."""
import copy
import json
import unittest
from unittest.mock import patch

from test_ci_delivery import delivery, needs_for
from test_ci_units import BUDGET, ROOT, fixture, names, units

HOST = 'swamp/ansible/tasks/instance-host.yml'
IMAGE = 'swamp/tests/ansible_instance_host_image_test.ts'
BOUNDARIES = [
    'the saved dir host prepare boundary matches the shipped tasks',
    'the saved zfs host prepare boundary matches the shipped tasks',
    'the saved nested host prepare boundary matches the shipped tasks',
    'a passed nested-fleet image build repeats the host prepare stage without changes',
    'a prepared-image replacement rechecks the local socket despite the default remote',
]


class MandatoryAdmissionTest(unittest.TestCase):
    def setUp(self):
        self.budget = {**BUDGET, 'pr_shards': 1, 'shard_workers': 1, 'shard_seconds': 100}
        self.image = fixture('swamp/image.ts', 60, ['swamp/host.yml', 'swamp/other.yml'],
                             cases=['prepare', 'normal', 'nested'], slices=3,
                             mandatory_cases={'swamp/host.yml': ['prepare']})
        self.ordinary = fixture('swamp/ordinary.py', 70, ['swamp/host.yml', 'swamp/other.yml'])
        self.fixtures = {f['file']: f for f in (self.ordinary, self.image)}

    def plan(self, paths=('swamp/host.yml',), **options):
        return units.plan(paths, self.budget, self.fixtures, [], **options)

    def assert_partition(self, result):
        expected = [name for f in self.fixtures.values() for name, _ in units.units_for(f, self.budget)]
        self.assertCountEqual(names(result) + units.unplaced_units(result), expected)

    def test_required_unit_is_reserved_before_ordinary_work_and_the_unplaced_remainder_fails(self):
        result = self.plan()
        self.assertCountEqual(names(result), ['swamp/ordinary.py', 'swamp/image.ts#0/3'])
        self.assertEqual(result['capacity'], [{'unplaced': ['swamp/image.ts#1/3', 'swamp/image.ts#2/3']}])
        self.assert_partition(result)

    def test_unrelated_input_keeps_original_whole_file_admission(self):
        result = self.plan(['swamp/other.yml'])
        self.assertEqual(names(result), ['swamp/ordinary.py'])
        self.assertNotIn('mandatory', result)
        self.assert_partition(result)

    def test_source_inventory_registry_and_shared_machinery_reserve_cases(self):
        scenarios = [([self.image['file']], {}), ([], {'inventory_changed': [self.image['file']]}),
                     ([], {'registry_changed': [self.image['file']]})]
        # The machinery selects its canary set; a canary file's required cases are reserved.
        self.budget['machinery_canary'] = [self.image['file']]
        scenarios += [([], {'shared_registry': True})] + [([path], {}) for path in units.MACHINERY]
        for paths, options in scenarios:
            with self.subTest(paths=paths, options=options):
                result = self.plan(paths, **options)
                self.assertEqual(result['mandatory'][0]['cases'], ['prepare'])
                self.assertIn('swamp/image.ts#0/3', names(result))

    def test_overlapping_rules_reserve_the_whole_containing_unit_once(self):
        self.image.update(slices=2, cases=['prepare', 'normal', 'nested', 'finish'])
        self.image['mandatory_cases'] = {'swamp/host.yml': ['prepare', 'nested'],
                                         'swamp/other.yml': ['prepare']}
        result = self.plan(['swamp/host.yml', 'swamp/other.yml'])
        self.assertEqual(result['mandatory'][0]['units'], ['swamp/image.ts#0/2'])
        self.assertEqual(units.case_names('swamp/image.ts#0/2', self.fixtures), ['prepare', 'nested'])
        self.assert_partition(result)

    def test_joint_mandatory_capacity_failure_names_cases_and_fails_required(self):
        self.image['mandatory_cases']['swamp/host.yml'] = self.image['cases']
        self.ordinary.update(cases=['host proof'], mandatory_cases={'swamp/host.yml': ['host proof']})
        result = self.plan()
        self.assertEqual(result['capacity'][0]['mandatory'], result['mandatory'])
        summary = units.plan_summary(result, self.fixtures)
        for case in ['host proof', *self.image['cases']]:
            self.assertIn(case, summary)
        self.assertIn('Required checks will fail', summary)
        for gate in ('required', 'delivery'):
            needs = needs_for(gate)
            needs['changes']['outputs']['fixture_capacity'] = json.dumps(result['capacity'])
            with self.subTest(gate=gate), self.assertRaisesRegex(SystemExit, 'Required fixture capacity exceeded'):
                delivery.validate(needs)
        self.assert_partition(result)

    def test_oversized_required_unit_still_fails_capacity(self):
        self.image.update(cost=120, slices=False)
        result = self.plan()
        self.assertTrue(result['capacity'][0]['mandatory'])
        self.assertEqual(result['capacity'][1]['oversize'][0]['unit'], self.image['file'])
        self.assert_partition(result)

    def test_required_subset_does_not_hide_oversized_full_fixture(self):
        self.image.update(cost=150, slices=3)
        result = self.plan()
        self.assertIn('swamp/image.ts#0/3', names(result))
        self.assertEqual(result['capacity'][0]['shards'], 3)
        self.assert_partition(result)


class MandatoryRegistryTest(unittest.TestCase):
    def test_invalid_or_excluded_required_names_cannot_silently_drop_coverage(self):
        original, inventory = units.registry.load(ROOT)
        invalid = [([], {}), ({HOST: []}, {}), ({HOST: 'case'}, {}), ({HOST: [42]}, {}),
                   ({HOST: ['renamed case']}, {}), ({HOST: [BOUNDARIES[0]] * 2}, {}),
                   ({'undeclared/input': [BOUNDARIES[0]]}, {}),
                   ({'undeclared/input': [BOUNDARIES[0]]}, {'self_hosted': True})]
        for mapping, extra in invalid:
            config = copy.deepcopy(original)
            entry = next(f for f in config['fixture'] if f['file'] == IMAGE)
            entry.update(mandatory_cases=mapping, **extra)
            with self.subTest(mapping=mapping, extra=extra), \
                    patch.object(units.registry, 'load', return_value=(config, inventory)), \
                    self.assertRaisesRegex(ValueError, 'mandatory_cases'):
                units.configuration()

    def test_parsed_yaml_opt_in_requires_exact_declared_nonmandatory_paths(self):
        original, inventory = units.registry.load(ROOT)
        valid = 'swamp/ansible/tasks/instance-fleet.yml'
        fleet = 'swamp/tests/ansible_instance_fleet_test.ts'
        settings = [(fleet, value) for value in
                    (valid, [42], [valid, valid], ['swamp/ansible/tasks/instance-fleet*.yml'],
                     ['swamp/ansible/instance-container.yml'], ['swamp/ansible/fleet-converge-inputs.json'])]
        settings.append((IMAGE, [HOST]))
        for file, value in settings:
            config = copy.deepcopy(original)
            entry = next(f for f in config['fixture'] if f['file'] == file)
            entry['parsed_yaml_inputs'] = value
            with self.subTest(file=file, value=value), \
                    patch.object(units.registry, 'load', return_value=(config, inventory)), \
                    self.assertRaisesRegex(ValueError, 'parsed_yaml_inputs'):
                units.configuration()

    def test_host_change_and_full_pressure_always_run_the_five_boundary_proofs(self):
        budget, fixtures, defaults = units.configuration()
        self.assertEqual(fixtures[IMAGE]['mandatory_cases'], {HOST: BOUNDARIES})
        # Saturate all lanes with earlier direct matches. Reservation must win
        # independently of metadata order and specificity of ordinary fixtures.
        pressure = {f'swamp/pressure{i}.py': fixture(f'swamp/pressure{i}.py', budget['shard_seconds'], [HOST])
                    for i in range(budget['pr_shards'] * budget['shard_workers'])}
        for paths, entries in [([HOST], fixtures), ([HOST], pressure | fixtures)]:
            with self.subTest(paths=paths, pressure=len(entries) != len(fixtures)):
                result = units.plan(paths, budget, entries, defaults)
                # Pressure beyond the shards is named and fails Required; the reserved proofs still run.
                self.assertEqual([row for row in result.get('capacity', []) if 'unplaced' not in row], [])
                self.assertEqual(result['mandatory'][0]['cases'], BOUNDARIES)
                reserved = result['mandatory'][0]['units']
                self.assertEqual(len(reserved), 5)
                self.assertTrue(set(reserved) <= set(names(result)))
                self.assertFalse(set(reserved) & set(units.unplaced_units(result)))
                all_units = names(result) + units.unplaced_units(result)
                self.assertEqual(len(all_units), len(set(all_units)))
                self.assertCountEqual([case for unit in reserved for case in units.case_names(unit, fixtures)], BOUNDARIES)
