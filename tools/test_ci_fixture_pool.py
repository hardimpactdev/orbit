"""Fixture matrices share twenty planned shards without admitting fork code or losing units."""
from pathlib import Path
import heapq
import json
import re
import sys
import tempfile
import tomllib
import unittest

import yaml

sys.path.insert(0, str(Path(__file__).parent))
from test_ci_units import BUDGET, ROOT, fixture, planned, units

ROOT = Path(__file__).resolve().parents[1]


class FixturePoolTest(unittest.TestCase):
    def test_two_classes_share_one_bound_and_overflow_names_every_unrun_unit(self):
        budget = {**BUDGET, 'runner_shards': 2, 'shard_workers': 1, 'self_hosted_workers': 1}
        files = [fixture('swamp/ordinary.py', 100, ['swamp/input']),
                 fixture('swamp/native.py', 100, ['swamp/input'], self_hosted=True),
                 fixture('swamp/overflow.py', 100, ['swamp/input'])]
        plan = planned(['swamp/input'], files, budget=budget)
        self.assertEqual(2, len(plan['shards']) + len(plan['self_hosted_shards']))
        self.assertEqual(['swamp/overflow.py'], units.unplaced_units(plan))
        self.assertEqual({'swamp/ordinary.py', 'swamp/native.py'}, set(units.planned_units(plan)))
        self.assertIn('Required checks will fail', units.plan_summary(plan, {f['file']: f for f in files}))

    def test_mandatory_cases_reserve_shared_capacity_before_other_files(self):
        budget = {**BUDGET, 'runner_shards': 1, 'shard_workers': 1, 'self_hosted_workers': 1}
        files = [fixture('swamp/ordinary.py', 100, ['swamp/input']),
                 fixture('swamp/mandatory.ts', 100, ['swamp/input'], self_hosted=True,
                         cases=['required'], mandatory_cases={'swamp/input': ['required']})]
        plan = planned(['swamp/input'], files, budget=budget)
        self.assertEqual(['swamp/mandatory.ts'], units.planned_units(plan))
        self.assertEqual(['swamp/ordinary.py'], units.unplaced_units(plan))
        # Two mandatory classes cannot separately claim the same one free place.
        files[0].update(cases=['required'], mandatory_cases={'swamp/input': ['required']})
        plan = planned(['swamp/input'], files, budget=budget)
        self.assertTrue(plan['capacity'][0]['mandatory'])
        self.assertLessEqual(len(plan['shards']) + len(plan['self_hosted_shards']), 1)

    def test_named_gap_only_uses_remaining_shared_places(self):
        budget = {**BUDGET, 'runner_shards': 2, 'shard_workers': 1, 'self_hosted_workers': 1,
                  'capacity_gap': ['swamp/gap.py']}
        files = [fixture('swamp/gap.py', 100, ['swamp/input'], self_hosted=True),
                 fixture('swamp/a.py', 100, ['swamp/input']), fixture('swamp/b.py', 100, ['swamp/input'])]
        plan = planned(['swamp/input'], files, budget=budget)
        self.assertNotIn('capacity', plan)
        self.assertEqual(['swamp/gap.py'], plan['not_run'][0]['units'])
        self.assertEqual({'swamp/a.py', 'swamp/b.py'}, set(units.planned_units(plan)))

    def test_both_nodes_use_shared_labels_and_no_job_installs_hosted_tools(self):
        workflow = yaml.safe_load((ROOT / '.github/workflows/ci.yml').read_text())
        job = workflow['jobs']['fixtures']
        # Fixed labels: no event payload chooses a runner, and no pull request starts a shard.
        self.assertEqual(['self-hosted', 'hardimpact', 'orbit-shard'], job['runs-on'])
        outputs = workflow['jobs']['changes']['outputs']
        for name in ('fixture_shards', 'self_hosted_shards'):
            self.assertIn("github.event_name == 'pull_request') && '[]'", outputs[name])
        for step in job['steps']:
            self.assertNotIn('runner.environment', step.get('if', ''))
            self.assertNotRegex(step.get('uses', ''), r'^(denoland/setup-deno|actions/setup-python|actions/cache)@')
        self.assertEqual(4, job['timeout-minutes'])
        self.assertNotIn('max-parallel', job['strategy'])
        self.assertTrue(any(step.get('run', '').startswith('bin/ci-units run ') for step in job['steps']))
        self.assertEqual(['self-hosted', 'hardimpact', 'orbit-shard'], workflow['jobs']['self-hosted-fixtures']['runs-on'])
        budget, _, _ = units.configuration()
        # Observed shared runners are a reporting denominator, not a per-run job limit.
        table = tomllib.loads((ROOT / '.github/ci/checks.toml').read_text())
        self.assertEqual(10, table['runner-pool']['slots'])
        self.assertEqual((20, 20, 10), (budget['runner_shards'], budget['pr_shards'], budget['self_hosted_shards']))
        self.assertEqual(16, budget['spread_shards'])
        self.assertEqual((150, 180, 210, 240), tuple(budget[key] for key in
                         ('shard_seconds', 'unit_ceiling_seconds', 'shard_deadline_seconds', 'shard_ceiling_seconds')))
        self.assertEqual((2, 2), (budget['shard_workers'], budget['self_hosted_workers']))
        # The deadline counts from the job's first step. Without that step it would count from the units' start
        # and could pass the job's own limit.
        for name in ('fixtures', 'self-hosted-fixtures'):
            self.assertEqual('echo "ORBIT_JOB_STARTED_AT=$(date +%s)" >> "$GITHUB_ENV"', workflow['jobs'][name]['steps'][0]['run'])
            self.assertEqual(4, workflow['jobs'][name]['timeout-minutes'])
        self.assertEqual(1.15, table['runner-pool']['slowest_runner_factor'])
        self.assertEqual(130, budget['lane_seconds'])

    def test_a_lane_that_holds_several_units_is_packed_for_the_slowest_runner(self):
        budget = {**BUDGET, 'shard_workers': 2, 'lane_seconds': 130}
        # Two 70-second units fit one 150-second lane, but not the 130-second lane of the slowest runner.
        rows = [(f'unit{i}', 70, 0) for i in range(4)]
        self.assertEqual(2, len(units.pack(rows, budget)))
        self.assertEqual(1, len(units.pack(rows, {**budget, 'lane_seconds': 150})))
        self.assertEqual(1, len(units.pack(rows, BUDGET | {'shard_workers': 2})))
        # One unit alone still fills shard_seconds, and nothing joins it.
        shards = units.pack([('heavy-a', 150, 0), ('heavy-b', 140, 0), ('small', 5, 0)], budget)
        self.assertEqual([['heavy-a', 'heavy-b'], ['small']], [shard['units'] for shard in shards])
        with self.assertRaisesRegex(ValueError, 'exceeds shard_seconds'):
            units.pack([('oversize', 151, 0)], budget)

    def test_real_plans_keep_every_shared_lane_within_the_slowest_runner_lane(self):
        budget, fixtures, defaults = units.configuration()
        costs = {name: cost for fixture in fixtures.values() for name, cost in units.units_for(fixture, budget)}
        shared = 0
        for path in ('.github/workflows/ci.yml', 'swamp/ansible/tasks/instance-host.yml',
                     'swamp/ansible/tasks/instance-fleet.yml', 'swamp/ansible/tasks/instance-container-phase.yml'):
            plan = units.plan([path], budget, fixtures, defaults)
            for shard in plan['shards'] + plan['self_hosted_shards']:
                # The runner's two workers take the units in the planned order.
                lanes = [[], []]
                for name in shard['units']:
                    min(lanes, key=lambda lane: sum(costs[unit] for unit in lane)).append(name)
                for lane in lanes:
                    limit = budget['lane_seconds'] if len(lane) > 1 else budget['shard_seconds']
                    self.assertLessEqual(sum(costs[unit] for unit in lane), limit, (path, shard['name'], lane))
                    shared += len(lane) > 1
        self.assertGreater(shared, 20)

    def test_the_slowest_runner_factor_is_bounded(self):
        source = (ROOT / '.github/ci/checks.toml').read_text()
        self.assertEqual(1, source.count('slowest_runner_factor = 1.15'))
        with tempfile.TemporaryDirectory() as scratch:
            path = Path(scratch) / 'checks.toml'
            for value in ('0.9', '2', '"1.15"', 'true'):
                with self.subTest(value=value):
                    path.write_text(source.replace('slowest_runner_factor = 1.15', f'slowest_runner_factor = {value}'))
                    with self.assertRaisesRegex(ValueError, 'slowest_runner_factor'):
                        units.configuration(path)
            # Without the key a lane is shard_seconds, as before.
            path.write_text(source.replace('slowest_runner_factor = 1.15', ''))
            self.assertEqual(150, units.configuration(path)[0]['lane_seconds'])

    def test_shared_policy_runs_the_whole_fleet_in_two_lane_shards(self):
        budget, fixtures, defaults = units.configuration()
        file = 'swamp/tests/ansible_instance_fleet_test.ts'
        plan = units.plan(['.github/workflows/ci.yml'], budget, fixtures, defaults)
        self.assertNotIn('capacity', plan)
        selected = units.planned_units(plan)
        expected = [name for name, _ in units.units_for(fixtures[file], budget)]
        self.assertEqual(90, len(expected))
        self.assertEqual(set(expected), {name for name in selected if name.split('#')[0] == file})
        self.assertEqual(17, len(plan['shards']) + len(plan['self_hosted_shards']))
        costs = {name: cost for fixture in fixtures.values() for name, cost in units.units_for(fixture, budget)}
        for shard in plan['shards'] + plan['self_hosted_shards']:
            self.assertEqual(2, units.shard_workers(shard['units'], budget, fixtures))
            lanes = [0, 0]
            for name in shard['units']:
                heapq.heappush(lanes, heapq.heappop(lanes) + costs[name])
            self.assertLessEqual(max(lanes), budget['shard_seconds'])


    def test_host_input_places_all_updates_units_without_capacity_failure(self):
        budget, fixtures, defaults = units.configuration()
        plan = units.plan(['swamp/ansible/tasks/instance-host.yml'], budget, fixtures, defaults)
        file = 'swamp/tests/ansible_instance_host_updates_test.ts'
        self.assertEqual(4, len(list(units.units_for(fixtures[file], budget))))
        self.assert_every_unit_placed(plan, file, budget, fixtures)
        self.assertNotIn('capacity', plan)
        self.assertEqual([], units.unplaced_units(plan))
        self.assertLessEqual(len(plan['shards']) + len(plan['self_hosted_shards']), budget['runner_shards'])


    def test_the_fleet_node_part_of_the_host_role_selects_no_fleet_step_unit(self):
        # No case of the fleet step runs instance-fleet-node.yml: the host role includes it on an Instance fleet Node.
        budget, fixtures, defaults = units.configuration()
        fleet = 'swamp/tests/ansible_instance_fleet_test.ts'
        plan = units.plan(['swamp/ansible/tasks/instance-fleet-node.yml'], budget, fixtures, defaults)
        self.assertNotIn(fleet, {name.split('#')[0] for name in units.planned_units(plan) + units.unplaced_units(plan)})
        self.assertNotIn('capacity', plan)
        # Each task file that a phase of the fixture copies still selects every unit of it.
        source = (ROOT / fleet).read_text()
        phases = json.loads(re.search(r'^const phases = (\[.*\]);$', source, re.M).group(1))
        self.assertEqual(18, len(phases))
        for part in ('-' + phase if phase else '' for phase in phases):
            with self.subTest(part=part):
                plan = units.plan([f'swamp/ansible/tasks/instance-fleet{part}.yml'], budget, fixtures, defaults)
                self.assertEqual(90, sum(name.split('#')[0] == fleet for name in units.planned_units(plan)))

    def test_a_host_role_change_with_the_fleet_node_part_and_the_container_fixture_fits_the_shards(self):
        # The changed paths of the btrfs pool change for the nested app-dev Node (17 files, 18 November 2026).
        budget, fixtures, defaults = units.configuration()
        paths = ['swamp/ansible/tasks/instance-fleet-node.yml', 'swamp/ansible/tasks/instance-host.yml',
                 'swamp/scripts/instance_clone.py', 'swamp/tests/_support/regenerate_host_image_boundaries.ts',
                 'swamp/tests/ansible_instance_container_test.ts', 'swamp/tests/ansible_instance_host_acl_test.ts',
                 'swamp/tests/ansible_instance_host_image_test.ts', 'swamp/tests/fixtures/host-image-boundaries.json',
                 'swamp/tests/fixtures/instance-container-incus.ts', 'swamp/tests/instance_clone_test.py']
        plan = units.plan(paths, budget, fixtures, defaults)
        self.assertNotIn('capacity', plan)
        self.assertEqual([], units.unplaced_units(plan))
        planned = {name.split('#')[0] for name in units.planned_units(plan)}
        self.assertNotIn('swamp/tests/ansible_instance_fleet_test.ts', planned)
        for part in ('host_updates', 'host_runtime', 'host_browser', 'host_acl', 't3_restart'):
            self.assert_every_unit_placed(plan, f'swamp/tests/ansible_instance_{part}_test.ts', budget, fixtures)
        self.assertEqual({row['file'] for row in plan.get('not_run', [])},
                         {'swamp/tests/ansible_instance_container_test.ts', 'swamp/tests/ansible_instance_host_test.ts'})

    def assert_every_unit_placed(self, plan, file, budget, fixtures):
        expected = {name for name, _ in units.units_for(fixtures[file], budget)}
        self.assertEqual(expected, {name for name in units.planned_units(plan) if name.split('#')[0] == file})


    def test_spare_places_shorten_lanes_without_changing_admitted_units(self):
        budget = {**BUDGET, 'runner_shards': 3, 'pr_shards': 3, 'shard_workers': 2}
        rows = [(f'unit{i}', 50, 0) for i in range(12)]
        self.assertEqual(2, len(units.pack(rows, budget)))
        result = units.spread({False: rows, True: []}, budget)
        self.assertEqual(3, len(result[False]))
        self.assertEqual(sorted(name for name, _, _ in rows),
                         sorted(name for shard in result[False] for name in shard['units']))
        self.assertTrue(all(len(shard['units']) == 4 for shard in result[False]))
        self.assertEqual([], result[True])


    def test_spread_target_limits_extra_jobs_but_never_rejects_admitted_units(self):
        budget = {**BUDGET, 'runner_shards': 4, 'spread_shards': 2, 'pr_shards': 4, 'shard_workers': 2}
        for count, seconds, expected_jobs in ((12, 50, 2), (8, 100, 4)):
            with self.subTest(count=count, seconds=seconds):
                rows = [(f'unit{i}', seconds, 0) for i in range(count)]
                result = units.spread({False: rows, True: []}, budget)
                self.assertEqual(expected_jobs, len(result[False]))
                self.assertEqual(sorted(name for name, _, _ in rows),
                                 sorted(name for shard in result[False] for name in shard['units']))
                self.assertEqual([], result[True])


if __name__ == '__main__':
    unittest.main()
