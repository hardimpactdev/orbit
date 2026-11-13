"""A real checkout tree, trusted PR CI and immutable web bytes authorize reuse."""
import copy
import io
import json
import os
from pathlib import Path
import runpy
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import zipfile

import yaml

ROOT = Path(__file__).resolve().parents[1]
receipt = type('Receipt', (), runpy.run_path(str(ROOT / 'bin/ci-tree-receipt')))
REPO = 'hardimpactdev/orbit'
HEAD, TESTED, MAIN, TREE = (c * 40 for c in 'abcd')
BODY = 'Docs-unaffected: docs/reference/implementation-loop.md: No contract change.'
START, UPLOAD, END, MERGE = ('2026-11-13T22:00:00Z', '2026-11-13T22:00:30Z', '2026-11-13T22:00:40Z', '2026-11-13T22:01:00Z')
JOBS = yaml.safe_load((ROOT / '.github/workflows/ci.yml').read_text())['jobs']


def archive(files):
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, 'w') as output:
        for name, data in files.items():
            output.writestr(name, data)
    return buf.getvalue()


def checkout(sha):
    return f'2026-11-13T22:00:00Z [command]/usr/bin/git log -1 --format=%H\n2026-11-13T22:00:00Z {sha}\n'


class API:
    repository = REPO

    def __init__(self):
        self.pr = dict(number=7, merged_at=MERGE, merge_commit_sha=MAIN, body=BODY,
                       base={'ref': 'main', 'repo': {'full_name': REPO}}, head={'sha': HEAD, 'repo': {'full_name': REPO}})
        self.run = dict(id=50, workflow_id=8, path=receipt.WORKFLOW, head_sha=HEAD,
                        event='pull_request', repository={'full_name': REPO}, head_repository={'full_name': REPO},
                        created_at=START, html_url='https://github.com/hardimpactdev/orbit/actions/runs/50')
        self.runs = [self.run]
        self.jobs = [dict(id=i, name=name, run_attempt=1, head_sha=HEAD, status='completed',
                          conclusion='success', started_at=START, completed_at=END)
                     for i, name in enumerate(('Required checks', 'Select build inputs', 'Docs build', 'Web build'), 60)]
        self.logs = {j['id']: checkout(TESTED) for j in self.jobs}
        self.logs[62] += f'2026-11-13T22:00:35Z Docs body SHA256: {receipt.sha256(BODY.encode())}\n'
        self.web_bytes = archive({'index.html': '<h1>Checked</h1>', 'version.json': json.dumps({'buildId': TESTED}), 'assets/test.js': 'tested code'})
        self.web = self.artifact(100, 'web-pr-50-1', self.web_bytes)
        self.record = dict(repository=REPO, pr=7, head=HEAD, run=50, attempt=1, tested_commit=TESTED,
                           tree=TREE, body_sha256=receipt.sha256(BODY.encode()), web_id=100,
                           web_digest=self.web['digest'].split(':')[1], web_attempt=1)
        self.commit = dict(tree={'sha': TREE}, parents=[{'sha': 'e' * 40}, {'sha': HEAD}])
        self.refresh_record()

    def artifact(self, id, name, data):
        return dict(id=id, name=name, workflow_run={'id': 50}, digest='sha256:' + receipt.sha256(data),
                    size_in_bytes=len(data), expired=False, created_at=UPLOAD)

    def refresh_record(self):
        self.record_bytes = archive({'receipt.json': json.dumps(self.record)})
        self.provenance = self.artifact(101, 'ci-tree-50-' + str(self.record['attempt']), self.record_bytes)

    def one(self, path):
        return {'actions/workflows/ci.yml': {'id': 8, 'path': receipt.WORKFLOW},
                'pulls/7': self.pr, 'git/commits/' + TESTED: self.commit,
                'actions/artifacts/100': self.web}[path]

    def rows(self, path, key=None):
        if path.startswith('commits/'):
            return [self.pr]
        if path.startswith('actions/workflows/'):
            return self.runs
        if '/jobs?' in path:
            return self.jobs
        if path.endswith('/artifacts'):
            return [self.provenance, self.web]
        raise AssertionError(path)

    def raw(self, path):
        if path.endswith('/logs'):
            return self.logs[int(path.split('/')[2])].encode()
        return {'actions/artifacts/100/zip': self.web_bytes, 'actions/artifacts/101/zip': self.record_bytes}[path]


class ReceiptTest(unittest.TestCase):
    def verify(self, api=None):
        return receipt.verify(api or API(), MAIN, TREE)

    def test_api_captures_log_and_archive_bytes_without_terminal_filtering(self):
        # gh 2.104 rejects ANSI in non-JSON output even when stdout is a pipe.
        for path, payload in (('actions/jobs/60/logs', b'checkout\x1b[0m\n'),
                              ('actions/artifacts/100/zip', b'PK\x03\x04\x1b[31m\x00')):
            with self.subTest(path=path), patch.object(subprocess, 'check_output', return_value=payload) as call:
                api = receipt.GitHub(REPO)
                self.assertEqual(api.raw(path), payload)
                self.assertEqual(call.call_args.args[0],
                    ['gh', 'api', '--allow-escape-sequences', f'repos/{REPO}/{path}'])
                self.assertLessEqual(call.call_args.kwargs['timeout'], 10)

    def test_same_tested_tree_reuses_bytes_even_when_pr_head_tree_differs(self):
        api = API()
        api.run['head_commit'] = {'tree_id': 'f' * 40}
        evidence, files = self.verify(api)
        self.assertEqual(evidence['tree'], TREE)
        self.assertEqual(files['index.html'], b'<h1>Checked</h1>')
        self.assertEqual(json.loads(files['version.json'])['buildId'], TESTED)

    def test_fork_other_workflow_same_name_and_non_pr_runs_cannot_spoof(self):
        for mutation in ({'head_repository': {'full_name': 'fork/orbit'}},
                         {'repository': {'full_name': 'fork/orbit'}},
                         {'workflow_id': 99, 'name': 'CI'}, {'path': '.github/workflows/fake.yml', 'name': 'CI'},
                         {'event': 'workflow_dispatch'}, {'head_sha': 'f' * 40}):
            api = API()
            api.run.update(mutation)
            with self.subTest(mutation=mutation), self.assertRaisesRegex(ValueError, 'No trusted'):
                self.verify(api)

    def test_fork_pr_does_not_qualify_even_with_an_imitating_run(self):
        api = API()
        api.pr['head']['repo']['full_name'] = 'fork/orbit'
        with self.assertRaisesRegex(ValueError, 'No unique'):
            self.verify(api)

    def test_latest_run_and_latest_attempt_cannot_be_hidden_by_old_green(self):
        for state in ('failure', 'skipped', 'cancelled', None):
            api = API()
            api.jobs.append(api.jobs[0] | dict(id=99, run_attempt=2, conclusion=state))
            with self.subTest(state=state), self.assertRaisesRegex(ValueError, 'Required checks'):
                self.verify(api)
        api = API()
        api.runs.append(api.run | dict(id=51, created_at='2026-11-13T22:02:00Z'))
        with self.assertRaisesRegex(ValueError, 'after merge'):
            self.verify(api)

    def test_changed_body_and_gate_only_rerun_cannot_bless_stale_docs(self):
        api = API()
        api.pr['body'] += '\nChanged after green'
        with self.assertRaisesRegex(ValueError, 'body changed'):
            self.verify(api)
        # Even a rewritten receipt claiming the new body cannot replace the actual Docs job log.
        api.jobs[0]['run_attempt'] = 2
        api.record['attempt'] = 2
        api.record['body_sha256'] = receipt.sha256(api.pr['body'].encode())
        api.refresh_record()
        with self.assertRaisesRegex(ValueError, 'Docs body evidence'):
            self.verify(api)

    def test_failed_only_docs_and_required_rerun_preserves_successful_web_execution(self):
        api = API()
        api.pr['body'] += '\nA reviewed reason'
        # Actions may copy successful job records to a new attempt, preserving their timestamps and logs.
        for job in api.jobs:
            job['run_attempt'] = 2
        api.record.update(attempt=2, body_sha256=receipt.sha256(api.pr['body'].encode()))
        api.logs[62] = checkout(TESTED) + f"2026-11-13T22:00:35Z Docs body SHA256: {api.record['body_sha256']}\n"
        api.refresh_record()
        self.assertEqual(self.verify(api)[0]['web_attempt'], 1)

    def test_wrong_artifact_digest_metadata_bytes_run_and_timestamp_are_refused(self):
        mutations = [lambda a: a.web.update(digest='sha256:' + '0' * 64),
                     lambda a: setattr(a, 'web_bytes', a.web_bytes + b'tampered'),
                     lambda a: a.web.update(workflow_run={'id': 51}),
                     lambda a: a.web.update(expired=True),
                     lambda a: a.web.update(created_at=MERGE)]
        for mutation in mutations:
            api = API()
            mutation(api)
            with self.subTest(mutation=mutation), self.assertRaises(ValueError):
                self.verify(api)

    def test_actual_checkout_tree_wins_over_claims_and_merge_behind_main_runs_normally(self):
        api = API()
        api.commit['tree']['sha'] = 'f' * 40
        with self.assertRaisesRegex(ValueError, 'tree differs'):
            self.verify(api)
        api = API()
        api.logs[63] = checkout(HEAD)
        with self.assertRaisesRegex(ValueError, 'different checkouts'):
            self.verify(api)
        api = API()
        api.commit['parents'][1]['sha'] = 'e' * 40
        with self.assertRaisesRegex(ValueError, 'final PR merge'):
            self.verify(api)

    def test_forged_or_unavailable_receipt_falls_back(self):
        for key, value in (('pr', 8), ('head', 'f' * 40), ('run', 51), ('attempt', 2)):
            api = API()
            api.record[key] = value
            api.refresh_record()
            with self.subTest(key=key), self.assertRaises(ValueError):
                self.verify(api)
        api = API()
        api.provenance['expired'] = True
        with self.assertRaisesRegex(ValueError, 'expired'):
            self.verify(api)

    def test_unsafe_zip_is_refused_before_promotion(self):
        for path in ('../evil', '/tmp/evil', 'assets/../../evil', 'assets\\evil'):
            with self.subTest(path=path), self.assertRaisesRegex(ValueError, 'Unsafe'):
                dict(receipt.files(archive({path: 'bad'})))
        with self.assertRaisesRegex(ValueError, 'Duplicate'):
            buf = io.BytesIO()
            with zipfile.ZipFile(buf, 'w') as z:
                z.writestr('index.html', 'a')
                z.writestr('index.html', 'b')
            dict(receipt.files(buf.getvalue()))

    def test_api_error_uses_normal_ci_without_setting_reuse(self):
        with tempfile.TemporaryDirectory() as tmp, patch.dict(os.environ, GITHUB_EVENT_NAME='push',
                GITHUB_REF='refs/heads/main', GITHUB_REPOSITORY=REPO, GITHUB_SHA=MAIN,
                GITHUB_OUTPUT=tmp + '/output', GITHUB_STEP_SUMMARY=tmp + '/summary'), patch.dict(receipt.attempt.__globals__,
                    verify=lambda *a: (_ for _ in ()).throw(ValueError('API unavailable')), git=lambda a: MAIN if a == 'HEAD' else TREE):
            receipt.attempt(Path(tmp) / 'web')
            self.assertEqual(Path(tmp, 'output').read_text(), 'reuse=false\n')
            self.assertFalse(Path(tmp, 'web').exists())

    def test_successful_attempt_writes_only_verified_files_and_traceable_receipt(self):
        api = API()
        evidence, web = self.verify(api)
        with tempfile.TemporaryDirectory() as tmp, patch.dict(os.environ, GITHUB_EVENT_NAME='push',
                GITHUB_REF='refs/heads/main', GITHUB_REPOSITORY=REPO, GITHUB_SHA=MAIN,
                GITHUB_OUTPUT=tmp + '/output', RUNNER_TEMP=tmp, GITHUB_STEP_SUMMARY=tmp + '/summary'), \
             patch.dict(receipt.attempt.__globals__, verify=lambda *a: (evidence, web),
                        git=lambda a: MAIN if a == 'HEAD' else TREE):
            receipt.attempt(Path(tmp) / 'web')
            self.assertEqual(Path(tmp, 'output').read_text(), 'reuse=true\n')
            self.assertEqual(Path(tmp, 'web/index.html').read_bytes(), web['index.html'])
            self.assertEqual(json.loads(Path(tmp, 'main-tree-receipt.json').read_text())['source_artifact_digest'], api.web['digest'])
            self.assertIn(TESTED, Path(tmp, 'summary').read_text())

    def test_null_repository_metadata_uses_normal_ci(self):
        for field in ('head_repository', 'repository'):
            api = API()
            api.run[field] = None
            with self.subTest(field=field), self.assertRaisesRegex(ValueError, 'No trusted'):
                self.verify(api)
        api = API()
        api.pr['head']['repo'] = None
        with self.assertRaisesRegex(ValueError, 'No unique'):
            self.verify(api)
        with tempfile.TemporaryDirectory() as tmp, patch.dict(os.environ, GITHUB_EVENT_NAME='push',
                GITHUB_REF='refs/heads/main', GITHUB_REPOSITORY=REPO, GITHUB_SHA=MAIN,
                GITHUB_OUTPUT=tmp + '/output', GITHUB_STEP_SUMMARY=tmp + '/summary'), \
             patch.dict(receipt.attempt.__globals__, verify=lambda *a: (_ for _ in ()).throw(AttributeError('unexpected API shape')),
                        git=lambda a: MAIN if a == 'HEAD' else TREE):
            receipt.attempt(Path(tmp) / 'web')
            self.assertEqual(Path(tmp, 'output').read_text(), 'reuse=false\n')
            self.assertIn('Normal main CI after', Path(tmp, 'summary').read_text())

    def test_pr_record_binds_real_environment_and_rejects_missing_job_outputs(self):
        with tempfile.TemporaryDirectory() as tmp:
            event = Path(tmp, 'event.json')
            event.write_text(json.dumps({'number': 7, 'pull_request': {'head': {'sha': HEAD}}}))
            env = dict(GITHUB_EVENT_NAME='pull_request', GITHUB_EVENT_PATH=str(event),
                       GITHUB_REPOSITORY=REPO, GITHUB_RUN_ID='50', GITHUB_RUN_ATTEMPT='2',
                       DOCS_BODY_SHA256=receipt.sha256(BODY.encode()), WEB_ID='100',
                       WEB_DIGEST='e' * 64, WEB_ATTEMPT='1')
            path = Path(tmp, 'receipt.json')
            with patch.dict(os.environ, env), patch.dict(receipt.record.__globals__,
                    git=lambda a: TESTED if a == 'HEAD' else TREE):
                receipt.record(path)
                data = json.loads(path.read_text())
                self.assertEqual(data, dict(repository=REPO, pr=7, head=HEAD, run=50, attempt=2,
                    tested_commit=TESTED, tree=TREE, body_sha256=env['DOCS_BODY_SHA256'],
                    web_id=100, web_digest='e' * 64, web_attempt=1))
                for key, value in (('WEB_ID', ''), ('WEB_DIGEST', ''), ('WEB_DIGEST', 'sha256:' + 'e' * 64),
                                   ('DOCS_BODY_SHA256', ''), ('WEB_ATTEMPT', '')):
                    with self.subTest(key=key, value=value), patch.dict(os.environ, {key: value}), self.assertRaises(ValueError):
                        receipt.record(path)

    def test_real_workflow_reuse_selects_no_tests_and_still_checks_delivery(self):
        from test_ci_delivery import delivery, needs_for
        from test_ci_build_plan import plan
        self.assertTrue(plan.select(['docs/a.md'], pull_request=True)['jobs']['web'])
        select = next(s for s in JOBS['changes']['steps'] if s.get('id') == 'plan')
        script = select['run']
        with tempfile.TemporaryDirectory() as tmp:
            env = os.environ | dict(REUSE='true', GITHUB_OUTPUT=tmp + '/output')
            subprocess.run(['bash', '-eu', '-c', script], env=env, cwd=ROOT, check=True)
            outputs = dict(line.split('=', 1) for line in Path(tmp, 'output').read_text().splitlines())
        self.assertFalse(any(json.loads(outputs['jobs']).values()))
        needs = needs_for()
        needs['changes']['outputs'].update(outputs)
        for name in needs:
            if name not in ('changes', 'gateway-release'):
                needs[name]['result'] = 'skipped'
        with patch.dict(os.environ, PROOF_REQUIRED='false', PROOF_BLOCKING='false', SLOW_CHECKS_BLOCKING='false'):
            delivery.validate(copy.deepcopy(needs))
            needs['gateway-release']['result'] = 'failure'
            with self.assertRaises(SystemExit):
                delivery.validate(needs)
        self.assertIn("github.ref == 'refs/heads/main'", next(s for s in JOBS['changes']['steps'] if s.get('id') == 'reuse')['if'])
        self.assertEqual(JOBS['changes']['permissions'], {'contents': 'read', 'actions': 'read', 'pull-requests': 'read'})
        self.assertEqual(JOBS['gateway-release']['name'], 'Gateway release inputs')
        self.assertIn("'Required checks'", JOBS['main-required']['name'])
        for job in JOBS.values():
            self.assertNotIn('checks', job.get('permissions', {}))


if __name__ == '__main__':
    unittest.main()
