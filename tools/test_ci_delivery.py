"""PR checks gate merges. Main delivery gates publication and release."""
import copy
import importlib.machinery
import importlib.util
import json
import os
import subprocess
from pathlib import Path
import tempfile
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


def needs_for():
    needs = {name: {'result': 'success'} for name in JOBS['required']['needs']}
    selected = {name: True for name in needs if name not in ('changes', 'ci-policy', 'gateway-release', 'fixtures', 'self-hosted-fixtures')}
    needs['changes']['outputs'] = dict(jobs=json.dumps(selected), gateway_release='true',
                                      fixture_shards='[{"name":"01"}]',
                                      fixture_capacity='[]',
                                      self_hosted_shards='[]')
    if 'self-hosted-fixtures' in needs:
        needs['self-hosted-fixtures']['result'] = 'skipped'
    return needs


class DeliveryGateTest(unittest.TestCase):
    def test_blocking_monitor_allows_two_hour_wait_and_two_hour_execution(self):
        running = dict(id=1, name='CI proof', run_attempt=1, status='in_progress', conclusion=None)
        finished = running | dict(status='completed', conclusion='success')
        with patch.dict(os.environ, PROOF_REQUIRED='true', SELF_HOSTED_FIXTURES='none',
                        FORK_PROOF='false', GH_REPO=REPO, CI_RUN_ID='42'), \
             patch.object(delivery.subprocess, 'check_output', side_effect=[json.dumps([{'jobs': [job]}]) for job in (running, finished)]) as api, \
             patch.object(delivery.time, 'monotonic', side_effect=[0, 0, 240 * 60]), \
             patch.object(delivery.time, 'sleep'):
            delivery.validate(needs_for())
        self.assertEqual(api.call_count, 2)

    def run_gate(self, needs):
        with patch.dict(os.environ, PROOF_REQUIRED='false', SELF_HOSTED_FIXTURES='none'):
            delivery.validate(needs)

    def test_main_delivery_uses_only_the_promoted_merge_queue_evidence(self):
        self.assertEqual(JOBS['delivery']['needs'], 'changes')
        self.assertIn('fixtures', JOBS['required']['needs'])
        self.assertIn('self-hosted-fixtures', JOBS['required']['needs'])
        self.assertIn("github.ref == 'refs/heads/main'", JOBS['delivery']['if'])
        self.assertIn('workflow_dispatch', JOBS['delivery']['if'])
        self.assertEqual(JOBS['cli-release']['needs'], 'main-release-inputs')
        self.assertIn('workflow_dispatch', JOBS['cli-release']['if'])
        self.assertEqual(JOBS['main-required']['needs'], 'delivery')
        self.assertIn("always()", JOBS['main-required']['if'])
        self.assertNotIn("needs.delivery.result", JOBS['main-required']['if'])
        gateway_verify = JOBS['main-required']['steps'][-1]
        self.assertEqual(gateway_verify['env']['SOURCE_RUN_ID'], '${{ github.run_id }}')
        self.assertEqual(gateway_verify['run'], 'python3 bin/ci-delivery verify --sha "$GITHUB_SHA"')
        self.assertIn("github.ref != 'refs/heads/main'", JOBS['required']['if'])
        self.assertEqual(JOBS['required']['name'], "${{ github.ref == 'refs/heads/main' && 'PR required checks' || 'Required checks' }}")
        self.assertEqual(JOBS['main-required']['name'], "${{ github.ref == 'refs/heads/main' && 'Required checks' || 'Main required checks' }}")
        self.run_gate(needs_for())

    def test_selection_and_release_jobs_must_pass(self):
        for job in ('changes', 'gateway-release'):
            for state in ('failure', 'cancelled', 'skipped', 'pending'):
                needs = needs_for()
                needs[job]['result'] = state
                with self.subTest(job=job, state=state), self.assertRaises(SystemExit):
                    self.run_gate(needs)

    def test_policy_must_pass_even_when_a_main_tree_is_reused(self):
        for reuse in ('false', 'true'):
            for status in (None, 'failure', 'cancelled', 'skipped', 'pending'):
                needs = needs_for()
                needs['changes']['outputs']['reuse'] = reuse
                if status is None:
                    needs.pop('ci-policy')
                else:
                    needs['ci-policy']['result'] = status
                with self.subTest(reuse=reuse, status=status), self.assertRaisesRegex(SystemExit, 'CI policy checks failed'):
                    self.run_gate(needs)

    def test_main_fails_on_fixture_capacity_and_skips_only_an_empty_matrix(self):
        for reuse in ('false', 'true'):
            for shards in ([], [{'name': '01'}]):
                for result in ('success', 'skipped', 'failure', 'cancelled', 'pending'):
                    needs = needs_for()
                    needs['changes']['outputs'].update(reuse=reuse, fixture_shards=json.dumps(shards))
                    needs['fixtures']['result'] = result
                    with self.subTest(reuse=reuse, shards=shards, result=result):
                        if result == 'success' or (not shards and result == 'skipped'):
                            self.run_gate(needs)
                        else:
                            with self.assertRaisesRegex(SystemExit, 'Required fixtures failed'):
                                self.run_gate(needs)
        needs = needs_for()
        needs['changes']['outputs'].update(fixture_capacity='[{"file":"overfull"}]', fixture_shards='[]')
        with self.assertRaisesRegex(SystemExit, 'Required fixture capacity exceeded.*overfull'):
            self.run_gate(needs)

    def test_main_required_runs_and_is_red_when_delivery_did_not_pass(self):
        job = JOBS['main-required']
        self.assertEqual(job['needs'], 'delivery')
        self.assertEqual(job['if'], "always() && github.ref == 'refs/heads/main' && "
                                   "contains(fromJSON('[\"push\", \"workflow_dispatch\"]'), github.event_name)")
        guard = next(step for step in job['steps'] if step.get('name') == 'Require successful main delivery')
        self.assertEqual(guard['env']['DELIVERY_RESULT'], '${{ needs.delivery.result }}')
        for result in ('success', 'failure', 'cancelled', 'skipped', 'pending', ''):
            with self.subTest(result=result):
                checked = subprocess.run(['bash', '-e', '-c', guard['run']],
                                         env=os.environ | {'DELIVERY_RESULT': result}, capture_output=True, text=True)
                self.assertEqual(checked.returncode == 0, result == 'success')
                if result != 'success':
                    self.assertIn('Main delivery checks did not pass:', checked.stdout)
        self.assertLess(job['steps'].index(guard), len(job['steps']) - 1)
        self.assertEqual(JOBS['fixtures']['timeout-minutes'], 4)
        self.assertEqual(JOBS['delivery']['timeout-minutes'], 2)
        self.assertEqual(job['timeout-minutes'], 2)

    def test_only_explicit_false_permits_skips_and_selections_are_complete_booleans(self):
        for job in json.loads(needs_for()['changes']['outputs']['jobs']):
            for value in (True, False, None, 'false', 0):
                for result in ('success', 'skipped', 'failure', 'cancelled', 'pending'):
                    needs = needs_for()
                    needs[job]['result'] = result
                    selections = json.loads(needs['changes']['outputs']['jobs'])
                    if value is None:
                        selections.pop(job)
                    else:
                        selections[job] = value
                    needs['changes']['outputs']['jobs'] = json.dumps(selections)
                    with self.subTest(job=job, selection=value, result=result):
                        if type(value) is bool and (result == 'success' or value is False and result == 'skipped'):
                            self.run_gate(needs)
                        else:
                            with self.assertRaises(SystemExit):
                                self.run_gate(needs)
        needs = needs_for()
        selections = json.loads(needs['changes']['outputs']['jobs']) | {'unexpected-job': True}
        needs['changes']['outputs']['jobs'] = json.dumps(selections)
        with self.assertRaisesRegex(SystemExit, 'Incomplete build selections'):
            self.run_gate(needs)

    def test_self_hosted_units_require_the_merge_queue_run_and_success(self):
        shards = [{'name': '01', 'units': ['swamp/tests/frankenphp_proxy_test.ts']}]
        for mode in ('fork', 'run', 'none'):  # fork is no mode: a pull request starts no shard
            for selected in ([], shards):
                for result in ('success', 'skipped', 'failure', 'cancelled', 'pending'):
                    needs = needs_for()
                    needs['changes']['outputs']['self_hosted_shards'] = json.dumps(selected)
                    needs['self-hosted-fixtures']['result'] = result
                    with self.subTest(mode=mode, selected=selected, result=result), \
                            patch.dict(os.environ, PROOF_REQUIRED='false', SELF_HOSTED_FIXTURES=mode):
                        if mode == 'fork':
                            with self.assertRaisesRegex(SystemExit, 'Unexpected self-hosted fixture state'):
                                delivery.validate(needs)
                        elif mode == 'run' and result != 'success' and not (not selected and result == 'skipped'):
                            with self.assertRaisesRegex(SystemExit, 'Required self-hosted fixtures failed'):
                                delivery.validate(needs)
                        elif mode != 'run' and result != 'skipped':
                            with self.assertRaisesRegex(SystemExit, 'Unexpected self-hosted fixture state'):
                                delivery.validate(needs)
                        else:
                            delivery.validate(needs)


class WorkflowGateTest(unittest.TestCase):
    def test_both_wrappers_pass_needs_to_the_shared_validator(self):
        for name in ('required',):
            job = JOBS[name]
            step = next(step for step in job['steps'] if 'NEEDS' in step.get('env', {}))
            code = step['run'].split("python3 - <<'PYTHON'\n", 1)[1].rsplit('PYTHON', 1)[0]
            needs = needs_for()
            with self.subTest(wrapper=name), patch.dict(os.environ, NEEDS=json.dumps(needs)), \
                    patch('runpy.run_path', return_value={'validate': Mock()}) as load:
                exec(code, {})
                load.assert_called_once_with('bin/ci-delivery')
                load.return_value['validate'].assert_called_once_with(needs)
            self.assertEqual(step['env']['NEEDS'], '${{ toJSON(needs) }}')
            self.assertIn('PROOF_REQUIRED', step['env'])
            self.assertIn('SELF_HOSTED_FIXTURES', step['env'])


class ProofGateTest(unittest.TestCase):
    def run_required(self, *, proof_required=False, fork=False, results=None, statuses=None, snapshots=None):
        needs = needs_for()
        rows = {name: {'name': name, 'status': 'completed', 'conclusion': 'success', 'id': 1, 'run_attempt': 1}
                for name in ('CI proof', 'CI proof eligibility')}
        rows['CI proof eligibility']['steps'] = []
        for name, result in (results or {}).items():
            if name in needs:
                needs[name]['result'] = result
            else:
                rows[name].update(status='in_progress' if result == 'pending' else 'completed', conclusion=result)
        snapshots = iter(snapshots or [list(rows.values())])
        def api(argv, **options):
            if '/statuses?' in argv[-1]:
                return json.dumps([statuses or []]).encode()
            self.assertEqual(argv[-1], 'repos/hardimpactdev/orbit/actions/runs/123/jobs?filter=all&per_page=100')
            self.assertIn('--paginate', argv)
            return json.dumps([{'jobs': next(snapshots)}]).encode()
        result = None
        with patch.dict(os.environ, PROOF_REQUIRED=str(proof_required).lower(), FORK_PROOF=str(fork).lower(),
                        SELF_HOSTED_FIXTURES='none', CI_RUN_ID='123', PROOF_SHA='a' * 40,
                        GH_REPO=REPO, PROOF_RUN_URL_PREFIX=f'https://github.com/{REPO}/actions/runs/'), \
                patch.object(delivery.subprocess, 'check_output', side_effect=api) as github, \
                patch.object(delivery.time, 'sleep') as sleep:
            try:
                delivery.validate(needs)
            except SystemExit as error:
                result = error.code
        return result, github, sleep

    def test_an_unselected_proof_needs_no_api_wait_and_a_selected_one_always_gates(self):
        for result in ('pending', 'failure', 'cancelled', 'skipped'):
            error, github, sleep = self.run_required(results={'CI proof': result})
            self.assertIsNone(error)
            github.assert_not_called()
            sleep.assert_not_called()
        for result in ('success', 'failure', 'cancelled', 'skipped'):
            error, _, _ = self.run_required(proof_required=True, results={'CI proof': result})
            self.assertEqual(error is None, result == 'success', error)

    def test_default_waits_for_jobs_missing_from_the_first_api_response(self):
        rows = [{'name': 'CI proof', 'status': 'completed', 'conclusion': 'success', 'id': 1, 'run_attempt': 1}]
        error, github, sleep = self.run_required(proof_required=True, snapshots=[[], rows])
        self.assertIsNone(error)
        self.assertEqual(github.call_count, 2)
        sleep.assert_called_once_with(15)

    def test_rerun_waits_for_the_newest_proof_attempt(self):
        old = {'name': 'CI proof', 'status': 'completed', 'conclusion': 'failure', 'id': 2, 'run_attempt': 1}
        new = {**old, 'status': 'in_progress', 'conclusion': None, 'id': 3, 'run_attempt': 2}
        done = {**new, 'status': 'completed', 'conclusion': 'success'}
        error, github, sleep = self.run_required(proof_required=True, snapshots=[[new, old], [done, old]])
        self.assertIsNone(error)
        self.assertEqual(github.call_count, 2)
        sleep.assert_called_once_with(15)
        error, _, sleep = self.run_required(proof_required=True, snapshots=[[done]])
        self.assertIsNone(error)
        sleep.assert_not_called()

    def test_fork_gate_requires_the_trusted_outside_proof_status(self):
        good = {'context': 'Orbit outside proof', 'state': 'success', 'creator': {'login': 'github-actions[bot]'},
                'target_url': 'https://github.com/hardimpactdev/orbit/actions/runs/123'}
        for statuses, passed in (([], False), ([good], True), ([{**good, 'state': 'pending'}, good], False),
                                 ([{**good, 'creator': {'login': 'contributor'}}], False)):
            error, _, _ = self.run_required(proof_required=True, fork=True, statuses=statuses)
            self.assertEqual(error is None, passed, error)

    def test_intentionally_ineligible_proof_skips_without_waiting_when_blocking(self):
        rows = [{'name': 'CI proof eligibility', 'status': 'completed', 'conclusion': 'success',
                 'id': 1, 'run_attempt': 1, 'steps': [{'name': 'Skip ineligible proof', 'conclusion': 'success'}]},
                {'name': 'CI proof', 'status': 'completed', 'conclusion': 'skipped', 'id': 2, 'run_attempt': 1}]
        error, github, sleep = self.run_required(proof_required=True, snapshots=[rows])
        self.assertIsNone(error)
        self.assertEqual(github.call_count, 1)
        sleep.assert_not_called()

    def test_failed_eligibility_and_unexpected_skip_fail_closed_without_waiting(self):
        for result in ('failure', 'cancelled', 'skipped'):
            error, _, sleep = self.run_required(proof_required=True, results={'CI proof eligibility': result, 'CI proof': 'skipped'})
            self.assertEqual(error, f'Proof eligibility failed: {result}')
            sleep.assert_not_called()
        error, _, sleep = self.run_required(proof_required=True, results={'CI proof': 'skipped'})
        self.assertIn('without ineligible metadata evidence', error)
        sleep.assert_not_called()

    def test_missing_or_stale_eligibility_evidence_fails_closed(self):
        proof = {'name': 'CI proof', 'status': 'completed', 'conclusion': 'skipped', 'id': 2, 'run_attempt': 2}
        eligibility = {'name': 'CI proof eligibility', 'status': 'completed', 'conclusion': 'success', 'id': 1, 'run_attempt': 1,
                       'steps': [{'name': 'Skip ineligible proof', 'conclusion': 'success'}]}
        error, _, sleep = self.run_required(proof_required=True, snapshots=[[proof, eligibility]])
        self.assertIn('same attempt', error)
        sleep.assert_not_called()
        with patch.object(delivery.time, 'monotonic', side_effect=[0, 0, 61]):
            error, _, sleep = self.run_required(proof_required=True, snapshots=[[proof]])
        self.assertEqual(error, 'Missing proof eligibility evidence')
        sleep.assert_not_called()
        eligibility['run_attempt'] = 2
        error, github, sleep = self.run_required(proof_required=True, snapshots=[[proof], [proof, eligibility]])
        self.assertIsNone(error)
        self.assertEqual(github.call_count, 2)
        sleep.assert_called_once_with(15)


class EvidenceTest(unittest.TestCase):
    def github(self, run=None, jobs=None, runs=None):
        github = Mock(repository=REPO)
        run = RUN if run is None else run
        github.one.side_effect = lambda path: {'id': 7, 'path': delivery.WORKFLOW} if path == 'actions/workflows/ci.yml' else run
        github.request.return_value = [{'workflow_runs': [run] if runs is None else runs}]
        github.jobs.return_value = {delivery.DELIVERY: JOB} if jobs is None else jobs
        return github

    def test_cli_publication_requires_exact_main_delivery(self):
        self.assertEqual(delivery.delivery_evidence(self.github(), SHA, '42')[0], RUN)
        source = (ROOT / '.github/workflows/orbit-cli-release.yml').read_text()
        resolve = yaml.safe_load(source)['jobs']['resolve']
        condition = resolve['if']
        self.assertNotIn('.conclusion', condition)
        self.assertIn("github.ref == 'refs/heads/main'", condition)
        verify = next(step for step in resolve['steps']
                      if step.get('run') == 'python3 bin/ci-delivery verify --sha "$COMMIT" --release')
        self.assertEqual(verify['env']['COMMIT'], '${{ steps.release.outputs.commit }}')
        self.assertNotIn('SOURCE_RUN_ID', verify['env'])  # Resolve the latest trusted main CI for this SHA.
        self.assertNotIn('if', verify)  # Delivery evidence is required for automatic and manual dispatches.
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

    def test_a_release_needs_the_release_inputs_of_the_same_main_run(self):
        # A later main run that passes delivery and fails Required checks skips the job: an earlier run's green one must not count.
        delivery.delivery_evidence(self.github(jobs={delivery.DELIVERY: JOB, delivery.RELEASE: JOB}), SHA, release=True)
        delivery.delivery_evidence(self.github(), SHA)
        for marker in (None, JOB | dict(conclusion='skipped'), JOB | dict(conclusion='failure'),
                       JOB | dict(status='in_progress', conclusion=None), JOB | dict(head_sha='b' * 40)):
            jobs = {delivery.DELIVERY: JOB} | ({} if marker is None else {delivery.RELEASE: marker})
            with self.subTest(marker=marker), self.assertRaisesRegex(ValueError, 'Gateway release inputs must pass in the newest main CI run'):
                delivery.delivery_evidence(self.github(jobs=jobs), SHA, release=True)

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

    def test_summary_links_source_and_delivery(self):
        summary = delivery.release_summary(RUN, {delivery.DELIVERY: JOB})
        self.assertIn(RUN['html_url'], summary)
        self.assertIn(f'[{delivery.DELIVERY}]({JOB["html_url"]})', summary)

    def test_docs_publication_requires_exact_main_delivery(self):
        docs = yaml.safe_load((ROOT / '.github/workflows/docs-site.yml').read_text())
        publish = docs['jobs']['publish']
        verify = next(step for step in publish['steps'] if step.get('name') == 'Require exact main delivery evidence')
        self.assertEqual(verify['env']['SOURCE_RUN_ID'], '${{ github.event.workflow_run.id }}')
        self.assertEqual(verify['run'], 'python3 bin/ci-delivery verify --sha "$SOURCE"')
        self.assertLess(publish['steps'].index(verify), len(publish['steps']) - 1)

    def test_get_requests_keep_paginated_page_shape(self):
        with patch.object(delivery.subprocess, 'check_output', return_value=b'[[{"id": 1}], [{"id": 2}]]') as command:
            result = delivery.GitHub(REPO).request('actions/runs/42/jobs?per_page=100')
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
