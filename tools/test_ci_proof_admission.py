"""Admission and lost receipts must never acknowledge an unproved main interval."""
import copy
import json
from pathlib import Path
import runpy
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[1]
q = SimpleNamespace(**runpy.run_path(str(ROOT / 'bin/ci-main-queue')))
p = SimpleNamespace(**runpy.run_path(str(ROOT / 'bin/ci-main-proof')))
r = SimpleNamespace(**runpy.run_path(str(ROOT / 'bin/ci-proof-run')))
A, B, C, D = (letter * 40 for letter in 'abcd')


def claim():
    return dict(version=1, attempt=1, consumer='proof', run_id='91', base=A, sha=C,
                commits=[B, C], covers=B + '..' + C, mode='range', advance=True,
                context='Main copy proof', admission_attempt=1, admission_limit=3)


def receipt(outcome='passed'):
    return dict(commit=C, base=A, outcome=outcome, operation_status='succeeded' if outcome == 'passed' else 'failed',
                started_at='2026-11-15T05:00:00Z', result=dict(outcome=outcome, selected_commands=['node:list'],
                cases=[dict(command='node:list', case='list', verdict=outcome)]))


class AdmissionTest(unittest.TestCase):
    def test_unadmitted_failure_never_publishes_checkpoint_even_with_range_base(self):
        for code in ('proof.github_unavailable', 'proof.commit_untrusted', 'tasks.capacity_unavailable', 'gateway.unreachable'):
            payload = dict(id=23029, status='failed', started_at=None, error=dict(code=code, detail='admission failed'),
                           result=dict(outcome='failed', cases=[], selected_commands=[]))
            recorded = r.report(1, json.dumps(payload), C, 'key')[2]
            recorded.update(base=A, result=payload['result'])
            report = p.report(claim(), recorded, 'failure')
            self.assertFalse(report['verdict_reached'])
            self.assertFalse(report['range_covered'])
            github = Mock(repo='hardimpactdev/orbit')
            with patch.dict(q.publish.__globals__, validate_claim=Mock()):
                q.publish(github, claim(), report, 'report-url')
            contexts = [call.args[2]['context'] for call in github.request.call_args_list]
            self.assertNotIn(q.marker('proof'), contexts)
            self.assertIn(q.PROOF_REPORT, contexts)
            self.assertEqual('pending; retry on next run', report['admission_retry']['state'])

    def test_three_claims_keep_base_and_union_new_commits_then_stop(self):
        github = Mock(repo='hardimpactdev/orbit')
        statuses = []
        github.pages.side_effect = lambda path: iter(statuses)
        github.request.side_effect = lambda method, path, body: statuses.insert(0, body)
        with patch.dict(q.claim_work.__globals__, recover=Mock(), queue_range=Mock(side_effect=[(A, [B, C]), (A, [B, C, D]), (A, [B, C, D]), (A, [B, C, D])])):
            claims = [q.claim_work(github, 'proof', sha, str(run)) for run, sha in ((91, C), (92, D), (93, D))]
            with self.assertRaisesRegex(ValueError, 'retries exhausted.*' + A):
                q.claim_work(github, 'proof', D, '94')
        self.assertEqual([1, 2, 3], [row['admission_attempt'] for row in claims])
        self.assertTrue(all(row['base'] == A for row in claims))
        self.assertEqual([B, C, D], claims[-1]['commits'])
        self.assertEqual(3, github.request.call_count)
        exhausted = p.report(claims[-1], {}, 'failure')
        self.assertEqual('exhausted; operator retry required', exhausted['admission_retry']['state'])
        self.assertFalse(exhausted['verdict_reached'])
        # Explicit operator reset grants attempts, but never writes an answer marker.
        self.assertEqual(1, q.reserve_proof_attempt(github, A, '95', reset=True))
        self.assertTrue(all(call.args[2]['context'] == q.PROOF_ATTEMPTS for call in github.request.call_args_list))

    def test_completed_green_and_red_case_verdicts_advance(self):
        for outcome in ('passed', 'failed'):
            report = p.report(claim(), receipt(outcome), 'success' if outcome == 'passed' else 'failure')
            self.assertTrue(report['verdict_reached'])
            github = Mock(repo='hardimpactdev/orbit')
            with patch.dict(q.publish.__globals__, validate_claim=Mock()):
                q.publish(github, claim(), report, 'url')
            markers = [call.args[2] for call in github.request.call_args_list if call.args[2]['context'] == q.marker('proof')]
            self.assertEqual(1, len(markers))
            self.assertEqual('success' if outcome == 'passed' else 'failure', markers[0]['state'])

    def test_completed_empty_selection_is_an_answer_but_empty_failure_is_not(self):
        row = receipt()
        row.update(started_at=None)
        row['result'] = dict(outcome='nothing_to_prove', cases=[], selected_commands=[])
        self.assertTrue(p.report(claim(), row)['verdict_reached'])
        for changes in (dict(operation_status='failed'), dict(outcome='failed'), dict(operation_status=None)):
            self.assertFalse(p.report(claim(), {**row, **changes})['verdict_reached'])

    def test_real_cli_empty_selection_shape_advances_without_selected_commands(self):
        document = json.loads((ROOT / 'apps/cli/tests/Expected/proof/proof-run/nothing-to-prove.json').read_text())
        self.assertNotIn('selected_commands', document['result'])
        target = document['result']['commit']
        recorded = r.report(0, json.dumps(document), target, 'key')[2]
        recorded.update(base=A, result=document['result'])
        answer = p.report({**claim(), 'sha': target}, recorded)
        self.assertTrue(answer['passed'])
        self.assertTrue(answer['range_covered'])
        self.assertTrue(answer['verdict_reached'])

    def test_incomplete_malformed_interrupted_and_wrong_sha_evidence_stays_unproven(self):
        changes = [dict(commit=B), dict(started_at=None), dict(operation_status='running'), dict(operation_status='interrupted'),
                   dict(result=dict(outcome='failed', cases=[], selected_commands=['node:list'])),
                   dict(result=dict(outcome='failed', cases=[None], selected_commands=['node:list']))]
        row = receipt()
        for verdict in ('unrun', 'skipped'):
            change = copy.deepcopy(row['result'])
            change['cases'][0]['verdict'] = verdict
            changes.append(dict(result=change))
        for change in changes:
            self.assertFalse(p.report(claim(), {**row, **change})['verdict_reached'], change)
        row['result']['commit'] = B
        self.assertFalse(p.report(claim(), row)['verdict_reached'])
        row['result']['commit'] = C
        row['result']['cases'] *= 2
        self.assertFalse(p.report(claim(), row)['verdict_reached'])

    def test_restoring_checkpoint_uses_latest_status_not_historical_failure(self):
        github = Mock()
        github.pages.side_effect = [[dict(context=q.marker('proof'), state='pending'), dict(context=q.marker('proof'), state='failure')],
                                   [dict(context=q.marker('proof'), state='success')]]
        with patch.object(q.full, 'git', side_effect=[B, A, B + '\n' + C]):
            self.assertEqual((B, [C]), q.queue_range(github, 'proof', C))

    def test_missing_report_collector_publishes_error_without_advancing(self):
        github = Mock(repo='hardimpactdev/orbit')
        github.request.return_value = dict(status='completed', conclusion='timed_out', head_branch='main',
                                          event='push', path='.github/workflows/ci-main-proof.yml')
        with patch.dict(q.settle.__globals__, artifact=Mock(side_effect=[claim(), None]), answered=Mock(return_value=False)), \
                patch.dict(q.publish.__globals__, validate_claim=Mock()):
            result = q.settle(github, '91')
        self.assertFalse(result['passed'])
        writes = [call.args[2] for call in github.request.call_args_list if call.args[0] == 'POST']
        self.assertNotIn(q.marker('proof'), [row['context'] for row in writes])
        self.assertEqual(q.PROOF_REPORT, writes[-1]['context'])

    def test_admission_report_is_settled_once_without_needing_a_checkpoint(self):
        github = Mock(repo='hardimpactdev/orbit')
        github.pages.return_value = [dict(context=q.PROOF_REPORT, state='failure', target_url=q.link(github, '91'))]
        self.assertTrue(q.answered(github, claim()))

    def test_available_http_status_and_transport_error_are_recorded(self):
        for status in (429, 500, 502, 503):
            payload = dict(error=dict(code='gateway.request_failed', message=f'Gateway request failed with HTTP status {status}.'))
            recorded = r.report(1, json.dumps(payload), C, 'key')[2]
            self.assertEqual(status, recorded['caller']['http_status'])
            self.assertEqual(1, recorded['caller']['exit_status'])
        payload = dict(error=dict(code='gateway.unreachable', message='Could not reach the gateway.'))
        recorded = r.report(1, json.dumps(payload), C, 'key')[2]
        self.assertEqual('Could not reach the gateway.', recorded['caller']['transport_error'])
        self.assertIsNone(recorded['caller']['http_status'])
        payload = dict(error=dict(code='proof.github_unavailable', detail='GitHub could not be read.'))
        self.assertIsNone(r.report(1, json.dumps(payload), C, 'key')[2]['caller']['http_status'])


if __name__ == '__main__':
    unittest.main()
