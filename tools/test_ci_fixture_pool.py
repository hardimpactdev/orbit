"""Fixture matrices share eighteen planned shards without admitting fork code or losing units."""
from pathlib import Path
import heapq
import sys
import unittest

import yaml

sys.path.insert(0, str(Path(__file__).parent))
from test_ci_units import BUDGET, fixture, planned, units

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

    def test_both_nodes_use_shared_labels_and_forks_use_hosted(self):
        workflow = yaml.safe_load((ROOT / '.github/workflows/ci.yml').read_text())
        job = workflow['jobs']['fixtures']
        # Pin the whole condition: a fork cannot choose labels from its own payload.
        self.assertEqual("${{ github.event_name == 'pull_request' && github.event.pull_request.head.repo.full_name != github.repository && 'ubuntu-26.04' || fromJSON('[\"self-hosted\",\"hardimpact\",\"orbit-shard\"]') }}", job['runs-on'])
        for step in job['steps']:
            if step.get('id') == 'python' or step.get('name') == 'Cache pinned fixture tools and collections' or step.get('uses', '').startswith('denoland/setup-deno@'):
                self.assertEqual("runner.environment != 'self-hosted'", step['if'])
        self.assertEqual(4, job['timeout-minutes'])
        self.assertNotIn('max-parallel', job['strategy'])
        self.assertTrue(any(step.get('run', '').startswith('bin/ci-units run ') for step in job['steps']))
        self.assertEqual(['self-hosted', 'hardimpact', 'orbit-shard'], workflow['jobs']['self-hosted-fixtures']['runs-on'])
        budget, _, _ = units.configuration()
        self.assertEqual(18, budget['runner_shards'])
        self.assertEqual((150, 180, 190, 240), tuple(budget[key] for key in
                         ('shard_seconds', 'unit_ceiling_seconds', 'shard_deadline_seconds', 'shard_ceiling_seconds')))
        self.assertEqual((2, 2), (budget['shard_workers'], budget['self_hosted_workers']))

    def test_shared_policy_runs_the_whole_fleet_in_two_lane_shards(self):
        budget, fixtures, defaults = units.configuration()
        file = 'swamp/tests/ansible_instance_fleet_test.ts'
        plan = units.plan(['.github/workflows/ci.yml'], budget, fixtures, defaults)
        self.assertNotIn('capacity', plan)
        selected = units.planned_units(plan)
        expected = [name for name, _ in units.units_for(fixtures[file], budget)]
        self.assertEqual(79, len(expected))
        self.assertEqual(set(expected), {name for name in selected if name.split('#')[0] == file})
        self.assertEqual(16, len(plan['shards']) + len(plan['self_hosted_shards']))
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
        expected = {name for name, _ in units.units_for(fixtures[file], budget)}
        self.assertEqual(4, len(expected))
        self.assertEqual(expected, {name for name in units.planned_units(plan) if name.split('#')[0] == file})
        self.assertNotIn('capacity', plan)
        self.assertEqual([], units.unplaced_units(plan))
        self.assertLessEqual(len(plan['shards']) + len(plan['self_hosted_shards']), budget['runner_shards'])


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
