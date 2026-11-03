"""Exercise the native CI policy with real trees and GitHub API fixtures."""
import base64
import io
import json
import os
import subprocess
import tempfile
import textwrap
import unittest
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / '.github/workflows/swamp-shape.yml').read_text()
BLOCK = SOURCE.split('      - name: Select files from the immutable event tree\n')[1].split('      - uses: actions/upload-artifact')[0]
PROGRAM = textwrap.dedent(BLOCK.split('        run: |\n')[1])
WEIGHTS = json.loads(' '.join(BLOCK.split('          NATIVE_SECONDS: >-\n')[1].split('        run: |')[0].split()))
FILES = [f'extensions/behaviors/operations/{family}/{index}_native_test.ts'
         for family in ('alpha', 'beta', 'routes') for index in range(2)]


class NativePlanTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.git('init', '-q')
        for file in FILES:
            self.write('swamp/' + file, '// test')
        self.write('swamp/.swamp.yaml', 'swampVersion: pinned\n')
        self.commit()
        self.base = self.git('rev-parse', 'HEAD')

    def write(self, file, content):
        path = self.root / file
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content)

    def git(self, *arguments):
        return subprocess.check_output(['git', '-c', 'user.name=Fixture', '-c',
            'user.email=fixture@example.test', '-c', 'commit.gpgsign=false', *arguments],
            cwd=self.root, text=True, stderr=subprocess.DEVNULL).strip()

    def commit(self):
        self.git('add', '.')
        self.git('commit', '-qm', 'Fixture')

    def plan(self, path, *, event='pull_request', stamp=None, weights=None, missing=False, queue=False, batch=False, expired=False):
        if batch:
            self.write('swamp/extensions/behaviors/operations/alpha/first.ts', 'earlier queued PR')
            self.commit()
        self.write(path, 'changed')
        self.commit()
        sha, tree = self.git('rev-parse', 'HEAD'), self.git('rev-parse', 'HEAD^{tree}')
        scratch = self.root / '.git/ci'
        scratch.mkdir()
        event_file, output = scratch / 'event', scratch / 'output'
        event_file.write_text(json.dumps({'pull_request': {'base': {'sha': self.base}},
                                          'merge_group': {'base_sha': self.base}}))
        output.touch()
        responses = {}
        if event == 'push' and not missing:
            prefix = 'repos/hardimpactdev/orbit/'
            record = {'tree': tree, 'base': self.base}
            record.update(stamp or {})
            blob = io.BytesIO()
            with zipfile.ZipFile(blob, 'w') as archive:
                archive.writestr('native-tested-tree.json', json.dumps(record))
            payloads = {
                prefix + 'actions/workflows/ci.yml/runs?event=merge_group&status=success&per_page=20': {'workflow_runs': [{'id': 11, 'head_branch': 'gh-readonly-queue/main/pr-1'}] if queue else []},
                prefix + f'commits/{sha}/pulls': [{'number': 1, 'merged_at': 'now', 'merge_commit_sha': 'other' if queue else sha,
                    'head': {'sha': 'tested-head', 'repo': {'full_name': 'hardimpactdev/orbit'}},
                    'base': {'repo': {'full_name': 'hardimpactdev/orbit'}}}],
                prefix + 'actions/workflows/ci.yml/runs?event=pull_request&head_sha=tested-head&status=success&per_page=5': {'workflow_runs': [{'id': 11}]},
                prefix + 'actions/runs/11/artifacts': {'artifacts': [{'id': 12, 'name': 'swamp-native-tested-tree', 'expired': expired}]},
            }
            responses = {key: base64.b64encode(json.dumps(value).encode()).decode() for key, value in payloads.items()}
            responses[prefix + 'actions/artifacts/12/zip'] = base64.b64encode(blob.getvalue()).decode()
        response_file = scratch / 'responses'
        response_file.write_text(json.dumps(responses))
        shim = scratch / 'gh'
        shim.write_text("#!/usr/bin/env python3\nimport base64,json,os,sys\ndata=json.load(open(os.environ['API_FIXTURE'])).get(sys.argv[-1]);\nif data is None: sys.exit(1)\nsys.stdout.buffer.write(base64.b64decode(data))\n")
        shim.chmod(0o755)
        env = {**os.environ, 'PATH': f'{scratch}:{os.environ["PATH"]}', 'API_FIXTURE': str(response_file),
               'GITHUB_EVENT_NAME': event, 'GITHUB_EVENT_PATH': str(event_file), 'GITHUB_OUTPUT': str(output),
               'GITHUB_REPOSITORY': 'hardimpactdev/orbit', 'GITHUB_REF': 'refs/heads/main', 'GITHUB_SHA': sha,
               'RUNNER_TEMP': str(scratch), 'NATIVE_SECONDS': json.dumps(weights or WEIGHTS)}
        result = subprocess.run(['bash', '-e', '-o', 'pipefail', '-c', PROGRAM], cwd=self.root / 'swamp', env=env,
                                capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)
        values = dict(line.split('=', 1) for line in output.read_text().splitlines())
        matrix = json.loads(values['matrix'])['include']
        selected = [file for shard in matrix for file in shard['files']]
        self.assertEqual(len(selected), len(set(selected)))
        self.assertEqual(values['selected'], str(bool(selected)).lower())
        return selected, matrix, result.stdout

    def test_family_source_and_its_fixtures_select_only_that_family(self):
        selected, _, _ = self.plan('swamp/extensions/behaviors/operations/alpha/fixture.ts')
        self.assertEqual(set(selected), {f for f in FILES if '/alpha/' in f})

    def test_family_workflow_selects_its_native_suites(self):
        selected, _, _ = self.plan('swamp/workflows/workflow-operation-route-create.yaml')
        self.assertEqual(set(selected), {f for f in FILES if '/routes/' in f})

    def test_family_proof_fixture_selects_its_suites(self):
        selected, _, _ = self.plan('swamp/proof/alpha.create/parity.py')
        self.assertEqual(set(selected), {f for f in FILES if '/alpha/' in f})

    def test_shared_library_runs_every_native_file(self):
        selected, _, _ = self.plan('swamp/extensions/behaviors/operations/_lib/output.ts')
        self.assertEqual(set(selected), set(FILES))

    def test_shared_harness_runs_every_native_file(self):
        selected, _, _ = self.plan('swamp/tests/_support/native_harness.ts')
        self.assertEqual(set(selected), set(FILES))

    def test_swamp_pin_runs_every_native_file(self):
        selected, _, _ = self.plan('swamp/.swamp.yaml')
        self.assertEqual(set(selected), set(FILES))

    def test_family_converted_entirely_to_units_needs_no_native_job(self):
        for file in FILES:
            if '/alpha/' in file:
                (self.root / 'swamp' / file).unlink()
        selected, _, _ = self.plan('swamp/extensions/behaviors/operations/alpha/program_test.ts')
        self.assertEqual(selected, [])

    def test_docs_need_no_native_run(self):
        selected, _, _ = self.plan('docs/reference/tasks.md')
        self.assertEqual(selected, [])
        caller = (ROOT / '.github/workflows/ci.yml').read_text().split('  swamp:\n')[1].split('  gateway-release:\n')[0]
        self.assertIn('changed-only: false', caller)

    def test_weighted_assignment_covers_files_and_spreads_expensive_files(self):
        weights = {file.removeprefix('extensions/behaviors/').removesuffix('_native_test.ts'): seconds
                   for file, seconds in zip(FILES, [100, 90, 80, 70, 10, 10])}
        selected, matrix, _ = self.plan('.github/workflows/swamp-shape.yml', weights=weights)
        self.assertEqual(set(selected), set(FILES))
        loads = [sum(weights[file.removeprefix('extensions/behaviors/').removesuffix('_native_test.ts')]
                     for file in shard['files']) for shard in matrix]
        self.assertEqual(sorted(loads), [80, 90, 90, 100])

    def test_exact_successful_pr_tree_and_base_reuse_native_results(self):
        selected, _, stdout = self.plan('swamp/shared.ts', event='push')
        self.assertEqual(selected, [])
        self.assertIn('Reuse successful PR #1 run 11', stdout)

    def test_stale_base_runs_full_suite(self):
        selected, _, _ = self.plan('swamp/shared.ts', event='push', stamp={'base': 'stale'})
        self.assertEqual(set(selected), set(FILES))

    def test_changed_tree_runs_full_suite(self):
        selected, _, _ = self.plan('swamp/shared.ts', event='push', stamp={'tree': 'different'})
        self.assertEqual(set(selected), set(FILES))

    def test_unproven_main_runs_full_suite(self):
        selected, _, _ = self.plan('swamp/shared.ts', event='push', missing=True)
        self.assertEqual(set(selected), set(FILES))

    def test_queue_batch_uses_batch_base_and_selects_touched_families(self):
        selected, _, _ = self.plan('swamp/extensions/behaviors/operations/beta/fixture.ts',
                                    event='merge_group', batch=True)
        self.assertEqual(set(selected), {f for f in FILES if '/beta/' in f or '/alpha/' in f})

    def test_queue_shared_change_runs_every_native_file(self):
        selected, _, _ = self.plan('.github/workflows/swamp-shape.yml', event='merge_group')
        self.assertEqual(set(selected), set(FILES))

    def test_successful_queue_tree_reuses_results_for_a_multi_pr_batch(self):
        selected, _, stdout = self.plan('swamp/shared.ts', event='push', queue=True, batch=True)
        self.assertEqual(selected, [])
        self.assertIn('Reuse successful merge queue run 11', stdout)

    def test_different_queue_tree_runs_full_suite(self):
        selected, _, _ = self.plan('swamp/shared.ts', event='push', queue=True, stamp={'tree': 'different'})
        self.assertEqual(set(selected), set(FILES))

    def test_expired_record_runs_full_suite(self):
        selected, _, _ = self.plan('swamp/shared.ts', event='push', queue=True, expired=True)
        self.assertEqual(set(selected), set(FILES))

    def test_nightly_always_runs_full_suite(self):
        selected, _, _ = self.plan('docs/reference/tasks.md', event='schedule')
        self.assertEqual(set(selected), set(FILES))


class NativeGateTest(unittest.TestCase):
    def test_merge_queue_has_required_checks_and_batch_scopes(self):
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        self.assertIn('  merge_group:\n    types: [checks_requested]', caller)
        self.assertIn('  merge_group:\n    types: [checks_requested]', SOURCE)
        self.assertIn('    name: Required checks\n    if: always()', caller)
        for source in [caller, SOURCE, (ROOT / '.github/actions/ci-scope/action.yml').read_text()]:
            self.assertNotIn('github.event.pull_request.base.sha || github.event.before', source)
            self.assertIn('github.event.merge_group.base_sha', source)
        for job in ['shape', 'validate', 'native-plan', 'test-shards']:
            block = SOURCE.split(f'  {job}:\n')[1].split('    name:')[0]
            self.assertIn("if: github.event_name != 'merge_group' || github.workflow == 'CI'", block)
        secrets = caller.split('  swamp:\n')[1].split('  gateway-release:\n')[0]
        self.assertEqual(secrets.count("github.event_name == 'merge_group'"), 4)

    def test_only_a_successful_explicit_plan_can_skip_native_jobs(self):
        block = SOURCE.split('  tests:\n')[1].split('  test-shards:\n')[0]
        command = textwrap.dedent(block.split('        run: |\n')[1])
        cases = [('true', 'success', 'success', 'success', 0),
                 ('false', 'skipped', 'success', 'success', 0),
                 ('true', 'skipped', 'success', 'success', 1),
                 ('false', 'skipped', 'failure', 'success', 1),
                 ('false', 'skipped', 'success', 'failure', 1),
                 ('', 'skipped', 'success', 'success', 1)]
        for selected, native, plan, unit, expected in cases:
            with self.subTest(selected=selected, native=native, plan=plan, unit=unit):
                result = subprocess.run(['bash', '-e', '-o', 'pipefail', '-c', command],
                    env={**os.environ, 'NATIVE_SELECTED': selected, 'NATIVE_RESULT': native,
                         'PLAN_RESULT': plan, 'SHARDS_RESULT': unit}, capture_output=True, text=True, check=False)
                self.assertEqual(result.returncode, expected, result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main()
