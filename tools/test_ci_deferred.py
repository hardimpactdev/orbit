"""Exact-push deferred coverage without GitHub calls or slow fixture bodies."""
import json
import os
from pathlib import Path
import runpy
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[1]
receiver = SimpleNamespace(**runpy.run_path(str(ROOT / 'bin/ci-deferred')))
full = receiver.full
api = full.api()


class DeferredTest(unittest.TestCase):
    def test_selected_exclusions_and_partial_capacity_deferrals_cover_complement_once(self):
        fixtures = {'swamp/a.ts': {'file': 'swamp/a.ts', 'cases': ['admitted', 'capacity', 'explicit'],
                                 'after_merge_cases': ['explicit']},
                    'swamp/b.py': {'file': 'swamp/b.py', 'cases': ['test_whole']},
                    'swamp/c.ts': {'file': 'swamp/c.ts', 'cases': ['all'], 'after_merge': True}}
        pr = {'shards': [{'units': ['swamp/a.ts#0/2']}], 'deferred': ['swamp/a.ts#1/2', 'swamp/b.py', 'swamp/c.ts'],
              'excluded': [{'file': 'swamp/a.ts', 'case': 'explicit'}]}
        result = receiver.requested_cases(pr, fixtures, api)
        self.assertEqual({'swamp/a.ts': ['capacity', 'explicit'], 'swamp/b.py': None, 'swamp/c.ts': None}, result)
        self.assertEqual({}, receiver.requested_cases({'deferred': []}, fixtures, api))

    def config(self):
        return {'budget': {'default_cost': 30, 'unit_ceiling_seconds': 180, 'slice_startup_seconds': 6},
                'fixture': [{'file': 'swamp/a.ts', 'cost': 10, 'case_costs': {'admitted': 10},
                             'after_merge_cases': ['slow']},
                            {'file': 'swamp/b.py', 'cost': 20, 'after_merge': True}]}

    def plan(self, requested, collect=None):
        current = {'swamp/a.ts': {'cases': ['admitted', 'slow']}, 'swamp/b.py': {'cases': ['test_whole']}}
        with patch.object(api, 'fixture_steps', return_value={'swamp/unrelated.ts': None}):
            return full.build_plan(self.config(), current, collect or current.__getitem__, api, requested=requested)

    def test_only_requested_files_and_cases_execute_with_complete_registration_guard(self):
        result = self.plan({'swamp/a.ts': ['slow']})
        self.assertEqual(['swamp/a.ts'], list(result['fixtures']))
        self.assertEqual(['slow'], [case for unit in result['units'] for case in unit['cases']])
        self.assertEqual(1, result['cases_total'])
        fixture = result['fixtures']['swamp/a.ts']
        self.assertEqual(['admitted', 'slow'], fixture['cases'])
        self.assertEqual(['admitted'], fixture['after_merge_cases'])
        step = api.unit_step(result['units'][0]['unit'], result['fixtures'], {}, Path('/collections'))
        self.assertEqual(['admitted', 'slow'], json.loads(step.env['ORBIT_TEST_CASES']))
        self.assertEqual(['admitted'], json.loads(step.env['ORBIT_TEST_SKIP']))
        self.assertEqual(full.CEILING, result['budget']['unit_ceiling_seconds'])
        self.assertEqual(full.DEADLINE, result['budget']['shard_deadline_seconds'])
        self.assertEqual(2, result['budget']['shard_workers'])

    def test_whole_python_deferred_file_retains_every_case(self):
        plan = self.plan({'swamp/b.py': None})
        self.assertEqual(['test_whole'], [case for unit in plan['units'] for case in unit['cases']])

    def test_unknown_or_renamed_requested_case_and_failed_collection_cannot_turn_green(self):
        for request in ({'swamp/missing.ts': None}, {'swamp/a.ts': ['renamed']}, {'swamp/a.ts': []}):
            with self.subTest(request=request), self.assertRaises(ValueError):
                self.plan(request)
        def fail(_file):
            raise ValueError('broken import')
        with self.assertRaisesRegex(ValueError, 'deferred registration failed'):
            self.plan({'swamp/a.ts': ['slow']}, fail)

    def test_empty_selection_is_green_only_when_explicit_and_matrix_is_skipped(self):
        plan = {**self.plan({}), 'empty_selection': True}
        with tempfile.TemporaryDirectory() as scratch:
            directory = Path(scratch)
            self.assertEqual(([], []), full.reconcile(plan, directory, 'skipped'))
            self.assertTrue(full.reconcile(plan, directory, 'failure')[0])
            plan.pop('empty_selection')
            self.assertTrue(full.reconcile(plan, directory, 'skipped')[0])

    def test_missing_or_ignored_deferred_case_fails_named_report(self):
        plan = self.plan({'swamp/a.ts': ['slow']})
        with tempfile.TemporaryDirectory() as scratch:
            directory = Path(scratch)
            self.assertTrue(full.reconcile(plan, directory, 'success')[0])
            shard = plan['shards'][0]
            path = directory / f'full-set-times-{shard["name"]}.json'
            row = {'unit': shard['units'][0], 'status': 'passed', 'seconds': 1, 'cases': [{'name': 'slow', 'status': 'ignored'}]}
            path.write_text(json.dumps([row]))
            self.assertTrue(full.reconcile(plan, directory, 'success')[0])
            row['cases'][0]['status'] = 'passed'
            path.write_text(json.dumps([row]))
            self.assertEqual(([], []), full.reconcile(plan, directory, 'success'))

    def test_real_registry_selection_keeps_seed_and_pair_deferrals(self):
        budget, fixtures, defaults = api.configuration()
        config, inventory = api.registry.load(ROOT)
        paths = ['swamp/tests/ansible_instance_container_test.ts', full.SYSTEMD_FIXTURE]
        pr = api.plan(paths, budget, fixtures, defaults)
        wanted = receiver.requested_cases(pr, fixtures, api)
        self.assertCountEqual(fixtures[paths[0]]['after_merge_cases'], wanted[paths[0]])
        self.assertTrue(any('next- pair' in name for name in wanted[paths[0]]))
        self.assertTrue(any('failed volume snapshot' in name for name in wanted[paths[0]]))
        with patch.object(api.selection, 'changed_inputs', return_value=('base', paths)), \
                patch.object(full, 'collect_current', side_effect=lambda file: inventory[file]):
            plan = receiver.build_plan('base', 'head')
        for file, names in wanted.items():
            actual = [case for unit in plan['units'] if unit['unit'].split('#')[0] == file for case in unit['cases']]
            self.assertCountEqual(names, actual)
        self.assertEqual(len(plan['units']), len({u for s in plan['shards'] for u in s['units']}))

    def test_metadata_only_changes_select_their_deferred_fixture(self):
        file = 'swamp/tests/ansible_instance_container_test.ts'
        path = '.github/ci/fixtures/' + file + '.toml'
        _, inventory = api.registry.load(ROOT)
        with patch.object(api.selection, 'changed_inputs', return_value=('base', [path])), \
                patch.object(api, 'inventory_changes', return_value=[file]), \
                patch.object(api, 'registry_changes', return_value=([file], False)), \
                patch.object(full, 'collect_current', side_effect=lambda f: inventory[f]):
            result = receiver.build_plan('base', 'head')
        self.assertEqual([file], list(result['fixtures']))
        self.assertEqual(len(api.configuration()[1][file]['after_merge_cases']), result['cases_total'])

    def test_unrelated_commit_is_empty_and_diverged_base_is_refused(self):
        with patch.object(api.selection, 'changed_inputs', return_value=('base', ['docs/mission.md'])):
            result = receiver.build_plan('base', 'head')
        self.assertTrue(result['empty_selection'])
        self.assertEqual([], result['units'])
        with patch.object(api.selection, 'changed_inputs', return_value=('ancestor', [])), \
                self.assertRaisesRegex(ValueError, 'must be an ancestor'):
            receiver.build_plan('base', 'head')

    def test_wholly_capacity_deferred_file_includes_new_live_case_absent_from_inventory(self):
        fixtures = {'swamp/a.ts': {'file': 'swamp/a.ts', 'cases': ['admitted', 'slow']}}
        requested = receiver.requested_cases({'shards': [], 'deferred': ['swamp/a.ts']}, fixtures, api)
        plan = self.plan(requested, lambda _file: {'cases': ['admitted', 'slow', 'new live case']})
        self.assertCountEqual(['admitted', 'slow', 'new live case'], [c for u in plan['units'] for c in u['cases']])
        self.assertFalse(plan['fixtures']['swamp/a.ts'].get('after_merge_cases'))

    def test_receiver_self_coverage_preserves_required_admission_and_adds_exclusions(self):
        budget, fixtures, defaults = api.configuration()
        _, inventory = api.registry.load(ROOT)
        paths = ['bin/ci-deferred', 'swamp/ansible/tasks/instance-host.yml']
        required = api.plan(paths, budget, fixtures, defaults)
        with patch.object(api.selection, 'changed_inputs', return_value=('base', paths)), \
                patch.object(full, 'collect_current', side_effect=lambda file: inventory.get(file) or api.collect(file)):
            plan = receiver.build_plan('base', 'head')
        self.assertEqual(required, plan['required_plan'])
        requested = receiver.requested_cases(required, fixtures, api)
        for file, names in requested.items():
            actual = [c for u in plan['units'] if u['unit'].split('#')[0] == file for c in u['cases']]
            self.assertTrue(set(names or fixtures[file]['cases']).issubset(actual), file)
        for file, fixture in fixtures.items():
            if fixture.get('after_merge') or fixture.get('after_merge_cases'):
                self.assertIn(file, plan['fixtures'])

    def test_dispatch_context_cannot_overwrite_push_or_another_backfill(self):
        with patch.dict(os.environ, {'GITHUB_EVENT_NAME': 'push'}):
            push = receiver.initial_context('sha')['context']
        with patch.dict(os.environ, {'GITHUB_EVENT_NAME': 'workflow_dispatch', 'GITHUB_RUN_ID': '123'}):
            first = receiver.initial_context('sha')['context']
        with patch.dict(os.environ, {'GITHUB_EVENT_NAME': 'workflow_dispatch', 'GITHUB_RUN_ID': '456'}):
            second = receiver.initial_context('sha')['context']
        self.assertEqual(3, len({push, first, second}))

    def test_invalid_base_leaves_named_red_plan_and_report(self):
        with tempfile.TemporaryDirectory() as scratch:
            directory = Path(scratch)
            path = directory / 'plan.json'
            with patch.object(full, 'git', side_effect=subprocess.CalledProcessError(128, ['git', 'rev-parse', 'gone'])), \
                    self.assertRaises(subprocess.CalledProcessError):
                receiver.plan_push('gone', 'tested', path)
            plan = json.loads(path.read_text())
            self.assertEqual('tested', plan['sha'])
            self.assertIn('rev-parse', plan['planner_error'])
            github = Mock()
            github.pages.return_value = []
            self.assertTrue(full.report(github, plan, directory, 'skipped', 'run-link', directory / 'report.json'))
            self.assertEqual('failure', github.request.call_args.args[2]['state'])
            self.assertEqual('statuses/tested', github.request.call_args.args[1])

    def test_zero_before_uses_parent_and_missing_plan_report_never_resolves_bad_base(self):
        with tempfile.TemporaryDirectory() as scratch:
            directory = Path(scratch)
            globals_ = receiver.plan_push.__globals__
            with patch.object(full, 'git', side_effect=['parent', 'tested']), \
                    patch.dict(globals_, build_plan=lambda base, sha: {'empty_selection': True}):
                receiver.plan_push('0' * 40, 'tested', directory / 'plan.json')
                self.assertEqual(('rev-parse', 'HEAD^^{commit}'), full.git.call_args_list[0].args)
            github = Mock(repo='repo')
            github.pages.return_value = []
            with patch.object(sys, 'argv', ['ci-deferred', 'report', '--base', 'gone', '--plan', str(directory / 'missing'),
                                          '--results', scratch, '--output', str(directory / 'report.json')]), \
                    patch.object(full, 'git', return_value='tested') as git, \
                    patch.object(full, 'GitHub', return_value=github), \
                    patch.dict(os.environ, {'GITHUB_RUN_ID': '123', 'GITHUB_EVENT_NAME': 'push'}):
                self.assertTrue(receiver.main())
            git.assert_called_once_with('rev-parse', 'HEAD')
            self.assertEqual('failure', github.request.call_args.args[2]['state'])

    def test_report_context_is_separate_from_required_and_full_set(self):
        plan = {'context': receiver.CONTEXT}
        status, comments = full.report_payloads(plan, [], [], {}, 'run', api.selection)
        self.assertEqual('Main deferred fixtures', status['context'])
        self.assertEqual([], comments)

    def test_deferred_workflow_passes_pinned_actionlint(self):
        result = subprocess.run([sys.executable, str(ROOT / 'tools/check_ci_workflow.py'),
                                 str(ROOT / '.github/workflows/ci-deferred.yml')], capture_output=True, text=True, timeout=90)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_workflow_keeps_every_push_and_systemd_on_managed_runners(self):
        import yaml
        workflow = yaml.safe_load((ROOT / '.github/workflows/ci-deferred.yml').read_text())
        self.assertNotIn('concurrency', workflow)
        jobs = workflow['jobs']
        self.assertTrue(all('concurrency' not in job for job in jobs.values()))
        self.assertEqual(['main'], workflow.get('on', workflow.get(True))['push']['branches'])
        self.assertEqual(5, jobs['fixtures']['strategy']['max-parallel'])
        self.assertEqual(['self-hosted', 'orbit-shard', 'sabre'], jobs['fixtures']['runs-on'])
        self.assertIn("has_shards == 'true'", jobs['fixtures']['if'])
        self.assertEqual({'contents': 'read'}, workflow['permissions'])
        self.assertNotIn('pull-requests', jobs['plan']['permissions'])
        self.assertEqual('write', jobs['report']['permissions']['statuses'])
        for job in jobs.values():
            checkout = next(s for s in job['steps'] if s.get('uses', '').startswith('actions/checkout@'))
            self.assertEqual('${{ github.sha }}', checkout['with']['ref'])
        main = yaml.safe_load((ROOT / '.github/workflows/ci.yml').read_text())
        for job in ('required', 'delivery'):
            self.assertNotIn('deferred', str(main['jobs'][job]['needs']))


if __name__ == '__main__':
    unittest.main()
