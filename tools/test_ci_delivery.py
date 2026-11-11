"""Main delivery remains strict on the merged tree; fixtures report independently."""
import copy
import importlib.machinery
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import textwrap
import unittest
from unittest.mock import Mock, patch

import yaml

ROOT = Path(__file__).resolve().parents[1]
loader = importlib.machinery.SourceFileLoader('delivery', str(ROOT / 'bin/ci-delivery'))
spec = importlib.util.spec_from_loader(loader.name, loader)
delivery = importlib.util.module_from_spec(spec)
loader.exec_module(delivery)
CI = (ROOT / '.github/workflows/ci.yml').read_text()
JOBS = yaml.safe_load(CI)['jobs']
SHA = 'a' * 40
REPO = 'hardimpactdev/orbit'
RUN = dict(id=42, workflow_id=7, path=delivery.WORKFLOW, head_sha=SHA, head_branch='main',
           event='push', repository={'full_name': REPO}, head_repository={'full_name': REPO},
           html_url=f'https://github.com/{REPO}/actions/runs/42', conclusion='failure')
JOB = dict(id=43, name=delivery.DELIVERY, run_attempt=1, head_sha=SHA,
           status='completed', conclusion='success', html_url=RUN['html_url'] + '/job/43')


def needs_for(name='delivery'):
    needs = {name: {'result': 'success'} for name in JOBS[name]['needs']}
    selected = {name: True for name in needs if name not in ('changes', 'gateway-release', 'fixtures')}
    needs['changes']['outputs'] = dict(jobs=json.dumps(selected), gateway_release='true',
                                      fixture_shards='[{"name":"01"}]', fixture_capacity='[{"file":"overfull"}]')
    return needs


class DeliveryGateTest(unittest.TestCase):
    def run_gate(self, needs):
        block = CI.split('  delivery:\n', 1)[1].split('\n  main-required:', 1)[0]
        code = textwrap.dedent(block.split("          python3 - <<'PYTHON'\n", 1)[1].split('          PYTHON', 1)[0])
        with patch.dict(os.environ, NEEDS=json.dumps(needs), PROOF_REQUIRED='false',
                        PROOF_BLOCKING='false', SLOW_CHECKS_BLOCKING='false'):
            exec(code, {})

    def test_main_has_no_fixture_or_required_or_fullset_dependency_and_accepts_capacity(self):
        self.assertEqual(set(JOBS['delivery']['needs']), set(JOBS['required']['needs']) - {'fixtures'})
        self.assertNotIn('fixtures', JOBS['delivery']['needs'])
        self.assertNotIn('required', JOBS['delivery']['needs'])
        self.assertIn("github.ref == 'refs/heads/main'", JOBS['delivery']['if'])
        self.assertIn('workflow_dispatch', JOBS['delivery']['if'])
        self.assertEqual(JOBS['cli-release']['needs'], 'delivery')
        self.assertIn('workflow_dispatch', JOBS['cli-release']['if'])
        self.assertEqual(JOBS['main-required']['needs'], 'delivery')
        self.assertIn("needs.delivery.result == 'success'", JOBS['main-required']['if'])
        gateway_verify = JOBS['main-required']['steps'][-1]
        self.assertEqual(gateway_verify['env']['SOURCE_RUN_ID'], '${{ github.run_id }}')
        self.assertEqual(gateway_verify['run'], 'python3 bin/ci-delivery verify --sha "$GITHUB_SHA"')
        self.assertIn("github.ref != 'refs/heads/main'", JOBS['required']['if'])
        self.assertEqual(JOBS['required']['name'], "${{ github.ref == 'refs/heads/main' && 'PR required checks' || 'Required checks' }}")
        self.assertEqual(JOBS['main-required']['name'], "${{ github.ref == 'refs/heads/main' && 'Required checks' || 'Main required checks' }}")
        # Main has real fixture jobs, but no fixture result is passed to the delivery gate.
        self.run_gate(needs_for())

    def test_every_selected_fast_job_must_pass_even_when_fixtures_are_red(self):
        for job in JOBS['delivery']['needs']:
            for state in ('failure', 'cancelled', 'skipped', 'pending'):
                needs = needs_for()
                needs[job]['result'] = state
                with self.subTest(job=job, state=state), self.assertRaises(SystemExit):
                    self.run_gate(needs)

    def test_only_explicit_false_permits_skips_and_selections_are_complete_booleans(self):
        for job in ('cli', 'gateway-tests', 'cli-tests', 'gateway-static', 'api-reference'):
            for value in (False, None, 'false', 0):
                needs = needs_for()
                needs[job]['result'] = 'skipped'
                selections = json.loads(needs['changes']['outputs']['jobs'])
                if value is None:
                    selections.pop(job)
                else:
                    selections[job] = value
                needs['changes']['outputs']['jobs'] = json.dumps(selections)
                with self.subTest(job=job, selection=value):
                    if value is False:
                        self.run_gate(needs)
                    else:
                        with self.assertRaises(SystemExit):
                            self.run_gate(needs)


class EvidenceTest(unittest.TestCase):
    def github(self, run=None, jobs=None, runs=None):
        github = Mock(repository=REPO)
        run = RUN if run is None else run
        github.one.side_effect = lambda path: {'id': 7, 'path': delivery.WORKFLOW} if path == 'actions/workflows/ci.yml' else run
        github.request.return_value = [{'workflow_runs': [run] if runs is None else runs}]
        github.jobs.return_value = {delivery.DELIVERY: JOB} if jobs is None else jobs
        return github

    def test_failed_workflow_run_can_release_only_after_exact_main_delivery_passes(self):
        github = self.github(jobs={delivery.DELIVERY: JOB, 'Fixtures (01)': JOB | dict(conclusion='failure'),
                                   delivery.REPORT: JOB | dict(status='in_progress', conclusion=None)})
        self.assertEqual(delivery.delivery_evidence(github, SHA, '42')[0], RUN)
        source = (ROOT / '.github/workflows/orbit-cli-release.yml').read_text()
        condition = source.split('    if: >-\n', 1)[1].split('    runs-on:', 1)[0]
        self.assertNotIn('.conclusion', condition)
        self.assertIn("head_branch == 'main'", condition)
        self.assertIn("head_repository.full_name == github.repository", condition)
        self.assertIn('SOURCE_RUN_ID: ${{ github.event.workflow_run.id }}', source)
        self.assertIn('python3 bin/ci-delivery verify --sha "$COMMIT"', source)
        self.assertNotIn('check_name=Required', source)

    def test_wrong_sha_branch_workflow_event_or_repository_cannot_imitate_delivery(self):
        mutations = [dict(head_sha='b' * 40), dict(head_branch='topic'), dict(event='pull_request'),
                     dict(event='pull_request_target'), dict(workflow_id=8), dict(path='.github/workflows/fake.yml'),
                     dict(repository={'full_name': 'fork/orbit'}), dict(head_repository={'full_name': 'fork/orbit'})]
        for fields in mutations:
            for source_run in ('42', None):
                with self.subTest(fields=fields, source=source_run), self.assertRaises(ValueError):
                    delivery.delivery_evidence(self.github(run=RUN | fields), SHA, source_run)

    def test_jobs_must_be_exact_completed_success_and_old_required_never_substitutes(self):
        for job in (None, JOB | dict(head_sha='b' * 40), JOB | dict(status='in_progress'),
                    JOB | dict(conclusion='failure'), JOB | dict(conclusion='skipped'), JOB | dict(conclusion='cancelled')):
            jobs = {'Required checks': JOB} if job is None else {delivery.DELIVERY: job}
            with self.subTest(job=job), self.assertRaisesRegex(ValueError, 'old Required checks cannot substitute'):
                delivery.delivery_evidence(self.github(jobs=jobs), SHA)

    def test_latest_trusted_run_and_latest_job_attempt_win(self):
        github = self.github(runs=[RUN, RUN | dict(id=44)])
        delivery.delivery_evidence(github, SHA)
        github.jobs.assert_called_once_with(44)
        pending = JOB | dict(id=45, run_attempt=2, status='in_progress', conclusion=None)
        latest = delivery.latest_jobs([pending, JOB])
        self.assertEqual(latest[delivery.DELIVERY], pending)
        with self.assertRaises(ValueError):
            delivery.delivery_evidence(self.github(jobs=latest), SHA)

    def test_older_workflow_run_cannot_bypass_a_newer_ci_run_on_the_same_sha(self):
        github = self.github(runs=[RUN, RUN | dict(id=44)])
        with self.assertRaisesRegex(ValueError, 'superseded'):
            delivery.delivery_evidence(github, SHA, '42')
        github.jobs.assert_not_called()

    def test_summary_links_source_and_pending_or_red_report(self):
        summary = delivery.release_summary(RUN, {})
        self.assertIn(RUN['html_url'], summary)
        self.assertIn('Main fixture report: pending', summary)
        report = JOB | dict(name=delivery.REPORT, conclusion='failure')
        self.assertIn(f'[Main fixture report: failure]({JOB["html_url"]})',
                      delivery.release_summary(RUN, {delivery.REPORT: report}))


class FixtureReportTest(unittest.TestCase):
    def pr(self, **fields):
        return dict(number=12, merged_at='2026-11-11', merge_commit_sha=SHA,
                    base={'ref': 'main', 'repo': {'full_name': REPO}}) | fields

    def test_attribution_requires_exact_main_merged_sha_in_this_repository(self):
        prs = [self.pr(), self.pr(number=13, merge_commit_sha='b' * 40),
               self.pr(number=14, merged_at=None), self.pr(number=15, base={'ref': 'topic'}),
               self.pr(number=16, base={'ref': 'main', 'repo': {'full_name': 'fork/orbit'}})]
        self.assertEqual(delivery.causal_prs([prs, [self.pr()]], REPO, SHA), [12])

    def test_failure_cancel_capacity_and_missing_selection_are_red_with_links(self):
        for outcome, capacity, selection in [('failure', '[]', 'success'), ('cancelled', '[]', 'success'),
                                               ('success', '[{"file":"overfull"}]', 'success'),
                                               ('skipped', '[]', 'failure')]:
            needs = needs_for('fixture-report')
            needs['changes']['result'] = selection
            needs['changes']['outputs']['fixture_capacity'] = capacity
            needs['fixtures']['result'] = outcome
            job = JOB | dict(name='Fixtures (01)', conclusion=outcome)
            body, passed = delivery.fixture_summary(needs, SHA, RUN['html_url'], {job['name']: job}, [12])
            self.assertFalse(passed)
            for value in (SHA, RUN['html_url'], JOB['html_url'], outcome, '#12', 'exact merge SHA'):
                self.assertIn(value, body)

    def test_empty_matrix_skips_only_without_capacity_and_success_stays_green(self):
        needs = needs_for('fixture-report')
        needs['changes']['outputs'].update(fixture_capacity='[]', fixture_shards='[]')
        needs['fixtures']['result'] = 'skipped'
        body, passed = delivery.fixture_summary(needs, SHA, RUN['html_url'], {}, [])
        self.assertTrue(passed)
        self.assertIn('no PR comment is written', body)
        needs['changes']['outputs']['fixture_shards'] = '[{"name":"01"}]'
        self.assertFalse(delivery.fixture_summary(needs, SHA, RUN['html_url'], {}, [])[1])
        needs['fixtures']['result'] = 'success'
        self.assertTrue(delivery.fixture_summary(needs, SHA, RUN['html_url'], {}, [])[1])

    def test_report_updates_bot_comment_on_rerun_and_fails_after_writing_red_evidence(self):
        github = Mock(repository=REPO)
        github.jobs.return_value = {delivery.REPORT: JOB | dict(name=delivery.REPORT)}
        marker = f'<!-- orbit-main-fixtures:{SHA} -->'
        def request(path, data=None):
            if '/pulls?' in path:
                return [[self.pr(), self.pr(number=13, merge_commit_sha='b' * 40)]]
            if path == 'issues/12/comments?per_page=100':
                return [[dict(id=100, body=marker, user={'login': 'github-actions[bot]'}),
                         dict(id=101, body=marker, user={'login': 'contributor'})]]
            self.assertEqual(path, 'issues/comments/100')
            self.assertIn(SHA, data['body'])
            return [{}]
        github.request.side_effect = request
        with tempfile.TemporaryDirectory() as scratch:
            summary = Path(scratch) / 'summary.md'
            with patch.dict(os.environ, GITHUB_REF='refs/heads/main', GITHUB_EVENT_NAME='push',
                            GITHUB_SHA=SHA, GITHUB_RUN_ID='42', GITHUB_STEP_SUMMARY=str(summary),
                            NEEDS=json.dumps(needs_for('fixture-report'))):
                for _ in range(2):
                    with self.assertRaisesRegex(ValueError, 'Main fixtures failed'):
                        delivery.report(github)
                self.assertIn('Capacity errors', summary.read_text())
        self.assertEqual(sum(call.args[0] == 'issues/comments/100' for call in github.request.call_args_list), 2)
        self.assertFalse(any('issues/13/' in call.args[0] for call in github.request.call_args_list))

    def test_write_job_is_main_only_has_no_untrusted_code_or_release_dependents(self):
        job = JOBS['fixture-report']
        self.assertEqual(job['needs'], ['changes', 'fixtures'])
        self.assertEqual(job['timeout-minutes'], 2)
        self.assertIn("github.ref == 'refs/heads/main'", job['if'])
        self.assertEqual(job['permissions']['pull-requests'], 'write')
        self.assertNotIn('pull_request_target', CI)
        self.assertNotIn('continue-on-error', str(job))
        self.assertFalse(job['steps'][0]['with']['persist-credentials'])
        github = Mock(repository=REPO)
        with patch.dict(os.environ, GITHUB_EVENT_NAME='pull_request', GITHUB_REF='refs/pull/1/merge'):
            with self.assertRaisesRegex(ValueError, 'only in main CI'):
                delivery.report(github)
        github.request.assert_not_called()

    def test_report_api_error_is_visible_and_fails_the_job(self):
        github = Mock(repository=REPO)
        github.request.side_effect = delivery.subprocess.CalledProcessError(1, ['gh', 'api'])
        with tempfile.TemporaryDirectory() as scratch:
            summary = Path(scratch) / 'summary.md'
            with patch.dict(os.environ, GITHUB_REPOSITORY=REPO, GITHUB_REF='refs/heads/main',
                            GITHUB_EVENT_NAME='push', GITHUB_SHA=SHA, GITHUB_RUN_ID='42',
                            GITHUB_STEP_SUMMARY=str(summary)), \
                    patch.object(delivery, 'GitHub', return_value=github), \
                    patch('sys.argv', ['ci-delivery', 'report']):
                with self.assertRaises(SystemExit):
                    delivery.main()
            self.assertIn('CI report failed:', summary.read_text())

    def test_comment_writes_use_nonpaginated_post_and_patch(self):
        for path, method in (('issues/12/comments', 'POST'), ('issues/comments/100', 'PATCH')):
            with self.subTest(method=method), \
                 patch.object(delivery.subprocess, 'check_output', return_value=b'{"id": 100}') as command:
                result = delivery.GitHub(REPO).request(path, {'body': 'fixture evidence'})
                argv = command.call_args.args[0]
                self.assertNotIn('--paginate', argv)
                self.assertNotIn('--slurp', argv)
                self.assertEqual(argv, ['gh', 'api', f'repos/{REPO}/{path}',
                                        '--method', method, '--input', '-'])
                self.assertEqual(json.loads(command.call_args.kwargs['input']), {'body': 'fixture evidence'})
                self.assertLessEqual(command.call_args.kwargs['timeout'], 20)
                self.assertEqual(result, [{'id': 100}])

    def test_get_requests_keep_paginated_page_shape(self):
        with patch.object(delivery.subprocess, 'check_output', return_value=b'[[{"id": 1}], [{"id": 2}]]') as command:
            result = delivery.GitHub(REPO).request('issues/12/comments?per_page=100')
            self.assertIn('--paginate', command.call_args.args[0])
            self.assertIn('--slurp', command.call_args.args[0])
            self.assertEqual(result, [[{'id': 1}], [{'id': 2}]])

    def test_api_budget_and_each_request_are_bounded_and_errors_propagate(self):
        with patch.object(delivery.time, 'monotonic', side_effect=[0, 91]):
            github = delivery.GitHub(REPO)
            with self.assertRaisesRegex(ValueError, 'budget exceeded'):
                github.request('actions/runs/42')
        with patch.object(delivery.subprocess, 'check_output', return_value=b'[{}]') as command:
            github = delivery.GitHub(REPO)
            github.request('actions/runs/42')
            self.assertLessEqual(command.call_args.kwargs['timeout'], 20)
