"""Exercise main cache seeding and registration, maintenance commands, bootstrap and the candidate gate with real
repositories."""
import copy
import fcntl
import importlib.machinery
import importlib.util
import json
import os
import signal
import shutil
import threading
import time
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True
loader = importlib.machinery.SourceFileLoader('tia_cache', sys.argv.pop(1))
spec = importlib.util.spec_from_loader(loader.name, loader)
cache = importlib.util.module_from_spec(spec)
loader.exec_module(cache)
# Registration writes under XDG_STATE_HOME; keep that out of the developer's own state.
STATE_HOME = tempfile.TemporaryDirectory(prefix='orbit-tia-state-')
os.environ['XDG_STATE_HOME'] = STATE_HOME.name
# Git's automatic maintenance must finish before these disposable repositories are removed.
# A template covers clones too: it applies where command-level Git configuration does not.
GIT_TEMPLATE = Path(STATE_HOME.name) / 'git-template'
GIT_TEMPLATE.mkdir()
(GIT_TEMPLATE / 'config').write_text('[maintenance]\n\tautoDetach = false\n[gc]\n\tautoDetach = false\n')
os.environ['GIT_TEMPLATE_DIR'] = str(GIT_TEMPLATE)


def repository(test, project_files=True):
    test.temporary = tempfile.TemporaryDirectory(prefix='orbit tia ')
    test.addCleanup(test.temporary.cleanup)
    test.root = Path(test.temporary.name) / 'main'
    test.root.mkdir()
    cache.git(test.root, 'init', '-b', 'main')
    cache.git(test.root, 'config', 'user.name', 'Orbit')
    cache.git(test.root, 'config', 'user.email', 'orbit@example.test')
    cache.git(test.root, 'remote', 'add', 'origin', str(test.root))
    (test.root / 'source.php').write_text('base')
    test.project = 'apps/docs'
    if project_files:
        project = test.root / test.project
        (project / 'tests').mkdir(parents=True)
        (test.root / '.gitignore').write_text('**/vendor/\n**/.orbit-tia/\n')
        (project / 'composer.lock').write_text('{"packages":[]}')
        (project / 'tests/Pest.php').write_text('<?php\n')
    test.commit = test.commit_change('initial')
    cache.git(test.root, 'update-ref', 'refs/remotes/origin/main', test.commit)
    test.common = cache.common_directory(test.root)
    test.store = cache.cache_store(test.common)
    test.info = {
        'cache': str(Path(test.temporary.name) / 'main-cache'),
        'runner': cache.runner_identity(b'{"packages":[]}', b'<?php\n'),
        'fingerprint': {'structural': {'schema': 20}, 'environmental': {'php_minor': '8.5'}},
    }
    test.graph = {
        'schema': 1, 'fingerprint': test.info['fingerprint'],
        'files': ['src/Example.php'], 'edges': {'tests/ExampleTest.php': [0]},
        'baselines': {'main': {'sha': test.commit, 'tree': [], 'results': {
            'example': {'status': 0, 'file': 'tests/ExampleTest.php'},
        }}},
    }


class CacheFixture(unittest.TestCase):
    def setUp(self):
        repository(self)

    def commit_change(self, message):
        cache.git(self.root, 'add', '.')
        cache.git(self.root, 'commit', '-m', message)
        return cache.git(self.root, 'rev-parse', 'HEAD')

    def advance(self, message):
        (self.root / 'source.php').write_text(message)
        return self.commit_change(message)

    def graph_at(self, commit):
        graph = copy.deepcopy(self.graph)
        graph['baselines']['main']['sha'] = commit
        return graph

    def publish(self, commit=None, graph=None, store=None, **changes):
        """Write one main publication straight into a store, in the format that seed reads."""
        commit = commit or self.commit
        text = json.dumps(self.graph_at(commit) if graph is None else graph)
        snapshot = {'schema': 1, 'project': self.project, 'runner': self.info['runner'],
                    'fingerprint': self.info['fingerprint'], 'graph': text, 'sha256': cache.digest(text.encode()),
                    'tested_commit': commit, **changes}
        path = cache.publication_path(store or self.store, self.project)
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(snapshot))
        return path

    def feature(self, name='feature'):
        root = Path(self.temporary.name) / name
        cache.git(self.root, 'worktree', 'add', '-b', name, str(root), 'main')
        return root

    def seed(self, root, info=None):
        info = info or {**self.info, 'cache': str(root / '.private-cache')}
        with patch.object(cache, 'metadata', return_value=info):
            cache.seed(root, self.store, [self.project])
        return Path(info['cache'])

    def main(self, *arguments, info=None):
        with patch.object(sys, 'argv', ['tia-cache', *arguments]), \
                patch.object(cache, 'metadata', return_value=info or self.info):
            return cache.main()


class SeedTest(CacheFixture):
    def test_runner_identity_tracks_locked_releases_and_project_bootstrap(self):
        project = self.root / self.project
        lock = project / 'composer.lock'
        bootstrap = project / 'tests/Pest.php'
        lock.write_text(json.dumps({'packages-dev': [{
            'name': 'nckrtl/pestphp-monorepo', 'version': 'v1.0.0',
            'source': {'reference': 'first-release'},
        }]}))
        with patch.object(cache, 'run', return_value=json.dumps(self.info)):
            initial = cache.metadata(self.root, self.project)['runner']
            self.assertEqual(initial, cache.metadata(self.root, self.project)['runner'])
            lock.write_text(lock.read_text().replace('first-release', 'next-release'))
            upgraded = cache.metadata(self.root, self.project)['runner']
            self.assertNotEqual(initial, upgraded)
            bootstrap.write_text('<?php pest()->tia()->directory("custom");\n')
            self.assertNotEqual(upgraded, cache.metadata(self.root, self.project)['runner'])

    def test_main_graph_seeds_each_worktree_and_writes_stay_private(self):
        self.publish()
        first = self.seed(self.feature())
        second = self.seed(self.feature('other'))
        self.assertEqual(['graph.json'], [path.name for path in first.iterdir()])
        self.assertEqual(self.graph, json.loads((first / 'graph.json').read_text()))
        (first / 'graph.json').write_text('private update')
        self.assertEqual(self.graph, json.loads((second / 'graph.json').read_text()))
        self.assertEqual(self.graph, json.loads(cache.read_publication(self.store, self.project)['graph']))

    def test_existing_worktree_cache_is_never_replaced(self):
        self.publish()
        root = self.feature()
        directory = self.seed(root)
        (directory / 'graph.json').write_text('feature progress')
        self.seed(root)
        self.assertEqual('feature progress', (directory / 'graph.json').read_text())

    def test_separate_clone_seeds_from_transported_main_publications(self):
        self.publish()
        clone = Path(self.temporary.name) / 'independent'
        cache.git(self.root, 'clone', str(self.root), str(clone))
        directory = clone / self.project / '.orbit-tia'
        with patch.dict(os.environ, {'ORBIT_MAIN_CACHE_STORE': str(self.store)}):
            self.assertEqual(0, self.main('seed', '--repository', str(clone), '--project', self.project,
                                          info={**self.info, 'cache': str(directory)}))
        self.assertEqual(self.graph, json.loads((directory / 'graph.json').read_text()))
        (directory / 'graph.json').write_text('private clone progress')
        self.assertEqual(self.graph, json.loads(cache.read_publication(self.store, self.project)['graph']))

    def test_separate_clone_seeds_from_the_store_registered_for_its_origin(self):
        with self.assertRaisesRegex(ValueError, 'no main publications to register'):
            self.main('register', '--repository', str(self.root))
        self.publish()
        self.assertEqual(0, self.main('register', '--repository', str(self.root)))
        self.assertEqual(self.store.resolve(), cache.registry_path(self.root).resolve())
        clone = Path(self.temporary.name) / 'task workspace'
        cache.git(self.root, 'clone', str(self.root), str(clone))
        directory = clone / self.project / '.orbit-tia'
        output = Path(self.temporary.name) / 'seed-output'
        with patch.dict(os.environ, {'ORBIT_MAIN_CACHE_STORE': ''}), output.open('w') as stream, \
                patch.object(sys, 'stdout', stream):
            self.assertEqual(0, self.main('seed', '--repository', str(clone), '--project', self.project,
                                          info={**self.info, 'cache': str(directory)}))
        self.assertIn(f'Seeding from the main cache store registered for this origin: {self.store}', output.read_text())
        self.assertEqual(self.graph, json.loads((directory / 'graph.json').read_text()))
        # The clone's own store stays empty: its private runs never become the shared baseline.
        self.assertFalse(cache.has_publications(cache.cache_store(cache.common_directory(clone))))

    def test_registration_keeps_the_first_live_store_until_forced(self):
        self.publish()
        self.assertTrue(cache.register_store(self.root, self.store))
        other = Path(self.temporary.name) / 'other-store'
        (other / 'published').mkdir(parents=True)
        self.assertFalse(cache.register_store(self.root, other))
        self.assertEqual(self.store.resolve(), cache.registry_path(self.root).resolve())
        self.assertTrue(cache.register_store(self.root, other, force=True))
        self.assertEqual(other.resolve(), cache.registry_path(self.root).resolve())
        shutil.rmtree(other)
        # A removed store no longer holds the registration.
        self.assertTrue(cache.register_store(self.root, self.store))
        self.assertEqual(self.store.resolve(), cache.registry_path(self.root).resolve())

    def test_origin_key_matches_https_and_ssh_urls_of_one_repository(self):
        keys = set()
        for url in ['https://github.com/hardimpactdev/orbit.git', 'git@github.com:hardimpactdev/orbit.git',
                    'ssh://git@GitHub.com/hardimpactdev/orbit', 'https://github.com/hardimpactdev/orbit/']:
            cache.git(self.root, 'remote', 'set-url', 'origin', url)
            keys.add(cache.origin_key(self.root))
        self.assertEqual(1, len(keys))
        cache.git(self.root, 'remote', 'set-url', 'origin', 'https://github.com/nckrtl/other.git')
        self.assertNotIn(cache.origin_key(self.root), keys)

    def test_missing_incompatible_corrupt_or_future_publication_is_a_cache_miss(self):
        root = self.feature()
        self.assertFalse(self.seed(root).exists())
        self.publish()
        path = cache.publication_path(self.store, self.project)
        snapshot = json.loads(path.read_text())
        future = self.advance('future')
        for changes in [{'runner': 'other'}, {'fingerprint': {}}, {'sha256': 'broken'},
                        {'tested_commit': future}, {'project': 'apps/cli'}, {'schema': 2}]:
            with self.subTest(changes=changes):
                path.write_text(json.dumps({**snapshot, **changes}))
                self.assertFalse(self.seed(root).exists())
        path.write_text('incomplete JSON')
        self.assertFalse(self.seed(root).exists())
        path.write_text('[]')
        self.assertFalse(self.seed(root).exists())

    def test_invalid_main_graphs_are_not_seeded(self):
        root = self.feature()
        side_root = self.feature('side')
        (side_root / 'source.php').write_text('side history')
        cache.git(side_root, 'commit', '-qam', 'side history')
        side = cache.git(side_root, 'rev-parse', 'HEAD')
        invalid = [
            {'baselines': {'feature': self.graph['baselines']['main']}},
            {'edges': {}}, {'files': ['/another/checkout/source.php']}, {'files': ['../outside.php']},
            {'schema': 2}, {'fingerprint': {}},
        ]
        for changes in invalid:
            with self.subTest(changes=changes):
                self.publish(graph={**self.graph, **changes})
                self.assertFalse(self.seed(root).exists())
        for field, value in [('tree', {'source.php': 'dirty'}), ('results', {}),
                             ('results', {'failed': {'status': 8}}), ('sha', side), ('sha', 'not a sha')]:
            with self.subTest(field=field, value=value):
                graph = self.graph_at(self.commit)
                graph['baselines']['main'][field] = value
                self.publish(graph=graph)
                self.assertFalse(self.seed(root).exists())
        self.publish()
        self.assertEqual(self.graph, json.loads((self.seed(root) / 'graph.json').read_text()))

    def test_seed_reports_each_miss_and_never_writes_the_store(self):
        root = self.feature()
        self.publish()
        before = sorted(path.relative_to(self.store) for path in self.store.rglob('*'))
        output = Path(self.temporary.name) / 'seed-output'
        with output.open('w') as stream, patch.object(sys, 'stdout', stream):
            self.seed(root)
            self.publish(runner='other')
            self.seed(root, {**self.info, 'cache': str(root / '.other-cache')})
        lines = output.read_text().splitlines()
        self.assertTrue(lines[0].startswith(f'[{self.project}] seeded main {self.commit[:12]} -> '), lines)
        self.assertEqual(f'[{self.project}] cache not seeded: Dependencies, runtime, or test configuration differ.',
                         lines[1])
        self.assertEqual(before, sorted(path.relative_to(self.store) for path in self.store.rglob('*')))

    def test_removed_actions_are_rejected(self):
        for action in ('refresh', 'status', 'work', 'import-ci', 'warm', 'publish'):
            with self.subTest(action=action), patch.object(sys, 'argv', ['tia-cache', action]), \
                    patch.object(sys, 'stderr'), self.assertRaises(SystemExit) as raised:
                cache.main()
            self.assertEqual(2, raised.exception.code)
        with patch.object(sys, 'argv', ['tia-cache', 'register', '--development-instance=303']), \
                patch.object(sys, 'stderr'), self.assertRaises(SystemExit):
            cache.main()
        for name in ('refresh', 'import_ci', 'publish_artifact', 'start_background', 'drain', 'status'):
            self.assertFalse(hasattr(cache, name), name)


class CommandTest(CacheFixture):
    def test_maintenance_commands_run_without_agent_output_only_for_the_worker(self):
        with patch.dict(os.environ, {'CLAUDECODE': '1', 'AI_AGENT': 'claude', 'PAO_DISABLE': '0'}):
            environment = cache.maintenance_environment()
            self.assertEqual('1', environment['PAO_DISABLE'])
            self.assertEqual('0', os.environ['PAO_DISABLE'])

    def test_maintenance_environment_keeps_temporary_directories(self):
        with patch.dict(os.environ, {
            'TMPDIR': '/worktree-tmp', 'TMP': '/short-tmp', 'TEMP': '/legacy-tmp',
            'DATABASE_URL': 'sqlite:///setup.sqlite',
        }):
            environment = cache.maintenance_environment()
            self.assertEqual('/worktree-tmp', environment['TMPDIR'])
            self.assertEqual('/short-tmp', environment['TMP'])
            self.assertEqual('/legacy-tmp', environment['TEMP'])
            self.assertNotIn('DATABASE_URL', environment)

    def test_nested_command_uses_caller_temporary_directory(self):
        private = self.root / 'private-tmp'
        private.mkdir()
        with patch.dict(os.environ, {'TMPDIR': str(private)}):
            observed = cache.run(
                self.root, sys.executable, '-c', 'import os; print(os.environ["TMPDIR"], end="")',
            )
        self.assertEqual(str(private), observed)

    def test_failed_command_includes_stdout_in_the_failure(self):
        with self.assertRaises(cache.CommandFailure) as raised:
            cache.run(
                self.root, sys.executable, '-c',
                'import sys; sys.stdout.write("gh failed on stdout\\n"); '
                'sys.stderr.write("hint on stderr\\n"); sys.exit(2)',
            )
        self.assertIn('gh failed on stdout', str(raised.exception))
        self.assertIn('hint on stderr', str(raised.exception))
        self.assertEqual(2, raised.exception.exit_code)

    def test_failed_command_keeps_the_tail_of_huge_stdout(self):
        with self.assertRaises(cache.CommandFailure) as raised:
            cache.run(
                self.root, sys.executable, '-c',
                'import sys; sys.stdout.write("head-marker\\n" + ("x" * 80000) + "\\ntail-marker\\n"); '
                'sys.exit(1)',
            )
        message = str(raised.exception)
        self.assertIn('tail-marker', message)
        self.assertNotIn('head-marker', message)
        self.assertLessEqual(len(message), cache.FAILURE_OUTPUT_LIMIT)

    def test_command_timeout_stops_descendants_before_returning(self):
        marker = self.root / 'descendant-ready'
        script = '''import fcntl, os, signal, sys, time
if os.fork() == 0:
    # Only the descendant outlives SIGTERM, so the command returns at once and SIGKILL must stop the group.
    signal.signal(signal.SIGTERM, signal.SIG_IGN)
    # Leave the output pipes, so only the process group ties this descendant to the command.
    devnull = os.open(os.devnull, os.O_WRONLY)
    os.dup2(devnull, 1)
    os.dup2(devnull, 2)
    with open('descendant.lock', 'w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        with open('descendant-ready', 'w') as marker:
            marker.write(str(os.getpid()))
        time.sleep(30)
        with open('escaped-child', 'w') as escaped:
            escaped.write('still running')
else:
    time.sleep(30)
'''
        try:
            with patch.object(cache, 'COMMAND_TIMEOUT', 0.5):
                with self.assertRaisesRegex(cache.CommandFailure, 'timed out'):
                    cache.run(self.root, sys.executable, '-c', script)

            self.assertTrue(marker.exists(), 'The descendant must start before timeout.')
            with (self.root / 'descendant.lock').open() as lock:
                # A live descendant still owns this independent lock, even after
                # its direct parent has exited. Allow bounded kernel teardown
                # after SIGKILL; a surviving child retains the lock for 30 s.
                deadline = time.monotonic() + 1
                while True:
                    try:
                        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                        break
                    except BlockingIOError:
                        if time.monotonic() >= deadline:
                            self.fail('A descendant survived the command timeout.')
                        time.sleep(0.01)
            self.assertFalse((self.root / 'escaped-child').exists())
        finally:
            if marker.exists():
                try:
                    os.kill(int(marker.read_text()), signal.SIGKILL)
                except ProcessLookupError:
                    pass


class BootstrapTest(CacheFixture):
    def test_bootstrap_has_one_composer_cache_writer_and_preserves_authentication(self):
        root = Path(self.temporary.name) / 'bootstrap-cache-writers'
        (root / 'bin').mkdir(parents=True)
        (root / 'bin/bootstrap').write_bytes(Path(cache.__file__).with_name('bootstrap').read_bytes())
        for project in cache.PROJECTS:
            (root / project).mkdir(parents=True)
        for name in ('gateway-app-key', 'worktree-cache', 'tia-cache', 'generated-merge'):
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
        self.assertEqual([str(root / project) for project in cache.PROJECTS],
                         (downloads / 'installs').read_text().splitlines())
        self.assertNotIn('fixture-token', result.stdout + result.stderr)

    def test_bootstrap_seeds_before_affected_tests_and_quality_checks_and_never_publishes(self):
        root = Path(self.temporary.name) / 'bootstrap'
        (root / 'bin').mkdir(parents=True)
        (root / 'bin/bootstrap').write_bytes(Path(cache.__file__).with_name('bootstrap').read_bytes())
        (root / 'bin/gateway-app-key').write_bytes(Path(cache.__file__).with_name('gateway-app-key').read_bytes())
        (root / 'bin/gateway-app-key').chmod(0o755)
        (root / 'bin/generated-merge').write_bytes(Path(cache.__file__).with_name('generated-merge').read_bytes())
        (root / 'bin/generated-merge').chmod(0o755)
        # The real bootstrap installs a clone-local merge driver. Give it a
        # private repository, rather than letting Git find the parent checkout.
        cache.git(root, 'init', '-b', 'main')
        cache.git(root, 'config', 'user.name', 'Orbit')
        cache.git(root, 'config', 'user.email', 'orbit@example.test')
        for project in cache.PROJECTS:
            (root / project).mkdir(parents=True)
        calls = root / 'calls'
        for name, script in {
            'composer': '#!/bin/sh\nif [ "$1" = install ]; then test "$COMPOSER_CACHE_DIR" = "$TIA_TEST_COMPOSER_CACHE" || exit 8; fi\necho "composer $*" >> "$TIA_TEST_CALLS"\nif [ "$1" = "$TIA_TEST_FAIL" ]; then exit 7; fi\nif [ "$1" != install ]; then test -z "$ORBIT_MAIN_CACHE_STORE"; fi\n',
            'worktree-cache': '#!/bin/sh\ntest "$ORBIT_MAIN_CACHE_STORE" = "$TIA_TEST_STORE"\n',
            'tia-cache': '#!/bin/sh\necho "cache $*" >> "$TIA_TEST_CALLS"\ntest "$ORBIT_MAIN_CACHE_STORE" = "$TIA_TEST_STORE" || exit 9\nexit 1\n',
        }.items():
            path = root / 'bin' / name
            path.write_text(script)
            path.chmod(0o755)
        environment = {**os.environ, 'ORBIT_HOME': str(root / 'orbit-home'),
                       'ORBIT_MAIN_CACHE_STORE': str(root / 'transported-main'),
                       'TIA_TEST_STORE': str(root / 'transported-main'),
                       'PATH': str(root / 'bin') + os.pathsep + os.environ['PATH'],
                       'TIA_TEST_CALLS': str(calls), 'TIA_TEST_FAIL': '',
                       'COMPOSER_CACHE_DIR': str(root / 'downloads'),
                       'TIA_TEST_COMPOSER_CACHE': str(root / 'downloads')}
        result = subprocess.run(['bash', str(root / 'bin/bootstrap')], capture_output=True, text=True, env=environment)
        self.assertEqual(0, result.returncode, result.stderr)
        driver = cache.git(root, 'config', '--local', '--get', 'merge.orbit-generated.driver')
        self.assertIn(str(root / 'bin/generated-merge'), driver)
        self.assertIn('--merge %O %A %B %P %X %Y', driver)
        lines = calls.read_text().splitlines()
        self.assertEqual(5, sum(line.startswith('composer install') for line in lines))
        self.assertEqual('cache seed --repository=' + str(root), lines[5])
        self.assertEqual(['composer test:affected', 'composer check'] * 5, lines[-10:])
        calls.unlink()
        result = subprocess.run(['bash', str(root / 'bin/bootstrap'), '--skip-checks'],
                                capture_output=True, text=True, env=environment)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertNotIn('test:affected', calls.read_text())
        # --with-bun and --with-rust install the Bun and Cargo projects' locked dependencies.
        for name, command in (('bun', 'install'), ('cargo', 'fetch')):
            fake = root / 'bin' / name
            fake.write_text(f'#!/bin/sh\necho "{name} $(basename "$PWD") $*" >> "$TIA_TEST_CALLS"\n')
            fake.chmod(0o755)
        for project in ('apps/web', 'apps/pi-server', 'apps/desktop/src-tauri', 'apps/agent'):
            (root / project).mkdir(parents=True, exist_ok=True)
        calls.unlink()
        result = subprocess.run(['bash', str(root / 'bin/bootstrap'), '--skip-checks', '--with-bun', '--with-rust'],
                                capture_output=True, text=True, env=environment)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(['bun web install --frozen-lockfile', 'bun pi-server install --frozen-lockfile',
                          'bun desktop install --frozen-lockfile', 'cargo agent fetch --locked',
                          'cargo src-tauri fetch --locked'],
                         [line for line in calls.read_text().splitlines() if line.startswith(('bun ', 'cargo '))])
        self.assertNotIn('test:affected', calls.read_text())
        result = subprocess.run(['bash', str(root / 'bin/bootstrap'), '--with-node'],
                                capture_output=True, text=True, env=environment)
        self.assertEqual(2, result.returncode)
        for name in ('bun', 'cargo'):
            (root / 'bin' / name).unlink()
        for failure in ('install', 'guidance:check', 'test:affected', 'check'):
            with self.subTest(failure=failure):
                calls.unlink()
                result = subprocess.run(['bash', str(root / 'bin/bootstrap')], capture_output=True, text=True,
                                        env={**environment, 'TIA_TEST_FAIL': failure})
                self.assertNotEqual(0, result.returncode)
                if failure in ('install', 'guidance:check'):
                    self.assertNotIn('composer test:affected', calls.read_text())

        # A clean bootstrap at fetched main checks everything and still leaves the main caches to CI.
        (root / '.gitignore').write_text('/calls\n/orbit-home/\n')
        cache.git(root, 'add', '.')
        cache.git(root, 'commit', '-m', 'bootstrap fixture')
        cache.git(root, 'update-ref', 'refs/remotes/origin/main', cache.git(root, 'rev-parse', 'HEAD'))
        local = {key: value for key, value in environment.items() if key != 'ORBIT_MAIN_CACHE_STORE'}
        local['TIA_TEST_STORE'] = ''
        calls.unlink(missing_ok=True)
        result = subprocess.run(['bash', str(root / 'bin/bootstrap')], capture_output=True, text=True, env=local)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(['cache seed --repository=' + str(root)],
                         [line for line in calls.read_text().splitlines() if line.startswith('cache ')])


class ReviewGateTest(unittest.TestCase):
    setUp = lambda self: repository(self, project_files=False)
    commit_change = CacheFixture.commit_change

    # Reuse repository setup, without repeating the cache test cases.
    def gate_fixture(self):
        (self.root / 'bin').mkdir()
        (self.root / 'tools').mkdir()
        (self.root / 'tools/check_budget.py').write_bytes(Path(cache.__file__).parent.parent.joinpath('tools/check_budget.py').read_bytes())
        runner = self.root / 'bin/review-check'
        runner.write_bytes(Path(cache.__file__).with_name('review-check').read_bytes())
        runner.chmod(0o755)
        processes = self.root / 'bin/check-processes'
        processes.write_bytes(Path(cache.__file__).with_name('check-processes').read_bytes())
        processes.chmod(0o755)
        for name in ('tia-cache', 'worktree-cache'):
            seed = self.root / 'bin' / name
            seed.write_text(f'#!/bin/sh\nprintf "{name} %s\\n" "$*" >> "$GATE_TEST_SEEDS"\n')
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
        for project in cache.PROJECTS:
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
    test|test:affected) echo "The candidate gate runs no tests."; exit 1 ;;
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

    def test_gate_seeds_quality_caches_but_no_test_caches_before_checks(self):
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
        self.assertEqual(cache.git(self.root, 'rev-parse', 'HEAD'), report['candidate'])
        self.assertEqual(self.commit, report['base'])
        expected = [('repository', ['bin/docs-impact', '--gate', '--base', self.commit]),
                    ('repository', ['bin/project-vocabulary']),
                    ('repository', ['bin/env-docs']),
                    ('repository', ['bin/tooling-baseline']),
                    ('repository', ['bin/docs-nav'])]
        for project in cache.PROJECTS:
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
        self.assertEqual(sorted(f'{Path(project).name}|changed|{self.commit}' for project in cache.PROJECTS), scopes)
        (self.common / 'scopes').unlink()
        result = subprocess.run([str(runner)], env={**self.gate_env, 'ORBIT_CHECK_SCOPE': 'full'},
                                capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        self.assertEqual(sorted(f'{Path(project).name}|full|' for project in cache.PROJECTS),
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
                self.assertEqual(sorted(Path(project).name for project in cache.PROJECTS),
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
        status = cache.git(self.root, 'status', '--porcelain')
        result = subprocess.run([str(runner)], env=self.gate_env, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        self.assertIn('with uncommitted changes', result.stdout)
        report = json.loads(next((self.common / 'orbit-checks').glob('*/*/result.json')).read_text())
        self.assertTrue(report['passed'])
        self.assertFalse(report['committed'])
        self.assertNotEqual(cache.git(self.root, 'rev-parse', 'HEAD^{tree}'), report['tree'])
        self.assertEqual(10, len((self.common / 'calls').read_text().splitlines()))
        self.assertEqual(status, cache.git(self.root, 'status', '--porcelain'))

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
        for project in cache.PROJECTS:
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
        shutil.copyfile(Path(cache.__file__).with_name('mcp-tools'), self.root / 'bin/mcp-tools')
        (self.root / 'docs').mkdir()
        (self.root / 'docs/openapi.json').write_text('{"paths": {}}')
        cache.git(self.root, 'update-ref', 'refs/remotes/origin/main', self.commit_change('generator fixture'))
        manifest = self.root / 'apps/gateway/resources/mcp/tools.json'
        manifest.parent.mkdir(parents=True)
        manifest.write_text('{"stale": true}')
        status = cache.git(self.root, 'status', '--porcelain')
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
        self.assertEqual(status, cache.git(self.root, 'status', '--porcelain'))

    def test_openapi_check_reports_the_generated_diff_without_writing_outputs(self):
        fixture = Path(self.temporary.name) / 'openapi fixture'
        (fixture / 'bin').mkdir(parents=True)
        (fixture / 'apps').mkdir()
        (fixture / 'docs').mkdir()
        repository = Path(cache.__file__).resolve().parent.parent
        for project in ('gateway', 'cli'):
            (fixture / 'apps' / project).symlink_to(repository / 'apps' / project, target_is_directory=True)
        (fixture / 'packages').symlink_to(repository / 'packages', target_is_directory=True)
        script = fixture / 'bin/docs-openapi'
        shutil.copyfile(repository / 'bin/docs-openapi', script)
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
