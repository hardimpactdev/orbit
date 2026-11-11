"""CI trust metadata must agree with Gateway admission without starting a proof."""
import importlib.machinery
import importlib.util
import json
import os
import subprocess
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
loader = importlib.machinery.SourceFileLoader('eligibility', str(ROOT / 'bin/ci-proof-eligibility'))
spec = importlib.util.spec_from_loader(loader.name, loader)
policy = importlib.util.module_from_spec(spec)
loader.exec_module(policy)
SHA = 'a' * 40


def review(state='APPROVED', sha=SHA, id=1, login='writer'):
    return {'state': state, 'commit_id': sha, 'id': id, 'submitted_at': '2026-11-11T12:00:00Z', 'user': {'login': login}}


def pr(state='open', sha=SHA, labels=('run-proofs',)):
    return {'number': 7, 'state': state, 'head': {'sha': sha}, 'labels': [{'name': label} for label in labels]}


class EligibilityTest(unittest.TestCase):
    def check(self, *, branches=(), prs=(), reviews=(), permission=None):
        responses = [list(branches)]
        if not branches:
            responses += [list(prs)]
            if any(row['state'] == 'open' and row['head']['sha'] == SHA and row['labels'] for row in prs):
                responses += [list(reviews)]
                if permission is not None:
                    responses += [permission]
        with patch.object(subprocess, 'check_output', side_effect=[json.dumps(value).encode() for value in responses]) as api:
            result = policy.eligible(policy.GitHub('hardimpactdev/orbit'), SHA)
        return result, api

    def test_current_branch_without_label_or_review_is_eligible_in_one_call(self):
        allowed, api = self.check(branches=[{'name': 'feature', 'commit': {'sha': SHA}}])
        self.assertTrue(allowed)
        self.assertEqual(api.call_count, 1)
        self.assertIn(f'commits/{SHA}/branches-where-head', api.call_args.args[0][-1])

    def test_deleted_branch_closed_merged_stale_head_and_missing_label_skip(self):
        for prs in ([], [pr(state='closed')], [dict(pr(state='closed'), merged_at='2026-11-11')],
                    [pr(sha='b' * 40)], [pr(labels=())]):
            with self.subTest(prs=prs):
                allowed, api = self.check(prs=prs)
                self.assertFalse(allowed)
                self.assertEqual(api.call_count, 2)

    def test_stale_branch_response_is_not_a_current_head(self):
        responses = [[{'name': 'moved', 'commit': {'sha': 'b' * 40}}], []]
        with patch.object(subprocess, 'check_output', side_effect=[json.dumps(value).encode() for value in responses]):
            self.assertFalse(policy.eligible(policy.GitHub('hardimpactdev/orbit'), SHA))

    def test_exact_current_approval_requires_write_maintain_or_admin(self):
        for permission in ({'permission': 'write'}, {'permission': 'admin'}, {'permission': 'read', 'role_name': 'maintain'},
                           {'permission': 'read'}, {'permission': 'triage'}):
            with self.subTest(permission=permission):
                allowed, _ = self.check(prs=[pr()], reviews=[review()], permission=permission)
                self.assertEqual(allowed, permission.get('permission') in ('write', 'admin') or permission.get('role_name') == 'maintain')

    def test_latest_decisive_review_per_writer_counts_comments_do_not_revoke(self):
        for reviews, allowed in (([review(), review('COMMENTED', id=2)], True),
                                 ([review(), review('CHANGES_REQUESTED', id=2)], False),
                                 ([review(), review('DISMISSED', id=2)], False),
                                 ([review(sha='b' * 40)], False),
                                 ([review(id=3), review('DISMISSED', id=2)], True),
                                 ([review('CHANGES_REQUESTED', id=2), review(login='other')], True)):
            with self.subTest(reviews=reviews):
                actual, _ = self.check(prs=[pr()], reviews=reviews, permission={'permission': 'write'} if allowed else None)
                self.assertEqual(actual, allowed)
        older = review('CHANGES_REQUESTED', id=99)
        older['submitted_at'] = '2026-11-10T12:00:00Z'
        self.assertTrue(self.check(prs=[pr()], reviews=[review(), older], permission={'permission': 'write'})[0])

    def test_api_error_malformed_metadata_and_limits_fail_not_ineligible(self):
        for response in (subprocess.CalledProcessError(1, 'gh'), b'not json', b'{}', b'[null]'):
            with self.subTest(response=response), patch.object(subprocess, 'check_output', side_effect=[response] if isinstance(response, Exception) else None,
                                                             return_value=response if not isinstance(response, Exception) else None):
                with self.assertRaises((ValueError, subprocess.SubprocessError)):
                    policy.eligible(policy.GitHub('hardimpactdev/orbit'), SHA)
        with patch.object(subprocess, 'check_output', return_value=json.dumps([{}] * 100).encode()) as api:
            with self.assertRaisesRegex(ValueError, 'pagination limit'):
                policy.GitHub('hardimpactdev/orbit').rows('pulls/7/reviews')
            self.assertEqual(api.call_count, 10)
        github = policy.GitHub('hardimpactdev/orbit')
        for limit in ('time', 'requests'):
            github.deadline = time.monotonic() - 1 if limit == 'time' else time.monotonic() + 20
            github.requests = policy.MAX_REQUESTS if limit == 'requests' else 0
            with patch.object(subprocess, 'check_output') as api, self.assertRaisesRegex(ValueError, 'budget'):
                github.get('pulls')
            api.assert_not_called()
        with patch.object(subprocess, 'check_output', return_value=b'[]') as api:
            policy.GitHub('hardimpactdev/orbit').get('pulls')
            self.assertLessEqual(api.call_args.kwargs['timeout'], 5)
            self.assertNotIn('--paginate', api.call_args.args[0])

    def test_missing_collaborator_is_not_a_writer_but_other_api_errors_fail(self):
        responses = [json.dumps(value).encode() for value in [[], [pr()], [review()]]]
        for status in ('404', '401', '403', '500'):
            error = subprocess.CalledProcessError(1, 'gh', output=json.dumps({'status': status}).encode())
            with self.subTest(status=status), patch.object(subprocess, 'check_output', side_effect=[*responses, error]):
                if status == '404':
                    self.assertFalse(policy.eligible(policy.GitHub('hardimpactdev/orbit'), SHA))
                else:
                    with self.assertRaises(subprocess.CalledProcessError):
                        policy.eligible(policy.GitHub('hardimpactdev/orbit'), SHA)

    def entrypoint(self, responses, orbit_payload=None, script='ci-proof-run'):
        with tempfile.TemporaryDirectory() as tmp:
            tmp = Path(tmp)
            # Exercise actual entrypoints and process startup; each read consumes one mock API response.
            data = tmp / 'api.json'
            data.write_text(json.dumps(responses))
            gh = tmp / 'gh'
            gh.write_text('#!/usr/bin/python3\nimport json, pathlib\np=pathlib.Path(' + repr(str(data)) + ')\nr=json.loads(p.read_text())\nvalue=r.pop(0)\np.write_text(json.dumps(r))\nprint(json.dumps(value))\n')
            gh.chmod(0o755)
            orbit = tmp / 'orbit'
            marker = tmp / 'orbit-called'
            orbit.write_text('#!/bin/sh\ntouch ' + str(marker) + '\n' + ('echo ' + "'" + json.dumps(orbit_payload) + "'\nexit 1\n" if orbit_payload else 'exit 99\n'))
            orbit.chmod(0o755)
            env = {**os.environ, 'PATH': str(tmp) + ':/usr/bin:/bin', 'GITHUB_REPOSITORY': 'hardimpactdev/orbit',
                   'PROOF_SHA': SHA, 'PROOF_CHECK_ELIGIBILITY': 'true', 'GITHUB_OUTPUT': str(tmp / 'outputs'),
                   'GITHUB_STEP_SUMMARY': str(tmp / 'summary'), 'PROOF_RECEIPT': str(tmp / 'receipt')}
            start = time.monotonic()
            done = subprocess.run(['python3', '-I', str(ROOT / 'bin' / script)], env=env, capture_output=True, text=True, timeout=5)
            elapsed = time.monotonic() - start
            return done, marker.exists(), (tmp / 'outputs').read_text() if (tmp / 'outputs').exists() else '', json.loads((tmp / 'receipt').read_text()) if (tmp / 'receipt').exists() else None, elapsed

    def test_hosted_entrypoint_emits_boolean_with_bounded_mocked_process_timing(self):
        for responses, allowed in (([[{'name': 'branch', 'commit': {'sha': SHA}}]], True),
                                   ([[], [pr(labels=())]], False),
                                   ([[], [pr()], [review()], {'permission': 'write'}], True)):
            with self.subTest(allowed=allowed):
                done, called, outputs, receipt, elapsed = self.entrypoint(responses, script='ci-proof-eligibility')
                self.assertEqual(done.returncode, 0, done.stderr)
                self.assertFalse(called)
                self.assertEqual(outputs, f'eligible={str(allowed).lower()}\n')
                self.assertIsNone(receipt)
                self.assertLess(elapsed, 2)
        done, called, outputs, _, _ = self.entrypoint([{}], script='ci-proof-eligibility')
        self.assertNotEqual(done.returncode, 0)
        self.assertFalse(called)
        self.assertEqual(outputs, '')

    def test_later_review_page_can_revoke_approval(self):
        responses = [[], [pr()], [review(id=id) for id in range(1, 101)], [review('DISMISSED', id=101)]]
        with patch.object(subprocess, 'check_output', side_effect=[json.dumps(value).encode() for value in responses]) as api:
            self.assertFalse(policy.eligible(policy.GitHub('hardimpactdev/orbit'), SHA))
        self.assertEqual(api.call_count, 4)
        self.assertIn('page=2', api.call_args.args[0][-1])

    def test_ineligible_recheck_never_calls_orbit_and_records_skip(self):
        done, called, outputs, receipt, elapsed = self.entrypoint([[], []])
        self.assertEqual(done.returncode, 0, done.stderr)
        self.assertFalse(called)
        self.assertEqual(outputs, 'outcome=skipped\n')
        self.assertEqual(receipt['outcome'], 'skipped')
        self.assertIn('No case ran and no proof passed', done.stdout)
        self.assertLess(elapsed, 2)

    def test_api_failure_never_calls_orbit_or_records_skip(self):
        done, called, outputs, receipt, _ = self.entrypoint([{}])
        self.assertNotEqual(done.returncode, 0)
        self.assertFalse(called)
        self.assertEqual(outputs, '')
        self.assertIsNone(receipt)

    def test_post_recheck_no_case_admission_trust_loss_is_skipped(self):
        payload = {'status': 'failed', 'error': {'code': 'proof.commit_untrusted'}, 'result': {'outcome': 'failed', 'cases': []}}
        done, called, outputs, receipt, _ = self.entrypoint([[{'name': 'feature', 'commit': {'sha': SHA}}]], payload)
        self.assertEqual(done.returncode, 0, done.stderr)
        self.assertTrue(called)
        self.assertEqual(outputs, 'outcome=skipped\n')
        self.assertEqual(receipt['outcome'], 'skipped')
        self.assertIn('No proof passed', done.stdout)


if __name__ == '__main__':
    unittest.main()
