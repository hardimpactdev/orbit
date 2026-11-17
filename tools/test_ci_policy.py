"""Required checks: every selected job, and a selected proof, gates the result."""
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / '.github/workflows/swamp-shape.yml').read_text()


class RequiredGateTest(unittest.TestCase):
    def test_git_build_watch_jobs_have_total_time_limits(self):
        import yaml
        workflow = yaml.safe_load((ROOT / '.github/workflows/git-build-watch.yml').read_text())
        for name, maximum in (('check', 10), ('publish', 5)):
            with self.subTest(job=name):
                minutes = workflow['jobs'][name].get('timeout-minutes')
                self.assertIs(type(minutes), int, 'set an explicit total job timeout')
                self.assertGreater(minutes, 0)
                self.assertLessEqual(minutes, maximum)

    def test_git_build_watch_creates_an_unassigned_issue(self):
        import json
        import yaml
        workflow = yaml.safe_load((ROOT / '.github/workflows/git-build-watch.yml').read_text())
        script = next(step['with']['script'] for step in workflow['jobs']['publish']['steps']
                      if 'script' in step.get('with', {}))
        harness = """
            const calls = [];
            const context = {repo: {owner: 'hardimpactdev', repo: 'orbit'}};
            const github = {
              paginate: async () => [],
              rest: {issues: {listForRepo: () => {}, create: async payload => calls.push(payload)}}
            };
            const require = () => ({readFileSync: () => '<!-- orbit-git-build-watch -->\\nNew Git build'});
        """
        result = subprocess.run(['node', '-e', harness + '\n(async () => {\n' + script
                                 + '\nconsole.log(JSON.stringify(calls));\n})();'],
                                text=True, capture_output=True, check=True, timeout=10)
        self.assertEqual(json.loads(result.stdout), [{
            'owner': 'hardimpactdev', 'repo': 'orbit',
            'title': 'Review Git recipe security updates',
            'body': '<!-- orbit-git-build-watch -->\nNew Git build',
        }])

    def test_local_fixture_times_are_never_committed(self):
        paths = subprocess.check_output([
            'git', 'ls-files', '-z', '--', 'ci-unit-times.json', '**/ci-unit-times.json',
        ], cwd=ROOT, text=True, timeout=10).strip('\0')
        self.assertEqual(paths, '', 'ci-unit-times.json: this is a local run output; remove it')

    def test_main_push_dispatches_cli_release_after_delivery_without_a_variable_gate(self):
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        block = caller.split('  cli-release:\n', 1)[1]
        condition = next(line.strip() for line in block.splitlines() if line.startswith('    if:'))
        self.assertEqual(condition, "if: always() && needs.delivery.result == 'success' && github.ref == 'refs/heads/main' && "
                                    "contains(fromJSON('[\"push\", \"workflow_dispatch\"]'), github.event_name)")
        self.assertIn('    needs: delivery\n', block)
        # Only delivery's own result gates the dispatch. The implicit success() would also skip it when an unselected test
        # job in Required's needs was skipped at job level (main 4e4cf83d, d9d10a93, 02f630f8 dispatched no release).
        skippable = [name for name in ('gateway-tests', 'cli-tests')
                     if "if: ${{ toJSON(fromJSON(needs.changes.outputs.jobs)['" + name + "']) != 'false' }}" in caller]
        self.assertTrue(skippable, 'the test assumes job-level skips in Required needs; update it if they are gone')
        self.assertIn('      actions: write\n', block)
        self.assertIn('gh workflow run orbit-cli-release.yml --repo "$GITHUB_REPOSITORY" --ref main -f commit="$GITHUB_SHA"', block)

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
        self.assertIn("    if: always() && github.ref != 'refs/heads/main'", caller)
        for source in [caller, SOURCE]:
            self.assertNotIn('merge_group', source)
            self.assertIn('github.event.pull_request.base.sha || github.event.before', source)

    def test_run_on_main_builds_and_publishes_the_release_web_build(self):
        # Required checks pass in the run on main, so that run must hold the Gateway release's web build.
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        for source in [caller.split('  changes:\n')[1].split('\n  swamp:\n')[0]]:
            self.assertIn("MAIN: ${{ github.ref == 'refs/heads/main' }}", source)
        import yaml
        steps = yaml.safe_load(caller)['jobs']['web']['steps']
        publish = next(s for s in steps if s.get('id') == 'web-artifact')
        self.assertEqual(publish['if'], "github.event_name == 'pull_request' || github.ref == 'refs/heads/main'")
        self.assertIn("format('web-dist-{0}', github.sha)", publish['with']['name'])
        self.assertIn("format('web-pr-{0}-{1}', github.run_id, github.run_attempt)", publish['with']['name'])

    def test_slow_jobs_are_absent_from_required_ci(self):
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        self.assertNotIn('project-check', caller)
        self.assertNotIn('  native-plan:', SOURCE)
        self.assertNotIn('  native-tests:', SOURCE)

    def run_required(self, *, proof_required=False,
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
        needs['self-hosted-fixtures']['result'] = 'skipped'
        selection = {job: True for job in jobs if job not in ('changes', 'ci-policy', 'gateway-release', 'fixtures', 'self-hosted-fixtures')}
        selection['swamp'] = True
        for name, value in (selections or {}).items():
            if value is None:
                selection.pop(name, None)
            else:
                selection[name] = value
        needs['changes']['outputs'] = {'jobs': json.dumps(selection), 'gateway_release': 'false', 'fixture_shards': '[]', 'fixture_capacity': '[]', 'self_hosted_shards': '[]'}
        rows = {name: {'name': name, 'status': 'completed', 'conclusion': 'success', 'id': 1, 'run_attempt': 1} for name in (
            'Swamp / Swamp shape gate', 'Swamp / Swamp workflow validate', 'Swamp / Swamp Deno tests', 'CI proof')}
        rows['CI proof eligibility'] = {'name': 'CI proof eligibility', 'status': 'completed', 'conclusion': 'success',
                                        'id': 2, 'run_attempt': 1, 'steps': []}
        for name, result in (results or {}).items():
            if name in needs:
                needs[name]['result'] = result
            else:
                rows[name].update(status='in_progress' if result == 'pending' else 'completed', conclusion=result)
        env = {'NEEDS': json.dumps(needs), 'PROOF_REQUIRED': str(proof_required).lower(),
               'FORK_PROOF': str(fork).lower(), 'SELF_HOSTED_FIXTURES': 'fork' if fork else 'run',
               'CI_RUN_ID': '123',
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

    def test_one_always_reporting_gate_requires_swamp_statically_and_waits_only_for_proof(self):
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        self.assertIn("name: ${{ github.ref == 'refs/heads/main' && 'PR required checks' || 'Required checks' }}", caller)
        block = caller.split('  required:\n', 1)[1]
        self.assertIn('    if: always()', block)
        jobs = block.split('needs: [', 1)[1].split(']', 1)[0].split(', ')
        self.assertIn('gateway-tests', jobs)
        self.assertIn('cli-tests', jobs)
        self.assertIn('sdk-tests', jobs)
        self.assertIn('swamp', jobs)
        self.assertNotIn('proof-run', jobs)
        self.assertIn('      actions: read', block)
        # No switch makes the proof report-only (ADR 0262).
        self.assertNotIn('BLOCKING', block)

    def test_every_other_selected_failure_or_skip_still_blocks(self):
        for component in ('gateway-tests', 'cli-tests', 'sdk-tests', 'agent', 'php', 'swamp'):
            for result in ('failure', 'cancelled', 'skipped'):
                with self.subTest(component=component, result=result):
                    error, _, _ = self.run_required(results={component: result})
                    self.assertIsNotNone(error)

    def test_gateway_pest_skip_requires_explicit_false_and_complete_boolean_selection(self):
        error, _, _ = self.run_required(results={'gateway-tests': 'skipped'},
                                         selections={'gateway-tests': False})
        self.assertIsNone(error)
        for selections in ({'gateway-tests': None}, {'gateway-tests': 'false'},
                           {'gateway-tests': 0}, {'unexpected-job': True}):
            with self.subTest(selections=selections):
                error, _, _ = self.run_required(selections=selections)
                self.assertEqual(error, 'Incomplete build selections')
        error, _, _ = self.run_required(results={'changes': 'failure'})
        self.assertEqual(error, 'Build input selection failed')

    def test_cli_and_sdk_pest_skip_require_explicit_false_and_complete_boolean_selection(self):
        for job in ('cli-tests', 'sdk-tests'):
            error, _, _ = self.run_required(results={job: 'skipped'}, selections={job: False})
            self.assertIsNone(error)
            for value in (None, 'false', 0):
                with self.subTest(job=job, selection=value):
                    error, _, _ = self.run_required(selections={job: value})
                    self.assertEqual(error, 'Incomplete build selections')

    def test_an_unselected_proof_needs_no_api_wait_and_a_selected_one_always_gates(self):
        for result in ('pending', 'failure', 'cancelled'):
            error, github, sleep = self.run_required(results={'CI proof': result})
            self.assertIsNone(error)
            self.assertEqual(github.call_count, 0)
            sleep.assert_not_called()
        for result in ('failure', 'cancelled'):
            error, _, _ = self.run_required(proof_required=True, results={'CI proof': result})
            self.assertIsNotNone(error)

    def test_default_requires_swamp_and_selected_proof_success(self):
        for component in ('swamp', 'CI proof'):
            for result in ('success', 'failure', 'skipped', 'cancelled'):
                error, _, _ = self.run_required(proof_required=True, results={component: result})
                self.assertEqual(error is None, result == 'success', (component, result, error))

    def test_default_waits_for_jobs_missing_from_the_first_api_response(self):
        rows = [{'name': name, 'status': 'completed', 'conclusion': 'success', 'id': 1, 'run_attempt': 1} for name in (
            'Swamp / Swamp shape gate', 'Swamp / Swamp workflow validate', 'Swamp / Swamp Deno tests', 'CI proof')]
        error, github, sleep = self.run_required(proof_required=True, snapshots=[[], rows])
        self.assertIsNone(error)
        self.assertEqual(github.call_count, 2)
        sleep.assert_called_once_with(15)

    def test_rerun_waits_for_the_newest_proof_attempt(self):
        fast = [{'name': name, 'status': 'completed', 'conclusion': 'success', 'id': 1, 'run_attempt': 1}
                for name in ('Swamp / Swamp workflow validate', 'Swamp / Swamp Deno tests')]
        old = {'name': 'CI proof', 'status': 'completed', 'conclusion': 'failure',
               'id': 2, 'run_attempt': 1}
        new = {**old, 'status': 'in_progress', 'conclusion': None, 'id': 3, 'run_attempt': 2}
        done = {**new, 'status': 'completed', 'conclusion': 'success'}
        error, github, sleep = self.run_required(proof_required=True, snapshots=[[*fast, new, old], [*fast, done, old]])
        self.assertIsNone(error)
        self.assertEqual(github.call_count, 2)
        sleep.assert_called_once_with(15)
        error, _, sleep = self.run_required(proof_required=True, snapshots=[[*fast, done]])
        self.assertIsNone(error)
        sleep.assert_not_called()

    def test_fork_gate_requires_the_trusted_outside_proof_status(self):
        good = {'context': 'Orbit outside proof', 'state': 'success',
                'creator': {'login': 'github-actions[bot]'},
                'target_url': 'https://github.com/hardimpactdev/orbit/actions/runs/123'}
        for statuses, passed in (([], False), ([good], True),
                                 ([{**good, 'state': 'pending'}, good], False),
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
            error, github, sleep = self.run_required(proof_required=True, results={'CI proof eligibility': result, 'CI proof': 'skipped'})
            self.assertEqual(error, f'Proof eligibility failed: {result}')
            sleep.assert_not_called()
        error, _, sleep = self.run_required(proof_required=True, results={'CI proof': 'skipped'})
        self.assertIn('without ineligible metadata evidence', error)
        sleep.assert_not_called()

    def proof_selected(self, context, key='PROOF_REQUIRED: '):
        """Evaluate the Required step's proof selection, a conjunction of `name == 'value'` terms, for one context."""
        import re
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        expression = caller.split('  required:\n', 1)[1].split(key + '${{ ', 1)[1].split(' }}', 1)[0]
        terms = [re.fullmatch(r"([a-z_.-]+(?:\.[A-Za-z_]+)*) == '([^']*)'", term.strip()) for term in expression.split('&&')]
        if key != 'PROOF_REQUIRED: ':
            terms = terms[:-1]  # the timeout's `275 || 2` tail
        self.assertTrue(all(terms), expression)
        return all(context[term[1]] == term[2] for term in terms)

    def test_the_execution_switch_selects_the_proof_for_required_checks(self):
        # The proof jobs start only with the switch on; Required must expect them under the same condition.
        selected_pr = {'github.event_name': 'pull_request', 'needs.changes.outputs.proof_run': 'true'}
        switched_off = self.proof_selected({**selected_pr, 'vars.ORBIT_PROOF_ENABLED': 'false'})
        self.assertFalse(switched_off)
        error, _, sleep = self.run_required(proof_required=switched_off,
                                            results={'CI proof eligibility': 'skipped', 'CI proof': 'skipped'})
        self.assertIsNone(error)
        sleep.assert_not_called()
        switched_on = self.proof_selected({**selected_pr, 'vars.ORBIT_PROOF_ENABLED': 'true'})
        self.assertTrue(switched_on)
        error, _, _ = self.run_required(proof_required=switched_on,
                                        results={'CI proof eligibility': 'skipped', 'CI proof': 'skipped'})
        self.assertEqual(error, 'Proof eligibility failed: skipped')
        # Required keeps the proof's wait budget only while a proof can run.
        for switch in ('true', 'false'):
            self.assertEqual(self.proof_selected({**selected_pr, 'vars.ORBIT_PROOF_ENABLED': switch}, key='timeout-minutes: '),
                             switch == 'true')
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        for job in ('proof-eligibility', 'proof-run'):
            self.assertIn("vars.ORBIT_PROOF_ENABLED == 'true'", caller.split(f'\n  {job}:\n', 1)[1].split('\n    runs-on:', 1)[0])

    def test_missing_or_stale_eligibility_evidence_fails_closed(self):
        from unittest.mock import patch
        proof = {'name': 'CI proof', 'status': 'completed', 'conclusion': 'skipped', 'id': 2, 'run_attempt': 2}
        eligibility = {'name': 'CI proof eligibility', 'status': 'completed', 'conclusion': 'success', 'id': 1, 'run_attempt': 1,
                       'steps': [{'name': 'Skip ineligible proof', 'conclusion': 'success'}]}
        error, _, sleep = self.run_required(proof_required=True, snapshots=[[proof, eligibility]])
        self.assertIn('same attempt', error)
        sleep.assert_not_called()
        with patch('time.monotonic', side_effect=[0, 0, 61]):
            error, _, sleep = self.run_required(proof_required=True, snapshots=[[proof]])
        self.assertEqual(error, 'Missing proof eligibility evidence')
        sleep.assert_not_called()
        eligibility['run_attempt'] = 2
        error, github, sleep = self.run_required(proof_required=True, snapshots=[[proof], [proof, eligibility]])
        self.assertIsNone(error)
        self.assertEqual(github.call_count, 2)
        sleep.assert_called_once_with(15)

    def test_proof_preflight_is_selected_hosted_bounded_and_outside_required_dependencies(self):
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        preflight = caller.split('  proof-eligibility:\n', 1)[1].split('  proof-run:\n', 1)[0]
        proof = caller.split('  proof-run:\n', 1)[1].split('  swamp:\n', 1)[0]
        required = caller.split('  required:\n', 1)[1].split('  delivery:\n', 1)[0]
        for condition in ("github.event_name == 'pull_request'", 'github.event.pull_request.head.repo.full_name == github.repository',
                          "needs.changes.outputs.proof_run == 'true'"):
            self.assertIn(condition, preflight)
            self.assertIn(condition, proof)
        for block in (preflight, proof):
            self.assertIn('    permissions:\n      contents: read\n      pull-requests: read', block)
            self.assertNotIn(': write', block)
        self.assertIn('runs-on: ubuntu-26.04', preflight)
        self.assertIn('timeout-minutes: 1', preflight)
        self.assertIn('    needs: changes', preflight)
        self.assertIn('ref: ${{ github.sha }}', preflight)
        self.assertIn('persist-credentials: false', preflight)
        self.assertNotIn('orbit proof:run', preflight)
        self.assertIn('needs: [changes, proof-eligibility]', proof)
        self.assertIn("needs.proof-eligibility.outputs.eligible == 'true'", proof)
        self.assertIn("PROOF_CHECK_ELIGIBILITY: 'true'", proof)
        self.assertIn('bin/ci-proof-eligibility', proof)
        self.assertNotIn('proof-eligibility', required.split('needs: [', 1)[1].split(']', 1)[0])
        outside = (ROOT / '.github/workflows/proof-outside.yml').read_text()
        self.assertNotIn('PROOF_CHECK_ELIGIBILITY', outside)
        self.assertIn('ref: ${{ github.sha }}', outside)
        self.assertIn('pull_request_target:', outside)

    def test_proof_admission_requires_explicit_enablement(self):
        import yaml
        for filename, jobs in [('ci.yml', ('proof-eligibility', 'proof-run')),
                               ('proof-outside.yml', ('proof-run',))]:
            workflow = yaml.safe_load((ROOT / '.github/workflows' / filename).read_text())
            for job in jobs:
                condition = workflow['jobs'][job]['if']
                self.assertTrue(condition.startswith("vars.ORBIT_PROOF_ENABLED == 'true' && "),
                                (filename, job, condition))
                self.assertNotIn('||', condition)

    def test_units_use_one_pool_job_with_deno_parallelism(self):
        block = SOURCE.split('\n  tests:\n')[1]
        self.assertNotIn('  test-shards:', SOURCE)
        self.assertNotIn('matrix:', block)
        self.assertNotIn('needs:', block)
        self.assertNotIn('continue-on-error', block)
        self.assertIn('DENO_JOBS: "4"', block)
        self.assertIn("deno test --parallel -A extensions/behaviors/operations", block)

    def test_self_hosted_php_is_verified_from_the_repository_root(self):
        action = (ROOT / '.github/actions/php/action.yml').read_text()
        installer, verifier = action.split('    - name: Verify the managed runner PHP build')
        self.assertIn("if: runner.environment != 'self-hosted'", installer)
        self.assertIn("if: runner.environment == 'self-hosted'", verifier)
        self.assertIn('working-directory: ${{ github.workspace }}', verifier)
        self.assertIn('python3 bin/php-build verify --profile ci-runner', verifier)

    def test_managed_tools_match_the_hosted_job_pins(self):
        import re
        setup = (ROOT / 'tools/ci-runners/setup.sh').read_text()
        for variable, workflow, key in [('BUN_VERSION', 'ci.yml', 'bun-version'),
                                         ('DENO_VERSION', 'swamp-shape.yml', 'deno-version')]:
            version = re.search(r'^' + variable + r'=(.+)$', setup, re.M).group(1)
            self.assertEqual(set(re.findall(key + r': v?([\d.]+)',
                             (ROOT / '.github/workflows' / workflow).read_text())), {version})

    def test_swamp_runs_hosted_and_proof_still_guards_forks(self):
        tests = SOURCE.split('\n  tests:\n', 1)[1]
        self.assertIn('runs-on: ubuntu-26.04', tests)
        self.assertNotIn('ORBIT_SABRE_RUNNER', SOURCE)
        for command in ('sudo -n true', 'docker info', 'shivammathur/setup-php'):
            self.assertNotIn(command, tests)
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        proof = caller.split('  proof-run:\n', 1)[1].split('  swamp:\n', 1)[0]
        self.assertIn("github.event_name == 'pull_request'", proof)
        self.assertIn('github.event.pull_request.head.repo.full_name == github.repository', proof)
        self.assertIn('cancel-in-progress: true', proof)
        self.assertIn('PROOF_RECEIPT:', proof)
        self.assertIn('if: always()', proof)
        self.assertNotIn('not_run_capacity', caller)
        self.assertNotIn('PROOF_REPORT_ONLY', proof)
        for name in ('docs', 'web', 'api-reference', 'gateway-tests', 'cli-tests'):
            block = re.search(r'^  ' + re.escape(name) + r':\n(.*?)(?=^  [\w-]+:|\Z)', caller, re.M | re.S).group(1)
            self.assertIn('runs-on: ubuntu-26.04', block)
        self.assertIn('runs-on: ubuntu-26.04', SOURCE.split('\n  validate:\n', 1)[1])

    def test_swamp_skip_requires_explicit_false_and_never_uses_the_jobs_api(self):
        error, github, _ = self.run_required(results={'swamp': 'skipped'}, selections={'swamp': False})
        self.assertIsNone(error)
        github.assert_not_called()
        for value in (None, 'false', 0):
            error, _, _ = self.run_required(selections={'swamp': value})
            self.assertEqual(error, 'Incomplete build selections')


if __name__ == '__main__':
    unittest.main()
