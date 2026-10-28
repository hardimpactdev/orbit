"""Exercise bin/bootstrap and the candidate gate (bin/review-check) with real repositories."""
import importlib.machinery
import importlib.util
import json
import os
import shutil
import threading
import time
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.dont_write_bytecode = True
REPOSITORY = Path(sys.argv.pop(1)).resolve()
BIN = REPOSITORY / 'bin'
PROJECTS = ('apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk')
STATE_HOME = tempfile.TemporaryDirectory(prefix='orbit-gate-state-')
# Git's automatic maintenance must finish before these disposable repositories are removed.
# A template covers clones too: it applies where command-level Git configuration does not.
GIT_TEMPLATE = Path(STATE_HOME.name) / 'git-template'
GIT_TEMPLATE.mkdir()
(GIT_TEMPLATE / 'config').write_text('[maintenance]\n\tautoDetach = false\n[gc]\n\tautoDetach = false\n')
os.environ['GIT_TEMPLATE_DIR'] = str(GIT_TEMPLATE)


def git(root, *args):
    return subprocess.run(['git', *args], cwd=root, capture_output=True, text=True, check=True).stdout.strip()


class RepositoryFixture(unittest.TestCase):
    """A main repository whose origin is itself, with one commit that origin/main points at."""

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='orbit gate ')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name) / 'main'
        self.root.mkdir()
        git(self.root, 'init', '-b', 'main')
        git(self.root, 'config', 'user.name', 'Orbit')
        git(self.root, 'config', 'user.email', 'orbit@example.test')
        git(self.root, 'remote', 'add', 'origin', str(self.root))
        (self.root / 'source.php').write_text('base')
        self.commit = self.commit_change('initial')
        git(self.root, 'update-ref', 'refs/remotes/origin/main', self.commit)
        self.common = Path(git(self.root, 'rev-parse', '--path-format=absolute', '--git-common-dir'))

    def commit_change(self, message):
        git(self.root, 'add', '.')
        git(self.root, 'commit', '-m', message)
        return git(self.root, 'rev-parse', 'HEAD')


class BootstrapTest(RepositoryFixture):
    def test_bootstrap_has_one_composer_cache_writer_and_preserves_authentication(self):
        root = Path(self.temporary.name) / 'bootstrap-cache-writers'
        (root / 'bin').mkdir(parents=True)
        (root / 'bin/bootstrap').write_bytes((BIN / 'bootstrap').read_bytes())
        for project in PROJECTS:
            (root / project).mkdir(parents=True)
        for name in ('gateway-app-key', 'worktree-cache'):
            script = root / 'bin' / name
            script.write_text('#!/bin/sh\nexit 0\n')
            script.chmod(0o755)
        composer = root / 'bin/composer'
        composer.write_text('''#!/usr/bin/env python3
import fcntl, json, os, sys
from pathlib import Path
if sys.argv[1] == 'install':
    assert os.environ['BOOTSTRAP_LAST_ASYNC_PID'] == '', 'Composer installs must run synchronously'
    assert json.loads(os.environ['COMPOSER_AUTH']) == {'github-oauth': {'github.com': 'fixture-token'}}
    cache = Path(os.environ['COMPOSER_CACHE_DIR'])
    assert cache == Path(os.environ['BOOTSTRAP_TEST_CACHE'])
    with (cache / 'writer.lock').open('w') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            sys.exit('Concurrent Composer cache writers')
        with (cache / 'installs').open('a') as calls:
            calls.write(os.getcwd() + '\\n')
''')
        composer.chmod(0o755)
        # Observe Bash's async-job state, including jobs that already exited.
        # The old background loop leaves a prior PID in every install after the
        # first, so it fails even if the scheduler runs the jobs one at a time.
        bash_environment = root / 'bash-env'
        bash_environment.write_text('set -T\ntrap \'export BOOTSTRAP_LAST_ASYNC_PID="${!:-}"\' DEBUG\n')
        downloads = root / 'downloads'
        downloads.mkdir()
        result = subprocess.run(['bash', str(root / 'bin/bootstrap'), '--skip-checks'],
                                capture_output=True, text=True, env={
                                    **os.environ, 'BASH_ENV': str(bash_environment), 'PATH': str(root / 'bin') + os.pathsep + os.environ['PATH'],
                                    'COMPOSER_CACHE_DIR': str(downloads), 'BOOTSTRAP_TEST_CACHE': str(downloads),
                                    'COMPOSER_AUTH': json.dumps({'github-oauth': {'github.com': 'fixture-token'}}),
                                })
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual([str(root / project) for project in PROJECTS],
                         (downloads / 'installs').read_text().splitlines())
        self.assertNotIn('fixture-token', result.stdout + result.stderr)

    def bootstrap_fixture(self):
        """A repository with the real bootstrap and its helpers, and a Composer that records each call."""
        root = Path(self.temporary.name) / 'bootstrap'
        (root / 'bin').mkdir(parents=True)
        for name in ('bootstrap', 'gateway-app-key'):
            (root / 'bin' / name).write_bytes((BIN / name).read_bytes())
            (root / 'bin' / name).chmod(0o755)
        # Keep bootstrap Git reads inside the disposable fixture.
        git(root, 'init', '-b', 'main')
        for project in PROJECTS:
            (root / project).mkdir(parents=True)
        self.calls = root / 'calls'
        for name, script in {
            'composer': '#!/bin/sh\nif [ "$1" = install ]; then test "$COMPOSER_CACHE_DIR" = "$BOOTSTRAP_TEST_COMPOSER_CACHE" || exit 8; fi\necho "composer $*" >> "$BOOTSTRAP_TEST_CALLS"\nif [ "$1" = "$BOOTSTRAP_TEST_FAIL" ]; then exit 7; fi\n',
            'worktree-cache': '#!/bin/sh\necho "worktree-cache $*" >> "$BOOTSTRAP_TEST_CALLS"\n',
        }.items():
            path = root / 'bin' / name
            path.write_text(script)
            path.chmod(0o755)
        self.bootstrap_env = {**os.environ, 'ORBIT_HOME': str(root / 'orbit-home'),
                              'PATH': str(root / 'bin') + os.pathsep + os.environ['PATH'],
                              'BOOTSTRAP_TEST_CALLS': str(self.calls), 'BOOTSTRAP_TEST_FAIL': '',
                              'COMPOSER_CACHE_DIR': str(root / 'downloads'),
                              'BOOTSTRAP_TEST_COMPOSER_CACHE': str(root / 'downloads')}
        return root

    def bootstrap(self, root, *arguments, **environment):
        self.calls.unlink(missing_ok=True)
        return subprocess.run(['bash', str(root / 'bin/bootstrap'), *arguments], capture_output=True, text=True,
                              env={**self.bootstrap_env, **environment})

    def test_bootstrap_seeds_quality_caches_then_runs_each_projects_full_tests_and_quality_checks(self):
        root = self.bootstrap_fixture()
        result = self.bootstrap(root)
        self.assertEqual(0, result.returncode, result.stderr)
        driver = subprocess.run(['git', 'config', '--local', '--get', 'merge.orbit-generated.driver'], cwd=root)
        self.assertEqual(1, driver.returncode)
        lines = self.calls.read_text().splitlines()
        self.assertEqual(['composer install --no-interaction --prefer-dist'] * 5 + ['worktree-cache '], lines[:6])
        # Without test impact analysis, the stock full suite is the only test command.
        self.assertEqual(['composer guidance:check'] * 5 + ['composer test', 'composer check'] * 5, lines[6:])

    def test_bootstrap_with_skipped_checks_installs_dependencies_only(self):
        root = self.bootstrap_fixture()
        result = self.bootstrap(root, '--skip-checks')
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertNotIn('composer test', self.calls.read_text())
        self.assertNotIn('composer check', self.calls.read_text())
        self.assertEqual(2, self.bootstrap(root, '--with-node').returncode)

    def test_bootstrap_with_bun_and_rust_installs_their_locked_dependencies(self):
        root = self.bootstrap_fixture()
        for name in ('bun', 'cargo'):
            fake = root / 'bin' / name
            fake.write_text(f'#!/bin/sh\necho "{name} $(basename "$PWD") $*" >> "$BOOTSTRAP_TEST_CALLS"\n')
            fake.chmod(0o755)
        for project in ('apps/web', 'apps/pi-server', 'apps/desktop/src-tauri', 'apps/agent'):
            (root / project).mkdir(parents=True, exist_ok=True)
        result = self.bootstrap(root, '--skip-checks', '--with-bun', '--with-rust')
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(['bun web install --frozen-lockfile', 'bun pi-server install --frozen-lockfile',
                          'bun desktop install --frozen-lockfile', 'cargo agent fetch --locked',
                          'cargo src-tauri fetch --locked'],
                         [line for line in self.calls.read_text().splitlines() if line.startswith(('bun ', 'cargo '))])

    def test_bootstrap_fails_when_any_step_fails_and_runs_no_tests_before_guidance_passes(self):
        root = self.bootstrap_fixture()
        for failure in ('install', 'guidance:check', 'test', 'check'):
            with self.subTest(failure=failure):
                result = self.bootstrap(root, BOOTSTRAP_TEST_FAIL=failure)
                self.assertNotEqual(0, result.returncode)
                if failure in ('install', 'guidance:check'):
                    self.assertNotIn('composer test', self.calls.read_text())


class ReviewGateTest(RepositoryFixture):
    def gate_fixture(self):
        (self.root / 'bin').mkdir()
        (self.root / 'tools').mkdir()
        (self.root / 'tools/check_budget.py').write_bytes(REPOSITORY.joinpath('tools/check_budget.py').read_bytes())
        runner = self.root / 'bin/review-check'
        runner.write_bytes((BIN / 'review-check').read_bytes())
        runner.chmod(0o755)
        processes = self.root / 'bin/check-processes'
        processes.write_bytes((BIN / 'check-processes').read_bytes())
        processes.chmod(0o755)
        seed = self.root / 'bin/worktree-cache'
        seed.write_text('#!/bin/sh\nprintf "worktree-cache %s\\n" "$*" >> "$GATE_TEST_SEEDS"\n')
        seed.chmod(0o755)
        docs_impact = self.root / 'bin/docs-impact'
        docs_impact.write_text("""#!/bin/sh
printf '%s|%s\\n' "$PWD" "$*" >> "$GATE_TEST_DOCS"
if [ "$GATE_TEST_MODE" = docs ]; then exit 5; fi
""")
        docs_impact.chmod(0o755)
        for name in ('project-vocabulary', 'env-docs', 'tooling-baseline', 'docs-nav', 'docs-openapi', 'mcp-tools', 'api-fixtures'):
            check = self.root / 'bin' / name
            check.write_text('#!/bin/sh\nexit 0\n')
            check.chmod(0o755)
        for project in PROJECTS:
            directory = self.root / project
            directory.mkdir(parents=True)
            (directory / '.gitkeep').write_text('')
            # A gate that runs Pest fails: CI runs the test suites.
            pest = directory / 'vendor/bin/pest'
            pest.parent.mkdir(parents=True)
            pest.write_text('#!/bin/sh\necho "The candidate gate runs no tests."\nexit 1\n')
            pest.chmod(0o755)
        self.commit_change('gate fixture')
        fake_bin = Path(self.temporary.name) / 'fake-bin'
        fake_bin.mkdir()
        composer = fake_bin / 'composer'
        composer.write_text("""#!/bin/sh
printf '%s|%s\\n' "$PWD" "$*" >> "$GATE_TEST_CALLS"
case "$1" in
    test) echo "The candidate gate runs no tests."; exit 1 ;;
esac
if [ "$1" = check ]; then
    printf '%s|%s|%s\\n' "${PWD##*/}" "${ORBIT_CHECK_SCOPE:-}" "${ORBIT_CHECK_BASE:-}" >> "$GATE_TEST_SCOPES"
    printf '%s|%s\\n' "${PWD##*/}" "${ORBIT_CHECK_PROCESSES:-}" >> "$GATE_TEST_PROCESSES"
fi
if [ "$1" = check ] && [ -n "${GATE_TEST_BARRIER:-}" ]; then
    case "${PWD##*/}" in
        cli) mine=cli; other=docs ;;
        docs) mine=docs; other=cli ;;
        *) mine= ;;
    esac
    if [ -n "$mine" ]; then
        touch "$GATE_TEST_BARRIER/$mine"
        waited=0
        while [ ! -e "$GATE_TEST_BARRIER/$other" ]; do
            waited=$((waited + 1))
            [ "$waited" -gt "${GATE_TEST_BARRIER_TICKS:-30}" ] && exit 8
            sleep 0.1
        done
    fi
fi
if [ "$1" = check ] && [ "${PWD##*/}" = e2e ]; then
    case "$GATE_TEST_MODE" in
        fail) exit 7 ;;
        mutate) echo changed > "$GATE_TEST_ROOT/source.php" ;;
    esac
fi
""")
        composer.chmod(0o755)
        self.gate_env = {**os.environ, 'PATH': str(fake_bin) + os.pathsep + os.environ['PATH'],
                         'GATE_TEST_CALLS': str(self.common / 'calls'), 'GATE_TEST_ROOT': str(self.root),
                         'GATE_TEST_SEEDS': str(self.common / 'seeds'), 'GATE_TEST_DOCS': str(self.common / 'docs'),
                         'GATE_TEST_SCOPES': str(self.common / 'scopes'),
                         'GATE_TEST_PROCESSES': str(self.common / 'processes')}
        for name in ('ORBIT_CHECK_PROCESSES', 'ORBIT_CHECK_SCOPE', 'ORBIT_CHECK_BASE'):
            self.gate_env.pop(name, None)
        return runner

    def test_gate_seeds_quality_caches_before_checks(self):
        runner = self.gate_fixture()
        result = subprocess.run([str(runner)], env=self.gate_env, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        self.assertEqual([f'worktree-cache --worktree {self.root.resolve()}'],
                         (self.common / 'seeds').read_text().splitlines())

    def test_gate_runs_all_five_projects_and_records_builder_and_exact_candidate(self):
        runner = self.gate_fixture()
        result = subprocess.run([str(runner)], env=self.gate_env, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        report = json.loads(next((self.common / 'orbit-checks').glob('*/*/result.json')).read_text())
        self.assertTrue(report['passed'])
        self.assertEqual('builder', report['role'])
        self.assertEqual(git(self.root, 'rev-parse', 'HEAD'), report['candidate'])
        self.assertEqual(self.commit, report['base'])
        expected = [('repository', ['bin/docs-impact', '--gate', '--base', self.commit]),
                    ('repository', ['bin/project-vocabulary']),
                    ('repository', ['bin/env-docs']),
                    ('repository', ['bin/tooling-baseline']),
                    ('repository', ['bin/docs-nav'])]
        for project in PROJECTS:
            expected.extend((project, command) for command in [['composer', 'validate', '--strict'], ['composer', 'check']])
        expected.extend(('repository', [f'bin/{name}', '--check']) for name in ('docs-openapi', 'mcp-tools'))
        expected.append(('repository', ['bin/api-fixtures', '--check']))
        self.assertEqual(expected, [(item['project'], item['command']) for item in report['checks']])
        for check in report['checks']:
            self.assertIn(f'[{check["project"]}] {" ".join(check["command"])}: passed ({check["seconds"]:.3f}s;',
                          result.stdout)
        self.assertEqual([f'{self.root.resolve()}|--gate --base {self.commit}'],
                         (self.common / 'docs').read_text().splitlines())
        self.assertEqual(10, len((self.common / 'calls').read_text().splitlines()))

    def test_gate_scopes_pint_and_rector_to_the_merge_base_in_composer_check_only(self):
        runner = self.gate_fixture()
        result = subprocess.run([str(runner)], env=self.gate_env, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        scopes = sorted((self.common / 'scopes').read_text().splitlines())
        self.assertEqual(sorted(f'{Path(project).name}|changed|{self.commit}' for project in PROJECTS), scopes)
        (self.common / 'scopes').unlink()
        result = subprocess.run([str(runner)], env={**self.gate_env, 'ORBIT_CHECK_SCOPE': 'full'},
                                capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        self.assertEqual(sorted(f'{Path(project).name}|full|' for project in PROJECTS),
                         sorted((self.common / 'scopes').read_text().splitlines()))

    def gate_module(self, runner):
        loader = importlib.machinery.SourceFileLoader('review_check_lanes', str(runner))
        gate = importlib.util.module_from_spec(importlib.util.spec_from_loader(loader.name, loader))
        loader.exec_module(gate)
        return gate

    def test_gate_keeps_every_tool_within_the_process_budget(self):
        runner = self.gate_fixture()
        for budget in ('1', '4', '10'):
            with self.subTest(budget=budget):
                (self.common / 'processes').unlink(missing_ok=True)
                result = subprocess.run([str(runner)], env={**self.gate_env, 'ORBIT_CHECK_PROCESSES': budget},
                                        capture_output=True, text=True)
                self.assertEqual(0, result.returncode, result.stderr + result.stdout)
                lines = (self.common / 'processes').read_text().splitlines()
                self.assertEqual(sorted(Path(project).name for project in PROJECTS),
                                 sorted(line.split('|')[0] for line in lines))
                for line in lines:
                    self.assertTrue(1 <= int(line.split('|')[1]) <= int(budget), line)
        gate = self.gate_module(runner)
        groups = gate.lanes([('apps/gateway', ()), ('apps/cli', ()), ('repository', ()), ('apps/web', ())])
        # The repository checks share the Gateway lane, because bin/docs-openapi --check boots the Gateway.
        self.assertEqual([['apps/gateway', 'repository', 'apps/web'], ['apps/cli']],
                         [[project for _order, project, _commands in group] for group in groups])
        self.assertEqual([1, 1, 3, 3, 5, 5], [gate.lane_count([[()]] * 5, budget) for budget in (1, 2, 4, 5, 8, 16)])

    def test_a_budget_of_two_or_less_runs_one_lane_to_fit_a_small_task_vm(self):
        gate = self.gate_module(self.gate_fixture())
        groups = [[(0, 'apps/gateway', ())], [(1, 'apps/cli', ())], [(2, 'apps/e2e', ())]]
        self.assertEqual([1, 1], [gate.lane_count(groups, budget) for budget in (1, 2)])
        self.assertEqual(2, gate.lane_count(groups, 3))

    def test_the_heaviest_lane_gets_most_of_the_budget_and_the_processes_that_others_free(self):
        gate = self.gate_module(self.gate_fixture())
        weights = {'apps/gateway': 1500, 'apps/cli': 200, 'apps/docs': 20, 'apps/e2e': 100, 'packages/php-sdk': 80}
        # A budget of 4 runs three lanes at once: the Gateway gets two processes, whichever lane asks first.
        for first in ('apps/gateway', 'apps/cli'):
            with self.subTest(first=first):
                budget = gate.ProcessBudget(4, weights)
                for lane in ('apps/gateway', 'apps/cli', 'apps/docs'):
                    budget.start(lane)
                granted = {lane: budget.acquire(lane) for lane in (first, *(lane for lane in ('apps/gateway', 'apps/cli', 'apps/docs') if lane != first))}
                self.assertEqual({'apps/gateway': 2, 'apps/cli': 1, 'apps/docs': 1}, granted)
                self.assertEqual(4, sum(budget.held.values()))
        # When the other lanes finish, the Gateway's next command gets the processes they freed.
        budget.release('apps/gateway')
        budget.finish('apps/cli')
        budget.finish('apps/docs')
        self.assertEqual(4, budget.acquire('apps/gateway'))
        # With every process in use, a command waits until another command releases one.
        budget = gate.ProcessBudget(2, weights)
        for lane in ('apps/gateway', 'apps/cli', 'apps/docs'):
            budget.start(lane)
        self.assertEqual(1, budget.acquire('apps/gateway'))
        self.assertEqual(1, budget.acquire('apps/cli'))
        acquired = []
        waiter = threading.Thread(target=lambda: acquired.append(budget.acquire('apps/docs')))
        waiter.start()
        time.sleep(0.2)
        self.assertEqual([], acquired)
        budget.release('apps/cli')
        waiter.join(5)
        self.assertEqual([1], acquired)

    def barrier_environment(self):
        barrier = Path(self.temporary.name) / 'barrier'
        barrier.mkdir()
        return {**self.gate_env, 'GATE_TEST_BARRIER': str(barrier), 'ORBIT_CHECK_PROCESSES': '3'}

    def test_gate_runs_projects_at_the_same_time_up_to_the_process_setting(self):
        runner = self.gate_fixture()
        environment = self.barrier_environment()
        result = subprocess.run([str(runner)], env=environment, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        result = subprocess.run([str(runner)], env={**environment, 'ORBIT_CHECK_PROCESSES': 'many'},
                                capture_output=True, text=True)
        self.assertEqual(1, result.returncode, result.stderr + result.stdout)
        self.assertIn("ORBIT_CHECK_PROCESSES must be a positive integer, not 'many'.", result.stderr)

    def assert_one_project_at_a_time(self, budget):
        # The CLI check waits half a second for the Docs check, which has not started, and gives up.
        runner = self.gate_fixture()
        environment = {**self.barrier_environment(), 'ORBIT_CHECK_PROCESSES': budget, 'GATE_TEST_BARRIER_TICKS': '5'}
        result = subprocess.run([str(runner)], env=environment, capture_output=True, text=True)
        self.assertEqual(1, result.returncode, result.stderr + result.stdout)
        self.assertIn('[apps/cli] composer check: FAILED', result.stdout)

    def test_a_budget_of_one_runs_one_project_at_a_time(self):
        self.assert_one_project_at_a_time('1')

    def test_a_budget_of_two_runs_one_project_at_a_time_so_a_2_gb_task_vm_runs_one_phpstan_or_rector(self):
        self.assert_one_project_at_a_time('2')

    def test_gate_rejects_failed_checks_and_candidate_mutation(self):
        runner = self.gate_fixture()
        for mode in ['docs', 'fail', 'mutate']:
            with self.subTest(mode=mode):
                result = subprocess.run([str(runner)], env={**self.gate_env, 'GATE_TEST_MODE': mode},
                                        capture_output=True, text=True)
                self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        for path in (self.common / 'orbit-checks').glob('*/*/result.json'):
            self.assertFalse(json.loads(path.read_text())['passed'])

    def test_gate_checks_uncommitted_input_as_it_is(self):
        runner = self.gate_fixture()
        (self.root / 'source.php').write_text('uncommitted')
        status = git(self.root, 'status', '--porcelain')
        result = subprocess.run([str(runner)], env=self.gate_env, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        self.assertIn('with uncommitted changes', result.stdout)
        report = json.loads(next((self.common / 'orbit-checks').glob('*/*/result.json')).read_text())
        self.assertTrue(report['passed'])
        self.assertFalse(report['committed'])
        self.assertNotEqual(git(self.root, 'rev-parse', 'HEAD^{tree}'), report['tree'])
        self.assertEqual(10, len((self.common / 'calls').read_text().splitlines()))
        self.assertEqual(status, git(self.root, 'status', '--porcelain'))

    def test_generated_checks_follow_inputs_and_outputs_without_running_for_unrelated_paths(self):
        runner = self.gate_fixture()
        gate_loader = importlib.machinery.SourceFileLoader('review_check', str(runner))
        gate_spec = importlib.util.spec_from_loader(gate_loader.name, gate_loader)
        gate = importlib.util.module_from_spec(gate_spec)
        gate_loader.exec_module(gate)
        for path in ('apps/gateway/routes/api.php', 'apps/cli/app/Commands/Example.php',
                     'packages/php-sdk/src/Requests/Example.php', 'bin/docs-openapi', 'bin/mcp-tools',
                     'docs/openapi.json', 'docs/docs.json', 'apps/gateway/resources/mcp/tools.json'):
            with self.subTest(path=path):
                commands = [command for project, commands in gate.selected_projects([path], self.root)
                            if project == 'repository' for command in commands]
                self.assertIn(('bin/docs-openapi', '--check'), commands)
                self.assertIn(('bin/mcp-tools', '--check'), commands)
        for path in ('packages/php-sdk/fixtures/example.json', 'apps/web/fixtures/example.json',
                     'docs/openapi.json', 'bin/api-fixtures', 'bin/review-check'):
            with self.subTest(fixture=path):
                commands = [command for project, commands in gate.selected_projects([path], self.root)
                            if project == 'repository' for command in commands]
                self.assertIn(('bin/api-fixtures', '--check'), commands)
        for path in ('docs/reference/example.md', 'apps/web/src/App.tsx', 'apps/pi-server/src/index.ts'):
            with self.subTest(path=path):
                self.assertNotIn('repository', dict(gate.selected_projects([path], self.root)))
        profiles = dict(gate.selected_projects(['bin/check-classification-fakes'], self.root))
        for project in PROJECTS:
            self.assertEqual((('php', '../../bin/check-classification-fakes', 'tests'),), profiles[project])
        for paths, projects in ((['apps/agent/src/main.rs'], ['apps/agent']),
                                (['apps/desktop/src/main.ts'], ['apps/desktop/src-tauri']),
                                (['.github/workflows/ci.yml'], ['apps/agent', 'apps/desktop/src-tauri'])):
            profiles = dict(gate.selected_projects(paths, self.root))
            for project in projects:
                self.assertEqual((('cargo', 'fmt', '--all', '--', '--check'),), profiles[project])

    def test_gate_records_missing_artifact_tools_and_failed_rust_formatting(self):
        runner = self.gate_fixture()
        (self.root / 'bin/docs-openapi').unlink()
        rust = self.root / 'apps/agent/src/main.rs'
        rust.parent.mkdir(parents=True)
        rust.write_text('fn main() {}')
        cargo = Path(self.gate_env['PATH'].split(os.pathsep)[0]) / 'cargo'
        cargo.write_text('#!/bin/sh\necho "Rust formatting differs"\nexit 7\n')
        cargo.chmod(0o755)
        result = subprocess.run([str(runner)], env=self.gate_env, capture_output=True, text=True)
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        self.assertIn('bin/docs-openapi: required tool not found', result.stdout)
        self.assertIn('Rust formatting differs', result.stdout)
        report = json.loads(next((self.common / 'orbit-checks').glob('*/*/result.json')).read_text())
        check = next(item for item in report['checks'] if item['project'] == 'apps/agent')
        self.assertEqual(['cargo', 'fmt', '--all', '--', '--check'], check['command'])
        self.assertEqual(7, check['exit_code'])
        self.assertFalse(report['passed'])

    def test_gate_rejects_real_stale_mcp_outputs_and_names_the_regeneration_command(self):
        runner = self.gate_fixture()
        shutil.copyfile(BIN / 'mcp-tools', self.root / 'bin/mcp-tools')
        (self.root / 'docs').mkdir()
        (self.root / 'docs/openapi.json').write_text('{"paths": {}}')
        git(self.root, 'update-ref', 'refs/remotes/origin/main', self.commit_change('generator fixture'))
        manifest = self.root / 'apps/gateway/resources/mcp/tools.json'
        manifest.parent.mkdir(parents=True)
        manifest.write_text('{"stale": true}')
        status = git(self.root, 'status', '--porcelain')
        result = subprocess.run([str(runner)], env=self.gate_env, capture_output=True, text=True)
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        self.assertIn('apps/gateway/resources/mcp/tools.json', result.stdout)
        self.assertIn('Run bin/mcp-tools.', result.stdout)
        self.assertIn('--- apps/gateway/resources/mcp/tools.json', result.stdout)
        self.assertIn('+++ generated/apps/gateway/resources/mcp/tools.json', result.stdout)
        report = json.loads(next((self.common / 'orbit-checks').glob('*/*/result.json')).read_text())
        check = next(item for item in report['checks'] if item['command'] == ['bin/mcp-tools', '--check'])
        self.assertEqual(1, check['exit_code'])
        self.assertFalse(report['passed'])
        self.assertEqual('{"stale": true}', manifest.read_text())
        self.assertEqual(status, git(self.root, 'status', '--porcelain'))

    def test_openapi_check_reports_the_generated_diff_without_writing_outputs(self):
        fixture = Path(self.temporary.name) / 'openapi fixture'
        (fixture / 'bin').mkdir(parents=True)
        (fixture / 'apps').mkdir()
        (fixture / 'docs').mkdir()
        repository = REPOSITORY
        for project in ('gateway', 'cli'):
            (fixture / 'apps' / project).symlink_to(repository / 'apps' / project, target_is_directory=True)
        (fixture / 'packages').symlink_to(repository / 'packages', target_is_directory=True)
        script = fixture / 'bin/docs-openapi'
        shutil.copyfile(repository / 'bin/docs-openapi', script)
        shutil.copyfile(repository / 'bin/docs-navigation', fixture / 'bin/docs-navigation')
        shutil.copyfile(repository / 'docs/site.json', fixture / 'docs/site.json')
        shutil.copytree(repository / 'docs/navigation', fixture / 'docs/navigation')
        shutil.copyfile(repository / 'docs/docs.json', fixture / 'docs/docs.json')
        document = fixture / 'docs/openapi.json'
        document.write_text('{"stale": true}\n')
        navigation = (fixture / 'docs/docs.json').read_bytes()
        result = subprocess.run([sys.executable, str(script), '--check'], capture_output=True, text=True)
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        self.assertIn('Run bin/docs-openapi', result.stderr)
        self.assertIn('--- docs/openapi.json', result.stdout)
        self.assertIn('+++ generated/docs/openapi.json', result.stdout)
        self.assertIn('-{"stale": true}', result.stdout)
        self.assertEqual('{"stale": true}\n', document.read_text())
        self.assertEqual(navigation, (fixture / 'docs/docs.json').read_bytes())

    @unittest.skipUnless(shutil.which('setfacl') and shutil.which('getfacl'), 'needs setfacl and getfacl')
    def test_review_directory_keeps_named_acl_entries_effective(self):
        runner = self.gate_fixture()
        # Like a host where the Git common dir has a default ACL with a named user entry.
        subprocess.run(['setfacl', '-d', '-m', 'u:nobody:rwX,m::rwx', str(self.common)], check=True)
        result = subprocess.run([str(runner)], env=self.gate_env, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        report = next((self.common / 'orbit-checks').glob('*/review-*/result.json'))
        directory = report.parent
        self.assertNotEqual('---', acl_entry(directory.parent, 'mask::'))
        self.assertNotEqual('---', acl_entry(directory, 'mask::'), 'mkdtemp mode 0700 set the ACL mask to ---')
        self.assertEqual('---', acl_entry(directory, 'other::'))
        rights = effective_named_rights(directory, 'nobody')
        self.assertEqual(('r', 'x'), (rights[0], rights[2]), rights)
        logs = list(directory.glob('*.log'))
        self.assertTrue(logs)
        for path in [report, *logs]:
            self.assertEqual('r', effective_named_rights(path, 'nobody')[0], path.name)


def acl_entry(path, prefix):
    """Return the rights of the first getfacl entry that starts with prefix."""
    lines = subprocess.run(['getfacl', '-cp', str(path)], capture_output=True, text=True, check=True).stdout.splitlines()
    return next(line.split()[0][len(prefix):] for line in lines if line.startswith(prefix))


def effective_named_rights(path, user):
    """A named user entry's rights after the ACL mask, such as r-x."""
    named, mask = acl_entry(path, f'user:{user}:'), acl_entry(path, 'mask::')
    return ''.join(right if limit != '-' else '-' for right, limit in zip(named, mask))


if __name__ == '__main__':
    unittest.main(verbosity=2)
