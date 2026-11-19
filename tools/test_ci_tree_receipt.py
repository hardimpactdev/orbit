"""A green merge queue run on the exact main commit and its immutable web bytes authorize delivery."""
from contextlib import chdir
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
MAIN, TREE, BASE, AHEAD = (c * 40 for c in 'cdeb')
START, UPLOAD, END = ('2026-11-13T22:00:00Z', '2026-11-13T22:00:30Z', '2026-11-13T22:00:40Z')
JOBS = yaml.safe_load((ROOT / '.github/workflows/ci.yml').read_text())['jobs']


def archive(files):
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, 'w') as output:
        for name, data in files.items():
            output.writestr(name, data)
    return buf.getvalue()


class API:
    repository = REPO

    def __init__(self):
        self.run = dict(id=50, workflow_id=8, path=receipt.WORKFLOW, head_sha=MAIN, event='merge_group',
                        head_branch=f'gh-readonly-queue/main/pr-7-{BASE}', repository={'full_name': REPO},
                        head_repository={'full_name': REPO}, status='completed', conclusion='success', run_attempt=1,
                        html_url='https://github.com/hardimpactdev/orbit/actions/runs/50')
        self.runs = [self.run]
        self.jobs = [dict(id=i, name=name, run_attempt=1, head_sha=MAIN, status='completed',
                          conclusion='success', started_at=START, completed_at=END)
                     for i, name in enumerate(('Required checks', 'Select build inputs', 'Web build'), 60)]
        self.web_bytes = archive({'index.html': '<h1>Checked</h1>', 'version.json': json.dumps({'buildId': MAIN}), 'assets/test.js': 'tested code'})
        self.web = dict(id=100, name='web-queue-50-1', workflow_run={'id': 50}, digest='sha256:' + receipt.sha256(self.web_bytes),
                        size_in_bytes=len(self.web_bytes), expired=False, created_at=UPLOAD)
        # Main delivery passed on these commits.
        self.delivered = {BASE}

    def one(self, path):
        return {'actions/workflows/ci.yml': {'id': 8, 'path': receipt.WORKFLOW}}[path]

    def rows(self, path, key=None):
        if 'event=merge_group' in path:
            return self.runs
        if 'branch=main' in path:
            sha = path.rsplit('=', 1)[-1]
            return [dict(self.run, id=70, event='push', head_branch='main', head_sha=sha)]
        if '/70/jobs?' in path:
            return [dict(self.jobs[0], name='Main delivery checks', head_sha=sha,
                         conclusion='success' if sha in self.delivered else 'failure')
                    for sha in (BASE, AHEAD, MAIN)]
        if '/jobs?' in path:
            return self.jobs
        if path.endswith('/artifacts'):
            return [self.web]
        raise AssertionError(path)

    def raw(self, path):
        return {'actions/artifacts/100/zip': self.web_bytes}[path]


class History(API):
    """Each commit has its own main delivery result and, when named, its own merge queue run."""
    def __init__(self, queue=None):
        super().__init__()
        self.queue = queue or {}

    def rows(self, path, key=None):
        sha = path.rsplit('=', 1)[-1]
        if 'event=merge_group' in path:
            return [dict(self.run, head_sha=sha, **self.queue[sha])] if sha in self.queue else []
        if 'branch=main' in path:
            return [dict(self.run, id=int(sha[0], 16), event='push', head_branch='main', head_sha=sha)]
        sha = next(sha for sha in (BASE, AHEAD, MAIN) if f'/{int(sha[0], 16)}/jobs?' in path)
        return [dict(self.jobs[0], name='Main delivery checks', head_sha=sha,
                     conclusion='success' if sha in self.delivered else 'failure')]


class ReceiptTest(unittest.TestCase):
    def verify(self, api=None):
        return receipt.verify(api or API(), MAIN, TREE)

    def test_api_captures_archive_bytes_without_terminal_filtering(self):
        # gh 2.104 rejects ANSI in non-JSON output even when stdout is a pipe.
        payload = b'PK\x03\x04\x1b[31m\x00'
        with patch.object(subprocess, 'check_output', return_value=payload) as call:
            api = receipt.GitHub(REPO)
            self.assertEqual(api.raw('actions/artifacts/100/zip'), payload)
            self.assertEqual(call.call_args.args[0],
                ['gh', 'api', '--allow-escape-sequences', f'repos/{REPO}/actions/artifacts/100/zip'])
            self.assertLessEqual(call.call_args.kwargs['timeout'], 10)

    def test_the_queue_run_on_the_exact_commit_delivers_its_web_build(self):
        evidence, files = self.verify()
        self.assertEqual(evidence, dict(repository=REPO, tested_commit=MAIN, tree=TREE, run=50, attempt=1,
                                        source_url=API().run['html_url'], web_id=100, source_artifact_digest=API().web['digest']))
        self.assertEqual(files['index.html'], b'<h1>Checked</h1>')
        self.assertEqual(json.loads(files['version.json'])['buildId'], MAIN)

    def test_a_commit_that_no_queue_run_tested_is_refused(self):
        # A commit merged past the queue has a pull request run at most: no tree comparison can bless it.
        api = API()
        api.runs = []
        with self.assertRaisesRegex(ValueError, 'No merge queue CI run tested this commit'):
            self.verify(api)

    def test_fork_other_workflow_same_name_and_non_queue_runs_cannot_spoof(self):
        for mutation in ({'head_repository': {'full_name': 'fork/orbit'}}, {'repository': {'full_name': 'fork/orbit'}},
                         {'head_repository': None}, {'repository': None},
                         {'workflow_id': 99, 'name': 'CI'}, {'path': '.github/workflows/fake.yml', 'name': 'CI'},
                         {'event': 'pull_request'}, {'event': 'workflow_dispatch'}, {'head_sha': 'f' * 40},
                         {'head_branch': 'gh-readonly-queue/release/pr-7-' + BASE}, {'head_branch': None}):
            api = API()
            api.run.update(mutation)
            with self.subTest(mutation=mutation), self.assertRaisesRegex(ValueError, 'No merge queue CI run'):
                self.verify(api)

    def test_newest_run_and_newest_attempt_cannot_be_hidden_by_old_green(self):
        for state in ('failure', 'skipped', 'cancelled', None):
            api = API()
            api.run['run_attempt'] = 2
            api.jobs.append(api.jobs[0] | dict(id=99, run_attempt=2, conclusion=state))
            with self.subTest(state=state), self.assertRaisesRegex(ValueError, 'Required checks is not green'):
                self.verify(api)
        # The gate of a rerun has not started: the first attempt's green gate does not count.
        api = API()
        api.run['run_attempt'] = 2
        with self.assertRaisesRegex(ValueError, 'current attempt'):
            self.verify(api)
        api = API()
        api.runs = [api.run, api.run | dict(id=51)]
        failed = [job | dict(conclusion='failure') for job in api.jobs]
        rows = api.rows
        api.rows = lambda path, key=None: failed if '/51/jobs?' in path else rows(path, key)
        with self.assertRaisesRegex(ValueError, 'Required checks is not green'):
            self.verify(api)

    def test_the_gate_counts_before_github_closes_the_run(self):
        # The queue merges when Required checks reports; the run itself ends a moment later.
        api = API()
        api.run.update(status='in_progress', conclusion=None)
        self.assertEqual(self.verify(api)[0]['run'], 50)

    def test_every_evidence_job_must_be_green_on_the_commit(self):
        for index in range(3):
            for change in ({'conclusion': 'failure'}, {'status': 'in_progress'}, {'head_sha': 'f' * 40}):
                api = API()
                api.jobs[index].update(change)
                with self.subTest(job=api.jobs[index]['name'], change=change), self.assertRaisesRegex(ValueError, 'is not green'):
                    self.verify(api)

    def test_a_failed_only_rerun_keeps_the_first_web_build(self):
        # Actions copies a successful job to the new attempt with its first attempt number and artifact.
        api = API()
        api.run['run_attempt'] = 2
        api.jobs[0]['run_attempt'] = 2
        self.assertEqual(self.verify(api)[0]['web_id'], 100)
        # A rerun Web build publishes under its own attempt: the first attempt's build is not the tested one.
        api = API()
        api.run['run_attempt'] = 2
        for job in api.jobs:
            job['run_attempt'] = 2
        with self.assertRaisesRegex(ValueError, 'No unique web build'):
            self.verify(api)
        api.web['name'] = 'web-queue-50-2'
        self.assertEqual(self.verify(api)[0]['web_id'], 100)

    def test_wrong_artifact_name_digest_metadata_bytes_run_and_timestamp_are_refused(self):
        mutations = [lambda a: a.web.update(name='web-queue-50-2'),
                     lambda a: a.web.update(digest='sha256:' + '0' * 64),
                     lambda a: setattr(a, 'web_bytes', a.web_bytes + b'tampered'),
                     lambda a: a.web.update(workflow_run={'id': 51}),
                     lambda a: a.web.update(expired=True),
                     lambda a: a.web.update(created_at='2026-11-13T22:01:00Z'),
                     lambda a: setattr(a, 'web_bytes', archive({'index.html': 'only'}))]
        for index, mutation in enumerate(mutations):
            api = API()
            mutation(api)
            if index == 6:
                api.web.update(digest='sha256:' + receipt.sha256(api.web_bytes), size_in_bytes=len(api.web_bytes))
            with self.subTest(mutation=index), self.assertRaises(ValueError):
                self.verify(api)

    def test_baseline_is_the_group_base_when_the_queue_tests_or_tested_it(self):
        # The group ahead is still running: the queue merges this group only after that one is green.
        for state in (dict(status='in_progress', conclusion=None), dict(status='queued', conclusion=None),
                      dict(status='completed', conclusion='success')):
            with self.subTest(state=state):
                self.assertEqual(receipt.queue_baseline(History({AHEAD: state}), [AHEAD, BASE]), AHEAD)

    def test_baseline_walks_past_a_commit_that_neither_the_queue_nor_main_verified(self):
        # AHEAD reached main past the queue and its delivery failed: the plan must also cover its changes.
        self.assertEqual(receipt.queue_baseline(History(), [AHEAD, BASE]), BASE)
        for state in ('failure', 'cancelled'):
            with self.subTest(state=state):
                api = History({AHEAD: dict(status='completed', conclusion=state)})
                self.assertEqual(receipt.queue_baseline(api, [AHEAD, BASE]), BASE)
        with self.assertRaisesRegex(ValueError, 'No verified commit'):
            receipt.queue_baseline(History(), [AHEAD])

    def test_baseline_reads_the_merge_group_base_and_its_first_parent_history(self):
        with tempfile.TemporaryDirectory() as scratch, chdir(scratch):
            def git(*args):
                return subprocess.check_output(['git', '-c', 'user.name=Fixture', '-c', 'user.email=test@example.com', *args],
                                               text=True).strip()
            git('init', '-q')
            git('commit', '-q', '--allow-empty', '-m', 'Delivered main')
            git('commit', '-q', '--allow-empty', '-m', 'Group ahead')
            history = git('rev-list', 'HEAD').splitlines()
            seen = []
            output = io.StringIO()
            with patch.dict(os.environ, QUEUE_BASE=history[0], GITHUB_REPOSITORY=REPO), \
                    patch.dict(receipt.baseline.__globals__, queue_baseline=lambda api, shas: seen.append(shas) or shas[1]), \
                    patch('sys.stdout', output):
                receipt.baseline()
            self.assertEqual(seen, [history])
            self.assertEqual(output.getvalue().strip(), history[1])
            with patch.dict(os.environ, QUEUE_BASE='', GITHUB_REPOSITORY=REPO), self.assertRaisesRegex(ValueError, 'Missing merge group base'):
                receipt.baseline()

    def test_carried_gateway_change_selects_gateway_checks_for_a_docs_group(self):
        import test_ci_build_plan

        with tempfile.TemporaryDirectory() as scratch, chdir(scratch):
            def git(*args):
                return subprocess.check_output(['git', '-c', 'user.name=Fixture',
                                                '-c', 'user.email=test@example.com', *args],
                                               text=True).strip()

            git('init', '-q')
            git('commit', '-q', '--allow-empty', '-m', 'Newest verified main')
            base = git('rev-parse', 'HEAD')
            gateway = Path('apps/gateway/app/Actions/Refresh.php')
            gateway.parent.mkdir(parents=True)
            gateway.write_text('<?php')
            git('add', '.')
            git('commit', '-qm', 'Main change merged past the queue')
            parent = git('rev-parse', 'HEAD')
            docs = Path('docs/reference/release.md')
            docs.parent.mkdir(parents=True)
            docs.write_text('Test')
            git('add', '.')
            git('commit', '-qm', 'Docs group')
            plan = test_ci_build_plan.plan
            self.assertFalse(plan.select(plan.changed_inputs(parent, 'HEAD')[1], merge_group=True)['jobs']['gateway-tests'])
            self.assertTrue(plan.select(plan.changed_inputs(base, 'HEAD')[1], merge_group=True)['jobs']['gateway-tests'])

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

    def test_api_error_blocks_delivery_without_setting_reuse(self):
        with tempfile.TemporaryDirectory() as tmp, patch.dict(os.environ, GITHUB_EVENT_NAME='push',
                GITHUB_REF='refs/heads/main', GITHUB_REPOSITORY=REPO, GITHUB_SHA=MAIN,
                GITHUB_OUTPUT=tmp + '/output', GITHUB_STEP_SUMMARY=tmp + '/summary'), patch.dict(receipt.attempt.__globals__,
                    verify=lambda *a: (_ for _ in ()).throw(ValueError('API unavailable')), git=lambda a: MAIN if a == 'HEAD' else TREE):
            with self.assertRaisesRegex(SystemExit, 'Main delivery blocked'):
                receipt.attempt(Path(tmp) / 'web')
            self.assertFalse(Path(tmp, 'output').exists())
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
            self.assertIn(MAIN, Path(tmp, 'summary').read_text())

    def test_an_unexpected_api_shape_blocks_delivery(self):
        with tempfile.TemporaryDirectory() as tmp, patch.dict(os.environ, GITHUB_EVENT_NAME='push',
                GITHUB_REF='refs/heads/main', GITHUB_REPOSITORY=REPO, GITHUB_SHA=MAIN,
                GITHUB_OUTPUT=tmp + '/output', GITHUB_STEP_SUMMARY=tmp + '/summary'), \
             patch.dict(receipt.attempt.__globals__, verify=lambda *a: (_ for _ in ()).throw(AttributeError('unexpected API shape')),
                        git=lambda a: MAIN if a == 'HEAD' else TREE):
            with self.assertRaisesRegex(SystemExit, 'Main delivery blocked'):
                receipt.attempt(Path(tmp) / 'web')
            self.assertFalse(Path(tmp, 'output').exists())
            self.assertIn('Main delivery blocked after', Path(tmp, 'summary').read_text())

    def test_delivery_runs_only_from_main(self):
        for env in (dict(GITHUB_EVENT_NAME='merge_group', GITHUB_REF='refs/heads/main'),
                    dict(GITHUB_EVENT_NAME='push', GITHUB_REF='refs/heads/gh-readonly-queue/main/pr-7-' + BASE)):
            with self.subTest(env=env), patch.dict(os.environ, env), self.assertRaisesRegex(ValueError, 'only from main CI'):
                receipt.attempt(Path('unused'))

    def test_real_workflow_reuse_selects_no_tests_and_still_checks_delivery(self):
        from test_ci_build_plan import plan
        self.assertTrue(plan.select(['docs/a.md'], merge_group=True)['jobs']['web'])
        self.assertFalse(plan.select(['docs/a.md'], pull_request=True)['jobs']['web'])
        select = next(s for s in JOBS['changes']['steps'] if s.get('id') == 'plan')
        with tempfile.TemporaryDirectory() as tmp:
            env = os.environ | dict(MAIN='true', GITHUB_OUTPUT=tmp + '/output')
            subprocess.run(['bash', '-eu', '-c', select['run']], env=env, cwd=ROOT, check=True)
            outputs = dict(line.split('=', 1) for line in Path(tmp, 'output').read_text().splitlines())
        self.assertFalse(any(json.loads(outputs['jobs']).values()))
        guard = JOBS['delivery']['steps'][0]['run']
        success = dict(POLICY='success', CHANGES='success', REUSE='true', RELEASE='success')
        for missing in (None, *success):
            failed = {missing: ''} if missing else {}
            with self.subTest(missing=missing):
                result = subprocess.run(['bash', '-eu', '-c', guard],
                                        env=os.environ | success | failed, capture_output=True, text=True)
                self.assertEqual(result.returncode, bool(missing))
        self.assertIn("github.ref == 'refs/heads/main'", next(s for s in JOBS['changes']['steps'] if s.get('id') == 'reuse')['if'])
        self.assertEqual(JOBS['changes']['permissions'], {'contents': 'read', 'actions': 'read', 'pull-requests': 'read'})
        self.assertEqual(JOBS['gateway-release']['name'], 'Gateway release inputs')
        self.assertIn("'Required checks'", JOBS['main-required']['name'])
        for job in JOBS.values():
            self.assertNotIn('checks', job.get('permissions', {}))


if __name__ == '__main__':
    unittest.main()
