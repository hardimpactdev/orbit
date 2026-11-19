"""Required checks: every selected job, and a selected proof, gates the result."""
import json
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

    def test_main_push_dispatches_cli_release_after_the_release_inputs_without_a_variable_gate(self):
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        block = caller.split('  cli-release:\n', 1)[1]
        condition = next(line.strip() for line in block.splitlines() if line.startswith('    if:'))
        self.assertEqual(condition, "if: always() && needs.main-release-inputs.result == 'success' && github.ref == 'refs/heads/main' && "
                                    "contains(fromJSON('[\"push\", \"workflow_dispatch\"]'), github.event_name)")
        self.assertIn('    needs: main-release-inputs\n', block)
        # Only that job's own result gates the dispatch. The implicit success() would also skip it when an unselected test
        # job in Required's needs was skipped at job level (main 4e4cf83d, d9d10a93, 02f630f8 dispatched no release).
        skippable = [name for name in ('gateway-tests', 'cli-tests')
                     if "if: ${{ toJSON(fromJSON(needs.changes.outputs.jobs)['" + name + "']) != 'false' }}" in caller]
        self.assertTrue(skippable, 'the test assumes job-level skips in Required needs; update it if they are gone')
        self.assertIn('      actions: write\n', block)
        self.assertIn('gh workflow run orbit-cli-release.yml --repo "$GITHUB_REPOSITORY" --ref main -f commit="$GITHUB_SHA"', block)
        self.assertEqual(caller.count('gh workflow run orbit-cli-release.yml'), 1,
                         'main delivery must dispatch the CLI release only once')

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

    def test_the_merge_queue_run_is_the_one_run_on_the_tree_that_reaches_main(self):
        import yaml
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        workflow = yaml.safe_load(caller)
        # PyYAML reads the key `on` as True.
        self.assertIn('merge_group', workflow[True])
        self.assertIn("    if: always() && github.ref != 'refs/heads/main'", caller)
        plan = next(step for step in workflow['jobs']['changes']['steps'] if step.get('id') == 'plan')
        self.assertEqual('${{ github.event.merge_group.base_sha }}', plan['env']['QUEUE_BASE'])
        self.assertIn('elif [ "$GITHUB_EVENT_NAME" = merge_group ]; then', plan['run'])
        self.assertIn('BASE="$(python3 bin/ci-tree-receipt baseline)"', plan['run'])
        self.assertIn('args+=(--merge-group)', plan['run'])
        # A pull request no longer waits for main: its plan needs no delivered base and writes no delivery evidence.
        self.assertNotIn('PR delivery baseline', caller)
        self.assertNotIn('ci-tree-receipt record', caller)
        for name in ('SWAMP_API_KEY', 'SWAMP_SIGNIN_TOKEN'):
            self.assertIn("github.event_name == 'merge_group'", workflow['jobs']['swamp']['secrets'][name])
        for source in [caller, SOURCE]:
            self.assertIn('github.event.pull_request.base.sha || github.event.before', source)

    def test_main_promotes_the_verified_web_artifact_from_the_merge_queue_run(self):
        import yaml
        caller = yaml.safe_load((ROOT / '.github/workflows/ci.yml').read_text())['jobs']
        publish = next(step for step in caller['web']['steps'] if step.get('id') == 'web-artifact')
        self.assertEqual(publish['if'], "github.event_name == 'merge_group'")
        self.assertEqual(publish['with']['name'], 'web-queue-${{ github.run_id }}-${{ github.run_attempt }}')
        promote = next(step for step in caller['changes']['steps'] if step.get('name') == 'Publish the verified web build for main')
        self.assertEqual(promote['if'], "steps.reuse.outputs.reuse == 'true'")
        self.assertEqual(promote['with']['name'], 'web-dist-${{ github.sha }}')

    def test_web_tests_gate_the_build_with_locked_browsers(self):
        import yaml
        workflow = yaml.safe_load((ROOT / '.github/workflows/ci.yml').read_text())
        job = workflow['jobs']['web']
        self.assertEqual(('ubuntu-26.04', 4), (job['runs-on'], job['timeout-minutes']))
        steps = job['steps']
        commands = [step.get('run', '') for step in steps]
        required = [
            'bun install --frozen-lockfile',
            'bun run test:unit',
            'bun run test:browser',
            'bun run build && test -s dist/index.html && test -s dist/version.json',
        ]
        indexes = [commands.index(command) for command in required]
        self.assertEqual(indexes, sorted(indexes))
        # The browsers and their system libraries come from the shared action, between the install and the tests.
        browsers = steps.index({'uses': './.github/actions/web-browsers'})
        self.assertTrue(indexes[0] < browsers < indexes[1])
        publish = next(i for i, step in enumerate(steps) if step.get('id') == 'web-artifact')
        self.assertLess(indexes[-1], publish)
        for index in indexes:
            self.assertNotIn('if', steps[index])
            self.assertFalse(steps[index].get('continue-on-error', False))

    def test_web_installs_system_libraries_from_cached_packages_without_a_mirror(self):
        import yaml
        workflow = yaml.safe_load((ROOT / '.github/workflows/ci.yml').read_text())['jobs']
        steps = yaml.safe_load((ROOT / '.github/actions/web-browsers/action.yml').read_text())['runs']['steps']
        setup = next(step for step in steps if step.get('id') == 'browser-downloads')
        self.assertIn('Dir::Cache::archives "/var/cache/orbit-playwright-apt"', setup['run'])
        self.assertIn('APT::Keep-Downloaded-Packages', setup['run'])
        cache = next(step for step in steps if step.get('name') == 'Cache browser downloads')
        self.assertEqual({'~/.cache/ms-playwright', '/var/cache/orbit-playwright-apt/*.deb'}, set(cache['with']['path'].splitlines()))
        key = cache['with']['key']
        for identity in ('runner.os', 'runner.arch', 'steps.browser-downloads.outputs.playwright', 'steps.browser-downloads.outputs.image'):
            self.assertIn(identity, key)
        # A miss starts from the newest entry of another image, then from the cache the Web job kept before: the same
        # paths, so GitHub matches it. A cold download of the whole set does not fit the job.
        newer, older = cache['with']['restore-keys'].splitlines()
        self.assertTrue(key.startswith(newer))
        self.assertEqual(older, newer.replace('web-browsers-v3-', 'web-browsers-v2-'))
        installer = steps[-1]
        self.assertEqual(installer['name'], 'Install test browsers and system libraries')
        self.assertNotIn('if', installer)
        run = installer['run']
        # Cached packages install with apt from the files, with no index refresh and no download; then the browsers
        # install alone. Otherwise the mirror path of before runs.
        self.assertIn('apt-get install --yes --no-install-recommends \\\n    --no-download "$archives"/*.deb', run)
        self.assertNotIn('apt-get update', run)
        self.assertNotIn('Dir::Cache::archives=', run)
        order = [run.index(text) for text in ('if offline; then', 'bunx playwright install --only-shell webkit chromium',
                                              'bunx playwright install --with-deps --only-shell webkit chromium', 'dpkg-query -W')]
        self.assertEqual(order, sorted(order))
        # After either path, only the installed versions stay, so the saved set is what one install on this image uses.
        self.assertIn('[ "$(dpkg-query -W -f=\'${Version}\' "$name:$arch" 2>/dev/null)" = "$version" ] || rm -f -- "$deb"', run)
        # The Web job and the job on main run the same steps, so main saves each cache under the key the others read.
        for job in ('web', 'web-test-caches'):
            uses = [step for step in workflow[job]['steps'] if step.get('uses') == './.github/actions/web-browsers']
            self.assertEqual(1, len(uses), job)
            self.assertNotIn('playwright install', json.dumps(workflow[job]))
        warm = workflow['web-test-caches']
        self.assertEqual(warm['if'], "github.ref == 'refs/heads/main' && github.event_name == 'push'")
        self.assertNotIn('needs', warm)
        self.assertNotIn('web-test-caches', json.dumps(workflow['required']) + json.dumps(workflow['delivery']) + json.dumps(workflow['main-required']))

    def test_slow_jobs_are_absent_from_required_ci(self):
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        self.assertNotIn('project-check', caller)
        self.assertNotIn('  native-plan:', SOURCE)
        self.assertNotIn('  native-tests:', SOURCE)

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
        switched_on = self.proof_selected({**selected_pr, 'vars.ORBIT_PROOF_ENABLED': 'true'})
        self.assertTrue(switched_on)
        # Required keeps the proof's wait budget only while a proof can run.
        for switch in ('true', 'false'):
            self.assertEqual(self.proof_selected({**selected_pr, 'vars.ORBIT_PROOF_ENABLED': switch}, key='timeout-minutes: '),
                             switch == 'true')
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        for job in ('proof-eligibility', 'proof-run'):
            self.assertIn("vars.ORBIT_PROOF_ENABLED == 'true'", caller.split(f'\n  {job}:\n', 1)[1].split('\n    runs-on:', 1)[0])

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

if __name__ == '__main__':
    unittest.main()
