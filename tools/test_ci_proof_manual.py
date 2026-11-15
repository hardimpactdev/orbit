"""Manual proofs use trusted workflow code, repository reachability and real case receipts."""
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

import yaml

ROOT = Path(__file__).resolve().parents[1]
m = SimpleNamespace(**runpy.run_path(str(ROOT / 'bin/ci-proof-manual')))
SHA = 'a' * 40


def environment():
    return {'GITHUB_REPOSITORY': 'hardimpactdev/orbit', 'GITHUB_REF': 'refs/heads/main',
            'GITHUB_EVENT_NAME': 'workflow_dispatch', 'GITHUB_ACTOR': 'author', 'GITHUB_TRIGGERING_ACTOR': 'operator',
            'PROOF_SHA': SHA}


class AdmissionTest(unittest.TestCase):
    def test_dispatch_requires_trusted_main_and_exact_sha_before_git_or_api(self):
        for key, value in [('GITHUB_REPOSITORY', 'fork/orbit'), ('GITHUB_REF', 'refs/heads/probe'),
                           ('GITHUB_EVENT_NAME', 'push'), ('PROOF_SHA', 'main'), ('PROOF_SHA', SHA + '\n')]:
            github = Mock()
            with self.subTest(key=key), patch.object(subprocess, 'check_output') as git, self.assertRaises(ValueError):
                m.check({**environment(), key: value}, github)
            github.get.assert_not_called()
            git.assert_not_called()

    def test_original_and_rerunning_actor_both_need_repository_write_access(self):
        for refused in ('author', 'operator'):
            github = Mock()
            github.get.side_effect = lambda path, **kwargs: {'permission': 'read' if f'/{refused}/' in path else 'write'}
            with self.subTest(refused=refused), patch.object(subprocess, 'check_output') as git, self.assertRaises(ValueError):
                m.check(environment(), github)
            git.assert_not_called()

    def test_membership_api_failure_never_reaches_the_runner(self):
        github = Mock()
        github.get.side_effect = subprocess.CalledProcessError(1, ['gh', 'api'])
        with patch.object(subprocess, 'check_output') as git, self.assertRaises(subprocess.CalledProcessError):
            m.check(environment(), github)
        git.assert_not_called()

    def test_accepts_repository_branch_ancestor_and_head_but_not_a_detached_or_tag_only_commit(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            def git(*args):
                return subprocess.check_output(['git', '-C', folder, *args], text=True, stderr=subprocess.DEVNULL).strip()
            git('init')
            git('config', 'user.name', 'Fixture')
            git('config', 'user.email', 'fixture@example.test')
            git('commit', '--allow-empty', '-m', 'base')
            base = git('rev-parse', 'HEAD')
            git('commit', '--allow-empty', '-m', 'branch head')
            head = git('rev-parse', 'HEAD')
            git('update-ref', 'refs/remotes/origin/probe', head)
            git('commit', '--allow-empty', '-m', 'not on remote branch')
            detached = git('rev-parse', 'HEAD')
            git('tag', 'tag-only', detached)
            github = Mock()
            github.get.return_value = {'permission': 'write'}
            for sha in (base, head):
                self.assertEqual(sha, m.check({**environment(), 'PROOF_SHA': sha}, github, root))
            with self.assertRaisesRegex(ValueError, 'not reachable'):
                m.check({**environment(), 'PROOF_SHA': detached}, github, root)
            self.assertEqual(['collaborators/author/permission', 'collaborators/operator/permission'],
                             [call.args[0] for call in github.get.call_args_list[:2]])


class CallerTest(unittest.TestCase):
    def invoke(self, receipt, exit_code=0):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'receipt.json'
            env = {**environment(), 'PROOF_RECEIPT': str(path), 'PROOF_BASE': 'b' * 40, 'PROOF_ALL': 'true',
                   'PROOF_CHECK_ELIGIBILITY': 'true'}
            def caller():
                self.assertEqual('', os.environ['PROOF_BASE'])
                self.assertEqual('false', os.environ['PROOF_ALL'])
                self.assertEqual('false', os.environ['PROOF_CHECK_ELIGIBILITY'])
                self.assertEqual('true', os.environ['PROOF_STORE_RESULT'])
                self.assertEqual(SHA, os.environ['PROOF_SHA'])
                path.write_text(json.dumps(receipt))
                raise SystemExit(exit_code)
            with patch.dict(os.environ, {}, clear=True), patch.object(runpy, 'run_path', return_value={'main': caller}) as load:
                result = m.run(env, SHA)
            load.assert_called_once_with(str(ROOT / 'bin/ci-proof-run'))
            return result

    def test_existing_caller_keeps_signal_ownership_and_complete_receipts(self):
        self.assertEqual(0, self.invoke({'commit': SHA, 'outcome': 'passed',
                                        'result': {'outcome': 'passed', 'cases': [{'verdict': 'passed'}]}}))

    def test_trust_refusal_and_interrupted_or_failed_execution_stay_red(self):
        for code in (1, 124, 130, 143):
            with self.subTest(code=code):
                self.assertEqual(code, self.invoke({}, code))

    def test_empty_or_wrong_sha_receipt_cannot_prove_hosted_execution(self):
        for receipt in ({}, {'commit': SHA, 'outcome': 'passed', 'result': {'outcome': 'nothing_to_prove', 'cases': []}},
                        {'commit': 'b' * 40, 'outcome': 'passed', 'result': {'outcome': 'passed', 'cases': [{}]}}):
            with self.subTest(receipt=receipt), self.assertRaises(ValueError):
                self.invoke(receipt)


class WorkflowTest(unittest.TestCase):
    def test_manual_workflow_has_no_automatic_trigger_or_target_checkout_and_serializes_manual_runs(self):
        workflow = yaml.safe_load((ROOT / '.github/workflows/ci-proof-manual.yml').read_text())
        self.assertEqual({'workflow_dispatch'}, set(workflow['on']))
        self.assertTrue(workflow['on']['workflow_dispatch']['inputs']['commit']['required'])
        self.assertEqual({'group': 'manual-copy-proof', 'cancel-in-progress': False}, workflow['concurrency'])
        self.assertEqual({'contents': 'read'}, workflow['permissions'])
        jobs = workflow['jobs']
        self.assertIn("github.ref == 'refs/heads/main'", jobs['preflight']['if'])
        self.assertIn("github.repository == 'hardimpactdev/orbit'", jobs['preflight']['if'])
        self.assertEqual('preflight', jobs['proof']['needs'])
        main = yaml.safe_load((ROOT / '.github/workflows/ci-main-proof.yml').read_text())
        self.assertEqual(main['jobs']['proof']['runs-on'], jobs['proof']['runs-on'])
        for job in jobs.values():
            checkout = job['steps'][0]['with']
            self.assertEqual('${{ github.sha }}', checkout['ref'])
            self.assertEqual('hardimpactdev/orbit', checkout['repository'])
            self.assertEqual(0, checkout['fetch-depth'])
            self.assertFalse(checkout['persist-credentials'])
        text = (ROOT / '.github/workflows/ci-proof-manual.yml').read_text()
        self.assertNotIn('ci-main-queue', text)
        self.assertNotIn('ORBIT_MAIN_PROOF_ENABLED', text)
        upload = jobs['proof']['steps'][-1]
        self.assertEqual('always()', upload['if'])
        self.assertEqual('proof-receipt', upload['with']['name'])
        self.assertEqual('error', upload['with']['if-no-files-found'])
        run = subprocess.run([sys.executable, str(ROOT / 'tools/check_ci_workflow.py'), '.github/workflows/ci-proof-manual.yml'],
                             cwd=ROOT, capture_output=True, text=True, timeout=60)
        self.assertEqual(0, run.returncode, run.stdout + run.stderr)


if __name__ == '__main__':
    unittest.main()
