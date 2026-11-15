"""Lost wakeups, coalesced coverage, terminal recovery and separate proof scope."""
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
q = SimpleNamespace(**runpy.run_path(str(ROOT / 'bin/ci-main-queue')))
p = SimpleNamespace(**runpy.run_path(str(ROOT / 'bin/ci-main-proof')))
A, B, C = ('a' * 40, 'b' * 40, 'c' * 40)


def claim(consumer='fixtures', mode='deferred'):
    return {'version': 1, 'attempt': 1, 'consumer': consumer, 'run_id': '91', 'base': A, 'sha': C,
            'commits': [B, C], 'covers': B + '..' + C, 'mode': mode, 'advance': True, 'context': q.CONTEXTS[consumer]}


class QueueTest(unittest.TestCase):
    def test_replaced_wakeups_still_claim_every_waiting_commit_on_newest_tree(self):
        with patch.dict(q.claim_work.__globals__, recover=Mock(), queue_range=Mock(return_value=(A, [B, C]))):
            result = q.claim_work(Mock(), 'fixtures', C, '91', full_set=True)
        self.assertEqual([B, C], result['commits'])
        self.assertEqual(C, result['sha'])
        self.assertEqual('deferred', result['mode'])  # Full set cannot preempt a nonempty queue.

    def test_full_set_only_claims_an_empty_queue_and_does_not_advance_deferred_coverage(self):
        with patch.dict(q.claim_work.__globals__, recover=Mock(), queue_range=Mock(return_value=(C, []))), patch.object(q.full, 'git', return_value=B):
            self.assertIsNone(q.claim_work(Mock(), 'fixtures', C, '91'))
            result = q.claim_work(Mock(), 'fixtures', C, '91', nightly=True)
        self.assertEqual('full', result['mode'])
        self.assertFalse(result['advance'])
        self.assertEqual([C], result['commits'])

    def test_marker_survives_artifact_expiry_and_red_answers_advance_too(self):
        github = Mock()
        github.pages.side_effect = [[], [{'context': q.marker('fixtures'), 'state': 'failure'}]]
        with patch.object(q.full, 'git', side_effect=[B, A, B + '\n' + C]):
            base, commits = q.queue_range(github, 'fixtures', C)
        self.assertEqual((B, [C]), (base, commits))

    def test_every_commit_gets_one_report_before_the_checkpoint_is_published(self):
        github = Mock(repo='hardimpactdev/orbit')
        with patch.dict(q.publish.__globals__, validate_claim=Mock()):
            q.publish(github, claim(), {'passed': True, 'problems': []}, 'report-url')
        writes = [call.args for call in github.request.call_args_list]
        self.assertEqual(['statuses/' + B, 'statuses/' + C, 'statuses/' + C], [write[1] for write in writes])
        self.assertEqual(['report-url', 'report-url'], [write[2]['target_url'] for write in writes[:2]])
        self.assertEqual(q.marker('fixtures'), writes[-1][2]['context'])

    def test_publication_failure_never_advances_the_checkpoint(self):
        github = Mock(repo='hardimpactdev/orbit')
        github.request.side_effect = [None, OSError('API down')]
        with patch.dict(q.publish.__globals__, validate_claim=Mock()), self.assertRaises(OSError):
            q.publish(github, claim(), {'passed': True}, 'report-url')
        self.assertEqual(2, github.request.call_count)
        self.assertNotIn(q.marker('fixtures'), str(github.request.call_args_list))

    def test_missing_terminal_report_is_a_red_answer_and_no_claim_is_not_an_answer(self):
        github = Mock(repo='hardimpactdev/orbit')
        github.request.return_value = {'status': 'completed', 'conclusion': 'timed_out', 'head_branch': 'main', 'event': 'push', 'path': '.github/workflows/ci-deferred.yml'}
        publish = Mock()
        with patch.dict(q.settle.__globals__, artifact=Mock(side_effect=[claim(), None]), answered=Mock(return_value=False), publish=publish):
            result = q.settle(github, '91')
        self.assertFalse(result['passed'])
        self.assertIn('NOT RUN', result['problems'][0]['reason'])
        self.assertEqual(claim(), publish.call_args.args[1])
        with patch.dict(q.settle.__globals__, artifact=Mock(return_value=None), publish=publish):
            self.assertIsNone(q.settle(github, '92'))
        self.assertEqual(1, publish.call_count)

    def test_new_attempt_cannot_reuse_the_previous_attempts_green_report(self):
        github = Mock(repo='hardimpactdev/orbit')
        github.request.return_value = dict(status='completed', conclusion='failure', run_attempt=2, head_branch='main', event='push', path='.github/workflows/ci-deferred.yml')
        old = {'claim': claim(), 'passed': True}
        with patch.dict(q.settle.__globals__, artifact=Mock(side_effect=[claim(), old]), answered=Mock(return_value=False), publish=Mock()):
            result = q.settle(github, '91')
        self.assertFalse(result['passed'])
        self.assertEqual(2, result['claim']['attempt'])

    def test_outside_or_unfinished_runs_are_never_settled(self):
        for run in [dict(status='in_progress', head_branch='main', event='push', path='.github/workflows/ci-deferred.yml'),
                    dict(status='completed', head_branch='branch', event='push', path='.github/workflows/ci-deferred.yml'),
                    dict(status='completed', head_branch='main', event='pull_request', path='.github/workflows/ci-deferred.yml'),
                    dict(status='completed', head_branch='main', event='push', path='.github/workflows/ci.yml')]:
            github = Mock()
            github.request.return_value = run
            download = Mock()
            with patch.dict(q.settle.__globals__, artifact=download):
                self.assertIsNone(q.settle(github, '91'))
            download.assert_not_called()

    def test_collector_retries_do_not_duplicate_an_answer(self):
        github = Mock()
        github.request.return_value = dict(status='completed', head_branch='main', event='push', path='.github/workflows/ci-deferred.yml')
        publish = Mock()
        with patch.dict(q.settle.__globals__, artifact=Mock(return_value=claim()), answered=Mock(return_value=True), publish=publish):
            self.assertIsNone(q.settle(github, '91'))
        publish.assert_not_called()

    def test_concurrent_prior_consumer_keeps_its_lease(self):
        github = Mock()
        github.pages.return_value = [{'workflow_run': {'id': 90}, 'expired': False}]
        github.request.return_value = {'path': '.github/workflows/ci-deferred.yml', 'status': 'in_progress'}
        with self.assertRaisesRegex(ValueError, 'still owns'):
            q.recover(github, 'fixtures', '91')


    def test_failure_journal_is_append_once_even_if_seen_index_is_lost(self):
        github = Mock(repo='hardimpactdev/orbit')
        github.pages.side_effect = [[{'id': 1, 'expired': False, 'workflow_run': {'id': 91}}], [],
                                   [{'id': 1, 'expired': False, 'workflow_run': {'id': 91}}], []]
        report = {'claim': claim(), 'passed': False, 'problems': [{'reason': 'missing shard'}]}
        with tempfile.TemporaryDirectory() as directory, patch.dict(q.journal.__globals__, artifact=Mock(return_value=report)):
            path = Path(directory) / 'failures.jsonl'
            q.journal(github, path)
            path.with_suffix('.jsonl.seen.json').unlink()
            q.journal(github, path)
            rows = [json.loads(line) for line in path.read_text().splitlines()]
        self.assertEqual(1, len(rows))
        self.assertEqual(C, rows[0]['tested_sha'])


class RangePlanTest(unittest.TestCase):
    def test_union_keeps_reverted_inputs_and_whole_file_requests(self):
        deferred = q.deferred
        api = Mock()
        api.registry.load.return_value = ({}, {})
        reads = []

        def historical(base, sha, read):
            reads.append((base, sha))
            return ({'swamp/a.ts': ['old']} if sha == B else {'swamp/b.ts': None}), {'swamp/a.ts': ['old']}, ['reverted-input']

        with patch.dict(deferred.build_range.__globals__, historical_request=historical), patch.object(deferred.full, 'api', return_value=api), \
                patch.object(deferred.full, 'build_plan', return_value={}) as build:
            result = deferred.build_range(A, C, [B, C])
        self.assertEqual([(A, B), (B, C)], reads)
        self.assertEqual({'swamp/a.ts': ['old'], 'swamp/b.ts': None}, build.call_args.kwargs['requested'])
        self.assertEqual(['reverted-input'], result['changed_paths'])
        self.assertEqual({'x': None}, deferred.union_requests([{'x': ['old']}, {'x': None}, {'x': ['new']}]))

    def test_whole_live_population_still_refuses_a_disappeared_historical_case(self):
        deferred = q.deferred
        api = Mock()
        api.registry.load.return_value = ({}, {})

        def build(_config, _inventory, collect, _api, **_kwargs):
            return collect('swamp/a.ts')

        with patch.dict(deferred.build_range.__globals__, historical_request=Mock(return_value=({'swamp/a.ts': None}, {'swamp/a.ts': ['old']}, []))), \
                patch.object(deferred.full, 'api', return_value=api), patch.object(deferred.full, 'build_plan', build), \
                patch.object(deferred.full, 'collect_current', return_value={'cases': ['new']}), \
                self.assertRaisesRegex(ValueError, 'historical deferred cases no longer registered: old'):
            deferred.build_range(A, B, [B])


    def test_real_git_history_unions_old_and_new_deferrals_even_when_input_is_reverted(self):
        deferred = q.deferred
        deferred.full.api()  # Load the current planner before moving only its data reads to the historical repository.
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)

            def git(*args):
                return subprocess.check_output(['git', '-C', directory, *args], text=True, stderr=subprocess.DEVNULL).strip()

            git('init', '-q')
            git('config', 'user.email', 'test@example.invalid')
            git('config', 'user.name', 'Test')
            table = root / '.github/ci/checks.toml'
            table.parent.mkdir(parents=True)
            table.write_text((ROOT / '.github/ci/checks.toml').read_text().split('[all-checks]')[0].replace('swamp/tests/ansible_inputs_test.py', 'swamp/tests/a_test.ts'))
            metadata = root / '.github/ci/fixtures/swamp/tests/a_test.ts.toml'
            metadata.parent.mkdir(parents=True)
            template = 'file = "swamp/tests/a_test.ts"\ncost = 10\ninputs = ["source.py"]\ncases = ["fast", "old", "new"]\nafter_merge_cases = ["{}"]\n'
            metadata.write_text(template.format('old'))
            source = root / 'source.py'
            source.write_text('original')
            fixture = root / 'swamp/tests/a_test.ts'
            fixture.parent.mkdir(parents=True)
            fixture.write_text('// fixture')
            git('add', '.')
            git('commit', '-qm', 'base')
            base = git('rev-parse', 'HEAD')
            source.write_text('temporary change')
            git('commit', '-qam', 'touch input')
            first = git('rev-parse', 'HEAD')
            source.write_text('original')
            metadata.write_text(template.format('new'))
            git('commit', '-qam', 'revert input and move exclusion')
            last = git('rev-parse', 'HEAD')
            self.assertNotIn('source.py', git('diff', '--name-only', base, last))
            with patch.dict(deferred.historical_request.__globals__, ROOT=root), patch.dict(deferred.full.git.__globals__, ROOT=root):
                before = deferred.historical_request(base, first)[0]
                after = deferred.historical_request(first, last)[0]
            self.assertEqual({'swamp/tests/a_test.ts': ['old']}, before)
            self.assertEqual({'swamp/tests/a_test.ts': ['new']}, after)
            self.assertEqual({'swamp/tests/a_test.ts': ['new', 'old']}, deferred.union_requests([before, after]))


class ProofScopeTest(unittest.TestCase):
    def receipt(self):
        return {'commit': C, 'outcome': 'passed', 'result': {'cases': [{'command': 'node:list', 'case': 'list', 'verdict': 'passed'}]}}

    def test_current_api_receipt_never_claims_range_coverage(self):
        result = p.report(claim('proof', 'newest-only'), self.receipt())
        self.assertTrue(result['passed'])
        self.assertFalse(result['range_covered'])
        self.assertEqual([B], result['not_individually_tested'])
        github = Mock(repo='hardimpactdev/orbit')
        with patch.dict(q.publish.__globals__, validate_claim=Mock()):
            q.publish(github, claim('proof', 'newest-only'), result, 'report')
        self.assertEqual(['failure', 'success'], [call.args[2]['state'] for call in github.request.call_args_list[:2]])

    def test_nightly_requires_all_eight_kinds_and_keeps_nonpassing_case(self):
        self.assertFalse(p.report(claim('proof'), self.receipt(), 'failure')['passed'])
        self.assertFalse(p.report(claim('proof', 'all'), self.receipt())['passed'])
        receipt = self.receipt()
        receipt['result']['cases'] = [{'command': kind, 'case': 'one', 'verdict': 'passed'} for kind in p.KINDS]
        self.assertTrue(p.report(claim('proof', 'all'), receipt)['passed'])
        receipt['result']['cases'][0]['verdict'] = 'unrun'
        self.assertFalse(p.report(claim('proof', 'all'), receipt)['passed'])

    def test_wrong_sha_missing_or_duplicate_receipts_are_red(self):
        for mutation in ('sha', 'missing', 'duplicate'):
            receipt = self.receipt()
            if mutation == 'sha':
                receipt['commit'] = A
            elif mutation == 'missing':
                receipt['result'] = {}
            else:
                receipt['result']['cases'] *= 2
            self.assertFalse(p.report(claim('proof'), receipt)['passed'])


class WorkflowTest(unittest.TestCase):
    def test_all_consumer_workflows_pass_actionlint(self):
        paths = [str(ROOT / '.github/workflows' / file) for file in ('ci-deferred.yml', 'ci-full-set.yml', 'ci-main-proof.yml', 'ci-main-report.yml')]
        run = subprocess.run([sys.executable, str(ROOT / 'tools/check_ci_workflow.py'), *paths], capture_output=True, text=True, timeout=90)
        self.assertEqual(0, run.returncode, run.stdout + run.stderr)

    def test_claim_is_uploaded_before_fixture_planning_and_report_before_checkpoint(self):
        import yaml
        workflow = yaml.safe_load((ROOT / '.github/workflows/ci-deferred.yml').read_text())
        jobs = workflow['jobs']
        self.assertEqual('claim', jobs['plan']['needs'])
        self.assertTrue(any(step.get('with', {}).get('name') == 'main-queue-claim' for step in jobs['claim']['steps']))
        steps = jobs['report']['steps']
        upload = next(i for i, step in enumerate(steps) if step.get('with', {}).get('name') == 'main-queue-report')
        publish = next(i for i, step in enumerate(steps) if 'ci-main-queue publish' in step.get('run', ''))
        self.assertLess(upload, publish)
        proof = yaml.safe_load((ROOT / '.github/workflows/ci-main-proof.yml').read_text())
        self.assertIn('ORBIT_MAIN_PROOF_ENABLED', proof['jobs']['claim']['if'])
        self.assertEqual({'group': 'main-copy-proof-consumer', 'cancel-in-progress': False}, proof['concurrency'])
        self.assertNotIn('pull_request', proof['on'])


if __name__ == '__main__':
    unittest.main()
