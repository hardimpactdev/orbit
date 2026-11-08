"""Keep fast required CI gates while slow jobs are paused."""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / '.github/workflows/swamp-shape.yml').read_text()


class RequiredGateTest(unittest.TestCase):
    def test_agent_lock_changes_reuse_cache_only_with_the_same_toolchain_and_platform(self):
        import re
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        agent = caller.split('  agent:\n', 1)[1].split('\n  changes:\n', 1)[0]
        primary = re.search(r'^          key: (.+)$', agent, re.M).group(1)
        block = re.search(r'^          restore-keys: \|\n((?:            .+\n)+)', agent, re.M)
        prefixes = [line.strip() for line in block.group(1).splitlines()] if block else []

        def render(template, os='Linux', arch='X64', compiler='rust-A', lock='lock-A'):
            values = {'runner.os': os, 'runner.arch': arch, 'steps.rust.outputs.version': compiler}
            return re.sub(r'\$\{\{\s*(.*?)\s*\}\}',
                          lambda match: lock if match[1].startswith('hashFiles(') else values[match[1]], template)

        previous = render(primary)
        current = render(primary, lock='lock-B')
        self.assertNotEqual(previous, current)
        restore = [render(prefix, lock='lock-B') for prefix in prefixes]
        self.assertTrue(any(previous.startswith(prefix) for prefix in restore), 'lock change loses the warm dependency cache')
        for changed in ({'os': 'macOS'}, {'arch': 'ARM64'}, {'compiler': 'rust-B'}):
            with self.subTest(changed=changed):
                incompatible = render(primary, **changed)
                self.assertFalse(any(incompatible.startswith(prefix) for prefix in restore))

    def test_there_is_no_merge_queue_trigger_or_handling(self):
        # PRs merge straight into main after their gate (2026-11-05); no workflow, action or job reads a queue event.
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        scope = (ROOT / '.github/actions/ci-scope/action.yml').read_text()
        self.assertIn('    name: Required checks\n    if: always()', caller)
        for source in [caller, SOURCE, scope]:
            self.assertNotIn('merge_group', source)
            self.assertIn('github.event.pull_request.base.sha || github.event.before', source)

    def test_run_on_main_builds_and_publishes_the_release_web_build(self):
        # Required checks pass in the run on main, so that run must hold the Gateway release's web build.
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        for source in [caller.split('  changes:\n')[1].split('\n  swamp:\n')[0], (ROOT / '.github/actions/ci-scope/action.yml').read_text()]:
            self.assertIn("MAIN: ${{ github.ref == 'refs/heads/main' }}", source)
        publish = caller.split('      - name: Publish web build\n')[1].split('\n')[0]
        self.assertEqual(publish, "        if: steps.scope.outputs.selected == 'true' && github.event_name != 'pull_request' && github.ref == 'refs/heads/main'")

    def test_slow_jobs_are_absent_from_required_ci(self):
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        self.assertNotIn('project-check', caller)
        self.assertNotIn('  native-plan:', SOURCE)
        self.assertNotIn('  native-tests:', SOURCE)

    def test_selected_component_failure_or_skip_blocks_required_gate(self):
        import json
        import os
        import subprocess
        import textwrap
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        block = caller.split('  required:\n', 1)[1]
        code = textwrap.dedent(block.split("          python3 - <<'PYTHON'\n", 1)[1].split('          PYTHON', 1)[0])
        jobs = block.split('needs: [', 1)[1].split(']', 1)[0].split(', ')
        for component in ('pi', 'agent', 'proof-run'):
            self.assertIn(component, jobs)
            for selected, result, success in ((True, 'success', True), (True, 'failure', False),
                                              (True, 'skipped', False), (False, 'skipped', True)):
                needs = {job: {'result': 'success'} for job in jobs}
                selection = {job: False for job in jobs if job not in ('changes', 'gateway-release', 'proof-run')}
                if component != 'proof-run':
                    selection[component] = selected
                needs[component]['result'] = result
                needs['changes']['outputs'] = {'jobs': json.dumps(selection), 'gateway_release': 'false'}
                env = {**os.environ, 'NEEDS': json.dumps(needs),
                       'PROOF_REQUIRED': str(component == 'proof-run' and selected).lower(), 'FORK_PROOF': 'false'}
                done = subprocess.run(['python3', '-c', code], env=env,
                                      capture_output=True, timeout=10)
                self.assertEqual(done.returncode == 0, success, (component, selected, result, done.stderr))

    def test_fork_gate_requires_the_latest_trusted_outside_proof_on_the_exact_head(self):
        import json
        import os
        import subprocess
        import textwrap
        from unittest.mock import patch
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        block = caller.split('  required:\n', 1)[1]
        self.assertIn("PROOF_REQUIRED: ${{ github.event_name == 'pull_request' && needs.changes.outputs.proof_run == 'true' }}", block)
        self.assertIn('PROOF_SHA: ${{ github.event.pull_request.head.sha }}', block)
        code = textwrap.dedent(block.split("          python3 - <<'PYTHON'\n", 1)[1].split('          PYTHON', 1)[0])
        jobs = block.split('needs: [', 1)[1].split(']', 1)[0].split(', ')
        prefix = 'https://github.com/hardimpactdev/orbit/actions/runs/'
        good = {'context': 'Orbit outside proof', 'state': 'success',
                'creator': {'login': 'github-actions[bot]'}, 'target_url': prefix + '123'}
        examples = [(True, [], False), (True, [good], True), (False, [], True),
                    (True, [good, {**good, 'state': 'failure'}], True)]
        examples += [(True, [{**good, 'state': state}, good], False) for state in ('pending', 'failure', 'error')]
        examples += [(True, [{**good, 'creator': {'login': 'contributor'}}], False),
                     (True, [{**good, 'target_url': 'https://github.com/fork/orbit/actions/runs/123'}], False)]
        for selected, statuses, passed in examples:
            with self.subTest(statuses=statuses):
                needs = {job: {'result': 'success'} for job in jobs}
                needs['proof-run']['result'] = 'skipped'
                selection = {job: False for job in jobs if job not in ('changes', 'gateway-release', 'proof-run')}
                needs['changes']['outputs'] = {'jobs': json.dumps(selection), 'gateway_release': 'false'}
                env = {'NEEDS': json.dumps(needs), 'PROOF_REQUIRED': str(selected).lower(), 'FORK_PROOF': 'true',
                       'PROOF_SHA': 'a' * 40, 'GH_REPO': 'hardimpactdev/orbit', 'PROOF_RUN_URL_PREFIX': prefix}
                with patch.dict(os.environ, env), patch.object(subprocess, 'check_output', return_value=json.dumps([statuses]).encode()) as github:
                    result = None
                    try:
                        exec(code, {})
                    except SystemExit as error:
                        result = error.code
                    self.assertEqual(result is None, passed, result)
                    if selected:
                        github.assert_called_once_with([
                            'timeout', '60', 'gh', 'api', '--paginate', '--slurp',
                            'repos/hardimpactdev/orbit/commits/' + 'a' * 40 + '/statuses?per_page=100',
                        ], timeout=65)
                    else:
                        github.assert_not_called()

    def test_units_use_one_pool_job_with_deno_parallelism(self):
        block = SOURCE.split('  tests:\n')[1]
        self.assertNotIn('  test-shards:', SOURCE)
        self.assertNotIn('matrix:', block)
        self.assertNotIn('needs:', block)
        self.assertNotIn('continue-on-error', block)
        self.assertIn('DENO_JOBS: "4"', block)
        self.assertIn("deno test --parallel -A extensions/behaviors/operations", block)


if __name__ == '__main__':
    unittest.main()
