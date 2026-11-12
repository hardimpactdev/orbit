"""Fixture selection, bounded packing, process deadlines and the required matrix gate."""
import contextlib
import io
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
BUDGET = dict(shard_seconds=150, shard_workers=4, shard_deadline_seconds=190, shard_ceiling_seconds=240, unit_ceiling_seconds=180, slice_seconds=60, slice_startup_seconds=6, pr_shards=6, default_cost=30)


def fixture(file, cost=30, inputs=(), **options):
    return dict(file=file, cost=cost, inputs=list(inputs), **options)


def planned(paths, fixtures, defaults=(), budget=None):
    return units.plan(paths, budget or BUDGET, {f['file']: f for f in fixtures}, defaults)


def names(result):
    return [name for shard in result['shards'] for name in shard['units']]


class PlanningTest(unittest.TestCase):
    def test_largest_first_packing_fills_holes_and_is_deterministic(self):
        fixtures = [fixture(f'swamp/{name}.py', cost, ['swamp/input']) for name, cost in [('a', 40), ('b', 60), ('c', 50), ('d', 50)]]
        result = planned(['swamp/input'], fixtures)
        self.assertEqual(result['shards'], [{'name': '01', 'units': ['swamp/b.py', 'swamp/c.py', 'swamp/d.py', 'swamp/a.py']}])
        self.assertEqual(result, planned(['swamp/input'], list(reversed(fixtures))))

    def test_six_shards_have_four_bounded_lanes_and_defer_the_twenty_fifth_full_lane(self):
        fixtures = [fixture(f'swamp/{i:02d}.py', 100, ['swamp/input']) for i in range(25)]
        result = planned(['swamp/input'], fixtures)
        self.assertEqual(len(result['shards']), 6)
        self.assertTrue(all(len(shard['units']) == 4 for shard in result['shards']))
        self.assertEqual(result['deferred'], ['swamp/24.py'])
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
        step = units.unit_step(file, fixtures, {}, Path('/tmp/fixture-collections'))
        self.assertEqual(step.argv, ['python3', '-I', 'tests/ansible_instance_mail_test.py'])
        result = units.plan([file], budget, fixtures, defaults)
        self.assertIn(file, names(result))
        self.assertNotIn(file, result['deferred'])
        self.assertFalse(fixtures[file].get('after_merge'))
        self.assertLessEqual(fixtures[file]['cost'], 15)

    def test_limit_defers_names_and_keeps_direct_matches_over_shared_globs(self):
        fixtures = [fixture('swamp/direct.py', 100, ['swamp/tasks/input.yml']),
                    fixture('swamp/other.py', 100, ['swamp/**']),
                    fixture('swamp/third.py', 100, ['swamp/**'])]
        result = planned(['swamp/tasks/input.yml'], fixtures, budget={**BUDGET, 'pr_shards': 2, 'shard_workers': 1})
        self.assertEqual(len(result['shards']), 2)
        self.assertIn('swamp/direct.py', names(result))
        self.assertEqual(result['deferred'], ['swamp/third.py'])

    def test_admission_uses_table_order_and_defers_every_slice_of_a_file_together(self):
        budget = {**BUDGET, 'pr_shards': 1, 'shard_workers': 1, 'shard_seconds': 100}
        first, second = fixture('swamp/first.py', 40, ['swamp/input']), fixture('swamp/second.py', 70, ['swamp/input'])
        self.assertEqual(names(planned(['swamp/input'], [first, second], budget=budget)), ['swamp/first.py'])
        self.assertEqual(names(planned(['swamp/input'], [second, first], budget=budget)), ['swamp/second.py'])
        sliced = fixture('swamp/sliced.ts', 121, ['swamp/input'], slices=5)
        result = planned(['swamp/input'], [sliced, first], budget=budget)
        self.assertEqual(names(result), ['swamp/first.py'])
        self.assertEqual(set(result['deferred']), {f'swamp/sliced.ts#{k}/5' for k in range(5)})

    def test_after_merge_is_named_when_selected_and_never_runs_in_pr_shards(self):
        result = planned(['swamp/slow.ts'], [fixture('swamp/slow.ts', 233, slices=False, after_merge=True, reason='one long case')])
        self.assertEqual(result, dict(shards=[], deferred=['swamp/slow.ts'], unmapped=[]))

    def test_unknown_swamp_path_selects_default_and_names_it(self):
        fixtures = [fixture('swamp/default.py'), fixture('swamp/specific.py', inputs=['swamp/known/**'])]
        result = planned(['swamp/new/file', 'docs/page.md'], fixtures, ['swamp/default.py'])
        self.assertEqual(names(result), ['swamp/default.py'])
        self.assertEqual(result['unmapped'], ['swamp/new/file'])
        self.assertEqual(planned(['docs/page.md'], fixtures)['shards'], [])

    def test_self_support_globs_and_control_files(self):
        fixtures = [fixture('swamp/tests/a.ts', inputs=['swamp/tests/_support/helper.ts', 'swamp/ansible/tasks/a-*.yml']),
                    fixture('swamp/tests/b.py'), fixture('swamp/tests/c.ts', after_merge=True, reason='pending mapping')]
        for path in ('swamp/tests/a.ts', 'swamp/tests/_support/helper.ts', 'swamp/ansible/tasks/a-b.yml'):
            self.assertEqual(names(planned([path], fixtures)), ['swamp/tests/a.ts'])
        self.assertEqual(names(planned(['swamp/ansible/tasks/a-sub/file.yml'], fixtures)), [])
        for path in ('bin/ci-units', units.PRELOAD):
            result = planned([path], fixtures)
            self.assertEqual(set(names(result)), {'swamp/tests/a.ts', 'swamp/tests/b.py'})
            self.assertEqual(result['deferred'], ['swamp/tests/c.ts'])
        self.assertIsNotNone(units.selection_regex('swamp/**/a?.ts').fullmatch('swamp/deep/path/ab.ts'))
        self.assertIsNotNone(units.selection_regex('swamp/**/a?.ts').fullmatch('swamp/ab.ts'))

    def test_plan_summary_explains_deferral_and_unmapped_paths(self):
        text = units.plan_summary(dict(shards=[], deferred=['slow'], unmapped=['swamp/new']), {'slow': {'reason': 'one long case'}})
        self.assertIn('one long case', text)
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
        with patch.object(units, 'ROOT', self.root):
            return units.inventory_changes(base, head)

    def test_population_edit_selects_only_its_fixture_through_cli(self):
        head = self.commit({**self.initial, self.a: {'cases': ['old', 'new']}})
        fixtures = {file: fixture(file) for file in (self.a, self.b)}
        with patch.object(units, 'ROOT', self.root), \
             patch.object(units, 'configuration', return_value=(BUDGET, fixtures, [])), \
             patch.object(units.gate, 'resolve_base', return_value=self.base), \
             patch.object(units.selection, 'changed_inputs', return_value=(self.base, [
                 'tools/tests/ci-fixture-cases.json',
                 'apps/gateway/tests/Unit/Domain/TaskVms/TaskVmRefreshGateTest.php'])), \
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

    def test_fixture_cost_edit_selects_only_its_file_through_cli(self):
        head = self.commit(self.initial.replace('cost = 40', 'cost = 45'))
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

    def test_shared_budget_defaults_and_order_select_all(self):
        for text in (self.initial.replace('default_cost = 30', 'default_cost = 31'),
                     self.initial.replace('default = []', 'default = ["swamp/egress.py"]'),
                     self.initial.replace('file = "swamp/process.ts"', 'file = "TEMP"')
                     .replace('file = "swamp/egress.py"', 'file = "swamp/process.ts"')
                     .replace('file = "TEMP"', 'file = "swamp/egress.py"')):
            with self.subTest(text=text):
                changed, shared = self.changes(text)
                self.assertTrue(shared)
                fixtures = {file: fixture(file) for file in ('swamp/process.ts', 'swamp/egress.py')}
                result = units.plan(['.github/ci/checks.toml'], BUDGET, fixtures, [],
                                    registry_changed=changed, shared_registry=shared)
                self.assertCountEqual(names(result), fixtures)

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
        topic = self.commit(self.initial.replace('cost = 40', 'cost = 45'))
        self.git('checkout', '-qb', 'base', self.base)
        main = self.commit(self.initial.replace('cost = 117', 'cost = 118'))
        with contextlib.chdir(self.root), patch.object(units, 'ROOT', self.root):
            self.assertEqual(units.registry_changes(self.git('merge-base', main, topic), topic),
                             (['swamp/process.ts'], False))

    def test_egress_role_is_narrow_and_known_slow_file_is_deferred(self):
        budget, fixtures, defaults = units.configuration()
        file = 'swamp/tests/ansible_task_vm_host_egress_test.py'
        unrelated = units.plan(['swamp/ansible/tasks/instance-container-process.yml',
                                'swamp/workflows/workflow-operation-process-container.yaml'], budget, fixtures, defaults)
        self.assertNotIn(file, names(unrelated) + unrelated['deferred'])
        for path in (file, 'swamp/ansible/task-vm-host.yml'):
            result = units.plan([path], budget, fixtures, defaults)
            self.assertNotIn(file, names(result))
            self.assertIn(file, result['deferred'])
            self.assertIn('43102509039', units.plan_summary(result, fixtures))


class TableTest(unittest.TestCase):
    def test_writes_audit_is_owned_by_controls_and_never_scheduled_as_a_fixture(self):
        file = 'swamp/tests/writes_declared_test.ts'
        budget, fixtures, defaults = units.configuration()
        self.assertNotIn(file, units.fixture_steps())
        self.assertNotIn(file, fixtures)
        self.assertNotIn(file, json.loads(units.INVENTORY.read_text()))
        for path in (file, 'swamp/tests/writes-reviewed.json',
                     'swamp/workflows/workflow-operation-instance-mail-update.yaml',
                     'swamp/models/@nckrtl/orbit-gateway/orbit-gateway.yaml', 'bin/ci-units'):
            with self.subTest(path=path):
                result = units.plan([path], budget, fixtures, defaults)
                scheduled = {unit.split('#')[0] for unit in names(result) + result['deferred']}
                self.assertNotIn(file, scheduled)

    def test_table_files_costs_and_unsliceable_rules(self):
        budget, fixtures, defaults = units.configuration()
        self.assertEqual(budget, BUDGET)
        self.assertTrue(defaults)
        for f in fixtures.values():
            self.assertTrue((ROOT / f['file']).is_file(), f['file'])
            if f.get('after_merge'):
                self.assertTrue(f.get('reason', '').strip(), f['file'])
            elif not units.sliceable(f):
                self.assertLessEqual(f['cost'], budget['shard_seconds'], f['file'])
        self.assertLessEqual(set(units.fixture_steps()), set(fixtures))
        must = {str(p.relative_to(ROOT)) for p in (ROOT / 'swamp/tests').glob('*instance*') if p.is_file()}
        must.update('swamp/tests/' + name for name in ['ansible_task_container_host_prepare_test.ts',
                    'ansible_container_host_system_test.py', 'ansible_command_output_test.py'])
        self.assertLessEqual(must, set(fixtures))
        for f in fixtures.values():
            result = units.plan([f['file']], budget, fixtures, defaults)
            self.assertIn(f['file'], {u.split('#')[0] for u in names(result) + result['deferred']})

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
            path.write_text(source + '\n[[fixture]]\nfile = \"swamp/tests/_support/fixture_supervisor.py\"\ncost = 151\ninputs = []\n')
            with self.assertRaisesRegex(ValueError, 'unsliceable cost exceeds'):
                units.configuration(path)

    def test_case_exclusions_cannot_survive_a_test_rename(self):
        with tempfile.TemporaryDirectory() as scratch:
            path = Path(scratch) / 'checks.toml'
            source = (ROOT / '.github/ci/checks.toml').read_text()
            entry = next(f for f in tomllib.loads(source)['fixture']
                         if f['file'] == 'swamp/tests/ansible_instance_host_image_test.ts')
            excluded = entry['after_merge_cases'][0]
            changed = source.replace(json.dumps(excluded), json.dumps('renamed case no longer in source'))
            self.assertNotEqual(source, changed)
            source = changed
            path.write_text(source)
            with self.assertRaisesRegex(ValueError, 'every after_merge_cases name must be registered'):
                units.configuration(path)

    def test_container_dependency_closure_selects_the_affected_files(self):
        budget, fixtures, defaults = units.configuration()
        for path, expected in [
            ('swamp/workflows/workflow-operation-process-destroy.yaml', 'swamp/tests/process_destroy_failed_cache_test.ts'),
            ('swamp/ansible/schedule-logs.yml', 'swamp/tests/ansible_schedule_logs_test.ts'),
            ('swamp/ansible/library/orbit_schedule_journal.py', 'swamp/tests/ansible_schedule_logs_test.ts'),
            ('apps/gateway/app/Infrastructure/AgentView/StreamWebSocketClient.php', 'swamp/tests/ansible_schedule_logs_test.ts'),
            ('swamp/ansible/tasks/instance-migrate-retire.yml', 'swamp/tests/ansible_instance_migrate_test.ts'),
            ('swamp/ansible/instance_bridge.py', 'swamp/tests/ansible_instance_container_test.ts'),
            ('swamp/tests/_support/instance_host.ts', 'swamp/tests/ansible_instance_host_image_test.ts'),
            ('swamp/ansible/tasks/task-container-host-system.yml', 'swamp/tests/ansible_container_host_system_test.py'),
        ]:
            result = units.plan([path], budget, fixtures, defaults)
            self.assertIn(expected, [u.split('#')[0] for u in names(result) + result['deferred']])


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

    def test_case_exclusions_reach_the_preload_even_without_slicing(self):
        file = 'swamp/tests/ansible_instance_host_runtime_test.ts'
        _, fixtures, _ = units.configuration()
        step = units.unit_step(file, fixtures, units.fixture_steps(), Path('/collections'))
        self.assertIn('--preload', step.argv)
        self.assertEqual(json.loads(step.env['ORBIT_TEST_SKIP']), fixtures[file]['after_merge_cases'])
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
            self.assertIn('ceiling 0.3 s. Raise its cost in .github/ci/checks.toml (the plan adds a slice) or make it faster.', output.getvalue())
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
            # Drop exact names before assigning slice indexes: after skipping zero,
            # slice 0/2 contains one and three, while two belongs to slice 1/2.
            result = subprocess.run(['deno', 'test', '-A', '--preload', str(ROOT / units.PRELOAD), str(file)],
                                    env={**os.environ, 'ORBIT_TEST_SLICE': '0/2', 'ORBIT_TEST_SKIP': '["zero"]', 'NO_COLOR': '1'},
                                    capture_output=True, text=True, timeout=15)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertIn('one ...', result.stdout)
            self.assertIn('three ...', result.stdout)
            self.assertNotIn('zero ...', result.stdout)
            self.assertNotIn('two ...', result.stdout)
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
            ('// old-name\nDeno.test("new-name", () => { BODY; });', {'ORBIT_TEST_SKIP': '["old-name"]'}, 'Stale after_merge_cases'),
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

    def test_fixture_hashes_do_not_gate_docs_or_unrelated_inputs(self):
        with tempfile.TemporaryDirectory() as scratch:
            inventory = json.loads(units.INVENTORY.read_text())
            file = 'swamp/tests/ansible_instance_migrate_test.ts'
            inventory[file]['source_sha256'] = 'stale'
            path = Path(scratch) / 'inventory.json'
            path.write_text(json.dumps(inventory))
            with patch.object(units, 'INVENTORY', path):
                budget, fixtures, defaults = units.configuration()
                self.assertEqual(units.plan(['docs/mission.md'], budget, fixtures, defaults)['shards'], [])
                selected = units.plan(['swamp/tests/ansible_instance_container_test.ts'], budget, fixtures, defaults)
                self.assertIn('swamp/tests/ansible_instance_container_test.ts', {u.split('#')[0] for u in names(selected)})
                self.assertNotIn('capacity', selected)
                migration = units.plan([file], budget, fixtures, defaults)
                self.assertTrue(migration['deferred'])
                self.assertNotIn('capacity', migration)
                self.assertIn('12 hosted shards', units.plan_summary(migration, fixtures))

    def test_named_case_costs_and_startup_replace_the_equal_share_estimate(self):
        f = fixture('swamp/cases.ts', 140, cases=['large', 'small'], slices=2,
                    case_costs={'large': 130, 'small': 10})
        self.assertEqual(units.units_for(f, BUDGET), [('swamp/cases.ts#0/2', 136), ('swamp/cases.ts#1/2', 16)])
        f['slices'] = 100
        self.assertEqual(len(units.units_for(f, BUDGET)), 2, 'no empty slices')
        result = planned(['swamp/cases.ts'], [fixture('swamp/cases.ts', 2500, cases=[f'case-{i}' for i in range(25)], slices=25)])
        self.assertEqual(result['shards'], [])
        self.assertEqual(len(result['deferred']), 25)
        self.assertEqual(result['capacity'], [{'file': 'swamp/cases.ts', 'seconds': 2650, 'shards': 7, 'workers': 28}])

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
        self.assertEqual(result['deferred'], ['swamp/large.ts'])
        self.assertEqual(result['capacity'][0]['oversize'], [{'unit': 'swamp/large.ts', 'seconds': 186, 'cases': ['unresolved large case']}])
        self.assertNotIn('after_merge_cases', f)

    def test_tool_setup_is_inside_the_shard_deadline_and_empty_runs_fail(self):
        step = units.gate.Step('shape', 'setup stand-in', [sys.executable, '-c', 'import signal; signal.pause()'])
        with patch.object(units.gate, 'Step', return_value=step), contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaisesRegex(RuntimeError, 'OVER BUDGET'):
                units.prepare(time.monotonic() + .3)
        with tempfile.TemporaryDirectory() as scratch, contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(units.run([], BUDGET, {}, Path(scratch) / 'times.json'), 1)

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
    def test_hosted_matrix_budget_tools_artifacts_and_output(self):
        source = (ROOT / '.github/workflows/ci.yml').read_text()
        block = source.split('  fixtures:\n', 1)[1].split('\n  proof:\n', 1)[0]
        for text in ['needs: changes', "needs.changes.outputs.fixture_shards != '[]'", 'runs-on: ubuntu-26.04',
                     'timeout-minutes: 4', 'fail-fast: false', 'shard: ${{ fromJSON(needs.changes.outputs.fixture_shards) }}',
                     'name: Fixtures (${{ matrix.shard.name }})', 'ci-unit-times-${{ matrix.shard.name }}',
                     'bin/ci-units run', "if: steps.tools.outputs.php == 'true'", 'shape-gate-requirements.txt']:
            self.assertIn(text, block)
        self.assertNotIn('self-hosted', block)
        self.assertIn('fixture_shards: ${{ steps.units.outputs.fixture_shards }}', source)
        self.assertIn('bin/ci-units plan --base "$BASE" --head "$HEAD" --github-output "$GITHUB_OUTPUT"', source)

    def test_required_stays_red_when_whole_file_capacity_is_exceeded(self):
        source = (ROOT / '.github/workflows/ci.yml').read_text().split('  required:\n', 1)[1]
        code = textwrap.dedent(source.split("          python3 - <<'PYTHON'\n", 1)[1].split('          # Shared', 1)[0])
        needs = {'changes': {'result': 'success', 'outputs': {'fixture_capacity': '[{"file":"migration","shards":11}]',
                                                           'fixture_shards': '[]'}}, 'fixtures': {'result': 'success'}}
        with patch.dict(os.environ, {'NEEDS': json.dumps(needs)}):
            with self.assertRaisesRegex(SystemExit, 'Required fixture capacity exceeded.*migration'):
                exec(code, {})

    def test_required_accepts_skipped_only_with_empty_shards_and_preserves_job_selection_rules(self):
        # Exercise Required's actual inline rule before its unchanged build-selection checks.
        source = (ROOT / '.github/workflows/ci.yml').read_text().split('  required:\n', 1)[1]
        self.assertIn('changes, swamp, fixtures]', source)
        code = textwrap.dedent(source.split("          python3 - <<'PYTHON'\n", 1)[1].split('          # Shared', 1)[0])
        for shards in ([], [{'name': '01', 'units': ['a']} ]):
            for result in ('success', 'skipped', 'failure', 'cancelled'):
                needs = {'changes': {'result': 'success', 'outputs': {'fixture_shards': json.dumps(shards), 'fixture_capacity': '[]'}}, 'fixtures': {'result': result}}
                with patch.dict(os.environ, {'NEEDS': json.dumps(needs)}):
                    if result == 'success' or (result == 'skipped' and not shards):
                        scope = {}
                        exec(code, scope)
                        self.assertNotIn('fixtures', scope['needs'])
                    else:
                        with self.assertRaisesRegex(SystemExit, 'Required fixtures failed'):
                            exec(code, {})
        self.assertIn("runpy.run_path('bin/ci-delivery')['validate'](needs)", source)


if __name__ == '__main__':
    unittest.main()
