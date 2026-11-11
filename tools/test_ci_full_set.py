"""Receiver policy and API payload tests. No fixture bodies or GitHub writes."""
import io
import json
import os
from pathlib import Path
import runpy
import shutil
import subprocess
import sys
import tarfile
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[1]
full = SimpleNamespace(**runpy.run_path(str(ROOT / 'bin/ci-full-set')))
selection = SimpleNamespace(**runpy.run_path(str(ROOT / 'bin/ci-build-plan')))
# Allows local integration with the architect's read-only, not-yet-merged API.
API = Path(os.environ.get('CI_FULL_SET_TEST_API', ROOT / 'bin/ci-units'))


def sample_plan():
    return {'base': 'before', 'sha': 'tested', 'commits': ['tested', 'skipped'],
            'fixtures': {'swamp/a.ts': {'inputs': ['swamp/ansible/a.yml']}, 'swamp/b.py': {'inputs': []}},
            'units': [{'unit': 'swamp/a.ts#0/1', 'cases': ['one'], 'status': 'NOT RUN', 'declared_seconds': 100},
                      {'unit': 'swamp/b.py', 'cases': ['test_b'], 'status': 'NOT RUN', 'declared_seconds': 10}],
            'shards': [{'name': '01', 'units': ['swamp/a.ts#0/1']}, {'name': '02', 'units': ['swamp/b.py']}]}


@unittest.skipUnless(API.exists(), 'shared ci-units API is supplied by #3374')
class PlanningTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.api = full.module('receiver_test_units', API)

    def config(self, fixtures):
        return {'budget': {'default_cost': 30, 'unit_ceiling_seconds': 180, 'slice_startup_seconds': 6},
                'fixture': fixtures}

    def plan(self, fixtures, current, old=None):
        with patch.object(self.api, 'fixture_steps', return_value={f['file']: None for f in fixtures}):
            return full.build_plan(self.config(fixtures), old or {}, lambda file: {'cases': current[file]}, self.api)

    def test_every_pr_exclusion_and_after_merge_file_is_restored(self):
        files = [{'file': 'swamp/migration.ts', 'cost': 6000, 'after_merge': True,
                  'after_merge_cases': ['excluded'], 'case_costs': {'old': 60}, 'slices': 92},
                 {'file': 'swamp/container.ts', 'cost': 100, 'after_merge_cases': ['clone']},
                 {'file': 'swamp/host.py', 'cost': 167, 'after_merge': True}]
        current = {'swamp/migration.ts': ['old', 'excluded', 'new'], 'swamp/container.ts': ['retained', 'clone'], 'swamp/host.py': ['test_host']}
        result = self.plan(files, current)
        self.assertEqual(3, result['files_total'])
        self.assertEqual(6, result['cases_total'])
        for file, names in current.items():
            actual = [name for unit in result['units'] if unit['unit'].split('#')[0] == file for name in unit['cases']]
            self.assertCountEqual(actual, names)
        self.assertEqual(2, len(result['restored_pr_exclusions']))
        self.assertCountEqual(['swamp/migration.ts', 'swamp/host.py'], result['after_merge_files'])
        self.assertFalse(any(f.get('after_merge_cases') or f.get('after_merge') for f in result['fixtures'].values()))

    def test_hosted_prerequisite_deferrals_reach_full_set_with_all_cases(self):
        files = ['swamp/tests/node_worker_systemd_cleanup_test.ts',
                 'swamp/tests/fleet_reboot_unreachable_test.ts',
                 'swamp/tests/ansible_task_vm_fleet_build_test.ts']
        budget, configured, defaults = self.api.configuration()
        inventory = json.loads((ROOT / 'tools/tests/ci-fixture-cases.json').read_text())
        fixtures = [configured[file] for file in files]
        for fixture in fixtures:
            excluded = fixture['after_merge_cases']
            self.assertEqual(len(excluded), 1)
            self.assertTrue(fixture['reason'])
            pr = self.api.plan([fixture['file']], budget, configured, defaults)
            selected = [case for shard in pr['shards'] for unit in shard['units']
                        if unit.split('#')[0] == fixture['file'] for case in self.api.case_names(unit, configured)]
            self.assertCountEqual(selected, [case for case in fixture['cases'] if case not in excluded])
        current = {file: inventory[file]['cases'] for file in files}
        result = self.plan(fixtures, current)
        for file in files:
            restored = [case for unit in result['units'] if unit['unit'].split('#')[0] == file for case in unit['cases']]
            self.assertCountEqual(restored, current[file])
        self.assertEqual(len(result['restored_pr_exclusions']), 3)

    def test_changed_registration_population_uses_live_names_and_new_case_estimate(self):
        result = self.plan([{'file': 'swamp/a.ts', 'cost': 25, 'case_costs': {'old': 25}}],
                           {'swamp/a.ts': ['renamed', 'loop 1', 'loop 2']},
                           {'swamp/a.ts': {'cases': ['old'], 'source_sha256': 'stale'}})
        self.assertEqual(['renamed', 'loop 1', 'loop 2'], result['fixtures']['swamp/a.ts']['cases'])
        self.assertEqual({'renamed': 30, 'loop 1': 30, 'loop 2': 30}, result['fixtures']['swamp/a.ts']['case_costs'])
        self.assertNotIn('old', [name for u in result['units'] for name in u['cases']])

    def test_all_cases_pack_beyond_five_shards_with_four_workers(self):
        cases = [f'case {n}' for n in range(93)]
        files = [{'file': 'swamp/migration.ts', 'cost': 16740, 'after_merge': True,
                  'case_costs': {name: 180 for name in cases}}]
        result = self.plan(files, {'swamp/migration.ts': cases})
        self.assertGreater(len(result['shards']), 5)
        self.assertEqual(4, result['budget']['shard_workers'])
        admitted = [name for shard in result['shards'] for name in shard['units']]
        self.assertCountEqual(admitted, [unit['unit'] for unit in result['units']])
        self.assertCountEqual(cases, [name for unit in result['units'] for name in unit['cases']])
        self.assertEqual(540, result['budget']['unit_ceiling_seconds'])

    def test_indivisible_over_ceiling_case_is_run_instead_of_refused(self):
        result = self.plan([{'file': 'swamp/a.ts', 'cost': 1000, 'case_costs': {'slow': 1000}}], {'swamp/a.ts': ['slow']})
        self.assertEqual(1, len(result['units']))
        self.assertGreater(result['units'][0]['declared_seconds'], 540)
        self.assertEqual(1, len(result['shards']))

    def test_collector_failure_runs_an_unsliced_file_without_stale_expected_cases(self):
        with patch.object(self.api, 'fixture_steps', return_value={'swamp/a.ts': None}):
            result = full.build_plan(self.config([{'file': 'swamp/a.ts', 'cost': 10, 'after_merge_cases': ['old']}]),
                                     {'swamp/a.ts': {'cases': ['old']}}, Mock(side_effect=ValueError('import failed')), self.api)
        self.assertEqual('import failed', result['collection_errors']['swamp/a.ts'])
        self.assertNotIn('cases', result['fixtures']['swamp/a.ts'])
        self.assertEqual('swamp/a.ts', result['units'][0]['unit'])

    @unittest.skipUnless(shutil.which('deno'), 'needs Deno; receiver plan installs the pinned version')
    def test_preload_collects_loop_registration_changes_without_invoking_bodies(self):
        with tempfile.TemporaryDirectory() as scratch:
            source = Path(scratch) / 'registration_test.ts'
            source.write_text('for (const n of [1,2,3]) Deno.test(`case ${n}`, () => { throw new Error("body must not run"); });\n')
            with patch.dict(full.collect_current.__globals__, {'api': lambda: self.api, 'ROOT': API.resolve().parents[1]}):
                self.assertEqual(['case 1', 'case 2', 'case 3'], full.collect_current(str(source))['cases'])
            source.write_text(source.read_text().replace('[1,2,3]', '[1,2,3,4]'))
            with patch.dict(full.collect_current.__globals__, {'api': lambda: self.api, 'ROOT': API.resolve().parents[1]}):
                self.assertEqual(['case 1', 'case 2', 'case 3', 'case 4'], full.collect_current(str(source))['cases'])

    def test_real_unit_timeout_retains_its_name_and_next_unit_can_run(self):
        step = self.api.gate.Step('shape', 'named stalled fixture', [sys.executable, '-c', 'import signal; signal.pause()'])
        with patch('sys.stdout', io.StringIO()):
            result = self.api.run_unit(step, os.environ.copy(), .15)
            following = self.api.run_unit(self.api.gate.Step('shape', 'following fixture', [sys.executable, '-c', 'pass']), os.environ.copy(), 3)
        self.assertEqual('named stalled fixture', result['unit'])
        self.assertEqual('OVER BUDGET', result['status'])
        self.assertLess(result['seconds'], 3)
        self.assertEqual('passed', following['status'])

    @unittest.skipUnless(shutil.which('deno'), 'needs Deno; receiver plan installs the pinned version')
    def test_ignored_and_exit_zero_deno_cases_cannot_pass_the_receiver(self):
        for body in ('Deno.test({name: "ignored", ignore: true, fn() {}});', 'Deno.exit(0);'):
            with tempfile.TemporaryDirectory() as scratch, patch('sys.stdout', io.StringIO()):
                source = Path(scratch) / 'false_green_test.ts'
                source.write_text(body)
                step = self.api.gate.Step('shape', 'false green fixture',
                                          ['deno', 'test', '--no-check', '-A', '--preload', str(API.resolve().parents[1] / self.api.PRELOAD), str(source)])
                result = self.api.run_unit(step, os.environ.copy(), 5)
                self.assertEqual('FAILED', result['status'])

    def test_plan_initializes_every_file_before_collection(self):
        snapshots = []
        fixture = {'file': 'swamp/a.ts', 'cost': 10}
        def collect(file):
            self.assertEqual(file, snapshots[0]['units'][0]['unit'])
            self.assertEqual('NOT RUN', snapshots[0]['units'][0]['status'])
            return {'cases': ['one']}
        with patch.object(self.api, 'fixture_steps', return_value={}):
            full.build_plan(self.config([fixture]), {}, collect, self.api, snapshots.append)



class ResultsTest(unittest.TestCase):
    def test_empty_plan_cannot_masquerade_as_success(self):
        with tempfile.TemporaryDirectory() as scratch:
            problems, _ = full.reconcile({'shards': [], 'units': []}, Path(scratch), 'success')
        self.assertEqual('full-set plan', problems[0]['unit'])

    def test_missing_artifact_and_unrun_units_are_named(self):
        with tempfile.TemporaryDirectory() as scratch:
            problems, _ = full.reconcile(sample_plan(), Path(scratch), 'cancelled')
        self.assertTrue(any(p['unit'] == 'shard 01' and 'artifact' in p['reason'] for p in problems))
        self.assertTrue(any(p['unit'] == 'swamp/a.ts#0/1' and 'NOT RUN' in p['reason'] for p in problems))
        self.assertTrue(any('cancelled' in p['reason'] for p in problems))

    def write_results(self, directory, status='passed', seconds=1, cases=None):
        full.save(directory / 'full-set-times-01.json', [{'unit': 'swamp/a.ts#0/1', 'status': status, 'seconds': seconds,
                                                        'cases': cases if cases is not None else [{'name': 'one', 'status': 'passed'}]}])
        full.save(directory / 'full-set-times-02.json', [{'unit': 'swamp/b.py', 'status': 'passed', 'seconds': 2,
                                                        'cases': [{'name': 'test_b', 'status': 'passed'}]}])

    def test_named_timeout_and_eighty_percent_warning(self):
        with tempfile.TemporaryDirectory() as scratch:
            directory = Path(scratch)
            self.write_results(directory, 'OVER BUDGET', 540.02)
            problems, warnings = full.reconcile(sample_plan(), directory, 'failure')
        self.assertIn('OVER BUDGET', problems[0]['reason'])
        self.assertIn('540', problems[0]['reason'])
        self.assertEqual('swamp/a.ts#0/1', warnings[0]['unit'])

    def test_success_requires_every_current_case_and_no_ignored_outcome(self):
        for cases in ([], [{'name': 'stale', 'status': 'passed'}], [{'name': 'one', 'status': 'ignored'}]):
            with tempfile.TemporaryDirectory() as scratch:
                self.write_results(Path(scratch), cases=cases)
                problems, _ = full.reconcile(sample_plan(), Path(scratch), 'success')
                self.assertTrue(problems)
        with tempfile.TemporaryDirectory() as scratch:
            self.write_results(Path(scratch))
            self.assertEqual(([], []), full.reconcile(sample_plan(), Path(scratch), 'success'))

    def test_missing_one_unit_or_invalid_artifact_is_red(self):
        with tempfile.TemporaryDirectory() as scratch:
            directory = Path(scratch)
            full.save(directory / 'full-set-times-01.json', [])
            (directory / 'full-set-times-02.json').write_text('{')
            problems, _ = full.reconcile(sample_plan(), directory, 'success')
        self.assertTrue(any('missing unit' in p['reason'] for p in problems))
        self.assertTrue(any('invalid timing' in p['reason'] for p in problems))


class PrerequisitesTest(unittest.TestCase):
    def test_frankenphp_uses_recipe_url_and_digest_without_installing_on_the_host(self):
        recipe = json.loads((ROOT / 'apps/gateway/resources/machine-software/recipe.json').read_text())
        pin = recipe['instances']['php']['versions'][recipe['php']['ci']]
        def download(url, path, digest):
            self.assertEqual(pin['url'], url)
            self.assertEqual(pin['sha256'], digest)
            with tarfile.open(path, 'w:gz') as archive:
                member = tarfile.TarInfo('bin/frankenphp')
                member.size = 6
                archive.addfile(member, io.BytesIO(b'binary'))
        with tempfile.TemporaryDirectory() as scratch, patch.dict(full.frankenphp_runtime.__globals__, {'module': lambda *_: SimpleNamespace(download=download)}):
            binary = full.frankenphp_runtime(Path(scratch))
            self.assertEqual(b'binary', binary.read_bytes())
            self.assertTrue(os.access(binary, os.X_OK))
            self.assertEqual(Path(scratch), binary.parent)

    def test_missing_frankenphp_is_a_named_prerequisite_failure_before_any_fixture(self):
        plan = sample_plan()
        fixture_api = SimpleNamespace(CANCELLED=Mock(), gate=SimpleNamespace(Step=lambda *a, **kw: SimpleNamespace(name=a[1])), run=Mock())
        fixture_api.run_unit = Mock(side_effect=[{'status': 'passed'}, {'status': 'FAILED'}])
        with tempfile.TemporaryDirectory() as scratch, patch.dict(full.run_shard.__globals__, {'api': lambda: fixture_api}), patch.dict(os.environ), patch.object(full.run_shard.__globals__['signal'], 'signal'):
            output = Path(scratch) / 'times.json'
            output.with_suffix('.swamp').write_text('/scratch/pinned-swamp')
            self.assertEqual(1, full.run_shard(plan, '01', output))
            results = json.loads(output.read_text())
            self.assertEqual('NOT RUN', results[0]['status'])
            self.assertIn('pinned FrankenPHP prerequisite: FAILED', results[0]['reason'])
            fixture_api.run.assert_not_called()


class RangeTest(unittest.TestCase):
    def test_skipped_pushes_use_last_published_completion_instead_of_event_before(self):
        github = Mock()
        old = {'id': 10, 'head_sha': 'last', 'conclusion': 'success', 'updated_at': '1'}
        cancelled = dict(old, id=11, head_sha='cancelled', conclusion='cancelled', updated_at='3')
        unreported = dict(old, id=12, head_sha='unreported', updated_at='2')
        def pages(path, key):
            if 'workflows' in path:
                return [old, cancelled, unreported]
            if '/10/' in path:
                return [{'name': 'full-set-report', 'expired': False}]
            return []
        github.pages.side_effect = pages
        scope = full.covered_range.__globals__
        with patch.dict(scope, {'git': Mock(return_value='new\nskipped\nmore')}), patch.object(subprocess, 'run', return_value=SimpleNamespace(returncode=0)):
            result = full.covered_range(github, 'new', 99)
        self.assertEqual('last', result['base'])
        self.assertEqual(['new', 'skipped', 'more'], result['commits'])

    def test_initial_range_starts_before_introduction_even_if_pending_push_was_replaced(self):
        github = Mock()
        github.pages.return_value = []
        calls = []
        def git(*args):
            calls.append(args)
            return 'introduced' if args[0] == 'log' else 'introduced parent' if args[0] == 'rev-list' and '--parents' in args else 'new\nskipped\nintroduced'
        with patch.dict(full.covered_range.__globals__, {'git': git}):
            result = full.covered_range(github, 'new', 99)
        self.assertEqual('parent', result['base'])
        self.assertEqual(['new', 'skipped', 'introduced'], result['commits'])
        self.assertIn(('rev-list', '--first-parent', 'parent..new'), calls)


class ReportingTest(unittest.TestCase):
    def candidates(self):
        return {1: {'paths': ['swamp/ansible/a.yml']}, 2: {'paths': ['docs/page.md']}}

    def test_exact_failure_reason_link_sha_status_and_only_mapped_candidate_payload(self):
        problem = {'unit': 'swamp/a.ts#0/1', 'reason': 'OVER BUDGET 540.1s', 'files': ['swamp/a.ts']}
        status, comments = full.report_payloads(sample_plan(), [problem], [], self.candidates(), 'https://run', selection)
        self.assertEqual({'state': 'failure', 'context': 'Main full set', 'description': '1 failures; 0 budget warnings', 'target_url': 'https://run'}, status)
        self.assertEqual([1], [n for n, _ in comments])
        body = comments[0][1]['body']
        for part in ('OVER BUDGET 540.1s', 'https://run', '`tested`', 'not confirmed blame', '#1'):
            self.assertIn(part, body)
        self.assertNotIn('#2', body)

    def test_uncertain_failure_names_all_range_candidates_and_green_needs_no_comment(self):
        problem = {'unit': 'swamp/b.py', 'reason': 'FAILED', 'files': ['swamp/b.py']}
        _, comments = full.report_payloads(sample_plan(), [problem], [], self.candidates(), 'https://run', selection)
        self.assertEqual([1, 2], [n for n, _ in comments])
        for _, payload in comments:
            for part in ('uncertain', '#1', '#2'):
                self.assertIn(part, payload['body'])
        status, comments = full.report_payloads(sample_plan(), [], [], {}, 'https://run', selection)
        self.assertEqual('success', status['state'])
        self.assertEqual([], comments)

    def test_only_merged_prs_whose_merge_commit_is_in_covered_main_range(self):
        github = Mock()
        def pr(number, commit, merged='now', base='main'):
            return {'number': number, 'merge_commit_sha': commit, 'merged_at': merged, 'base': {'ref': base}}
        def pages(path):
            if path.endswith('/files'):
                return [{'filename': 'swamp/ansible/a.yml'}]
            return [pr(1, 'tested'), pr(2, 'outside'), pr(3, 'tested', None), pr(4, 'tested', base='other')]
        github.pages.side_effect = pages
        self.assertEqual([1], list(full.candidate_prs(github, ['tested', 'skipped'])))
        self.assertFalse(any('pulls/2/' in c.args[0] for c in github.pages.call_args_list))

    def test_attribution_read_failure_still_publishes_a_red_status(self):
        github = Mock()
        with tempfile.TemporaryDirectory() as scratch:
            directory = Path(scratch)
            ResultsTest().write_results(directory, 'FAILED')
            with patch.dict(full.report.__globals__, {'candidate_prs': Mock(side_effect=OSError('rate limited'))}), patch('sys.stdout', io.StringIO()):
                with self.assertRaisesRegex(RuntimeError, 'PR attribution failed'):
                    full.report(github, sample_plan(), directory, 'failure', 'https://run', directory / 'report.json')
            self.assertFalse((directory / 'report.json').exists())
        status = github.request.call_args_list[0]
        self.assertEqual('failure', status.args[2]['state'])
        self.assertEqual(1, len(github.request.call_args_list))

    def test_comment_write_failure_does_not_publish_a_completion_watermark(self):
        github = Mock()
        github.request.side_effect = [None, OSError('comment denied')]
        with tempfile.TemporaryDirectory() as scratch:
            directory = Path(scratch)
            ResultsTest().write_results(directory, 'FAILED')
            with patch.dict(full.report.__globals__, {'candidate_prs': lambda *_: self.candidates()}), self.assertRaisesRegex(OSError, 'comment denied'):
                full.report(github, sample_plan(), directory, 'failure', 'https://run', directory / 'report.json')
            self.assertFalse((directory / 'report.json').exists())

    def test_report_writes_real_api_payload_shapes_offline(self):
        github = Mock()
        with tempfile.TemporaryDirectory() as scratch:
            directory = Path(scratch)
            ResultsTest().write_results(directory, 'OVER BUDGET', 540)
            with patch.dict(full.report.__globals__, {'candidate_prs': lambda *_: {1: {'paths': ['swamp/ansible/a.yml']}}}), patch('sys.stdout', io.StringIO()):
                code = full.report(github, sample_plan(), directory, 'failure', 'https://run', directory / 'report.json')
            self.assertTrue(code)
            self.assertTrue((directory / 'report.json').exists())
        calls = github.request.call_args_list
        self.assertEqual(('POST', 'statuses/tested'), calls[0].args[:2])
        self.assertEqual('failure', calls[0].args[2]['state'])
        self.assertEqual(('POST', 'issues/1/comments'), calls[1].args[:2])
        self.assertIsInstance(calls[1].args[2]['body'], str)


class WorkflowTest(unittest.TestCase):
    def test_receiver_workflow_passes_pinned_actionlint(self):
        result = subprocess.run([sys.executable, str(ROOT / 'tools/check_ci_workflow.py'), str(ROOT / '.github/workflows/ci-full-set.yml')],
                                capture_output=True, text=True, timeout=90)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_main_only_pool_capacity_serialization_and_scoped_write_permissions(self):
        import yaml
        workflow = yaml.safe_load((ROOT / '.github/workflows/ci-full-set.yml').read_text())
        triggers = workflow.get('on', workflow.get(True))
        self.assertEqual({'push', 'workflow_dispatch'}, set(triggers))
        self.assertEqual(['main'], triggers['push']['branches'])
        self.assertEqual({'group': 'main-full-set', 'cancel-in-progress': False}, workflow['concurrency'])
        jobs = workflow['jobs']
        fixtures = jobs['fixtures']
        self.assertEqual(['self-hosted', 'orbit-shard', 'sabre'], fixtures['runs-on'])
        self.assertEqual(5, fixtures['strategy']['max-parallel'])
        self.assertFalse(fixtures['strategy']['fail-fast'])
        for name in ('plan', 'report'):
            self.assertIn("github.ref == 'refs/heads/main'", jobs[name]['if'])
        for name, job in jobs.items():
            writes = {key for key, value in job.get('permissions', {}).items() if value == 'write'}
            self.assertEqual({'pull-requests', 'statuses'} if name == 'report' else set(), writes)
        self.assertEqual('ubuntu-26.04', jobs['report']['runs-on'])


if __name__ == '__main__':
    unittest.main()
