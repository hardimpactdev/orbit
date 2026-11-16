"""Fixture matrices share nine runner places without admitting fork code or a second wave."""
from pathlib import Path
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
        self.assertEqual(9, budget['runner_shards'])
        self.assertEqual((150, 180, 190, 240), tuple(budget[key] for key in
                         ('shard_seconds', 'unit_ceiling_seconds', 'shard_deadline_seconds', 'shard_ceiling_seconds')))
        self.assertEqual((4, 2), (budget['shard_workers'], budget['self_hosted_workers']))


if __name__ == '__main__':
    unittest.main()
