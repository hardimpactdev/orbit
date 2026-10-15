"""Exercise main cache import, publication, and seeding with real repositories and a fake GitHub CLI."""
import copy
from contextlib import contextmanager, redirect_stdout
import io
import hashlib
import runpy
import zipfile
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
from unittest.mock import Mock, patch

loader = importlib.machinery.SourceFileLoader('tia_cache', sys.argv.pop(1))
spec = importlib.util.spec_from_loader(loader.name, loader)
cache = importlib.util.module_from_spec(spec)
loader.exec_module(cache)
# Publication registers stores under XDG_STATE_HOME; keep that out of the developer's own state.
STATE_HOME = tempfile.TemporaryDirectory(prefix='orbit-tia-state-')
os.environ['XDG_STATE_HOME'] = STATE_HOME.name
# Git's automatic maintenance must finish before these disposable repositories are removed.
# A template covers clones and bare remotes too: local push receivers clear command-level Git configuration.
GIT_TEMPLATE = Path(STATE_HOME.name) / 'git-template'
GIT_TEMPLATE.mkdir()
(GIT_TEMPLATE / 'config').write_text('[maintenance]\n\tautoDetach = false\n[gc]\n\tautoDetach = false\n')
os.environ['GIT_TEMPLATE_DIR'] = str(GIT_TEMPLATE)
REPOSITORY = 'nckrtl/orbit'
# Answers the gh calls of the worker from files in $GH_FIXTURE and records every call.
FAKE_GH = r'''#!/usr/bin/env python3
import json, os, shutil, sys
from pathlib import Path
fixture = Path(os.environ['GH_FIXTURE'])
with (fixture / 'calls').open('a') as calls:
    calls.write(json.dumps(sys.argv[1:]) + '\n')
if (fixture / 'fail').exists():
    sys.exit('HTTP 503: GitHub is unavailable')
arguments = sys.argv[1:]
if arguments[0] == 'api':
    path = arguments[1].split('?')[0]
    if path == 'repos/nckrtl/orbit/actions/workflows/ci.yml/runs':
        print((fixture / 'runs.json').read_text())
    elif path.startswith('repos/nckrtl/orbit/actions/runs/') and path.endswith('/jobs'):
        jobs = fixture / ('jobs-' + path.split('/')[5] + '.json')
        if not jobs.exists():
            sys.exit('HTTP 404: Not Found')
        print(jobs.read_text())
    else:
        sys.exit('HTTP 404: Not Found')
elif arguments[:2] == ['run', 'download']:
    run_id, destination = arguments[2], arguments[arguments.index('-D') + 1]
    assert arguments[arguments.index('-R') + 1] == 'nckrtl/orbit'
    assert arguments[arguments.index('-p') + 1] == 'sandbox-tia-*'
    source = fixture / ('artifacts-' + run_id)
    if not source.is_dir():
        sys.exit('no valid artifacts found to download')
    for artifact in source.iterdir():
        shutil.copytree(artifact, Path(destination) / artifact.name)
else:
    sys.exit('unexpected gh call')
'''


def php_minor():
    return subprocess.run(['php', '-r', 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'],
                          check=True, capture_output=True, text=True).stdout


def pint_cache(php=None):
    """A Pint cache as Pint writes it, for this PHP version unless another is named."""
    php = php or subprocess.run(['php', '-r', 'echo PHP_VERSION;'], check=True, capture_output=True, text=True).stdout
    return json.dumps({'php': php, 'version': '3.95.25', 'rules': {}, 'hashes': {}}).encode()


def phpstan_cache(**meta):
    """A framed PHPStan result cache whose metadata fit this PHP runtime, with the given entries replaced."""
    script = r'''
$extensions = array_values(array_filter(get_loaded_extensions(),
    static fn (string $extension): bool => ! in_array($extension, ['xdebug', 'blackfire', 'phpstan_turbo'], true)));
sort($extensions);
$meta = serialize(array_replace(['cacheVersion' => 'fixture', 'phpVersion' => PHP_VERSION_ID,
    'phpExtensions' => $extensions, 'extensionVersions' => []], json_decode($argv[1], true)));
echo "<?php return; ?>\nlastFullAnalysisTime 13\n", serialize(1791289105), 'meta ', strlen($meta), "\n", $meta;
'''
    return subprocess.run(['php', '-r', script, json.dumps(meta)], check=True, capture_output=True).stdout




@contextmanager
def launched_workers(failure=None):
    """Record background worker launches and let every other command run."""
    real = subprocess.Popen
    launches = []

    def popen(command, *arguments, **options):
        if len(command) > 2 and command[2] == 'work':
            if failure is not None:
                raise failure
            launches.append((command, options))
            return Mock(pid=4242)
        return real(command, *arguments, **options)

    with patch.object(cache.subprocess, 'Popen', side_effect=popen):
        yield launches


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
        (project / 'pint.json').write_text('{"cache-file":"vendor/pint.cache"}')
        (project / 'phpstan.neon').write_text('parameters:\n    level: 6\n')
        # The background worker runs the tool as main holds it.
        (test.root / 'bin').mkdir()
        shutil.copyfile(cache.__file__, test.root / 'bin/tia-cache')
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

    def artifact(self, commit, run_id=123, graph=None, quality=None, php=None, manifest=None):
        """Write one extracted CI artifact, as the Export main caches step of ci.yml does."""
        directory = Path(tempfile.mkdtemp(dir=self.temporary.name, prefix='artifact-')) / f'sandbox-tia-1-{commit}'
        directory.mkdir()
        encoded = json.dumps(self.graph_at(commit) if graph is None else graph).encode()
        (directory / 'graph.json').write_bytes(encoded)
        inputs = {}
        for name in cache.TEST_INPUTS:
            data = cache.blob(self.root, commit, f'{self.project}/{name}')
            if data is not None:
                inputs[name] = cache.digest(data)
        content = {'schema': 1, 'project': self.project, 'commit': commit, 'run_id': str(run_id),
                   'graph_sha256': cache.digest(encoded), 'inputs': inputs}
        if quality is not None:
            (directory / 'quality').mkdir()
            content['quality'] = {}
            for tool, data in quality.items():
                (directory / 'quality' / tool).write_bytes(data)
                content['quality'][tool] = cache.digest(data)
            content['php'] = php or php_minor()
        (directory / 'manifest.json').write_text(json.dumps({**content, **(manifest or {})}))
        return directory

    def publish(self, commit=None, run_id=123, **options):
        commit = commit or self.commit
        artifact = self.artifact(commit, run_id, **options)
        return cache.publish_artifact(self.common, self.store, self.project, artifact, commit, run_id)

    def drain(self, projects=None):
        """Queue and run the worker in this process, as bin/tia-cache refresh does in main's frozen copy."""
        projects = projects or [self.project]
        with cache.queue_lock(self.store):
            cache.enqueue(self.common, self.store, projects)
        cache.drain(self.common, self.store, cache.acquire_worker(self.store, blocking=True))
        results = cache.load_requests(self.store)['results']
        return int(any(not results.get(project, {}).get('success') for project in projects))

    def feature(self, name='feature'):
        root = Path(self.temporary.name) / name
        cache.git(self.root, 'worktree', 'add', '-b', name, str(root), 'main')
        return root

    def seed(self, root, info=None):
        info = info or {**self.info, 'cache': str(root / '.private-cache')}
        with patch.object(cache, 'metadata', return_value=info):
            cache.seed(root, self.store, [self.project])
        return Path(info['cache'])


class MainCacheTest(CacheFixture):
    def ci_artifact(self):
        artifact = self.artifact(self.commit)
        return artifact, json.loads((artifact / 'manifest.json').read_text())

    def test_ci_baseline_import_keeps_private_results_and_never_publishes(self):
        artifact, _ = self.ci_artifact()
        with patch.object(cache, 'metadata', return_value=self.info):
            self.assertTrue(cache.import_ci(self.root, self.project, artifact, self.commit))
            destination = self.root / self.project / '.orbit-tia/graph.json'
            self.assertEqual(json.loads(destination.read_text()), self.graph)
            self.assertEqual(destination.stat().st_mode & 0o777, 0o600)
            destination.write_text('newer private results')
            self.assertFalse(cache.import_ci(self.root, self.project, artifact, self.commit))
            self.assertEqual(destination.read_text(), 'newer private results')
        self.assertFalse(self.store.exists())

    def test_a_newer_ci_baseline_replaces_the_stale_graph_of_a_template(self):
        destination = self.root / self.project / '.orbit-tia/graph.json'
        with patch.object(cache, 'metadata', return_value=self.info):
            cache.import_ci(self.root, self.project, self.artifact(self.commit), self.commit)
            # Main moves on after the template was built, and CI tests the newer commit.
            newer = self.advance('newer main')
            output = io.StringIO()
            with redirect_stdout(output):
                self.assertTrue(cache.import_ci(self.root, self.project, self.artifact(newer), newer))
            self.assertEqual(json.loads(destination.read_text())['baselines']['main']['sha'], newer)
            self.assertIn(f'replaced private graph of main {self.commit[:12]} with newer CI baseline {newer}', output.getvalue())
            # The same or an older CI baseline never replaces it.
            for commit in (newer, self.commit):
                output = io.StringIO()
                with redirect_stdout(output):
                    self.assertFalse(cache.import_ci(self.root, self.project, self.artifact(commit), commit))
                self.assertEqual(json.loads(destination.read_text())['baselines']['main']['sha'], newer)
                self.assertIn('kept existing private graph: ', output.getvalue())

    def test_ci_baseline_import_writes_absent_quality_caches_that_fit_the_checkout(self):
        phpstan = phpstan_cache()
        artifact = self.artifact(self.commit, quality={'pint': pint_cache(), 'phpstan': phpstan})
        project = self.root / self.project
        (project / 'vendor').mkdir()
        (project / 'vendor/pint.cache').write_text('local pint')
        output = io.StringIO()
        with redirect_stdout(output), patch.object(cache, 'metadata', return_value=self.info):
            self.assertTrue(cache.import_ci(self.root, self.project, artifact, self.commit))
        self.assertIn(f'[{self.project}] imported CI phpstan cache; PHPStan may still run cold if the PHP build differs',
                      output.getvalue())
        self.assertEqual((project / 'vendor/pint.cache').read_text(), 'local pint')
        self.assertEqual((project / 'vendor/phpstan/cache/resultCache.php').read_bytes(), phpstan)
        self.assertFalse(self.store.exists())

    def test_ci_quality_caches_that_do_not_fit_run_cold_and_corrupt_ones_fail(self):
        project = self.root / self.project
        cached = project / 'vendor/phpstan/cache/resultCache.php'
        (project / 'phpstan.neon').write_text('parameters:\n    level: 9\n')
        artifact = self.artifact(self.commit, quality={'phpstan': b'phpstan result'})
        with patch.object(cache, 'metadata', return_value=self.info):
            cache.import_ci(self.root, self.project, artifact, self.commit)
        self.assertFalse(cached.exists())
        cache.git(self.root, 'checkout', '--', '.')
        artifact = self.artifact(self.commit, quality={'phpstan': phpstan_cache(phpVersion=70400)}, php='7.4')
        with patch.object(cache, 'metadata', return_value=self.info):
            cache.import_ci(self.root, self.project, artifact, self.commit)
        self.assertFalse(cached.exists())
        artifact = self.artifact(self.commit, quality={'phpstan': b'phpstan result'})
        (artifact / 'quality/phpstan').write_bytes(b'tampered')
        with patch.object(cache, 'metadata', return_value=self.info), self.assertRaises(ValueError):
            cache.import_ci(self.root, self.project, artifact, self.commit)
        self.assertFalse(cached.exists())

    def test_ci_quality_caches_import_only_when_the_tool_would_use_them(self):
        project = self.root / self.project
        pint, phpstan = project / 'vendor/pint.cache', project / 'vendor/phpstan/cache/resultCache.php'
        cases = [
            ('pint', pint_cache(php='8.5.0-fixture'), 'differs from CI PHP 8.5.0-fixture'),
            ('pint', b'{"version":"3.95.25"}', 'the cache records no PHP version'),
            ('pint', b'not json', 'the cache records no PHP version'),
            ('phpstan', phpstan_cache(phpVersion=80000), 'differs from CI PHP 8.0.0'),
            ('phpstan', b'<?php return; ?>\nlastFullAnalysisTime 13\ni:1;', 'the cache records no PHP version'),
        ]
        for tool, data, reason in cases:
            with self.subTest(tool=tool, reason=reason):
                output = io.StringIO()
                with redirect_stdout(output), patch.object(cache, 'metadata', return_value=self.info):
                    cache.import_ci(self.root, self.project, self.artifact(self.commit, quality={tool: data}), self.commit)
                self.assertIn(f'[{self.project}] {tool} cache not imported, it runs cold: ', output.getvalue())
                self.assertIn(reason, output.getvalue())
                self.assertFalse(pint.exists() or phpstan.exists())
                (project / '.orbit-tia/graph.json').unlink()

    def test_ci_baseline_that_does_not_fit_the_checkout_exits_stale_without_writing(self):
        artifact = self.artifact(self.commit)
        (self.root / self.project / 'composer.lock').write_text('{"packages":[{"name":"new"}]}')
        newer = self.commit_change('lock')

        def import_ci(artifact, commit):
            return subprocess.run([sys.executable, str(self.root / 'bin/tia-cache'), 'import-ci',
                                   '--repository', str(self.root), '--project', self.project,
                                   '--artifact', str(artifact), '--commit', commit], capture_output=True, text=True)

        result = import_ci(artifact, self.commit)
        self.assertEqual(cache.STALE_EXIT, result.returncode, result.stdout + result.stderr)
        self.assertIn('its tests run cold: The CI baseline test configuration differs.', result.stdout)
        cache.git(self.root, 'checkout', '--detach', 'HEAD~1')
        result = import_ci(self.artifact(newer), newer)
        self.assertEqual(cache.STALE_EXIT, result.returncode, result.stdout + result.stderr)
        self.assertIn('is not in the history of HEAD', result.stdout)
        self.assertFalse((self.root / self.project / '.orbit-tia').exists())

    def test_ci_baseline_rejects_tampering_and_configuration_drift_before_writing(self):
        artifact, manifest = self.ci_artifact()
        for field, value in [('project', 'apps/gateway'), ('commit', 'a' * 40),
                             ('graph_sha256', 'b' * 64), ('inputs', {}), ('run_id', '')]:
            with self.subTest(field=field), patch.object(cache, 'metadata', return_value=self.info):
                (artifact / 'manifest.json').write_text(json.dumps({**manifest, field: value}))
                with self.assertRaises(ValueError):
                    cache.import_ci(self.root, self.project, artifact, self.commit)
                self.assertFalse((self.root / self.project / '.orbit-tia').exists())

    def test_ci_baseline_refuses_failed_results_nonportable_paths_and_symlink_destinations(self):
        artifact, manifest = self.ci_artifact()
        failed = copy.deepcopy(self.graph)
        failed['baselines']['main']['results']['example']['status'] = 8
        nonportable = copy.deepcopy(self.graph)
        nonportable['files'] = ['/home/runner/repository/file.php']
        for graph in [failed, nonportable]:
            encoded = json.dumps(graph).encode()
            (artifact / 'graph.json').write_bytes(encoded)
            (artifact / 'manifest.json').write_text(json.dumps({**manifest, 'graph_sha256': cache.digest(encoded)}))
            with patch.object(cache, 'metadata', return_value=self.info), self.assertRaises(ValueError):
                cache.import_ci(self.root, self.project, artifact, self.commit)
        (artifact / 'graph.json').write_text(json.dumps(self.graph))
        (artifact / 'manifest.json').write_text(json.dumps(manifest))
        outside = Path(self.temporary.name) / 'outside'
        outside.mkdir()
        (self.root / self.project / '.orbit-tia').symlink_to(outside)
        with patch.object(cache, 'metadata', return_value=self.info), self.assertRaises(ValueError):
            cache.import_ci(self.root, self.project, artifact, self.commit)
        self.assertEqual(list(outside.iterdir()), [])

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

    def test_published_ci_graph_matches_the_runner_identity_of_its_commit(self):
        self.publish()
        snapshot = cache.read_publication(self.store, self.project)
        self.assertEqual(self.info['runner'], snapshot['runner'])
        self.assertEqual(self.graph['fingerprint'], snapshot['fingerprint'])
        self.assertEqual({'run_id': 123}, snapshot['source'])
        self.assertEqual((self.commit, self.commit), (snapshot['tested_commit'], snapshot['graph_commit']))

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
        with patch.dict(os.environ, {'ORBIT_MAIN_CACHE_STORE': str(self.store)}), \
                patch.object(sys, 'argv', ['tia-cache', 'seed', '--repository', str(clone), '--project', self.project]), \
                patch.object(cache, 'metadata', return_value={**self.info, 'cache': str(directory)}), \
                patch.object(cache, 'start_background') as background:
            self.assertEqual(0, cache.main())
        background.assert_not_called()
        self.assertEqual(self.graph, json.loads((directory / 'graph.json').read_text()))
        (directory / 'graph.json').write_text('private clone progress')
        self.assertEqual(self.graph, json.loads(cache.read_publication(self.store, self.project)['graph']))

    def test_separate_clone_seeds_from_the_store_registered_for_its_origin(self):
        self.publish()
        self.assertEqual(self.store.resolve(), cache.registry_path(self.root).resolve())
        clone = Path(self.temporary.name) / 'task workspace'
        cache.git(self.root, 'clone', str(self.root), str(clone))
        directory = clone / self.project / '.orbit-tia'
        with patch.dict(os.environ, {'ORBIT_MAIN_CACHE_STORE': ''}), \
                patch.object(sys, 'argv', ['tia-cache', 'seed', '--repository', str(clone), '--project', self.project]), \
                patch.object(cache, 'metadata', return_value={**self.info, 'cache': str(directory)}), \
                patch.object(cache, 'start_background') as background:
            self.assertEqual(0, cache.main())
        self.assertEqual(self.graph, json.loads((directory / 'graph.json').read_text()))
        # The clone's own store stays empty: its private runs never become the shared baseline.
        self.assertFalse(cache.has_publications(cache.cache_store(cache.common_directory(clone))))
        self.assertEqual(sorted(set(cache.PROJECTS) - {self.project}), sorted(background.call_args.args[2]))

    def test_linked_worktree_seeding_queues_an_import_for_its_own_lagging_store(self):
        self.publish()
        worktree = self.feature()
        self.advance('merged elsewhere')
        cache.git(self.root, 'update-ref', 'refs/remotes/origin/main', cache.git(self.root, 'rev-parse', 'HEAD'))
        with patch.object(sys, 'argv', ['tia-cache', 'seed', '--repository', str(worktree), '--project', self.project]), \
                patch.object(cache, 'metadata', return_value={**self.info, 'cache': str(worktree / '.private')}), \
                patch.object(cache, 'start_background') as background:
            self.assertEqual(0, cache.main())
        self.assertIn(self.project, background.call_args.args[2])

    def test_registration_keeps_the_first_live_store_until_forced(self):
        self.publish()
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
        for url in ['https://github.com/nckrtl/orbit.git', 'git@github.com:nckrtl/orbit.git',
                    'ssh://git@GitHub.com/nckrtl/orbit', 'https://github.com/nckrtl/orbit/']:
            cache.git(self.root, 'remote', 'set-url', 'origin', url)
            keys.add(cache.origin_key(self.root))
            self.assertEqual(REPOSITORY, cache.github_repository(self.root))
        self.assertEqual(1, len(keys))
        cache.git(self.root, 'remote', 'set-url', 'origin', 'https://github.com/nckrtl/other.git')
        self.assertNotIn(cache.origin_key(self.root), keys)
        cache.git(self.root, 'remote', 'set-url', 'origin', 'https://gitlab.com/nckrtl/orbit.git')
        with self.assertRaisesRegex(ValueError, 'not a GitHub repository'):
            cache.github_repository(self.root)

    def test_lagging_registered_store_is_refreshed_once_per_failed_target(self):
        self.publish()
        clone = Path(self.temporary.name) / 'task workspace'
        cache.git(self.root, 'clone', str(self.root), str(clone))
        newer = self.advance('merged feature')
        cache.git(clone, 'fetch', 'origin')
        with patch.object(cache, 'start_background') as background:
            cache.request_refresh(clone, self.store)
        self.assertIn(self.project, background.call_args.args[2])
        self.assertEqual(self.common, background.call_args.args[0])
        with cache.queue_lock(self.store):
            state = cache.load_requests(self.store)
            for project in cache.PROJECTS:
                state['results'][project] = {'commit': newer, 'success': False, 'checks': []}
            cache.save_requests(self.store, state)
        with patch.object(cache, 'start_background') as background:
            cache.request_refresh(clone, self.store)
        background.assert_not_called()

    def test_worker_prunes_run_logs_that_no_result_names(self):
        self.store.mkdir(parents=True, exist_ok=True)
        named, stale, current = (self.store / name for name in ('run-named', 'run-stale', 'run-current'))
        for directory in (named, stale, current):
            directory.mkdir()
            (directory / 'check.log').write_text('log')
        state = {'results': {self.project: {'checks': [{'log': str(named / 'check.log')}]}}}
        cache.prune_runs(self.store, state, current)
        self.assertTrue(named.is_dir())
        self.assertTrue(current.is_dir())
        self.assertFalse(stale.exists())

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

    def test_invalid_main_graphs_are_not_published(self):
        older = self.commit
        commit = self.advance('next main')
        invalid = [
            {'baselines': {'feature': self.graph_at(commit)['baselines']['main']}},
            {'edges': {}}, {'files': ['/another/checkout/source.php']},
        ]
        for changes in invalid:
            with self.subTest(changes=changes), self.assertRaises(ValueError):
                self.publish(commit, graph={**self.graph_at(commit), **changes})
        for field, value in [('tree', {'source.php': 'dirty'}), ('results', {}),
                             ('results', {'failed': {'status': 8}}), ('sha', older)]:
            with self.subTest(field=field, value=value), self.assertRaises(ValueError):
                graph = self.graph_at(commit)
                graph['baselines']['main'][field] = value
                self.publish(commit, graph=graph)
        self.assertFalse(cache.publication_path(self.store, self.project).exists())

    def test_artifact_must_match_the_commit_run_and_test_configuration(self):
        commit = self.advance('next main')
        for manifest in [{'run_id': '999'}, {'commit': self.commit}, {'graph_sha256': 'b' * 64},
                         {'inputs': {'composer.lock': 'other'}}, {'project': 'apps/cli'}]:
            with self.subTest(manifest=manifest), self.assertRaises(ValueError):
                self.publish(commit, manifest=manifest)
        (self.root / self.project / 'composer.lock').write_text('{"packages":["changed"]}')
        changed = self.commit_change('dependencies change')
        # The artifact names the new commit, but CI tested it with the old lock file.
        artifact = self.artifact(commit, manifest={'commit': changed})
        with self.assertRaisesRegex(ValueError, 'test configuration differs'):
            cache.publish_artifact(self.common, self.store, self.project, artifact, changed, 123)
        self.assertFalse(cache.publication_path(self.store, self.project).exists())

    def test_publications_only_advance_along_main(self):
        newer = self.advance('next main')
        self.publish(newer)
        published = cache.publication_path(self.store, self.project).read_bytes()
        # A delayed older artifact leaves the newer publication in place.
        self.publish(self.commit, run_id=122)
        self.assertEqual(published, cache.publication_path(self.store, self.project).read_bytes())
        cache.git(self.root, 'checkout', '-q', '-b', 'side', self.commit)
        side = self.advance('side history')
        with self.assertRaises(cache.CommandFailure):
            self.publish(side, run_id=124)
        self.assertEqual(published, cache.publication_path(self.store, self.project).read_bytes())

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

    def test_background_runner_survives_caller_removal_and_does_not_hold_closeout(self):
        with launched_workers() as launches:
            cache.start_background(self.common, self.store, [self.project])
        [(command, options)] = launches
        self.assertTrue(Path(command[1]).is_relative_to(self.store))
        self.assertEqual(Path(cache.__file__).read_bytes(), Path(command[1]).read_bytes())
        self.assertIn(str(self.common), command)
        self.assertTrue(options['start_new_session'])
        self.assertEqual(subprocess.DEVNULL, options['stdin'])

    def test_the_worker_always_runs_mains_copy_of_the_tool(self):
        main_source = Path(cache.__file__).read_bytes() + b'\n# main version\n'
        (self.root / 'bin/tia-cache').write_bytes(main_source)
        main = self.commit_change('main version of the tool')
        cache.git(self.root, 'update-ref', 'refs/remotes/origin/main', main)
        with launched_workers() as launches:
            cache.start_background(self.common, self.store, [self.project])
        self.assertEqual(main_source, Path(launches[0][0][1]).read_bytes())
        self.assertEqual(cache.digest(main_source), cache.load_requests(self.store)['pending'][self.project]['worker_key'])
        # Without the tool on main, no worker starts and no request is recorded.
        cache.git(self.root, 'rm', '-q', 'bin/tia-cache')
        cache.git(self.root, 'update-ref', 'refs/remotes/origin/main', self.commit_change('no tool'))
        with launched_workers() as launches, self.assertRaisesRegex(RuntimeError, 'no bin/tia-cache'):
            cache.start_background(self.common, self.store, ['apps/cli'])
        self.assertEqual([], launches)
        self.assertNotIn('apps/cli', cache.load_requests(self.store)['pending'])

    def test_a_foreground_refresh_also_runs_mains_copy(self):
        marker = Path(self.temporary.name) / 'worker-ran'
        (self.root / 'bin/tia-cache').write_text(
            f'#!/usr/bin/env python3\nimport sys\nopen({str(marker)!r}, "w").write(" ".join(sys.argv[1:]))\n')
        cache.git(self.root, 'update-ref', 'refs/remotes/origin/main', self.commit_change('main worker'))
        # main's stand-in worker records no result, so the requested project counts as failed.
        self.assertEqual(1, cache.refresh(self.common, self.store, [self.project]))
        self.assertTrue(marker.read_text().startswith('work --repository ' + str(self.common)))
        self.assertFalse(cache.active_worker(self.store))

    def test_a_branch_clone_seeding_from_the_registered_store_starts_only_mains_worker(self):
        self.publish()
        marker = Path(self.temporary.name) / 'worker-ran'
        main_worker = f'#!/usr/bin/env python3\nimport sys\nopen({str(marker)!r}, "w").write(" ".join(sys.argv[1:]))\n'
        (self.root / 'bin/tia-cache').write_text(main_worker)
        main = self.commit_change('main worker')
        cache.git(self.root, 'update-ref', 'refs/remotes/origin/main', main)
        clone = Path(self.temporary.name) / 'branch clone'
        cache.git(self.root, 'clone', '-q', str(self.root), str(clone))
        cache.git(clone, 'checkout', '-q', '-b', 'unmerged-change')
        # The clone's branch carries another copy of the tool, as an unmerged pull request does.
        shutil.copyfile(cache.__file__, clone / 'bin/tia-cache')
        try:
            result = subprocess.run([sys.executable, str(clone / 'bin/tia-cache'), 'seed', '--repository', str(clone),
                                     '--project', self.project], capture_output=True, text=True,
                                    env={**os.environ, 'ORBIT_MAIN_CACHE_STORE': ''})
        finally:
            # The launcher acquires this lock before spawning the worker, which inherits it until exit.
            # Wait even when the command fails, before assertions or repository cleanup can race the worker.
            with cache.acquire_worker(self.store, blocking=True):
                pass
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertIn('Seeding from the main cache store registered for this origin', result.stdout)
        self.assertTrue(marker.exists(), result.stdout + result.stderr)
        self.assertTrue(marker.read_text().startswith('work --repository ' + str(self.common)))
        self.assertEqual([main_worker], [runner.read_text() for runner in (self.store / 'runners').iterdir()])

    def test_removed_local_check_actions_are_rejected(self):
        for action in ('warm', 'publish'):
            with self.subTest(action=action), patch.object(sys, 'argv', ['tia-cache', action]), \
                    self.assertRaises(SystemExit):
                cache.main()
        with patch.object(sys, 'argv', ['tia-cache', 'register', '--development-instance=303']), \
                self.assertRaises(SystemExit):
            cache.main()

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


class CiImportTest(CacheFixture):
    """The worker reads main CI through gh and publishes CI artifacts. It never runs a project command."""

    def setUp(self):
        super().setUp()
        self.fixture = Path(self.temporary.name) / 'gh-fixture'
        self.fixture.mkdir()
        tools = Path(self.temporary.name) / 'tools'
        tools.mkdir()
        (tools / 'gh').write_text(FAKE_GH)
        for name in ('composer', 'php', 'pest'):
            (tools / name).write_text(f'#!/bin/sh\necho "{name} $*" >> "$GH_FIXTURE/forbidden"\nexit 1\n')
        for tool in tools.iterdir():
            tool.chmod(0o755)
        self.runs = []
        self.clock = 0
        environment = patch.dict(os.environ, {'GH_FIXTURE': str(self.fixture),
                                              'PATH': str(tools) + os.pathsep + os.environ['PATH']})
        environment.start()
        self.addCleanup(environment.stop)
        repository_name = patch.object(cache, 'github_repository', return_value=REPOSITORY)
        repository_name.start()
        self.addCleanup(repository_name.stop)

    def ci_run(self, commit, docs='success', event='push', repository=REPOSITORY, jobs=None, artifact=True):
        """Record one finished main CI run. Each new run finishes after the previous ones."""
        self.clock += 1
        run_id = 1000 + len(self.runs)
        jobs = {'Web': 'success', 'Docs': docs, **(jobs or {})}
        (self.fixture / f'jobs-{run_id}.json').write_text(json.dumps({'jobs': [
            {'name': name, 'conclusion': conclusion, 'html_url': f'https://github.test/job/{name}'}
            for name, conclusion in jobs.items()]}))
        if artifact and jobs['Docs'] == 'success':
            artifact = self.artifact(commit, run_id) if artifact is True else artifact(commit, run_id)
            shutil.copytree(artifact, self.fixture / f'artifacts-{run_id}' / artifact.name)
        self.runs.append({
            'id': run_id, 'head_sha': commit, 'event': event, 'head_branch': 'main',
            'head_repository': {'full_name': repository}, 'conclusion': 'failure' if 'failure' in jobs.values() else 'success',
            'updated_at': f'2026-10-09T10:{self.clock:02d}:00Z', 'html_url': f'https://github.test/run/{run_id}',
        })
        (self.fixture / 'runs.json').write_text(json.dumps({'workflow_runs': list(reversed(self.runs))}))
        return run_id

    def refresh(self):
        return self.drain()

    def calls(self):
        path = self.fixture / 'calls'
        return [json.loads(line) for line in path.read_text().splitlines()] if path.exists() else []

    def state(self):
        return cache.load_requests(self.store)

    def test_worker_publishes_the_newest_successful_main_artifact_without_running_tests(self):
        self.ci_run(self.commit)
        newer = self.advance('next main')
        run_id = self.ci_run(newer)
        self.assertEqual(0, self.refresh())
        snapshot = cache.read_publication(self.store, self.project)
        self.assertEqual((newer, {'run_id': run_id}), (snapshot['tested_commit'], snapshot['source']))
        self.assertFalse((self.fixture / 'forbidden').exists())
        result = self.state()['results'][self.project]
        self.assertTrue(result['success'])
        self.assertEqual((newer, newer, run_id), (result['commit'], result['published'], result['run_id']))
        report = cache.status(self.common, self.store)
        self.assertTrue(report['current'][self.project])
        self.assertEqual({}, report['correctness_failures'])
        self.assertEqual(self.graph_at(newer), json.loads((self.seed(self.feature()) / 'graph.json').read_text()))
        # Downloads are inputs only; a current store asks GitHub for the run list and nothing more.
        self.assertEqual([], list(self.store.glob('run-*/artifacts-*')))
        downloads = sum(call[:2] == ['run', 'download'] for call in self.calls())
        self.assertEqual(0, self.refresh())
        self.assertEqual(downloads, sum(call[:2] == ['run', 'download'] for call in self.calls()))

    def test_status_keeps_the_keys_that_merge_gates_read(self):
        failed = self.advance('broken main')
        self.ci_run(failed, docs='failure')
        self.assertEqual(1, self.refresh())
        report = cache.status(self.common, self.store)
        self.assertEqual({'schema', 'main', 'worker_active', 'pending', 'current', 'results', 'failures',
                          'correctness_failures', 'needed', 'refresh_log'}, set(report))
        failure = report['correctness_failures'][self.project]['ci']
        self.assertEqual(('ci', 'check_failure', failed), (failure['tool'], failure['kind'], failure['commit']))
        self.assertEqual(['Docs'], failure['jobs'])
        self.assertIn(self.project, report['failures'])

    def test_failed_main_run_holds_until_a_later_run_passes_on_that_commit_or_a_descendant(self):
        self.ci_run(self.commit)
        broken = self.advance('broken main')
        failed_run = self.ci_run(broken, docs='failure')
        self.assertEqual(1, self.refresh())
        failure = self.state()['correctness_failures'][self.project]['ci']
        self.assertEqual((broken, failed_run, 'https://github.test/run/%d' % failed_run),
                         (failure['commit'], failure['run_id'], failure['run_url']))
        # The newest successful commit stays published while main is broken.
        self.assertEqual(self.commit, cache.read_publication(self.store, self.project)['tested_commit'])
        fixed = self.advance('fix main')
        self.ci_run(fixed)
        self.assertEqual(0, self.refresh())
        self.assertEqual({}, self.state()['correctness_failures'])
        self.assertEqual(fixed, cache.read_publication(self.store, self.project)['tested_commit'])

    def test_a_full_run_failure_that_finishes_after_a_newer_success_stays_open(self):
        nightly = self.commit
        newer = self.advance('merged during the nightly run')
        self.ci_run(newer)
        self.ci_run(nightly, docs='failure', event='schedule')
        self.assertEqual(1, self.refresh())
        self.assertEqual(nightly, self.state()['correctness_failures'][self.project]['ci']['commit'])
        self.assertEqual(newer, cache.read_publication(self.store, self.project)['tested_commit'])
        self.ci_run(nightly, event='workflow_dispatch')
        self.assertEqual(0, self.refresh())
        self.assertEqual({}, self.state()['correctness_failures'])
        self.assertEqual(newer, cache.read_publication(self.store, self.project)['tested_commit'])

    def test_pull_requests_forks_unfinished_and_other_jobs_are_not_main_results(self):
        self.publish()
        self.ci_run(self.commit)
        newer = self.advance('next main')
        self.ci_run(newer, docs='failure', event='pull_request')
        self.ci_run(newer, docs='failure', repository='someone/orbit')
        self.ci_run(newer, docs='cancelled')
        self.ci_run(newer, jobs={'Web': 'failure'}, artifact=False)
        self.assertEqual(1, self.refresh())
        self.assertEqual({}, self.state()['correctness_failures'])
        # The run passed for Docs but published no artifact, so the import fails without a correctness hold.
        self.assertEqual('maintenance_failure', self.state()['results'][self.project]['checks'][-1]['kind'])
        self.assertEqual(self.commit, cache.read_publication(self.store, self.project)['tested_commit'])
        self.assertEqual(('failure', ['Gateway privileged']), cache.job_result(
            [{'name': 'Gateway', 'conclusion': 'success'}, {'name': 'Gateway privileged', 'conclusion': 'failure'}],
            'apps/gateway'))
        self.assertEqual((None, []), cache.job_result([{'name': 'Gateway privileged', 'conclusion': 'success'}],
                                                      'apps/gateway'))
        self.assertEqual(('failure', ['Gateway']), cache.job_result(
            [{'name': 'Gateway', 'conclusion': 'timed_out'}], 'apps/gateway'))
        self.assertEqual(('failure', ['E2E subprocess']), cache.job_result(
            [{'name': 'E2E', 'conclusion': 'success'}, {'name': 'E2E subprocess', 'conclusion': 'failure'}], 'apps/e2e'))
        self.assertEqual((None, []), cache.job_result(
            [{'name': 'Gateway', 'conclusion': 'success'}, {'name': 'Gateway subprocess', 'conclusion': None}],
            'apps/gateway'))

    def test_unreadable_ci_keeps_earlier_failures_and_publications(self):
        self.publish()
        legacy = {'tool': 'tia', 'kind': 'check_failure', 'exit_code': 2, 'commit': self.commit, 'error': 'beast only'}
        with cache.queue_lock(self.store):
            state = self.state()
            state['correctness_failures'] = {self.project: {'tia': legacy}}
            cache.save_requests(self.store, state)
        published = cache.publication_path(self.store, self.project).read_bytes()
        newer = self.advance('next main')
        self.ci_run(newer)
        (self.fixture / 'fail').write_text('')
        self.assertEqual(1, self.refresh())
        state = self.state()
        self.assertEqual({self.project: {'tia': legacy}}, state['correctness_failures'])
        self.assertEqual('setup', state['results'][self.project]['checks'][0]['tool'])
        self.assertIn('HTTP 503', state['results'][self.project]['checks'][0]['error'])
        self.assertEqual(published, cache.publication_path(self.store, self.project).read_bytes())
        # Once CI is readable, a passing run on a descendant replaces the failure that local checks recorded.
        (self.fixture / 'fail').unlink()
        self.assertEqual(0, self.refresh())
        self.assertEqual({}, self.state()['correctness_failures'])
        self.assertEqual(newer, cache.read_publication(self.store, self.project)['tested_commit'])

    def test_no_finished_project_job_keeps_the_correctness_state(self):
        self.ci_run(self.commit, docs='cancelled')
        self.assertEqual(1, self.refresh())
        result = self.state()['results'][self.project]
        self.assertEqual('maintenance_failure', result['checks'][0]['kind'])
        self.assertNotIn('ci', result)
        self.assertEqual({}, self.state()['correctness_failures'])

    def test_a_refused_artifact_is_a_maintenance_failure_and_keeps_the_publication(self):
        self.publish()
        published = cache.publication_path(self.store, self.project).read_bytes()
        newer = self.advance('next main')
        self.ci_run(newer, artifact=lambda commit, run_id: self.artifact(commit, run_id, manifest={'run_id': '1'}))
        self.assertEqual(1, self.refresh())
        check = self.state()['results'][self.project]['checks'][-1]
        self.assertEqual(('import', 'maintenance_failure'), (check['tool'], check['kind']))
        self.assertIn('another run', Path(check['log']).read_text())
        self.assertEqual({}, self.state()['correctness_failures'])
        self.assertEqual(published, cache.publication_path(self.store, self.project).read_bytes())


class RealPestCacheTest(unittest.TestCase):
    def test_real_pest_main_graph_from_ci_selects_tests_in_the_next_worktrees(self):
        repository = Path(cache.__file__).resolve().parent.parent
        sdk = repository / 'packages/php-sdk'
        self.assertTrue((sdk / 'vendor/autoload.php').is_file(), 'Run bin/bootstrap --skip-checks first.')
        coverage = subprocess.run(
            ['php', '-r', 'exit(extension_loaded("pcov") || extension_loaded("xdebug") ? 0 : 1);'],
            check=False,
        )
        self.assertEqual(
            0,
            coverage.returncode,
            'Real Pest TIA records a graph only with PCOV or Xdebug. Install one for the PHP on PATH, '
            'on macOS with `brew install shivammathur/extensions/pcov@8.5`.',
        )
        with tempfile.TemporaryDirectory(prefix='orbit-real-tia-') as temporary:
            root = Path(temporary).resolve() / 'primary'
            project = 'apps/docs'
            directory = root / project
            (directory / 'src').mkdir(parents=True)
            (directory / 'tests').mkdir()
            (root / '.gitignore').write_text('**/vendor/\n**/.orbit-tia/\n**/.executed*\n')
            manifest = json.loads((sdk / 'composer.json').read_text())
            manifest['scripts'] = {'test:affected': 'vendor/bin/pest --parallel --processes=2 --tia --compact'}
            (directory / 'composer.json').write_text(json.dumps(manifest))
            shutil.copyfile(sdk / 'composer.lock', directory / 'composer.lock')
            (directory / 'phpunit.xml').write_text(
                '<phpunit bootstrap="vendor/autoload.php"><testsuites><testsuite name="unit">'
                '<directory>tests</directory></testsuite></testsuites><source><include>'
                '<directory>src</directory></include></source></phpunit>')
            (directory / 'tests/Pest.php').write_text(
                "<?php\n\npest()->tia()->defaultBranch('main')->locally()->filtered()"
                "->directory(dirname(__DIR__).'/.orbit-tia');\n"
                "require_once dirname(__DIR__).'/src/Value.php';\n")
            value = directory / 'src/Value.php'
            value.write_text("<?php\n\nfunction cacheValue(string $value): string\n{\n    return $value;\n}\n")
            (directory / 'tests/ValueTest.php').write_text(
                "<?php\n\nit('keeps dataset bytes', function (string $value): void {\n"
                "    file_put_contents(dirname(__DIR__).'/.executed', 'run\\n', FILE_APPEND);\n"
                "    expect(cacheValue($value))->toBe($value);\n})->with(['text', \"\\xff\"]);\n")
            (directory / 'tests/OtherTest.php').write_text(
                "<?php\n\nit('keeps another result', function (): void {\n"
                "    file_put_contents(dirname(__DIR__).'/.executed-other', 'run');\n"
                "    expect(1 + 1)->toBe(2);\n});\n")
            for args in (('init', '-b', 'main'), ('config', 'user.name', 'Orbit'),
                         ('config', 'user.email', 'orbit@example.test'), ('remote', 'add', 'origin', str(root))):
                cache.git(root, *args)
            common = cache.common_directory(root)
            store = cache.cache_store(common)

            def commit(message):
                cache.git(root, 'add', '.')
                cache.git(root, 'commit', '-m', message)
                sha = cache.git(root, 'rev-parse', 'HEAD')
                cache.git(root, 'update-ref', 'refs/remotes/origin/main', sha)
                cache.git(root, 'symbolic-ref', 'refs/remotes/origin/HEAD', 'refs/remotes/origin/main')
                return sha

            def install(checkout):
                shutil.copytree(sdk / 'vendor', checkout / project / 'vendor',
                                ignore=shutil.ignore_patterns('cache', 'pint.cache'))

            def ci(sha, run_id):
                """Run the project on main as CI does, then publish its graph as the worker does."""
                cache.run(directory, 'composer', 'test:affected')
                artifact = Path(temporary) / f'artifact-{run_id}'
                artifact.mkdir()
                graph = (directory / '.orbit-tia/graph.json').read_bytes()
                (artifact / 'graph.json').write_bytes(graph)
                (artifact / 'manifest.json').write_text(json.dumps({
                    'schema': 1, 'project': project, 'commit': sha, 'run_id': str(run_id),
                    'graph_sha256': cache.digest(graph),
                    'inputs': {name: cache.digest((directory / name).read_bytes())
                               for name in cache.TEST_INPUTS if (directory / name).is_file()},
                }))
                cache.publish_artifact(common, store, project, artifact, sha, run_id)
                (directory / '.executed').unlink(missing_ok=True)
                (directory / '.executed-other').unlink(missing_ok=True)
                return cache.read_publication(store, project)

            old = commit('older main')
            install(root)
            snapshot = ci(old, 1)
            self.assertEqual(3, len(json.loads(snapshot['graph'])['baselines']['main']['results']))
            value.write_text(value.read_text().replace('return $value;', 'return substr($value, 0);'))
            current = commit('newer main')

            # A worktree seeded from the older main graph runs only the tests that the change affects.
            first = Path(temporary).resolve() / 'first'
            cache.git(root, 'worktree', 'add', '-b', 'first-task', str(first), current)
            install(first)
            cache.seed(first, store, [project])
            self.assertEqual(snapshot['graph'], (first / project / '.orbit-tia/graph.json').read_text())
            output = cache.run(first / project, 'composer', 'test:affected')
            self.assertEqual('run\\nrun\\n', (first / project / '.executed').read_text())
            self.assertFalse((first / project / '.executed-other').exists(), output)

            # After CI records the newer main, the next worktree starts at that commit and runs nothing.
            snapshot = ci(current, 2)
            self.assertEqual(current, snapshot['tested_commit'])
            self.assertEqual(3, len(json.loads(snapshot['graph'])['baselines']['main']['results']))
            second = Path(temporary).resolve() / 'second'
            cache.git(root, 'worktree', 'add', '-b', 'second-task', str(second), current)
            install(second)
            cache.seed(second, store, [project])
            output = cache.run(second / project, 'composer', 'test:affected')
            self.assertFalse((second / project / '.executed').exists(), output)
            self.assertFalse((second / project / '.executed-other').exists(), output)


class MaintenanceQueueTest(CacheFixture):
    def setUp(self):
        super().setUp()
        for name, value in (('github_repository', REPOSITORY),
                            ('ci_history', None)):
            patcher = patch.object(cache, name, return_value=value) if value else patch.object(
                cache, name, side_effect=lambda common, repository, projects: {
                    project: {'success': None, 'failure': None} for project in projects})
            patcher.start()
            self.addCleanup(patcher.stop)

    def submit(self, projects=None):
        with cache.queue_lock(self.store):
            return cache.enqueue(self.common, self.store, projects or [self.project])

    @staticmethod
    def passed(common, store, project, commit, *arguments):
        return {'commit': commit, 'success': True, 'checks': []}

    def test_duplicate_requests_share_one_worker_and_keep_all_projects(self):
        self.submit()
        lock = cache.acquire_worker(self.store)
        try:
            cache.start_background(self.common, self.store, [self.project])
            cache.start_background(self.common, self.store, ['apps/cli'])
            state = cache.load_requests(self.store)
            self.assertEqual({'apps/cli', 'apps/docs'}, set(state['pending']))
            self.assertTrue(cache.active_worker(self.store))
            self.assertIsNone(cache.acquire_worker(self.store))
        finally:
            lock.close()
        calls = []

        def check(common, store, project, commit, *arguments):
            calls.append(project)
            return {'commit': commit, 'success': True, 'checks': []}

        with patch.object(cache, 'refresh_project', side_effect=check):
            self.assertEqual(0, cache.drain(self.common, self.store, cache.acquire_worker(self.store)))
        self.assertEqual(['apps/docs', 'apps/cli'], calls)
        self.assertEqual({}, cache.load_requests(self.store)['pending'])

    def test_refresh_fetches_an_external_merge_before_reading_ci(self):
        remote = self.advance('merged outside closeout')
        self.assertEqual(self.commit, cache.known_main(self.common))
        with patch.object(cache, 'refresh_project', side_effect=self.passed) as checks:
            self.assertEqual(0, self.drain())
        self.assertEqual(remote, checks.call_args.args[3])
        self.assertEqual(remote, cache.known_main(self.common))

    def test_refresh_releases_lock_and_preserves_other_project_progress_on_failure(self):
        failed = {'commit': self.commit, 'success': False, 'checks': [{'tool': 'import', 'exit_code': 1}]}
        passed = {'commit': self.commit, 'success': True, 'checks': [{'tool': 'import', 'exit_code': 0}]}
        with patch.object(cache, 'refresh_project', side_effect=[failed, passed]) as refresh:
            self.assertEqual(1, self.drain(['apps/cli', 'apps/docs']))
            self.assertEqual(2, refresh.call_count)
        self.assertFalse(cache.load_requests(self.store)['results']['apps/cli']['success'])
        self.assertTrue(cache.load_requests(self.store)['results']['apps/docs']['success'])
        self.assertFalse(cache.active_worker(self.store))
        with patch.object(cache, 'refresh_project', return_value=passed):
            self.assertEqual(0, self.drain(['apps/docs']))

    def test_partial_request_upgrades_all_retained_projects(self):
        with patch.object(cache, 'worker_key', return_value='old-runner'):
            self.submit(['apps/cli', 'apps/docs'])
        with patch.object(cache, 'worker_key', return_value='new-runner'):
            state = self.submit(['apps/docs'])
            self.assertEqual({'new-runner'}, {request['worker_key'] for request in state['pending'].values()})
            with patch.object(cache, 'refresh_project', side_effect=self.passed) as checks:
                self.assertEqual(0, cache.drain(self.common, self.store, cache.acquire_worker(self.store)))
            self.assertEqual(2, checks.call_count)
        self.assertEqual({}, cache.load_requests(self.store)['pending'])

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
signal.signal(signal.SIGTERM, signal.SIG_IGN)
if os.fork() == 0:
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

    def test_main_request_arriving_during_refresh_is_not_acknowledged_by_old_batch(self):
        self.submit()
        commits = []

        def check(common, store, project, commit, *arguments):
            commits.append(commit)
            if len(commits) == 1:
                self.next_commit = self.advance('next main')
                cache.git(self.root, 'update-ref', 'refs/remotes/origin/main', self.next_commit)
                self.submit()
            return {'commit': commit, 'success': True, 'checks': []}

        with patch.object(cache, 'refresh_project', side_effect=check):
            self.assertEqual(0, cache.drain(self.common, self.store, cache.acquire_worker(self.store)))
        self.assertEqual([self.commit, self.next_commit], commits)
        state = cache.load_requests(self.store)
        self.assertEqual({}, state['pending'])
        self.assertEqual(self.next_commit, state['results'][self.project]['commit'])

    def test_interrupted_worker_leaves_request_for_a_later_worker(self):
        self.submit()
        with patch.object(cache, 'refresh_project', side_effect=SystemExit('interrupted')):
            with self.assertRaises(SystemExit):
                cache.drain(self.common, self.store, cache.acquire_worker(self.store))
        self.assertFalse(cache.active_worker(self.store))
        self.assertIn(self.project, cache.load_requests(self.store)['pending'])
        with patch.object(cache, 'refresh_project', side_effect=self.passed):
            self.assertEqual(0, cache.drain(self.common, self.store, cache.acquire_worker(self.store)))
        self.assertEqual({}, cache.load_requests(self.store)['pending'])

    def test_launch_failure_keeps_a_durable_request_without_an_active_owner(self):
        with launched_workers(OSError('spawn failed')):
            with self.assertRaisesRegex(OSError, 'spawn failed'):
                cache.start_background(self.common, self.store, [self.project])
        self.assertIn(self.project, cache.load_requests(self.store)['pending'])
        self.assertFalse(cache.active_worker(self.store))

    def test_old_worker_leaves_upgraded_request_for_the_new_runner(self):
        with patch.object(cache, 'worker_key', return_value='new-runner'):
            self.submit()
        with patch.object(cache, 'worker_key', return_value='old-runner'), \
                patch.object(cache, 'refresh_project') as checks:
            cache.drain(self.common, self.store, cache.acquire_worker(self.store))
            checks.assert_not_called()
        self.assertFalse(cache.active_worker(self.store))
        self.assertIn(self.project, cache.load_requests(self.store)['pending'])
        with patch.object(cache, 'worker_key', return_value='new-runner'), \
                patch.object(cache, 'refresh_project', side_effect=self.passed):
            self.assertEqual(0, cache.drain(self.common, self.store, cache.acquire_worker(self.store)))
        self.assertEqual({}, cache.load_requests(self.store)['pending'])

    def test_status_reads_remote_main_without_advancing_primary_or_queueing_work(self):
        old = self.commit
        current = self.advance('next')
        self.assertEqual(old, cache.known_main(self.common))
        result = cache.status(self.common, self.store, remote=True)
        self.assertEqual(current, result['main'])
        self.assertTrue(result['needed'])
        self.assertEqual({}, result['pending'])
        self.assertFalse((self.store / 'requests.json').exists())
        self.assertEqual(old, cache.known_main(self.common))


class QualityPublicationTest(CacheFixture):
    def publish_quality(self, commit=None, run_id=123, data=b'portable tool result', **options):
        return self.publish(commit, run_id, quality={tool: data for tool in cache.QUALITY}, **options)

    def seed_quality(self, worktree):
        return subprocess.run([sys.executable, str(Path(cache.__file__).with_name('worktree-cache')),
                               '--worktree', str(worktree)], capture_output=True, text=True)

    def test_ci_quality_caches_seed_without_a_live_donor_and_writes_stay_private(self):
        self.publish_quality()
        feature = self.feature()
        result = self.seed_quality(feature)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn('published main', result.stdout)
        for tool in cache.QUALITY:
            path = feature / self.project / cache.QUALITY[tool][1]
            self.assertEqual(b'portable tool result', path.read_bytes())
            path.write_bytes(b'private edit')
            publication = json.loads(cache.quality_path(self.store, self.project, tool).read_text())
            self.assertEqual(b'portable tool result', cache.quality_data(publication, self.project, tool))
            self.assertEqual({'run_id': 123}, publication['source'])
        self.seed_quality(feature)
        self.assertEqual(b'private edit', (feature / self.project / cache.QUALITY['pint'][1]).read_bytes())

    def test_a_corrupt_ci_quality_cache_keeps_the_previous_publication(self):
        self.publish_quality()
        path = cache.quality_path(self.store, self.project, 'pint')
        original = path.read_bytes()
        newer = self.advance('new main')
        artifact = self.artifact(newer, quality={'pint': b'new result'})
        (artifact / 'quality/pint').write_bytes(b'tampered')
        with self.assertRaisesRegex(ValueError, 'pint cache checksum differs'):
            cache.publish_artifact(self.common, self.store, self.project, artifact, newer, 123)
        self.assertEqual(original, path.read_bytes())

    def test_corrupt_incompatible_and_future_quality_publications_are_not_seeded(self):
        self.publish_quality()
        feature = self.feature()
        path = cache.quality_path(self.store, self.project, 'pint')
        original = json.loads(path.read_text())
        future = self.advance('future')
        for change in [{'sha256': 'corrupt'}, {'fingerprint': 'wrong'}, {'tested_commit': future},
                       {'project': 'apps/cli'}, {'tool': 'phpstan'}, {'data': 'invalid base64'}]:
            with self.subTest(change=change):
                path.write_text(json.dumps({**original, **change}))
                self.assertEqual(0, self.seed_quality(feature).returncode)
                self.assertFalse((feature / self.project / cache.QUALITY['pint'][1]).exists())

    def test_a_ci_runtime_of_another_php_minor_is_not_seeded(self):
        self.publish_quality(php='7.4')
        feature = self.feature()
        self.assertEqual(0, self.seed_quality(feature).returncode)
        self.assertFalse((feature / self.project / cache.QUALITY['pint'][1]).exists())


class ReviewGateTest(unittest.TestCase):
    setUp = lambda self: repository(self, project_files=False)
    commit_change = CacheFixture.commit_change

    # Reuse repository setup, without repeating the cache test cases.
    def gate_fixture(self):
        (self.root / 'bin').mkdir()
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
            pest = directory / 'vendor/bin/pest'
            pest.parent.mkdir(parents=True)
            pest.write_text('#!/bin/sh\nexit 0\n')
            pest.chmod(0o755)
        (self.root / '.gitignore').write_text('.orbit-tia/\n')
        self.commit_change('gate fixture')
        fake_bin = Path(self.temporary.name) / 'fake-bin'
        fake_bin.mkdir()
        composer = fake_bin / 'composer'
        composer.write_text("""#!/bin/sh
printf '%s|%s\\n' "$PWD" "$*" >> "$GATE_TEST_CALLS"
if [ "$1" = test:affected ]; then
    tia_directory="${ORBIT_TIA_DIRECTORY:-.orbit-tia}"
    mkdir -p "$tia_directory"
    printf '[]\n' > "$tia_directory/affected.json"
fi
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
            [ "$waited" -gt 30 ] && exit 8
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

    def test_gate_seeds_quality_and_test_caches_before_checks(self):
        runner = self.gate_fixture()
        result = subprocess.run([str(runner)], env=self.gate_env, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        root = self.root.resolve()
        self.assertEqual([f'worktree-cache --worktree {root}', f'tia-cache seed --repository {root}'],
                         (self.common / 'seeds').read_text().splitlines())

    def test_gate_runs_all_five_projects_and_records_builder_and_exact_candidate(self):
        runner = self.gate_fixture()
        result = subprocess.run([str(runner)], env=self.gate_env, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        report = json.loads(next((self.common / 'orbit-checks').glob('*/*/result.json')).read_text())
        self.assertTrue(report['passed'])
        self.assertEqual('builder', report['role'])
        self.assertEqual(cache.git(self.root, 'rev-parse', 'HEAD'), report['candidate'])
        architecture_tests = {
            'apps/cli': ['tests/Feature/CommandSurfaceTest.php', 'tests/Feature/CliBinaryBuildContractTest.php'],
            'apps/gateway': ['tests/Unit/Architecture',
                             'tests/Feature/Infrastructure/Instances/ConfiguredOriginReadTest.php',
                             'tests/Feature/Infrastructure/Caddy/CaddyPublicationLockTest.php'],
            'apps/e2e': ['tests/Unit/E2E/Value/TopologyNodeCatalogTest.php',
                         'tests/Feature/Configuration/ComposerConfigurationTest.php',
                         'tests/Feature/Configuration/SubprocessTestGroupTest.php',
                         'tests/Feature/Configuration/DocsMergeRefWorkflowTest.php'],
            'packages/php-sdk': ['tests/Unit/SuccessRequestIdBoundaryTest.php',
                                 'tests/Unit/RepositoryGuidanceTest.php',
                                 'tests/Unit/Requests/Workspaces/WorkspaceRequestsTest.php',
                                 'tests/Unit/Requests/Deployments/DeploymentRequestsTest.php'],
        }
        self.assertEqual(self.commit, report['base'])
        expected = [('repository', ['bin/docs-impact', '--gate', '--base', self.commit]),
                    ('repository', ['bin/project-vocabulary']),
                    ('repository', ['bin/env-docs']),
                    ('repository', ['bin/tooling-baseline']),
                    ('repository', ['bin/docs-nav'])]
        for project in cache.PROJECTS:
            expected.extend((project, command) for command in [
                ['composer', 'validate', '--strict'], ['composer', 'check'], ['composer', 'test:affected']])
            expected.extend((project, ['vendor/bin/pest', path])
                            for path in architecture_tests.get(project, []))
        expected.extend(('repository', [f'bin/{name}', '--check']) for name in ('docs-openapi', 'mcp-tools'))
        expected.append(('repository', ['bin/api-fixtures', '--check']))
        self.assertEqual(expected, [(item['project'], item['command']) for item in report['checks']])
        for check in report['checks']:
            self.assertIn(f'[{check["project"]}] {" ".join(check["command"])}: passed ({check["seconds"]:.3f}s;',
                          result.stdout)
        self.assertEqual([f'{self.root.resolve()}|--gate --base {self.commit}'],
                         (self.common / 'docs').read_text().splitlines())
        self.assertEqual(15, len((self.common / 'calls').read_text().splitlines()))

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
        # The default budget of 4 runs three lanes at once: the Gateway gets two processes, whichever lane asks first.
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

    def test_gate_runs_projects_at_the_same_time_up_to_the_process_setting(self):
        runner = self.gate_fixture()
        barrier = Path(self.temporary.name) / 'barrier'
        barrier.mkdir()
        environment = {**self.gate_env, 'GATE_TEST_BARRIER': str(barrier), 'ORBIT_CHECK_PROCESSES': '3'}
        result = subprocess.run([str(runner)], env=environment, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        # One project at a time, also at a budget of 2 so that a 2 GB task VM runs one PHPStan or Rector: the CLI
        # check waits for the Docs check, which has not started, and gives up.
        for budget in ('1', '2'):
            for name in ('cli', 'docs'):
                (barrier / name).unlink(missing_ok=True)
            result = subprocess.run([str(runner)], env={**environment, 'ORBIT_CHECK_PROCESSES': budget},
                                    capture_output=True, text=True)
            self.assertEqual(1, result.returncode, result.stderr + result.stdout)
            self.assertIn('[apps/cli] composer check: FAILED', result.stdout)
        result = subprocess.run([str(runner)], env={**environment, 'ORBIT_CHECK_PROCESSES': 'many'},
                                capture_output=True, text=True)
        self.assertEqual(1, result.returncode, result.stderr + result.stdout)
        self.assertIn("ORBIT_CHECK_PROCESSES must be a positive integer, not 'many'.", result.stderr)

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
        self.assertEqual(15, len((self.common / 'calls').read_text().splitlines()))
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

    def test_cross_project_contract_failures_block_even_when_tia_selects_nothing(self):
        runner = self.gate_fixture()
        failures = {'apps/e2e': 'tests/Feature/Configuration/SubprocessTestGroupTest.php'}
        for project, contract in failures.items():
            pest = self.root / project / 'vendor/bin/pest'
            pest.write_text(f'#!/bin/sh\n[ "$1" != "{contract}" ] || exit 19\n')
        base = self.commit_change('contract fixtures')
        cache.git(self.root, 'update-ref', 'refs/remotes/origin/main', base)
        source = self.root / 'apps/gateway/app/Example.php'
        source.parent.mkdir()
        source.write_text('<?php // candidate')
        candidate = self.commit_change('gateway candidate')
        # This fixture exercises a local handoff; CI deliberately ignores the task test base.
        result = subprocess.run([str(runner)], env={**self.gate_env, 'ORBIT_TASK_CHECK_BASE': candidate,
                                                  'CI': '', 'GITHUB_ACTIONS': ''},
                                capture_output=True, text=True)
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        report = json.loads(next((self.common / 'orbit-checks').glob('*/*/result.json')).read_text())
        self.assertEqual(['apps/gateway/app/Example.php'], report['changed_paths'])
        self.assertEqual(candidate, report['test_base'])
        for project, contract in failures.items():
            check = next(item for item in report['checks'] if item['project'] == project
                         and item['command'] == ['vendor/bin/pest', contract])
            self.assertEqual(19, check['exit_code'])
        self.assertFalse(report['passed'])

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


class CiCacheRefTest(CacheFixture):
    def setUp(self):
        super().setUp()
        ref_loader = importlib.machinery.SourceFileLoader('ci_cache_ref', str(Path(cache.__file__).with_name('ci-cache-ref')))
        ref_spec = importlib.util.spec_from_loader(ref_loader.name, ref_loader)
        self.refs = importlib.util.module_from_spec(ref_spec)
        ref_loader.exec_module(self.refs)
        for project in cache.PROJECTS:
            if project != self.project:
                shutil.copytree(self.root / self.project, self.root / project)
        self.commit = self.commit_change('all project inputs')
        self.remote = Path(self.temporary.name) / 'origin.git'
        cache.git(self.root, 'init', '--bare', str(self.remote))
        cache.git(self.root, 'remote', 'set-url', 'origin', str(self.remote))
        cache.git(self.root, 'push', 'origin', 'main')
        self.exports = Path(self.temporary.name) / 'exports'
        self.exports.mkdir()
        for project in cache.PROJECTS:
            self.project = project
            artifact = self.artifact(self.commit, quality={'pint': pint_cache(), 'phpstan': phpstan_cache()},
                                     manifest={'schema': 2, 'run_attempt': '1', 'configurations': {
                                         tool: cache.digest((self.root / project / configuration).read_bytes())
                                         for tool, (configuration, _) in cache.QUALITY.items()}})
            shutil.copytree(artifact, self.exports / project.replace('/', '-'))
        self.project = 'apps/docs'

    def publish_ref(self):
        with patch.dict(os.environ, {'GITHUB_RUN_ID': '123', 'GITHUB_RUN_ATTEMPT': '1'}):
            self.refs.publish(self.root, self.exports, self.commit, 'origin')
        return self.refs.NAMESPACE + self.commit

    def import_ref(self, commit=None):
        output = io.StringIO()
        with redirect_stdout(output), patch.object(self.refs.tia, 'metadata', return_value=self.info):
            self.refs.import_ref(self.root, commit or self.commit)
        return output.getvalue()

    def corrupt_ref_file(self, path, data, mode='100644'):
        ref = self.refs.NAMESPACE + self.commit
        environment = {'GIT_INDEX_FILE': str(Path(self.temporary.name) / 'corrupt-index')}
        self.refs.git(self.root, 'read-tree', ref, environment=environment)
        oid = self.refs.git(self.root, 'hash-object', '-w', '--stdin', data=data).decode().strip()
        self.refs.git(self.root, 'update-index', '--add', '--cacheinfo', f'{mode},{oid},{path}', environment=environment)
        tree = self.refs.git(self.root, 'write-tree', environment=environment).decode().strip()
        commit = self.refs.git(self.root, 'commit-tree', tree, data=b'corrupted fixture\n').decode().strip()
        cache.git(self.root, 'update-ref', ref, commit)

    def test_matching_ref_imports_graph_and_quality_without_source_checkout_changes(self):
        ref = self.publish_ref()
        self.assertNotEqual(cache.git(self.root, 'rev-parse', ref), self.commit)
        self.assertEqual(len(cache.git(self.root, 'rev-list', '--parents', '-n', '1', ref).split()), 1)
        self.assertEqual(cache.git(self.root, 'status', '--porcelain'), '')
        self.assertIn('imported CI baseline', self.import_ref())
        for project in cache.PROJECTS:
            self.assertEqual(json.loads((self.root / project / '.orbit-tia/graph.json').read_text())['baselines']['main']['sha'], self.commit)
            self.assertEqual((self.root / project / 'vendor/phpstan/cache/resultCache.php').read_bytes(), phpstan_cache())
            self.assertEqual((self.root / project / 'vendor/pint.cache').read_bytes(), pint_cache())
        self.assertIn('kept existing', self.import_ref())

    def test_fresh_clone_setup_uses_ci_ref_and_private_package_cache_with_cold_fallback(self):
        ref = self.publish_ref()
        # CI may initialize bare repositories with a different default branch.
        cache.git(self.remote, 'symbolic-ref', 'HEAD', 'refs/heads/master')
        resources = Path(cache.__file__).resolve().parent.parent / 'apps/gateway/resources/task-vms'
        packages = runpy.run_path(str(resources / 'package-cache.py'))
        seed = runpy.run_path(str(resources / 'package-cache-seed.py'))['seed']
        archive = Path(self.temporary.name) / 'demo.zip'
        with zipfile.ZipFile(archive, 'w') as output:
            output.writestr('demo/composer.json', json.dumps({'name': 'acme/demo', 'version': '1.0.0'}))
            output.writestr('demo/proof.txt', 'installed')
        data = archive.read_bytes()
        url = 'https://api.github.com/repos/acme/demo/zipball/' + 'a' * 40
        artifact = dict(manager='composer', package='acme/demo', version='1.0.0', url=url,
                        integrity='sha1-' + hashlib.sha1(data).hexdigest(),
                        key='composer/acme/demo/' + hashlib.sha1(url.encode()).hexdigest() + '.zip')
        shared = Path(self.temporary.name) / 'shared'
        packages['fill'](shared, [artifact], 100000, lambda source: io.BytesIO(data))
        for path in shared.rglob('*'):
            path.chmod(0o555 if path.is_dir() else 0o444)
        shared.chmod(0o555)
        for present in (True, False):
            with self.subTest(caches_present=present):
                workspace = Path(self.temporary.name) / ('warm' if present else 'cold')
                cache.git(self.root, 'clone', '--depth', '1', '--branch', 'main', self.remote.as_uri(), str(workspace))
                self.assertEqual(cache.git(workspace, 'rev-parse', '--is-shallow-repository'), 'true')
                self.assertEqual(cache.git(workspace, 'rev-parse', 'HEAD'), self.commit)
                home = workspace / '.private-home'
                seed(shared, home, [artifact] if present else [])
                if present:
                    cache.git(workspace, 'fetch', 'origin', ref + ':' + ref)
                with patch.object(self.refs.tia, 'metadata', return_value={**self.info, 'cache': str(workspace / '.cache')}):
                    output = io.StringIO()
                    with redirect_stdout(output):
                        self.refs.import_ref(workspace, self.commit)
                self.assertIn('imported CI baseline' if present else 'cold', output.getvalue())
                setup = workspace / 'install'
                setup.mkdir()
                (setup / 'composer.json').write_text(json.dumps({'require': {'acme/demo': '1.0.0'}}))
                dist = dict(type='zip', url=url if present else 'file://' + str(archive), shasum=hashlib.sha1(data).hexdigest())
                (setup / 'composer.lock').write_text(json.dumps({'content-hash': 'fixture', 'packages': [
                    dict(name='acme/demo', version='1.0.0', type='library', dist=dist)], 'packages-dev': []}))
                environment = {**os.environ, 'COMPOSER_CACHE_DIR': str(home / '.cache/composer'),
                               'COMPOSER_HOME': str(home / '.composer'), 'COMPOSER_ALLOW_SUPERUSER': '1'}
                result = subprocess.run(['composer', 'install', '--no-interaction', '--no-plugins', '--no-scripts',
                                         '--no-progress'], cwd=setup, env=environment, capture_output=True)
                self.assertEqual(result.returncode, 0, result.stderr.decode())
                self.assertEqual((setup / 'vendor/acme/demo/proof.txt').read_text(), 'installed')
                self.assertEqual((workspace / self.project / '.orbit-tia/graph.json').exists(), present)
                self.assertEqual((shared / artifact['key']).read_bytes(), data)

    def test_missing_ref_and_ref_for_another_sha_run_cold(self):
        self.assertIn('cold', self.import_ref())
        self.publish_ref()
        other = self.advance('new baseline without a ref')
        self.assertIn('cold', self.import_ref(other))
        self.assertFalse((self.root / self.project / '.orbit-tia/graph.json').exists())

    def test_ref_suffix_and_payload_sha_must_agree(self):
        ref = self.publish_ref()
        other = self.advance('other selected baseline')
        cache.git(self.root, 'update-ref', self.refs.NAMESPACE + other, cache.git(self.root, 'rev-parse', ref))
        self.assertIn('identity differs', self.import_ref(other))
        self.assertFalse((self.root / self.project / 'vendor/pint.cache').exists())

    def test_bad_checksum_in_any_project_rejects_the_entire_payload_before_writes(self):
        source = self.exports / 'packages-php-sdk' / 'quality/phpstan'
        source.write_bytes(b'<?php // corrupt')
        with self.assertRaisesRegex(ValueError, 'checksum'):
            self.publish_ref()
        self.assertEqual(cache.git(self.remote, 'for-each-ref', self.refs.NAMESPACE), '')

    def test_import_rejects_corrupt_executable_cache_before_any_project_is_written(self):
        self.publish_ref()
        self.corrupt_ref_file('packages/php-sdk/quality/phpstan', b'<?php // wrong bytes')
        self.assertIn('checksum differs', self.import_ref())
        for project in cache.PROJECTS:
            self.assertFalse((self.root / project / '.orbit-tia/graph.json').exists())
            self.assertFalse((self.root / project / 'vendor/pint.cache').exists())

    def test_import_rejects_symlinks_unknown_paths_and_limits_before_writes(self):
        self.publish_ref()
        original = cache.git(self.root, 'rev-parse', self.refs.NAMESPACE + self.commit)
        for path, mode in (('apps/docs/manifest.json', '120000'), ('apps/docs/unknown.php', '100644')):
            self.corrupt_ref_file(path, b'unsafe', mode)
            self.assertIn('unsafe or unknown', self.import_ref())
            cache.git(self.root, 'update-ref', self.refs.NAMESPACE + self.commit, original)
        for name in ('MAX_FILES', 'MAX_BYTES'):
            with patch.object(self.refs, name, 1):
                self.assertIn('payload limits', self.import_ref())
        self.assertFalse((self.root / self.project / '.orbit-tia/graph.json').exists())

    def test_required_cache_writes_are_not_disguised_as_a_cache_miss(self):
        self.publish_ref()
        with patch.object(self.refs.tia, 'atomic_write', side_effect=OSError('fixture disk full')):
            with self.assertRaisesRegex(OSError, 'disk full'):
                self.import_ref()

    def test_publisher_refuses_incomplete_or_other_run_exports(self):
        manifest = self.exports / 'apps-docs/manifest.json'
        data = json.loads(manifest.read_text())
        data['run_id'] = '456'
        manifest.write_text(json.dumps(data))
        with self.assertRaisesRegex(ValueError, 'same workflow run'):
            self.publish_ref()
        shutil.rmtree(self.exports / 'apps-docs')
        with self.assertRaisesRegex(ValueError, 'every Composer project'):
            self.publish_ref()
        self.assertEqual(cache.git(self.remote, 'for-each-ref', self.refs.NAMESPACE), '')

    def test_invalid_existing_publication_fails_without_overwriting(self):
        ref = self.refs.NAMESPACE + self.commit
        cache.git(self.root, 'push', 'origin', 'HEAD:' + ref)
        before = cache.git(self.remote, 'rev-parse', ref)
        with self.assertRaisesRegex(ValueError, 'unsafe|unknown'):
            self.publish_ref()
        self.assertEqual(cache.git(self.remote, 'rev-parse', ref), before)

    def test_retry_keeps_existing_valid_publication(self):
        ref = self.publish_ref()
        first = cache.git(self.remote, 'rev-parse', ref)
        for manifest in self.exports.rglob('manifest.json'):
            data = json.loads(manifest.read_text())
            data['run_attempt'] = '2'
            manifest.write_text(json.dumps(data))
        with patch.dict(os.environ, {'GITHUB_RUN_ID': '123', 'GITHUB_RUN_ATTEMPT': '2'}):
            self.refs.publish(self.root, self.exports, self.commit, 'origin')
        self.assertEqual(cache.git(self.remote, 'rev-parse', ref), first)

    def test_prune_keeps_newest_code_ancestor_not_last_job_and_only_cache_namespace(self):
        ref = self.publish_ref()
        newest = self.advance('newest main')
        # Simulate an old publication for a newer tested code commit.
        tree = cache.git(self.root, 'rev-parse', ref + '^{tree}')
        newer = self.refs.git(self.root, 'commit-tree', tree, data=b'new cache\n', environment={
            'GIT_AUTHOR_NAME': 'CI', 'GIT_AUTHOR_EMAIL': 'ci@example.test',
            'GIT_COMMITTER_NAME': 'CI', 'GIT_COMMITTER_EMAIL': 'ci@example.test',
            'GIT_AUTHOR_DATE': '2020-01-01T00:00:00Z', 'GIT_COMMITTER_DATE': '2020-01-01T00:00:00Z'}).decode().strip()
        cache.git(self.root, 'push', 'origin', newer + ':' + self.refs.NAMESPACE + newest)
        cache.git(self.root, 'push', 'origin', 'main:refs/tags/keep')
        self.refs.prune(self.root, 'origin', now=time.time() + 30 * 86400)
        self.assertEqual(cache.git(self.remote, 'for-each-ref', '--format=%(refname)', self.refs.NAMESPACE), self.refs.NAMESPACE + newest)
        self.assertEqual(cache.git(self.remote, 'rev-parse', 'refs/tags/keep'), newest)
        self.assertEqual(cache.git(self.remote, 'rev-parse', 'main'), self.commit)


if __name__ == '__main__':
    unittest.main(verbosity=2)
