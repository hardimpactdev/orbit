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

    def run_required(self, *, slow='true', proof_blocking='true', proof_required=False,
                     fork=False, results=None, statuses=None, snapshots=None, selections=None):
        import json
        import os
        import subprocess
        import textwrap
        from unittest.mock import patch
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        block = caller.split('  required:\n', 1)[1]
        code = textwrap.dedent(block.split("          python3 - <<'PYTHON'\n", 1)[1].split('          PYTHON', 1)[0])
        jobs = block.split('needs: [', 1)[1].split(']', 1)[0].split(', ')
        needs = {job: {'result': 'success'} for job in jobs}
        selection = {job: True for job in jobs if job not in ('changes', 'gateway-release')}
        selection['swamp'] = True
        for name, value in (selections or {}).items():
            if value is None:
                selection.pop(name, None)
            else:
                selection[name] = value
        needs['changes']['outputs'] = {'jobs': json.dumps(selection), 'gateway_release': 'false'}
        rows = {name: {'name': name, 'status': 'completed', 'conclusion': 'success', 'id': 1, 'run_attempt': 1} for name in (
            'Swamp / Swamp shape gate', 'Swamp / Swamp workflow validate', 'Swamp / Swamp Deno tests', 'CI proof')}
        for name, result in (results or {}).items():
            if name in needs:
                needs[name]['result'] = result
            else:
                rows[name].update(status='in_progress' if result == 'pending' else 'completed', conclusion=result)
        env = {'NEEDS': json.dumps(needs), 'PROOF_REQUIRED': str(proof_required).lower(),
               'FORK_PROOF': str(fork).lower(), 'PROOF_BLOCKING': proof_blocking,
               'SLOW_CHECKS_BLOCKING': slow, 'CI_RUN_ID': '123',
               'PROOF_SHA': 'a' * 40, 'GH_REPO': 'hardimpactdev/orbit',
               'PROOF_RUN_URL_PREFIX': 'https://github.com/hardimpactdev/orbit/actions/runs/'}
        snapshots = iter(snapshots or [list(rows.values())])
        def api(argv, **options):
            if '/statuses?' in argv[-1]:
                return json.dumps([statuses or []]).encode()
            self.assertEqual(argv[-1], 'repos/hardimpactdev/orbit/actions/runs/123/jobs?filter=all&per_page=100')
            self.assertIn('--paginate', argv)
            return json.dumps([{'jobs': next(snapshots)}]).encode()
        result = None
        with patch.dict(os.environ, env), patch.object(subprocess, 'check_output', side_effect=api) as github, \
                patch('time.sleep') as sleep:
            try:
                exec(code, {})
            except SystemExit as error:
                result = error.code
        return result, github, sleep

    def test_one_always_reporting_gate_has_no_static_slow_dependencies(self):
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        self.assertEqual(caller.count('    name: Required checks\n'), 1)
        block = caller.split('  required:\n', 1)[1]
        self.assertIn('    if: always()', block)
        jobs = block.split('needs: [', 1)[1].split(']', 1)[0].split(', ')
        self.assertIn('gateway-tests', jobs)
        self.assertIn('cli-tests', jobs)
        self.assertNotIn('swamp', jobs)
        self.assertNotIn('proof-run', jobs)
        self.assertIn('      actions: read', block)
        self.assertIn("SLOW_CHECKS_BLOCKING: ${{ vars.ORBIT_SLOW_CHECKS_BLOCKING != 'false' }}", block)
        self.assertIn("PROOF_BLOCKING: ${{ vars.ORBIT_PROOF_BLOCKING != 'false' }}", block)

    def test_every_other_selected_failure_or_skip_still_blocks(self):
        for component in ('gateway-tests', 'cli-tests', 'pi', 'agent', 'php', 'Swamp / Swamp workflow validate', 'Swamp / Swamp Deno tests'):
            for slow in ('true', 'false'):
                for result in ('failure', 'cancelled', 'skipped'):
                    with self.subTest(component=component, slow=slow, result=result):
                        error, _, _ = self.run_required(slow=slow, results={component: result})
                        self.assertIsNotNone(error)

    def test_gateway_pest_skip_requires_explicit_false_and_complete_boolean_selection(self):
        for slow in ('true', 'false'):
            error, _, _ = self.run_required(slow=slow, results={'gateway-tests': 'skipped'},
                                             selections={'gateway-tests': False})
            self.assertIsNone(error)
            for selections in ({'gateway-tests': None}, {'gateway-tests': 'false'},
                               {'gateway-tests': 0}, {'unexpected-job': True}):
                with self.subTest(slow=slow, selections=selections):
                    error, _, _ = self.run_required(slow=slow, selections=selections)
                    self.assertEqual(error, 'Incomplete build selections')
        error, _, _ = self.run_required(results={'changes': 'failure'})
        self.assertEqual(error, 'Build input selection failed')

    def test_cli_pest_skip_requires_explicit_false_and_complete_boolean_selection(self):
        for slow in ('true', 'false'):
            error, _, _ = self.run_required(slow=slow, results={'cli-tests': 'skipped'},
                                             selections={'cli-tests': False})
            self.assertIsNone(error)
            for value in (None, 'false', 0):
                with self.subTest(slow=slow, selection=value):
                    error, _, _ = self.run_required(slow=slow, selections={'cli-tests': value})
                    self.assertEqual(error, 'Incomplete build selections')

    def test_non_blocking_slow_jobs_need_not_complete_on_main_or_a_pr(self):
        for proof_required in (False, True):
            for result in ('pending', 'failure', 'cancelled'):
                error, github, sleep = self.run_required(slow='false', proof_required=proof_required,
                    results={'Swamp / Swamp shape gate': result, 'CI proof': result})
                self.assertIsNone(error)
                self.assertEqual(github.call_count, 1)
                sleep.assert_not_called()

    def test_default_requires_shape_and_selected_proof_success(self):
        for component in ('Swamp / Swamp shape gate', 'CI proof'):
            for result in ('success', 'failure', 'skipped', 'cancelled'):
                error, _, _ = self.run_required(proof_required=True, results={component: result})
                self.assertEqual(error is None, result == 'success', (component, result, error))

    def test_existing_proof_switch_does_not_disable_shape(self):
        error, _, sleep = self.run_required(proof_blocking='false', proof_required=True,
                                            results={'CI proof': 'pending'})
        self.assertIsNone(error)
        sleep.assert_not_called()
        error, _, _ = self.run_required(proof_blocking='false', results={'Swamp / Swamp shape gate': 'failure'})
        self.assertIsNotNone(error)

    def test_default_waits_for_jobs_missing_from_the_first_api_response(self):
        rows = [{'name': name, 'status': 'completed', 'conclusion': 'success', 'id': 1, 'run_attempt': 1} for name in (
            'Swamp / Swamp shape gate', 'Swamp / Swamp workflow validate', 'Swamp / Swamp Deno tests', 'CI proof')]
        error, github, sleep = self.run_required(proof_required=True, snapshots=[rows[1:3], rows])
        self.assertIsNone(error)
        self.assertEqual(github.call_count, 2)
        sleep.assert_called_once_with(15)

    def test_rerun_retains_old_successes_but_waits_for_the_newest_shape_attempt(self):
        fast = [{'name': name, 'status': 'completed', 'conclusion': 'success', 'id': 1, 'run_attempt': 1}
                for name in ('Swamp / Swamp workflow validate', 'Swamp / Swamp Deno tests')]
        old = {'name': 'Swamp / Swamp shape gate', 'status': 'completed', 'conclusion': 'failure',
               'id': 2, 'run_attempt': 1}
        new = {**old, 'status': 'in_progress', 'conclusion': None, 'id': 3, 'run_attempt': 2}
        done = {**new, 'status': 'completed', 'conclusion': 'success'}
        error, github, sleep = self.run_required(snapshots=[[*fast, new, old], [*fast, done, old]])
        self.assertIsNone(error)
        self.assertEqual(github.call_count, 2)
        sleep.assert_called_once_with(15)
        error, _, sleep = self.run_required(snapshots=[[*fast, done]])
        self.assertIsNone(error)
        sleep.assert_not_called()

    def test_fork_gate_requires_trusted_status_only_while_proof_blocks(self):
        good = {'context': 'Orbit outside proof', 'state': 'success',
                'creator': {'login': 'github-actions[bot]'},
                'target_url': 'https://github.com/hardimpactdev/orbit/actions/runs/123'}
        for statuses, passed in (([], False), ([good], True),
                                 ([{**good, 'state': 'pending'}, good], False),
                                 ([{**good, 'creator': {'login': 'contributor'}}], False)):
            error, _, _ = self.run_required(proof_required=True, fork=True, statuses=statuses)
            self.assertEqual(error is None, passed, error)
        for settings in ({'slow': 'false'}, {'proof_blocking': 'false'}):
            error, github, _ = self.run_required(proof_required=True, fork=True, **settings)
            self.assertIsNone(error)
            self.assertFalse(any('/statuses?' in call.args[0][-1] for call in github.call_args_list))

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
