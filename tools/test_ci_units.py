"""Fixture selection, bounded packing, process deadlines and the required matrix gate."""
import contextlib
import io
import heapq
import math
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import textwrap
import time
import tomllib
import unittest
from unittest.mock import patch
from importlib.machinery import SourceFileLoader
from importlib.util import module_from_spec, spec_from_loader

ROOT = Path(__file__).resolve().parents[1]
loader = SourceFileLoader('ci_units', str(ROOT / 'bin/ci-units'))
units = module_from_spec(spec_from_loader(loader.name, loader))
loader.exec_module(units)
BUDGET = dict(shard_seconds=150, shard_workers=4, shard_deadline_seconds=190, shard_ceiling_seconds=240, unit_ceiling_seconds=180, slice_seconds=60, slice_startup_seconds=6, pr_shards=9, runner_shards=9, spread_shards=9, self_hosted_shards=8, self_hosted_workers=2, default_cost=30, machinery_canary=[], capacity_gap=[])


def fixture(file, cost=30, inputs=(), **options):
    return dict(file=file, cost=cost, inputs=list(inputs), **options)


def planned(paths, fixtures, defaults=(), budget=None):
    return units.plan(paths, budget or BUDGET, {f['file']: f for f in fixtures}, defaults)


def names(result):
    return [name for shard in result['shards'] for name in shard['units']]


def scheduled(result):
    return units.planned_units(result) + units.unplaced_units(result) + [u for row in result.get('not_run', []) for u in row['units']]


class PlanningTest(unittest.TestCase):
    def test_largest_first_packing_fills_holes_and_is_deterministic(self):
        fixtures = [fixture(f'swamp/{name}.py', cost, ['swamp/input']) for name, cost in [('a', 40), ('b', 60), ('c', 50), ('d', 50)]]
        result = planned(['swamp/input'], fixtures)
        self.assertEqual(result['shards'], [{'name': '01', 'units': ['swamp/b.py', 'swamp/c.py', 'swamp/d.py', 'swamp/a.py']}])
        self.assertEqual(result, planned(['swamp/input'], list(reversed(fixtures))))

    def test_six_shards_have_four_bounded_lanes_and_name_the_twenty_fifth_full_lane(self):
        fixtures = [fixture(f'swamp/{i:02d}.py', 100, ['swamp/input']) for i in range(25)]
        result = planned(['swamp/input'], fixtures, budget={**BUDGET, 'pr_shards': 6})
        self.assertEqual(len(result['shards']), 6)
        self.assertTrue(all(len(shard['units']) == 4 for shard in result['shards']))
        self.assertEqual(result['capacity'], [{'unplaced': ['swamp/24.py']}])
        # Mixed sizes require real lane packing, not just a 400-second total.
        mixed = [fixture(f'swamp/{i}.py', 100, ['swamp/input']) for i in range(5)]
        self.assertEqual(len(planned(['swamp/input'], mixed)['shards']), 2)

    def test_deno_slices_use_ceil_cost_and_python_stays_whole(self):
        self.assertEqual(units.units_for(fixture('swamp/a.ts', 121), BUDGET),
                         [('swamp/a.ts#0/3', 121 / 3 + 6), ('swamp/a.ts#1/3', 121 / 3 + 6), ('swamp/a.ts#2/3', 121 / 3 + 6)])
        self.assertEqual(units.units_for(fixture('swamp/a.py', 99), BUDGET), [('swamp/a.py', 99)])
        self.assertEqual(units.units_for(fixture('swamp/a.ts', 99, slices=False), BUDGET), [('swamp/a.ts', 99)])
        self.assertEqual(units.units_for(fixture('swamp/a.ts', 60), BUDGET), [('swamp/a.ts', 66)])
        explicit = units.units_for(fixture('swamp/a.ts', 121, slices=5), BUDGET)
        self.assertEqual([u for u, _ in explicit], [f'swamp/a.ts#{k}/5' for k in range(5)])
        self.assertTrue(all(cost == 121 / 5 + 6 for _, cost in explicit))

    def test_fast_mail_python_fixture_runs_whole_in_required_pr_path(self):
        budget, fixtures, defaults = units.configuration()
        file = 'swamp/tests/ansible_instance_mail_test.py'
        self.assertEqual(set(units.collect(file)['cases']), set(fixtures[file]['cases']))
        # The collector prints the stored population and nothing that pins the file's bytes.
        self.assertEqual(tuple(units.collect(file)), units.registry.POPULATION)
        step = units.unit_step(file, fixtures, {}, Path('/tmp/fixture-collections'))
        self.assertEqual(step.argv, ['python3', '-I', 'tests/ansible_instance_mail_test.py'])
        result = units.plan([file], budget, fixtures, defaults)
        self.assertIn(file, names(result))
        self.assertNotIn('capacity', result)
        self.assertFalse(fixtures[file].get('self_hosted'))
        self.assertLessEqual(fixtures[file]['cost'], 15)

    def test_limit_names_overflow_and_keeps_direct_matches_over_shared_globs(self):
        fixtures = [fixture('swamp/direct.py', 100, ['swamp/tasks/input.yml']),
                    fixture('swamp/other.py', 100, ['swamp/**']),
                    fixture('swamp/third.py', 100, ['swamp/**'])]
        result = planned(['swamp/tasks/input.yml'], fixtures, budget={**BUDGET, 'pr_shards': 2, 'shard_workers': 1})
        self.assertEqual(len(result['shards']), 2)
        self.assertIn('swamp/direct.py', names(result))
        self.assertEqual(units.unplaced_units(result), ['swamp/third.py'])

    def test_admission_uses_table_order_and_leaves_every_slice_of_a_file_out_together(self):
        budget = {**BUDGET, 'pr_shards': 1, 'shard_workers': 1, 'shard_seconds': 100}
        first, second = fixture('swamp/first.py', 40, ['swamp/input']), fixture('swamp/second.py', 70, ['swamp/input'])
        self.assertEqual(names(planned(['swamp/input'], [first, second], budget=budget)), ['swamp/first.py'])
        self.assertEqual(names(planned(['swamp/input'], [second, first], budget=budget)), ['swamp/second.py'])
        sliced = fixture('swamp/sliced.ts', 121, ['swamp/input'], slices=5)
        result = planned(['swamp/input'], [sliced, first], budget=budget)
        self.assertEqual(names(result), ['swamp/first.py'])
        self.assertEqual(set(units.unplaced_units(result)), {f'swamp/sliced.ts#{k}/5' for k in range(5)})

    def test_overflow_fails_the_plan_and_names_every_unplaced_unit(self):
        budget = {**BUDGET, 'pr_shards': 1, 'shard_workers': 1, 'self_hosted_shards': 1, 'self_hosted_workers': 1}
        fixtures = [fixture(f'swamp/{name}.py', 100, ['swamp/input']) for name in ('a', 'b', 'c')]
        fixtures += [fixture(f'swamp/{name}.py', 100, ['swamp/input'], self_hosted=True) for name in ('d', 'e')]
        result = planned(['swamp/input'], fixtures, budget=budget)
        self.assertEqual(names(result), ['swamp/a.py'])
        self.assertEqual([u for s in result['self_hosted_shards'] for u in s['units']], ['swamp/d.py'])
        self.assertEqual(result['capacity'], [{'unplaced': ['swamp/b.py', 'swamp/c.py', 'swamp/e.py']}])
        self.assertNotIn('deferred', result)
        text = units.plan_summary(result, {f['file']: f for f in fixtures})
        for unit in ('swamp/b.py', 'swamp/c.py', 'swamp/e.py'):
            self.assertIn(unit, text)
        self.assertIn('Required checks will fail', text)

    def test_a_self_hosted_shard_has_self_hosted_workers_lanes_and_a_hosted_shard_keeps_shard_workers(self):
        budget = {**BUDGET, 'pr_shards': 1, 'self_hosted_shards': 1}
        fixtures = [fixture(f'swamp/{side}{i}.py', 100, ['swamp/input'], self_hosted=side == 'runner') for side in ('hosted', 'runner')
                    for i in range(4)]
        result = planned(['swamp/input'], fixtures, budget=budget)
        self.assertEqual(sorted(names(result)), [f'swamp/hosted{i}.py' for i in range(4)])
        self.assertEqual(sorted(u for s in result['self_hosted_shards'] for u in s['units']), ['swamp/runner0.py', 'swamp/runner1.py'])
        self.assertEqual(result['capacity'], [{'unplaced': ['swamp/runner2.py', 'swamp/runner3.py']}])
        # A file that needs more self-hosted shards than the pool has names them by its own lane count.
        big = [fixture('swamp/big.ts', 500, ['swamp/input'], self_hosted=True, slices=5)]
        row = planned(['swamp/input'], big, budget=budget)['capacity'][0]
        self.assertEqual((row['shards'], row['workers']), (3, 6))

    def test_self_hosted_units_run_only_in_their_own_shards_with_their_own_limit(self):
        fixtures = [fixture('swamp/hosted.py', 100, ['swamp/input']),
                    fixture('swamp/runner.ts', 200, ['swamp/input'], self_hosted=True, slices=4)]
        result = planned(['swamp/input'], fixtures)
        self.assertEqual(names(result), ['swamp/hosted.py'])
        self.assertEqual(sorted(u for s in result['self_hosted_shards'] for u in s['units']),
                         [f'swamp/runner.ts#{k}/4' for k in range(4)])
        self.assertNotIn('capacity', result)
        # The self-hosted limit is separate: a full hosted pool does not take its shards, and its overflow fails.
        full = planned(['swamp/input'], fixtures, budget={**BUDGET, 'pr_shards': 1, 'self_hosted_shards': 0})
        self.assertEqual(names(full), ['swamp/hosted.py'])
        self.assertEqual(full['self_hosted_shards'], [])
        self.assertEqual(units.unplaced_units(full), [f'swamp/runner.ts#{k}/4' for k in range(4)])
        self.assertTrue(full['capacity'][0]['self_hosted'])
        self.assertIn('above the self-hosted shard limit', units.plan_summary(full, {f['file']: f for f in fixtures}))

    def test_a_named_capacity_gap_runs_what_fits_names_the_rest_and_never_blocks(self):
        budget = {**BUDGET, 'self_hosted_shards': 1, 'self_hosted_workers': 1, 'capacity_gap': ['swamp/gap.ts']}
        gap = fixture('swamp/gap.ts', 180, ['swamp/input'], self_hosted=True, slices=3)
        other = fixture('swamp/other.py', 100, ['swamp/input'], self_hosted=True)
        fixtures = [gap, other]
        result = planned(['swamp/input'], fixtures, budget=budget)
        # The other file is admitted first even though the gap file comes first in the table.
        self.assertEqual([u for s in result['self_hosted_shards'] for u in s['units']], ['swamp/other.py'])
        self.assertEqual(result['not_run'], [{'file': 'swamp/gap.ts', 'units': [f'swamp/gap.ts#{k}/3' for k in range(3)],
                                              'reason': 'capacity gap per ADR 0262'}])
        self.assertNotIn('capacity', result)
        self.assertIn('Not run: `swamp/gap.ts`, 3 units, reason: capacity gap per ADR 0262',
                      units.plan_summary(result, {f['file']: f for f in fixtures}))
        # With room, the gap file's units that fit run; an oversized unit is named, not a blocker.
        roomy = {**budget, 'self_hosted_shards': 2, 'self_hosted_workers': 4}
        gap.update(cost=300, cases=['a', 'b', 'c'], case_costs={'a': 100, 'b': 160, 'c': 40})
        result = planned(['swamp/input'], fixtures, budget=roomy)
        self.assertEqual(sorted(u for s in result['self_hosted_shards'] for u in s['units']),
                         ['swamp/gap.ts#0/3', 'swamp/gap.ts#2/3', 'swamp/other.py'])
        self.assertEqual(result['not_run'][0]['units'], ['swamp/gap.ts#1/3'])
        self.assertNotIn('capacity', result)
        # Without the name, the same overflow fails Required.
        result = planned(['swamp/input'], fixtures, budget={**roomy, 'capacity_gap': []})
        self.assertIn('swamp/gap.ts#1/3', units.unplaced_units(result))
        self.assertNotIn('not_run', result)

    def test_a_capacity_gap_must_name_registered_self_hosted_files(self):
        original, inventory = units.registry.load(ROOT)
        hosted = next(f['file'] for f in original['fixture'] if not f.get('self_hosted'))
        for gaps in (['swamp/tests/missing_test.ts'], [hosted], 'swamp/tests/ansible_instance_migrate_move_test.ts',
                     ['swamp/tests/ansible_instance_migrate_move_test.ts'] * 2):
            config = json.loads(json.dumps(original))
            config['budget']['capacity_gap'] = gaps
            with self.subTest(gaps=gaps), patch.object(units.registry, 'load', return_value=(config, inventory)):
                with self.assertRaisesRegex(ValueError, 'capacity_gap must list unique registered self_hosted fixtures'):
                    units.configuration()

    def test_self_hosted_units_follow_the_unit_ceiling_with_startup(self):
        result = planned(['swamp/runner.ts'], [fixture('swamp/runner.ts', 145, cases=['long'], self_hosted=True)])
        self.assertEqual(result['self_hosted_shards'], [])
        self.assertEqual(result['capacity'][0]['oversize'], [{'unit': 'swamp/runner.ts', 'seconds': 151, 'cases': ['long']}])
        self.assertEqual(units.unplaced_units(result), ['swamp/runner.ts'])

    def test_unknown_swamp_path_selects_default_and_names_it(self):
        fixtures = [fixture('swamp/default.py'), fixture('swamp/specific.py', inputs=['swamp/known/**'])]
        result = planned(['swamp/new/file', 'docs/page.md'], fixtures, ['swamp/default.py'])
        self.assertEqual(names(result), ['swamp/default.py'])
        self.assertEqual(result['unmapped'], ['swamp/new/file'])
        self.assertEqual(planned(['docs/page.md'], fixtures)['shards'], [])

    def test_self_support_globs_and_control_files(self):
        fixtures = [fixture('swamp/tests/a.ts', inputs=['swamp/tests/_support/helper.ts', 'swamp/ansible/tasks/a-*.yml']),
                    fixture('swamp/tests/b.py'), fixture('swamp/tests/c.ts', self_hosted=True)]
        for path in ('swamp/tests/a.ts', 'swamp/tests/_support/helper.ts', 'swamp/ansible/tasks/a-b.yml'):
            self.assertEqual(names(planned([path], fixtures)), ['swamp/tests/a.ts'])
        self.assertEqual(names(planned(['swamp/ansible/tasks/a-sub/file.yml'], fixtures)), [])
        # The machinery selects the canary set, not every fixture, even one that lists a machinery path as an input.
        budget = {**BUDGET, 'machinery_canary': ['swamp/tests/b.py']}
        fixtures[0]['inputs'].append('bin/swamp-shape-gate')
        for path in ('bin/ci-units', 'bin/ci-registry', 'bin/swamp-shape-gate', units.PRELOAD):
            result = planned([path], fixtures, budget=budget)
            self.assertEqual(scheduled(result), ['swamp/tests/b.py'])
        self.assertEqual(scheduled(planned(['bin/ci-units', 'swamp/tests/a.ts'], fixtures, budget=budget)),
                         ['swamp/tests/a.ts', 'swamp/tests/b.py'])
        shared = units.plan([], budget, {f['file']: f for f in fixtures}, [], shared_registry=True)
        self.assertEqual(scheduled(shared), ['swamp/tests/b.py'])
        self.assertIsNotNone(units.selection_regex('swamp/**/a?.ts').fullmatch('swamp/deep/path/ab.ts'))
        self.assertIsNotNone(units.selection_regex('swamp/**/a?.ts').fullmatch('swamp/ab.ts'))

    def test_plan_summary_names_unplaced_units_and_unmapped_paths(self):
        text = units.plan_summary(dict(shards=[], self_hosted_shards=[], unmapped=['swamp/new'], capacity=[{'unplaced': ['slow']}]), {})
        self.assertIn('`slow`', text.replace('"slow"', '`slow`'))
        self.assertIn('swamp/new', text)
        self.assertIn('selected default', text)


class InventorySelectionTest(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory()
        self.addCleanup(self.scratch.cleanup)
        self.root = Path(self.scratch.name)
        self.git('init', '-q')
        self.git('config', 'user.name', 'CI test')
        self.git('config', 'user.email', 'ci@example.test')
        self.inventory = self.root / 'tools/tests/ci-fixture-cases.json'
        self.inventory.parent.mkdir(parents=True)
        self.a, self.b, self.c = ['swamp/tests/' + name + '.ts' for name in ('host_clean', 'systemd', 'new')]
        self.initial = {self.a: {'cases': ['old']}, self.b: {'cases': ['drain', 'restart']}}
        self.base = self.commit(self.initial)

    def git(self, *args):
        return subprocess.check_output(['git', *args], cwd=self.root, text=True, stderr=subprocess.PIPE).strip()

    def commit(self, entries):
        self.inventory.write_text(json.dumps(entries))
        self.git('add', '.')
        self.git('commit', '-qm', 'inventory')
        return self.git('rev-parse', 'HEAD')

    def changed(self, base, head):
        with contextlib.chdir(self.root):
            return units.inventory_changes(base, head)

    def test_population_edit_selects_only_its_fixture_through_cli(self):
        head = self.commit({**self.initial, self.a: {'cases': ['old', 'new']}})
        fixtures = {file: fixture(file) for file in (self.a, self.b)}
        with contextlib.chdir(self.root), patch.object(units, 'ROOT', self.root), \
             patch.object(units, 'configuration', return_value=(BUDGET, fixtures, [])), \
             patch.object(units.gate, 'resolve_base', return_value=self.base), \
             patch.object(units.selection, 'changed_inputs', return_value=(self.base, [
                 'tools/tests/ci-fixture-cases.json',
                 'apps/gateway/tests/Unit/Domain/TaskVms/TaskVmWorkflowGateTest.php'])), \
             patch.object(sys, 'argv', ['ci-units', 'plan', '--base', self.base, '--head', head]), \
             contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(units.main(), 0)
        self.assertEqual(names(json.loads(output.getvalue())), [self.a])

    def test_added_removed_and_deleted_inventory_entries_select_existing_fixtures(self):
        head = self.commit({self.b: self.initial[self.b], self.c: {'cases': ['new']}})
        changed = self.changed(self.base, head)
        self.assertEqual(changed, [self.a, self.c])
        fixtures = {file: fixture(file) for file in (self.a, self.b, self.c)}
        result = units.plan(['tools/tests/ci-fixture-cases.json'], BUDGET, fixtures, [], changed)
        self.assertEqual(set(names(result)), {self.a, self.c})
        self.inventory.unlink()
        self.git('add', '-u')
        self.git('commit', '-qm', 'remove inventory')
        deleted = self.git('rev-parse', 'HEAD')
        self.assertEqual(self.changed(head, deleted), sorted([self.b, self.c]))
        self.assertEqual(self.changed(deleted, head), sorted([self.b, self.c]))

    def test_formatting_only_inventory_change_selects_no_fixture_bodies(self):
        self.inventory.write_text(json.dumps(self.initial, indent=2))
        self.git('add', '.')
        self.git('commit', '-qm', 'format inventory')
        changed = self.changed(self.base, self.git('rev-parse', 'HEAD'))
        self.assertEqual(changed, [])
        self.assertEqual(names(units.plan(['tools/tests/ci-fixture-cases.json'], BUDGET,
                                         {self.a: fixture(self.a)}, [], changed)), [])

    def test_merge_base_excludes_main_only_inventory_edits(self):
        self.git('checkout', '-qb', 'topic')
        topic = self.commit({**self.initial, self.a: {'cases': ['old', 'new']}})
        self.git('checkout', '-qb', 'base', self.base)
        main = self.commit({**self.initial, self.b: {'cases': ['drain', 'restart', 'main-only']}})
        ancestor = self.git('merge-base', main, topic)
        self.assertEqual(ancestor, self.base)
        self.assertEqual(self.changed(ancestor, topic), [self.a])


class RegistrySelectionTest(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory()
        self.addCleanup(self.scratch.cleanup)
        self.root = Path(self.scratch.name)
        self.git('init', '-q')
        self.git('config', 'user.name', 'CI test')
        self.git('config', 'user.email', 'ci@example.test')
        self.table = self.root / '.github/ci/checks.toml'
        self.table.parent.mkdir(parents=True)
        self.initial = ('[budget]\ndefault_cost = 30\n[fixtures]\ndefault = []\n'
                        '[[fixture]]\nfile = "swamp/process.ts"\ncost = 40\n'
                        '[[fixture]]\nfile = "swamp/egress.py"\ncost = 117\n')
        self.base = self.commit(self.initial)

    def git(self, *args):
        return subprocess.check_output(['git', *args], cwd=self.root, text=True, stderr=subprocess.PIPE).strip()

    def commit(self, text):
        self.table.write_text(text)
        self.git('add', '.')
        self.git('commit', '-qm', 'table')
        return self.git('rev-parse', 'HEAD')

    def changes(self, text):
        head = self.commit(text)
        with contextlib.chdir(self.root), patch.object(units, 'ROOT', self.root):
            return units.registry_changes(self.base, head)

    def test_removing_the_after_merge_keys_is_a_machinery_change(self):
        deferred = self.initial.replace('cost = 40', 'cost = 40\nafter_merge = true\nreason = "slow"\nafter_merge_cases = ["late"]')
        before = self.commit(deferred)
        after = self.commit(self.initial.replace('cost = 40', 'cost = 40\nself_hosted = true'))
        with contextlib.chdir(self.root), patch.object(units, 'ROOT', self.root):
            self.assertEqual(units.registry_changes(before, after), ([], True))
            # The same edit with a changed input still selects the fixture.
            moved = self.commit(self.initial.replace('cost = 40', 'cost = 40\ninputs = ["swamp/new.yml"]'))
            self.assertEqual(units.registry_changes(before, moved), (['swamp/process.ts'], False))

    def test_a_runner_pool_change_is_a_machinery_change(self):
        # The pool's speed factor sets every shared lane, so it selects the machinery canary like a budget change.
        self.assertEqual(self.changes(self.initial + '[runner-pool]\nslowest_runner_factor = 1.2\n'), ([], True))

    def test_scheduling_metadata_alone_is_a_machinery_change(self):
        fixtures = {file: fixture(file) for file in ('swamp/process.ts', 'swamp/egress.py')}
        budget = {**BUDGET, 'machinery_canary': ['swamp/egress.py']}
        for edit in ('cost = 45', 'cost = 40\nself_hosted = true', 'cost = 40\nslices = 3',
                     'cost = 40\n[fixture.case_costs]\nfirst = 40'):
            with self.subTest(edit=edit):
                changed, shared = self.changes(self.initial.replace('cost = 40', edit))
                self.assertEqual((changed, shared), ([], True))
                result = units.plan(['.github/ci/checks.toml'], budget, fixtures, [], registry_changed=changed, shared_registry=shared)
                self.assertEqual(names(result), ['swamp/egress.py'])
        for edit in ('cost = 40\ninputs = ["swamp/new.yml"]', 'cost = 40\nhost_programs = ["jq"]', 'cost = 45\ninputs = ["swamp/a.yml"]'):
            with self.subTest(edit=edit):
                self.assertEqual(self.changes(self.initial.replace('cost = 40', edit))[0], ['swamp/process.ts'])

    def test_fixture_inputs_edit_selects_only_its_file_through_cli(self):
        head = self.commit(self.initial.replace('cost = 40', 'cost = 40\ninputs = ["swamp/new.yml"]'))
        fixtures = {file: fixture(file) for file in ('swamp/process.ts', 'swamp/egress.py')}
        with contextlib.chdir(self.root), patch.object(units, 'ROOT', self.root), \
             patch.object(units, 'configuration', return_value=(BUDGET, fixtures, [])), \
             patch.object(units.gate, 'resolve_base', return_value=self.base), \
             patch.object(units.selection, 'changed_inputs', return_value=(self.base, ['.github/ci/checks.toml'])), \
             patch.object(sys, 'argv', ['ci-units', 'plan', '--base', self.base, '--head', head]), \
             contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(units.main(), 0)
        self.assertEqual(names(json.loads(output.getvalue())), ['swamp/process.ts'])

    def test_formatting_and_check_edits_do_not_select_fixture_bodies(self):
        self.assertEqual(self.changes(self.initial + '\n# comment\n[check.docs]\ncost = 40\n'), ([], False))

    def test_shared_budget_defaults_and_order_select_the_machinery_canary(self):
        for text in (self.initial.replace('default_cost = 30', 'default_cost = 31'),
                     self.initial.replace('default = []', 'default = ["swamp/egress.py"]'),
                     self.initial.replace('file = "swamp/process.ts"', 'file = "TEMP"')
                     .replace('file = "swamp/egress.py"', 'file = "swamp/process.ts"')
                     .replace('file = "TEMP"', 'file = "swamp/egress.py"')):
            with self.subTest(text=text):
                changed, shared = self.changes(text)
                self.assertTrue(shared)
                fixtures = {file: fixture(file) for file in ('swamp/process.ts', 'swamp/egress.py')}
                result = units.plan(['.github/ci/checks.toml'], {**BUDGET, 'machinery_canary': ['swamp/egress.py']}, fixtures, [],
                                    registry_changed=changed, shared_registry=shared)
                self.assertCountEqual(names(result), {'swamp/egress.py', *changed})

    def test_first_table_addition_compares_against_empty_registry(self):
        self.table.unlink()
        self.git('add', '-u')
        self.git('commit', '-qm', 'before table')
        before = self.git('rev-parse', 'HEAD')
        after = self.commit(self.initial)
        with contextlib.chdir(self.root):
            self.assertEqual(units.registry_changes(before, after),
                             (['swamp/egress.py', 'swamp/process.ts'], True))

    def test_added_and_removed_entries_do_not_select_unchanged_files(self):
        changed, shared = self.changes(self.initial.replace('swamp/process.ts', 'swamp/new.ts'))
        self.assertEqual(changed, ['swamp/new.ts', 'swamp/process.ts'])
        self.assertFalse(shared)

    def test_merge_base_excludes_main_only_table_edits(self):
        self.git('checkout', '-qb', 'topic')
        topic = self.commit(self.initial.replace('cost = 40', 'cost = 40\ninputs = ["swamp/topic.yml"]'))
        self.git('checkout', '-qb', 'base', self.base)
        main = self.commit(self.initial.replace('cost = 117', 'cost = 117\ninputs = ["swamp/main.yml"]'))
        with contextlib.chdir(self.root), patch.object(units, 'ROOT', self.root):
            self.assertEqual(units.registry_changes(self.git('merge-base', main, topic), topic),
                             (['swamp/process.ts'], False))

    def test_hub_lan_role_is_narrow_and_its_three_files_run_self_hosted_within_the_lane_or_a_named_gap(self):
        budget, fixtures, defaults = units.configuration()
        files = [f'swamp/tests/ansible_wireguard_hub_lan_{part}_test.py' for part in ('rules', 'recovery', 'load')]
        # The rules file ran past the lane on the self-hosted pool: ADR 0262 names it a capacity gap.
        gap, fitting = files[0], files[1:]
        unrelated = units.plan(['swamp/ansible/tasks/instance-container-process.yml',
                                'swamp/workflows/workflow-operation-process-container.yaml'], budget, fixtures, defaults)
        self.assertFalse(set(files) & {u.split('#')[0] for u in scheduled(unrelated)})
        for file in files:
            self.assertTrue(fixtures[file]['self_hosted'])
        for file in fitting:
            self.assertLessEqual(fixtures[file]['cost'], budget['shard_seconds'], file)
        self.assertIn(gap, budget['capacity_gap'])
        for path in (*files, 'swamp/ansible/tasks/node-hub-lan-proof.yml', 'swamp/tests/_support/wireguard_hub_lan.py'):
            result = units.plan([path], budget, fixtures, defaults)
            self.assertFalse(set(files) & set(names(result)))
            self.assertNotIn('capacity', result)
        result = units.plan(['swamp/ansible/tasks/node-hub-lan-proof.yml'], budget, fixtures, defaults)
        self.assertEqual({u for s in result['self_hosted_shards'] for u in s['units']}, set(fitting))
        self.assertEqual(result['not_run'], [{'file': gap, 'units': [gap], 'reason': 'capacity gap per ADR 0262'}])


class TableTest(unittest.TestCase):
    def test_release_container_source_selects_its_fixture(self):
        budget, fixtures, defaults = units.configuration()
        result = units.plan(['swamp/scripts/release-container.py'], budget, fixtures, defaults)
        self.assertIn('swamp/tests/release_container_test.py', scheduled(result))

    def test_writes_audit_is_owned_by_controls_and_never_scheduled_as_a_fixture(self):
        file = 'swamp/tests/writes_declared_test.ts'
        budget, fixtures, defaults = units.configuration()
        self.assertNotIn(file, units.fixture_steps())
        self.assertNotIn(file, fixtures)
        self.assertNotIn(file, units.registry.load(ROOT)[1])
        for path in (file, 'swamp/tests/writes-reviewed.json',
                     'swamp/workflows/workflow-operation-instance-mail-update.yaml',
                     'swamp/models/@nckrtl/orbit-gateway/orbit-gateway.yaml', 'bin/ci-units'):
            with self.subTest(path=path):
                result = units.plan([path], budget, fixtures, defaults)
                self.assertNotIn(file, {unit.split('#')[0] for unit in scheduled(result)})

    def test_table_files_costs_and_unsliceable_rules(self):
        budget, fixtures, defaults = units.configuration()
        self.assertEqual({**budget, 'machinery_canary': [], 'capacity_gap': []},
                         {**BUDGET, 'shard_workers': 2, 'shard_deadline_seconds': 210, 'lane_seconds': 130, 'pr_shards': 20, 'runner_shards': 20, 'self_hosted_shards': 10, 'spread_shards': 16})
        self.assertTrue(defaults)
        for f in fixtures.values():
            self.assertTrue((ROOT / f['file']).is_file(), f['file'])
            # Every unit fits the 150-second lane with its startup, except in a capacity gap that ADR 0262 names;
            # no key keeps a file out of pull requests.
            for name, cost in units.units_for(f, budget):
                if f['file'] not in budget['capacity_gap']:
                    self.assertLessEqual(cost, budget['shard_seconds'], name)
            self.assertFalse({'after_merge', 'after_merge_cases', 'reason'} & set(f), f['file'])
        self.assertLessEqual(set(units.fixture_steps()), set(fixtures))
        must = {str(p.relative_to(ROOT)) for p in (ROOT / 'swamp/tests').glob('*instance*') if p.is_file()}
        must.update('swamp/tests/' + name for name in ['ansible_task_container_host_prepare_test.ts',
                    'ansible_container_host_system_test.py', 'ansible_command_output_test.py'])
        self.assertLessEqual(must, set(fixtures))
        for f in fixtures.values():
            result = units.plan([f['file']], budget, fixtures, defaults)
            self.assertIn(f['file'], {u.split('#')[0] for u in scheduled(result)})

    def test_configuration_rejects_an_over_budget_unit_even_when_it_is_not_selected(self):
        config, inventory = units.registry.load(ROOT)
        file = 'swamp/tests/ansible_command_output_test.py'
        entry = next(f for f in config['fixture'] if f['file'] == file)
        entry['cost'] = config['budget']['shard_seconds']
        with patch.object(units.registry, 'load', return_value=(config, inventory)):
            budget, fixtures, defaults = units.configuration()
            self.assertNotIn(file, scheduled(units.plan(['docs/mission.md'], budget, fixtures, defaults)))
            entry['cost'] += 1
            with self.assertRaisesRegex(ValueError, file + ': 151s exceeds the 150s fixture lane'):
                units.configuration()

    def test_required_planner_rejects_live_python_cost_drift_even_when_unselected(self):
        file = 'swamp/tests/instance_clone_test.py'
        collect = units.collect
        current = collect(file)
        for cases in ([*current['cases'], 'test_added_without_cost'], current['cases'][1:]):
            with self.subTest(cases=len(cases)):
                def changed_population(path):
                    return {**current, 'cases': cases} if path == file else collect(path)
                with patch.object(units, 'collect', side_effect=changed_population):
                    with self.assertRaisesRegex(ValueError, file + ': case_costs must name every case'):
                        budget, fixtures, defaults = units.configuration()
                        units.plan(['docs/mission.md'], budget, fixtures, defaults)

    def test_new_gate_files_get_default_cost_and_invalid_long_python_is_refused(self):
        with tempfile.TemporaryDirectory() as scratch:
            path = Path(scratch) / 'checks.toml'
            source = (ROOT / '.github/ci/checks.toml').read_text()
            path.write_text(source)
            new_file = 'swamp/tests/ansible_schedule_logs_test.ts'
            with patch.object(units, 'fixture_steps', return_value={**units.fixture_steps(), new_file: object()}):
                self.assertEqual(units.configuration(path)[1][new_file]['cost'], BUDGET['default_cost'])
            with patch.object(units, 'fixture_steps', return_value={**units.fixture_steps(), 'swamp/tests/_support/fixture_supervisor.py': object()}):
                fresh = units.configuration(path)[1]['swamp/tests/_support/fixture_supervisor.py']
                self.assertEqual(fresh['cost'], BUDGET['default_cost'])
                self.assertNotIn('cases', fresh)
            steps = {**units.fixture_steps(), 'swamp/tests/new_fixture.ts': object()}
            # The fallback must work for real new fixture files, and refuse paths that do not exist.
            with patch.object(units, 'fixture_steps', return_value=steps):
                with self.assertRaisesRegex(ValueError, 'Invalid fixture file'):
                    units.configuration(path)

    def test_run_takes_its_worker_count(self):
        import signal
        handlers = {sig: signal.getsignal(sig) for sig in (signal.SIGTERM, signal.SIGINT)}
        self.addCleanup(lambda: [signal.signal(sig, handler) for sig, handler in handlers.items()])
        for extra, workers in (([], units.configuration()[0]['shard_workers']), (['--workers', '1'], 1)):
            with self.subTest(workers=workers):
                with patch.object(sys, 'argv', ['ci-units', 'run', '--units', '[]', *extra]), \
                        patch.object(units, 'run', return_value=0) as run:
                    self.assertEqual(units.main(), 0)
                self.assertEqual(run.call_args.args[1]['shard_workers'], workers)

    def test_run_of_a_self_hosted_shard_uses_self_hosted_workers_and_refuses_a_mixed_shard(self):
        import signal
        handlers = {sig: signal.getsignal(sig) for sig in (signal.SIGTERM, signal.SIGINT)}
        self.addCleanup(lambda: [signal.signal(sig, handler) for sig, handler in handlers.items()])
        budget, fixtures, _ = units.configuration()
        runner = next(file for file, f in fixtures.items() if f.get('self_hosted'))
        hosted = next(file for file, f in fixtures.items() if not f.get('self_hosted'))
        for chosen, extra, workers in (([runner], [], budget['self_hosted_workers']), ([hosted], [], budget['shard_workers']),
                                       ([runner, hosted], ['--workers', '1'], 1)):
            with self.subTest(units=chosen, extra=extra):
                with patch.object(sys, 'argv', ['ci-units', 'run', '--units', json.dumps(chosen), *extra]), \
                        patch.object(units, 'run', return_value=0) as run:
                    self.assertEqual(units.main(), 0)
                self.assertEqual(run.call_args.args[1]['shard_workers'], workers)
        self.assertEqual(2, budget['self_hosted_workers'])
        self.assertEqual(2, budget['shard_workers'])
        with patch.object(sys, 'argv', ['ci-units', 'run', '--units', json.dumps([runner, hosted])]), \
                patch.object(units, 'run', return_value=0) as run, patch('sys.stderr'), self.assertRaises(SystemExit):
            units.main()
        run.assert_not_called()

    def test_host_programs_list_unique_program_names(self):
        for programs in ([], 'zip', ['zip', 'zip'], ['zip; rm'], [1]):
            with self.subTest(programs=programs):
                config, inventory = units.registry.load(ROOT)
                config['fixture'][0]['host_programs'] = programs
                with patch.object(units.registry, 'load', return_value=(config, inventory)):
                    with self.assertRaisesRegex(ValueError, 'host_programs must list unique program names'):
                        units.configuration()
        self.assertEqual(units.configuration()[1]['swamp/tests/gateway_swamp_load_test.ts']['host_programs'], ['jq'])

    def test_self_hosted_is_a_boolean_and_never_a_default(self):
        original, inventory = units.registry.load(ROOT)
        for change in ({'self_hosted': 'yes'}, {'self_hosted': 1}):
            config = json.loads(json.dumps(original))
            config['fixture'][0].update(change)
            with self.subTest(change=change), patch.object(units.registry, 'load', return_value=(config, inventory)):
                with self.assertRaisesRegex(ValueError, 'self_hosted must be a boolean'):
                    units.configuration()
        config = json.loads(json.dumps(original))
        next(f for f in config['fixture'] if f['file'] == config['fixtures']['default'][0])['self_hosted'] = True
        with patch.object(units.registry, 'load', return_value=(config, inventory)):
            with self.assertRaisesRegex(ValueError, 'Invalid default fixture'):
                units.configuration()

    def test_an_ansible_toolchain_pin_selects_the_canary_with_ansible_in_every_family(self):
        budget, fixtures, defaults = units.configuration()
        canary = budget['machinery_canary']
        for path in units.ANSIBLE_TOOLCHAIN:
            # Shared pins select only the canary set even though many fixtures depend on them.
            self.assertTrue(any(path in f['inputs'] for f in fixtures.values()), path)
            with self.subTest(path=path):
                result = units.plan([path], budget, fixtures, defaults)
                self.assertEqual(sorted({u.split('#')[0] for u in units.planned_units(result)}), sorted(canary))
                self.assertNotIn('capacity', result)
                self.assertEqual(len(result['shards']), 1)
        families = {'container': 'swamp/tests/ansible_container_host_system_test.py',
                    'host': 'swamp/tests/ansible_task_host_firewall_remove_test.ts', 'node': 'swamp/tests/node_converge_test.ts',
                    'migrate': 'swamp/tests/ansible_instance_migrate_source_test.ts',
                    'worker': 'swamp/tests/ansible_gateway_swamp_pin_test.ts'}
        for family, file in families.items():
            self.assertIn(file, canary, family)
            self.assertIn('swamp/ansible/ansible.cfg', fixtures[file]['inputs'], family)
            self.assertIn('ansible-playbook', (units.ROOT / file).read_text(), family)

    def test_the_machinery_canary_is_registered_hosted_and_fits_one_shard(self):
        budget, fixtures, _ = units.configuration()
        self.assertTrue(budget['machinery_canary'])
        original, inventory = units.registry.load(ROOT)
        self_hosted = next(f['file'] for f in original['fixture'] if f.get('self_hosted'))
        large = [f['file'] for f in original['fixture'] if not f.get('self_hosted') and f['file'] in fixtures and
                 sum(c for _, c in units.units_for(fixtures[f['file']], budget)) > 300]
        for canary, message in [([], 'unique registered hosted'), (['swamp/tests/missing_test.ts'], 'unique registered hosted'),
                                ([self_hosted], 'unique registered hosted'), (budget['machinery_canary'] * 2, 'unique registered hosted'),
                                (large[:3], 'fit one shard'), ('swamp/tests/ansible_inputs_test.py', 'unique registered hosted')]:
            config = json.loads(json.dumps(original))
            config['budget']['machinery_canary'] = canary
            with self.subTest(canary=canary), patch.object(units.registry, 'load', return_value=(config, inventory)):
                with self.assertRaisesRegex(ValueError, message):
                    units.configuration()

    def test_container_dependency_closure_selects_the_affected_files(self):
        budget, fixtures, defaults = units.configuration()
        for path, expected in [
            ('swamp/workflows/workflow-operation-process-destroy.yaml', 'swamp/tests/process_destroy_failed_cache_test.ts'),
            ('swamp/ansible/schedule-logs.yml', 'swamp/tests/ansible_schedule_logs_test.ts'),
            ('swamp/ansible/library/orbit_schedule_journal.py', 'swamp/tests/ansible_schedule_logs_test.ts'),
            ('apps/gateway/app/Infrastructure/AgentView/StreamWebSocketClient.php', 'swamp/tests/ansible_schedule_logs_test.ts'),
            ('swamp/ansible/tasks/instance-migrate-retire.yml', 'swamp/tests/ansible_instance_migrate_preflight_test.ts'),
            ('swamp/ansible/instance_bridge.py', 'swamp/tests/ansible_instance_container_test.ts'),
            ('swamp/tests/_support/instance_host.ts', 'swamp/tests/ansible_instance_host_image_test.ts'),
            ('swamp/ansible/files/instance-container-power.py', 'swamp/tests/ansible_instance_host_image_test.ts'),
            ('swamp/ansible/files/instance-container-wake.sh', 'swamp/tests/ansible_instance_host_image_test.ts'),
            ('swamp/ansible/tasks/instance-power-helper.yml', 'swamp/tests/ansible_instance_host_image_test.ts'),
            ('swamp/tests/fixtures/host-image-boundaries.json', 'swamp/tests/ansible_instance_host_acl_test.ts'),
            ('swamp/ansible/tasks/task-container-host-system.yml', 'swamp/tests/ansible_container_host_system_test.py'),
        ]:
            result = units.plan([path], budget, fixtures, defaults)
            self.assertIn(expected, [u.split('#')[0] for u in scheduled(result)])


class ExecutionTest(unittest.TestCase):
    def test_migrate_result_fixture_uses_deno_and_proves_its_case_receipts(self):
        budget, fixtures, _ = units.configuration()
        file = 'swamp/extensions/behaviors/operations/instance-dev-command/migrate_result_test.ts'
        self.assertEqual([name for name, _ in units.units_for(fixtures[file], budget)], [file])
        step = units.unit_step(file, fixtures, units.fixture_steps(), Path('/collections'))
        self.assertEqual(step.argv[:4], ['deno', 'test', '--preload', str(ROOT / units.PRELOAD)])
        self.assertEqual(step.argv[-2:], ['-A', file.removeprefix('swamp/')])
        self.assertEqual(json.loads(step.env['ORBIT_TEST_CASES']), fixtures[file]['cases'])
        self.assertNotIn('ORBIT_TEST_SLICE', step.env)

    def test_gate_flags_environment_and_slice_preload_are_retained(self):
        _, fixtures, _ = units.configuration()
        steps = units.fixture_steps()
        file = 'swamp/tests/ansible_instance_host_test.ts'
        step = units.unit_step(file + '#1/3', fixtures, steps, Path('/collections'))
        self.assertEqual(step.argv[:4], ['deno', 'test', '--preload', str(ROOT / units.PRELOAD)])
        self.assertEqual(step.argv[-2:], ['-A', 'tests/ansible_instance_host_test.ts'])
        self.assertEqual(step.env['ORBIT_TEST_SLICE'], '1/3')
        self.assertEqual(step.env['ANSIBLE_COLLECTIONS_PATH'], '/collections')
        self.assertIn('ORBIT_INSTANCE_HOST_DEADLINE_MS', step.env)
        for invalid in ('#3/3', '#0/0', '#-1/3', '#x'):
            with self.assertRaisesRegex(ValueError, 'Invalid slice'):
                units.unit_step(file + invalid, fixtures, steps, Path('/collections'))

    def test_every_case_reaches_the_preload_and_none_is_skipped(self):
        file = 'swamp/tests/node_worker_systemd_cleanup_test.ts'
        _, fixtures, _ = units.configuration()
        step = units.unit_step(file, fixtures, units.fixture_steps(), Path('/collections'))
        self.assertIn('--preload', step.argv)
        self.assertEqual(json.loads(step.env['ORBIT_TEST_CASES']), fixtures[file]['cases'])
        self.assertNotIn('ORBIT_TEST_SKIP', step.env)
        self.assertNotIn('ORBIT_TEST_SLICE', step.env)

    def test_each_step_has_its_own_temp_directory_and_gate_worker_environment(self):
        code = 'import json,os; print(json.dumps({k:os.environ[k] for k in ["TMPDIR","TMP","TEMP","DENO_JOBS","ANSIBLE_FORKS","PYTHON_CPU_COUNT","RAYON_NUM_THREADS","ANSIBLE_COLLECTIONS_PATH"]}))'
        step = units.gate.Step('shape', 'environment', [sys.executable, '-c', code], env={'ANSIBLE_COLLECTIONS_PATH': '/collections'})
        directories = []
        for _ in range(2):
            with contextlib.redirect_stdout(io.StringIO()) as output:
                self.assertEqual(units.run_unit(step, os.environ.copy(), 5)['status'], 'passed')
            env = json.loads(next(line for line in output.getvalue().splitlines() if line.startswith('{')))
            self.assertEqual(env['TMPDIR'], env['TMP'])
            self.assertEqual(env['TMPDIR'], env['TEMP'])
            self.assertEqual(env['ANSIBLE_COLLECTIONS_PATH'], '/collections')
            for key in ('DENO_JOBS', 'ANSIBLE_FORKS', 'PYTHON_CPU_COUNT', 'RAYON_NUM_THREADS'):
                self.assertEqual(env[key], '1')
            directories.append(env['TMPDIR'])
            self.assertFalse(Path(env['TMPDIR']).exists())
        self.assertNotEqual(*directories)

    def test_supervisor_reaps_orphans_before_leader_exit_and_keeps_leader_status(self):
        for leader_code, orphan_code in [(0, 7), (7, 0)]:
            with self.subTest(leader_code=leader_code), tempfile.TemporaryDirectory() as scratch:
                folder = Path(scratch)
                receipt = folder / 'receipt'
                leader = textwrap.dedent(f'''
                    import os, pathlib, time
                    folder = pathlib.Path({scratch!r})
                    (folder / 'leader').write_text(str(os.getpid()))
                    for index in range(4):
                        child = os.fork()
                        if child == 0:
                            if os.fork() == 0:
                                os.setsid()
                                (folder / ('orphan-' + str(index))).write_text(str(os.getpid()))
                                os._exit({orphan_code})
                            os._exit(0)
                        os.waitpid(child, 0)
                    while not (folder / 'release').exists():
                        time.sleep(.01)
                    os._exit({leader_code})
                ''')
                process = subprocess.Popen([sys.executable, str(ROOT / 'bin/ci-units'), '--supervise',
                                            '30', str(receipt), 'none', sys.executable, '-c', leader])
                try:
                    deadline = time.monotonic() + 5
                    while time.monotonic() < deadline:
                        files = list(folder.glob('orphan-*'))
                        pids = [p.read_text() for p in files]
                        if len(pids) == 4 and all(pids) and not any(Path('/proc', p).exists() for p in pids):
                            break
                        time.sleep(.01)
                    else:
                        self.fail('adopted children were not reaped while the leader was running')
                    self.assertIsNone(process.poll())
                    self.assertTrue(Path('/proc', (folder / 'leader').read_text()).exists())
                    self.assertFalse(receipt.exists(), 'unit finished before the reaping observation')
                    (folder / 'release').touch()
                    self.assertEqual(process.wait(timeout=5), 0)
                    self.assertEqual(json.loads(receipt.read_text())['status'], 'passed' if leader_code == 0 else 'FAILED')
                finally:
                    (folder / 'release').touch()
                    if process.poll() is None:
                        process.terminate()
                        process.wait(timeout=5)

    def test_deadline_kills_process_group_and_reports_recovery(self):
        # Both leader and child block until killed. No fixture, Ansible or timing race.
        with tempfile.TemporaryDirectory() as scratch:
            pid = Path(scratch) / 'pid'
            code = f'import subprocess,sys,signal; p=subprocess.Popen([sys.executable,"-c","import signal; signal.pause()"]); open({str(pid)!r},"w").write(str(p.pid)); signal.pause()'
            step = units.gate.Step('shape', 'fake#0/2', [sys.executable, '-c', code])
            with contextlib.redirect_stdout(io.StringIO()) as output:
                result = units.run_unit(step, os.environ.copy(), 0.3)
            self.assertEqual(result['status'], 'OVER BUDGET')
            self.assertLess(result['seconds'], 3)
            self.assertIn('==> fake#0/2: OVER BUDGET', output.getvalue())
            self.assertIn('ceiling 0.3 s. Raise its cost in .github/ci/fixtures (the plan adds a slice) or make it faster.', output.getvalue())
            child = int(pid.read_text())
            try:
                deadline = time.monotonic() + 2
                status = Path(f'/proc/{child}/stat').read_text().split()[2]
                while status != 'Z' and time.monotonic() < deadline:
                    time.sleep(0.001)
                    status = Path(f'/proc/{child}/stat').read_text().split()[2]
                self.assertEqual(status, 'Z', 'process-group child survived the ceiling')
            except FileNotFoundError:
                pass

    def test_deadline_reaps_a_child_that_detached_into_another_session(self):
        with tempfile.TemporaryDirectory() as scratch:
            pid = Path(scratch) / 'pid'
            child_code = f'import os,signal; os.setsid(); open({str(pid)!r},"w").write(str(os.getpid())); signal.pause()'
            code = f'import subprocess,sys,signal; subprocess.Popen([sys.executable,"-c",{child_code!r}]); signal.pause()'
            step = units.gate.Step('shape', 'detached', [sys.executable, '-c', code])
            with contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(units.run_unit(step, os.environ.copy(), 0.3)['status'], 'OVER BUDGET')
            self.assertFalse(Path(f'/proc/{pid.read_text()}').exists())

    def test_run_keeps_going_after_failures_and_publishes_times(self):
        with tempfile.TemporaryDirectory() as scratch:
            output = Path(scratch) / 'times.json'
            summary = Path(scratch) / 'summary.md'
            steps = {name: units.gate.Step('shape', name, [sys.executable, '-c', f'raise SystemExit({code})'])
                     for name, code in [('swamp/fail.py', 1), ('swamp/pass.py', 0)]}
            fixtures = {name: fixture(name) for name in steps}
            setup = units.gate.Step('shape', 'setup', [sys.executable, '-c', ''])
            with patch.object(units, 'prepare', return_value=(os.environ.copy(), Path('/collections'))), \
                    patch.object(units, 'fixture_steps', return_value=steps), \
                    patch.dict(os.environ, {'GITHUB_STEP_SUMMARY': str(summary)}), \
                    contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(units.run(list(steps), BUDGET, fixtures, output), 1)
            results = json.loads(output.read_text())
            self.assertEqual([r['status'] for r in results], ['FAILED', 'passed'])
            self.assertIn('swamp/pass.py', summary.read_text())

    def test_shard_deadline_names_stopped_and_never_started_units(self):
        with tempfile.TemporaryDirectory() as scratch:
            folder = Path(scratch)
            steps = {f'swamp/{i}.py': units.gate.Step('shape', str(i), [sys.executable, '-c', 'import signal; signal.pause()']) for i in range(3)}
            fixtures = {name: fixture(name) for name in steps}
            setup = units.gate.Step('shape', 'setup', [sys.executable, '-c', ''])
            budget = {**BUDGET, 'shard_workers': 1, 'shard_deadline_seconds': .5}
            with patch.object(units, 'prepare', return_value=(os.environ.copy(), Path('/collections'))), \
                    patch.object(units, 'fixture_steps', return_value=steps), \
                    contextlib.redirect_stdout(io.StringIO()) as output:
                self.assertEqual(units.run(list(steps), budget, fixtures, folder / 'times.json'), 1)
            results = json.loads((folder / 'times.json').read_text())
            self.assertEqual([r['status'] for r in results], ['OVER BUDGET', 'NOT RUN', 'NOT RUN'])
            self.assertIn('shard deadline', output.getvalue())
            self.assertLess(sum(r['seconds'] for r in results), 3)

    def test_shard_deadline_counts_from_the_job_start(self):
        with tempfile.TemporaryDirectory() as scratch:
            folder = Path(scratch)
            file = 'swamp/slow.py'
            steps = {file: units.gate.Step('shape', file, [sys.executable, '-c', 'import time; time.sleep(8)'])}
            budget = {**BUDGET, 'shard_workers': 1, 'shard_deadline_seconds': 60}

            def result(environment):
                with patch.object(units, 'prepare', return_value=(os.environ.copy(), Path('/collections'))), \
                        patch.object(units, 'fixture_steps', return_value=steps), \
                        patch.dict(os.environ, environment), contextlib.redirect_stdout(io.StringIO()) as output:
                    units.run([file], budget, {file: fixture(file)}, folder / 'times.json')
                return json.loads((folder / 'times.json').read_text())[0], output.getvalue()

            # The job's checkout and setup used 58 of the 60 seconds: the unit is stopped by name after two seconds.
            row, output = result({'ORBIT_JOB_STARTED_AT': str(time.time() - 58)})
            self.assertEqual('OVER BUDGET', row['status'])
            self.assertLess(row['seconds'], 6)
            self.assertIn('stopped at the shard deadline', output)
            # A job that used its whole time starts no unit.
            row, _ = result({'ORBIT_JOB_STARTED_AT': str(time.time() - 61)})
            self.assertEqual(('NOT RUN', 0), (row['status'], row['seconds']))
        # Outside a job, or with a start that is not a past time, the deadline counts from the run's start.
        for environment in ({}, {'ORBIT_JOB_STARTED_AT': ''}, {'ORBIT_JOB_STARTED_AT': 'soon'},
                            {'ORBIT_JOB_STARTED_AT': str(time.time() + 600)}):
            with patch.dict(os.environ, environment, clear=True):
                self.assertEqual(0, units.job_elapsed())
        with patch.dict(os.environ, {'ORBIT_JOB_STARTED_AT': str(time.time() - 40)}):
            self.assertAlmostEqual(40, units.job_elapsed(), delta=2)

    def test_four_units_run_together_with_separate_supervisors(self):
        with tempfile.TemporaryDirectory() as scratch:
            folder = Path(scratch)
            code = ('import pathlib,os,time; folder=pathlib.Path(' + repr(scratch) + '); '
                    '(folder/("ready-"+str(os.getpid()))).write_text(""); deadline=time.monotonic()+4\n'
                    'while len(list(folder.glob("ready-*"))) < 4 and time.monotonic() < deadline: time.sleep(.001)\n'
                    'assert len(list(folder.glob("ready-*"))) == 4, "units ran serially"')
            steps = {f'swamp/{i}.py': units.gate.Step('shape', str(i), [sys.executable, '-c', code]) for i in range(4)}
            fixtures = {name: fixture(name) for name in steps}
            setup = units.gate.Step('shape', 'setup', [sys.executable, '-c', ''])
            with patch.object(units, 'prepare', return_value=(os.environ.copy(), Path('/collections'))), \
                    patch.object(units, 'fixture_steps', return_value=steps), \
                    contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(units.run(list(steps), BUDGET, fixtures, folder / 'times.json'), 0)
            self.assertEqual(len(json.loads((folder / 'times.json').read_text())), 4)

    @unittest.skipUnless(shutil.which('deno'), 'Deno preload is also exercised by the hosted fixture shards')
    def test_preload_partitions_registration_and_retains_only_ignore(self):
        with tempfile.TemporaryDirectory() as scratch:
            file = Path(scratch) / 'slice_test.ts'
            file.write_text('Deno.test("zero", () => {}); Deno.test({name:"one", fn() {}}); Deno.test.ignore("two", () => {}); Deno.test("three", () => {});')
            timing = Path(scratch) / 'startup.json'
            for k, expected in [(0, ('zero', 'two')), (1, ('one', 'three'))]:
                result = subprocess.run(['deno', 'test', '-A', '--preload', str(ROOT / units.PRELOAD), str(file)],
                                        env={**os.environ, 'ORBIT_TEST_SLICE': f'{k}/2', 'NO_COLOR': '1',
                                             'ORBIT_TEST_TIMING_FILE': str(timing), 'ORBIT_TEST_STARTED_AT_MS': str(time.time_ns() // 1_000_000)}, capture_output=True, text=True, timeout=15)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                self.assertGreaterEqual(json.loads(timing.read_text())['startup_seconds'], 0)
                self.assertLess(json.loads(timing.read_text())['startup_seconds'], 15)
                for name in expected:
                    self.assertIn(name + ' ...', result.stdout)
                for name in {'zero', 'one', 'two', 'three'} - set(expected):
                    self.assertNotIn(name + ' ...', result.stdout)
            # No case is skipped: a leftover skip list has no effect on the slice.
            result = subprocess.run(['deno', 'test', '-A', '--preload', str(ROOT / units.PRELOAD), str(file)],
                                    env={**os.environ, 'ORBIT_TEST_SLICE': '0/2', 'ORBIT_TEST_SKIP': '["zero"]', 'NO_COLOR': '1'},
                                    capture_output=True, text=True, timeout=15)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertIn('zero ...', result.stdout)
            self.assertNotIn('one ...', result.stdout)
            file.write_text('Deno.test.only("only-case", () => {}); Deno.test("ordinary", () => {});')
            result = subprocess.run(['deno', 'test', '-A', '--preload', str(ROOT / units.PRELOAD), str(file)],
                                    env={**os.environ, 'ORBIT_TEST_SLICE': '0/2', 'NO_COLOR': '1'}, capture_output=True, text=True, timeout=15)
            self.assertIn('only-case ... ok', result.stdout)
            self.assertIn('only', result.stdout + result.stderr)


class PopulationTest(unittest.TestCase):
    def preload(self, source, **env):
        with tempfile.TemporaryDirectory() as scratch:
            file = Path(scratch) / 'population_test.ts'
            marker = Path(scratch) / 'body'
            timing = Path(scratch) / 'cases.json'
            source = source.replace('BODY', 'Deno.writeTextFileSync(' + json.dumps(str(marker)) + ', "ran")')
            file.write_text(source)
            result = subprocess.run(['deno', 'test', '--no-check', '-A', '--preload', str(ROOT / units.PRELOAD), str(file)],
                                    env={**os.environ, 'NO_COLOR': '1', 'ORBIT_TEST_TIMING_FILE': str(timing),
                                         'ORBIT_TEST_STARTED_AT_MS': str(time.time_ns() // 1_000_000), **env},
                                    text=True, capture_output=True, timeout=10)
            return result, marker.exists(), json.loads(timing.read_text()) if timing.exists() else None

    @unittest.skipUnless(shutil.which('deno'), 'needs Deno')
    def test_appended_and_loop_generated_cases_fail_before_any_body(self):
        for extra in ['Deno.test("appended", () => { BODY; });',
                      'for (let i = 0; i < 3; i++) Deno.test(`generated-${i}`, () => { BODY; });']:
            result, ran, receipt = self.preload('Deno.test("original", () => { BODY; });' + extra,
                                                ORBIT_TEST_CASES='["original"]')
            self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertIn('Stale fixture case population', result.stdout + result.stderr)
            self.assertFalse(ran)
            self.assertGreater(len(receipt['registered']), 1)

    @unittest.skipUnless(shutil.which('deno'), 'needs Deno')
    def test_zero_registrations_empty_slice_and_commented_exclusion_cannot_pass(self):
        for source, env, message in [
            ('', {}, 'registered zero tests'),
            ('Deno.test("one", () => { BODY; });', {'ORBIT_TEST_SLICE': '1/2'}, 'registered zero tests'),
            ('Deno.test("renamed", () => { BODY; });', {'ORBIT_TEST_CASES': '["original"]'}, 'Stale fixture case population'),
        ]:
            result, ran, _ = self.preload(source, **env)
            self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertIn(message, result.stdout + result.stderr)
            self.assertFalse(ran)

    @unittest.skipUnless(shutil.which('deno'), 'needs Deno')
    def test_case_receipt_has_exact_names_and_completed_times(self):
        result, ran, receipt = self.preload('Deno.test("measured", () => { BODY; });', ORBIT_TEST_CASES='["measured"]')
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertTrue(ran)
        self.assertEqual(receipt['cases'][0]['name'], 'measured')
        self.assertEqual(receipt['cases'][0]['status'], 'passed')
        self.assertGreaterEqual(receipt['cases'][0]['seconds'], 0)
        self.assertGreaterEqual(receipt['startup_seconds'], 0)

    @unittest.skipUnless(shutil.which('deno'), 'needs Deno')
    def test_restricted_deno_commands_can_collect_and_write_case_receipts(self):
        with tempfile.TemporaryDirectory() as scratch:
            file = Path(scratch) / 'restricted_test.ts'
            file.write_text('Deno.test("restricted", () => {});')
            name = 'swamp/restricted.ts'
            fixtures = {name: fixture(name, cases=['restricted'])}
            original = units.gate.Step('shape', name, ['deno', 'test', '--no-check', '--allow-read', '--allow-run', str(file)])
            step = units.unit_step(name, fixtures, {name: original}, Path('/collections'))
            self.assertIn('--allow-read', step.argv)
            self.assertIn('--allow-run', step.argv)
            with contextlib.redirect_stdout(io.StringIO()):
                result = units.run_unit(step, os.environ.copy(), 10)
            self.assertEqual(result['status'], 'passed')
            self.assertEqual(result['cases'][0]['name'], 'restricted')
            self.assertEqual(result['cases'][0]['status'], 'passed')

    @unittest.skipUnless(shutil.which('deno'), 'needs Deno')
    def test_process_success_requires_every_selected_deno_case_to_complete(self):
        for source, expected in [
            ('Deno.exit(0);', 'FAILED'),
            ('Deno.test.ignore("ignored", () => {});', 'FAILED'),
            ('Deno.test("passed", () => {}); Deno.test.ignore("ignored", () => {});', 'FAILED'),
            ('Deno.test("exit", () => Deno.exit(0));', 'FAILED'),
            ('Deno.test("passed", () => {});', 'passed'),
        ]:
            with self.subTest(source=source), tempfile.TemporaryDirectory() as scratch:
                file = Path(scratch) / 'receipt_test.ts'
                file.write_text(source)
                name = 'swamp/receipt_test.ts'
                original = units.gate.Step('shape', name, ['deno', 'test', '--no-check', '-A', str(file)])
                step = units.unit_step(name, {name: fixture(name)}, {name: original}, Path('/collections'))
                with contextlib.redirect_stdout(io.StringIO()):
                    result = units.run_unit(step, os.environ.copy(), 10)
                self.assertEqual(result['status'], expected, result)

    def test_docs_select_no_fixture_and_a_fixture_selects_itself(self):
        budget, fixtures, defaults = units.configuration()
        file = 'swamp/tests/ansible_instance_migrate_move_test.ts'
        self.assertEqual(units.plan(['docs/mission.md'], budget, fixtures, defaults)['shards'], [])
        selected = units.plan(['swamp/tests/ansible_instance_container_test.ts'], budget, fixtures, defaults)
        self.assertIn('swamp/tests/ansible_instance_container_test.ts', {u.split('#')[0] for s in selected['self_hosted_shards'] for u in s['units']})
        self.assertNotIn('capacity', selected)
        migration = units.plan([file], budget, fixtures, defaults)
        self.assertEqual(migration['shards'], [])
        self.assertTrue(migration['self_hosted_shards'])

    def test_named_case_costs_and_startup_replace_the_equal_share_estimate(self):
        f = fixture('swamp/cases.ts', 140, cases=['large', 'small'], slices=2,
                    case_costs={'large': 130, 'small': 10})
        self.assertEqual(units.units_for(f, BUDGET), [('swamp/cases.ts#0/2', 136), ('swamp/cases.ts#1/2', 16)])
        f['slices'] = 100
        self.assertEqual(len(units.units_for(f, BUDGET)), 2, 'no empty slices')
        result = planned(['swamp/cases.ts'], [fixture('swamp/cases.ts', 2500, cases=[f'case-{i}' for i in range(25)], slices=25)],
                         budget={**BUDGET, 'pr_shards': 6})
        self.assertEqual(result['shards'], [])
        self.assertEqual(result['capacity'], [{'file': 'swamp/cases.ts', 'seconds': 2650, 'self_hosted': False, 'shards': 7, 'workers': 28},
                                              {'unplaced': sorted(f'swamp/cases.ts#{k}/25' for k in range(25))}])

    def test_fleet_refresh_costs_keep_every_case_and_bound_each_refresh(self):
        budget, fixtures, defaults = units.configuration()
        file = 'swamp/tests/ansible_instance_fleet_test.ts'
        evidence = json.loads((ROOT / 'tools/tests/ci-fleet-cost-evidence.json').read_text())
        speed = json.loads((ROOT / 'tools/tests/ci-fleet-speed-evidence.json').read_text())
        self.assertEqual(len(speed['cases']), 10)
        for row in speed['cases']:
            self.assertIn(row['name'], fixtures[file]['cases'])
            self.assertEqual(fixtures[file]['case_costs'][row['name']], math.ceil(row['after_seconds'] * speed['estimate_factor']) + 1)
            evidence['case_seconds'][row['name']] = row['after_seconds']
        result = units.plan([file], budget, fixtures, defaults)
        for name in evidence['single_refresh_cases']:
            self.assertIn(name, fixtures[file]['cases'])
            self.assertLessEqual(fixtures[file]['case_costs'][name] + budget['slice_startup_seconds'], 90, name)
        self.assertFalse(result.get('capacity'))
        self.assertLessEqual(len(result['shards']), budget['pr_shards'])
        seen = []
        for shard in result['shards']:
            loads = [0.] * budget['shard_workers']
            for unit in shard['units']:
                cases = units.case_names(unit, fixtures)
                self.assertEqual(len(cases), 1, unit)
                seen.extend(cases)
                # Later main cases retain their own table estimates; replay measured split cases from evidence.
                seconds = sum(math.ceil(evidence['case_seconds'][name] * evidence['estimate_factor']) + 1
                              if name in evidence['case_seconds'] else fixtures[file]['case_costs'][name] for name in cases)
                heapq.heapreplace(loads, loads[0] + seconds + budget['slice_startup_seconds'])
            self.assertLess(max(loads), budget['shard_deadline_seconds'], shard['name'])
        self.assertCountEqual(seen, fixtures[file]['cases'])

    def test_reboot_slices_cover_every_case_with_measured_headroom(self):
        budget, fixtures, defaults = units.configuration()
        file = 'swamp/tests/fleet_reboot_test.ts'
        evidence = json.loads((ROOT / 'tools/tests/ci-reboot-cost-evidence.json').read_text())
        self.assertCountEqual(evidence['case_seconds'], fixtures[file]['cases'])
        result = units.plan([file], budget, fixtures, defaults)
        self.assertFalse(result.get('capacity'))
        selected = [unit for shard in result['shards'] for unit in shard['units']
                    if unit.split('#')[0] == file]
        self.assertEqual(len(selected), evidence['slices'])
        seen = []
        for unit in selected:
            cases = units.case_names(unit, fixtures)
            seen.extend(cases)
            measured = sum(evidence['case_seconds'][name] for name in cases)
            estimate = math.ceil(measured * evidence['estimate_factor']) + 1
            declared = fixtures[file]['cost'] / evidence['slices']
            self.assertGreaterEqual(declared, estimate)
            self.assertLessEqual(declared + budget['slice_startup_seconds'], 90)
        self.assertCountEqual(seen, fixtures[file]['cases'])

    def test_image_cases_all_remain_required_and_have_ninety_second_planning_headroom(self):
        budget, fixtures, defaults = units.configuration()
        file = 'swamp/tests/ansible_instance_host_image_test.ts'
        result = units.plan([file], budget, fixtures, defaults)
        self.assertFalse(result.get('capacity'))
        seen = []
        for shard in result['shards']:
            for unit in shard['units']:
                if unit.split('#')[0] != file:
                    continue
                cases = units.case_names(unit, fixtures)
                self.assertEqual(len(cases), 1)
                self.assertLessEqual(fixtures[file]['case_costs'][cases[0]] + budget['slice_startup_seconds'], 90)
                seen.extend(cases)
        self.assertCountEqual(seen, fixtures[file]['cases'])

    def test_container_host_prepare_packs_completed_case_costs_individually(self):
        budget, fixtures, defaults = units.configuration()
        file = 'swamp/tests/ansible_task_container_host_prepare_test.ts'
        fixture = fixtures[file]
        planned_units = units.units_for(fixture, budget)
        self.assertEqual(len(planned_units), len(fixture['cases']))
        for name, cost in planned_units:
            cases = units.case_names(name, fixtures)
            self.assertEqual(len(cases), 1)
            self.assertEqual(cost, fixture['case_costs'][cases[0]] + budget['slice_startup_seconds'])
        result = units.plan(['swamp/ansible/tasks/task-container-host-prepare.yml'], budget, fixtures, defaults)
        self.assertTrue(any(u.startswith(file) for s in result['shards'] for u in s['units']))
        self.assertNotIn('capacity', result)

    def test_an_oversize_single_case_is_named_and_blocks_without_becoming_an_exclusion(self):
        f = fixture('swamp/large.ts', 180, cases=['unresolved large case'])
        result = planned(['swamp/large.ts'], [f])
        self.assertEqual(result['shards'], [])
        self.assertEqual(result['capacity'][0]['oversize'], [{'unit': 'swamp/large.ts', 'seconds': 186, 'cases': ['unresolved large case']}])
        self.assertEqual(result['capacity'][1], {'unplaced': ['swamp/large.ts']})

    def test_tool_setup_is_inside_the_shard_deadline_and_empty_runs_fail(self):
        step = units.gate.Step('shape', 'setup stand-in', [sys.executable, '-c', 'import signal; signal.pause()'])
        with patch.object(units.gate, 'Step', return_value=step), contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaisesRegex(RuntimeError, 'OVER BUDGET'):
                units.prepare(time.monotonic() + .3)
        with tempfile.TemporaryDirectory() as scratch, contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(units.run([], BUDGET, {}, Path(scratch) / 'times.json'), 1)

    def test_a_custom_tools_cache_reaches_fixture_execution_without_a_default_install(self):
        with tempfile.TemporaryDirectory() as scratch:
            tools = Path(scratch) / 'custom-tools'
            venv = tools / units.gate.venv_name()
            (venv / 'bin').mkdir(parents=True)
            (venv / '.complete').touch()
            galaxy = venv / 'bin/ansible-galaxy'
            galaxy.write_text('#!/bin/sh\nexit 0\n')
            galaxy.chmod(0o755)
            # The fixture consumes PATH from the prepared cache and checks the collection path it receives.
            file = 'swamp/custom.py'
            step = units.gate.Step('shape', file, [sys.executable, '-c',
                'import os; '
                f'assert os.environ["PATH"].split(os.pathsep)[0] == {str(venv / "bin")!r}; '
                f'assert os.environ["ANSIBLE_COLLECTIONS_PATH"] == {str(units.gate.collections_path(tools))!r}'])
            times = Path(scratch) / 'times.json'
            with patch.object(units, 'fixture_steps', return_value={file: step}), contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(units.run([file], BUDGET, {file: fixture(file)}, times, tools), 0)
            self.assertEqual(json.loads(times.read_text())[0]['status'], 'passed')

    def test_cancelled_supervisor_reaps_detached_descendants_and_returns_a_receipt(self):
        with tempfile.TemporaryDirectory() as scratch:
            pid = Path(scratch) / 'pid'
            receipt = Path(scratch) / 'receipt'
            child = f'import os,signal; os.setsid(); open({str(pid)!r}, "w").write(str(os.getpid())); signal.pause()'
            leader = f'import subprocess,sys,signal; subprocess.Popen([sys.executable,"-c",{child!r}]); signal.pause()'
            process = subprocess.Popen([sys.executable, str(ROOT / 'bin/ci-units'), '--supervise', '180', str(receipt), 'none', sys.executable, '-c', leader])
            try:
                deadline = time.monotonic() + 4
                while not pid.exists() and time.monotonic() < deadline:
                    time.sleep(.01)
                self.assertTrue(pid.exists(), 'child never acknowledged readiness')
                process.terminate()
                self.assertEqual(process.wait(timeout=4), 0)
                self.assertEqual(json.loads(receipt.read_text())['status'], 'CANCELLED')
                self.assertFalse(Path(f'/proc/{pid.read_text()}').exists())
            finally:
                if process.poll() is None:
                    process.kill()
                    process.wait()


class WorkflowTest(unittest.TestCase):
    def test_ordinary_matrix_budget_tools_artifacts_and_output(self):
        source = (ROOT / '.github/workflows/ci.yml').read_text()
        block = source.split('  fixtures:\n', 1)[1].split('\n  self-hosted-fixtures:\n', 1)[0]
        for text in ['needs: changes', "needs.changes.outputs.fixture_shards != '[]'", "'ubuntu-26.04' || fromJSON('[\"self-hosted\",\"hardimpact\",\"orbit-shard\"]')",
                     'timeout-minutes: 4', 'fail-fast: false', 'shard: ${{ fromJSON(needs.changes.outputs.fixture_shards) }}',
                     'name: Fixtures (${{ matrix.shard.name }})', 'ci-unit-times-${{ matrix.shard.name }}',
                     'bin/ci-units run', "if: steps.tools.outputs.php == 'true'", 'shape-gate-requirements.txt']:
            self.assertIn(text, block)
        self.assertIn("github.event.pull_request.head.repo.full_name != github.repository", block)
        self.assertIn("fixture_shards: ${{ steps.reuse.outputs.reuse == 'true' && '[]' || steps.units.outputs.fixture_shards }}", source)
        self.assertIn('bin/ci-units plan --base "$BASE" --head "$HEAD" --github-output "$GITHUB_OUTPUT"', source)

    def test_self_hosted_shards_run_only_for_a_branch_of_this_repository(self):
        source = (ROOT / '.github/workflows/ci.yml').read_text()
        block = source.split('\n  self-hosted-fixtures:\n', 1)[1].split('\n  proof:\n', 1)[0]
        self.assertIn("if: ${{ github.event_name == 'pull_request' && github.event.pull_request.head.repo.full_name == github.repository "
                      "&& needs.changes.outputs.self_hosted_shards != '[]' }}", block)
        for text in ('name: Self-hosted fixtures (${{ matrix.shard.name }})', 'runs-on: [self-hosted, hardimpact, orbit-shard]',
                     'timeout-minutes: 4', 'shard: ${{ fromJSON(needs.changes.outputs.self_hosted_shards) }}', 'bin/ci-units run'):
            self.assertIn(text, block)
        self.assertIn("self_hosted_shards: ${{ steps.reuse.outputs.reuse == 'true' && '[]' || steps.units.outputs.self_hosted_shards }}", source)
        for gate in ('required', 'delivery'):
            self.assertIn('self-hosted-fixtures]', source.split(f'\n  {gate}:\n', 1)[1].split('\n    runs-on:', 1)[0])
        self.assertIn("SELF_HOSTED_FIXTURES: ${{ github.event_name != 'pull_request' && 'none' || "
                      "github.event.pull_request.head.repo.full_name == github.repository && 'run' || 'fork' }}", source)
        self.assertIn('SELF_HOSTED_FIXTURES: none', source.split('\n  delivery:\n', 1)[1])

if __name__ == '__main__':
    unittest.main()
