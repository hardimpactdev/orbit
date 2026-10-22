"""Exercise the publication and import of main CI's Pint and PHPStan cache refs with real repositories."""
from contextlib import redirect_stdout
import hashlib
import importlib.machinery
import importlib.util
import io
import json
import os
from pathlib import Path
import runpy
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
import zipfile

sys.dont_write_bytecode = True
loader = importlib.machinery.SourceFileLoader('ci_cache_ref', sys.argv.pop(1))
refs = importlib.util.module_from_spec(importlib.util.spec_from_loader(loader.name, loader))
loader.exec_module(refs)
STATE = tempfile.TemporaryDirectory(prefix='orbit-ci-ref-state-')
# Git's automatic maintenance must finish before these disposable repositories are removed.
# A template covers clones and bare remotes too: local push receivers clear command-level Git configuration.
GIT_TEMPLATE = Path(STATE.name) / 'git-template'
GIT_TEMPLATE.mkdir()
(GIT_TEMPLATE / 'config').write_text('[maintenance]\n\tautoDetach = false\n[gc]\n\tautoDetach = false\n')
os.environ['GIT_TEMPLATE_DIR'] = str(GIT_TEMPLATE)
for name in ('GITHUB_RUN_ID', 'GITHUB_RUN_ATTEMPT'):
    os.environ.pop(name, None)


def php(code):
    return subprocess.run(['php', '-r', code], check=True, capture_output=True, text=True).stdout


def pint_cache(version=None):
    """A Pint cache as Pint writes it, for this PHP version unless another is named."""
    return json.dumps({'php': version or PHP_VERSION, 'version': '3.95.25', 'rules': {}, 'hashes': {}}).encode()


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


PHP_VERSION = php('echo PHP_VERSION;')
PHP_MINOR = php('echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
PINT = pint_cache()
PHPSTAN = phpstan_cache()


def git(root, *args):
    return refs.git(root, *args).decode().strip()


class CiCacheRefFixture(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='orbit ci ref ')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name) / 'main'
        self.root.mkdir()
        git(self.root, 'init', '-b', 'main')
        git(self.root, 'config', 'user.name', 'Orbit')
        git(self.root, 'config', 'user.email', 'orbit@example.test')
        (self.root / '.gitignore').write_text('**/vendor/\n')
        (self.root / 'source.php').write_text('base')
        for project in refs.PROJECTS:
            directory = self.root / project
            directory.mkdir(parents=True)
            (directory / 'composer.lock').write_text('{"packages":[]}')
            (directory / 'pint.json').write_text('{"cache-file":"vendor/pint.cache"}')
            (directory / 'phpstan.neon').write_text('parameters:\n    level: 6\n')
        self.project = 'apps/docs'
        self.commit = self.commit_change('all project inputs')
        self.remote = Path(self.temporary.name) / 'origin.git'
        git(self.root, 'init', '--bare', str(self.remote))
        git(self.root, 'remote', 'add', 'origin', str(self.remote))
        git(self.root, 'push', 'origin', 'main')
        self.exports = Path(self.temporary.name) / 'exports'
        for project in refs.PROJECTS:
            self.export(project)

    def commit_change(self, message):
        git(self.root, 'add', '.')
        git(self.root, 'commit', '-m', message)
        return git(self.root, 'rev-parse', 'HEAD')

    def advance(self, message):
        (self.root / 'source.php').write_text(message)
        return self.commit_change(message)

    def export(self, project, quality=None, changes=None):
        """Write one project's export, as the Export main quality caches step of ci.yml does."""
        directory = self.exports / f'ci-cache-{refs.PROJECTS.index(project)}'
        shutil.rmtree(directory, ignore_errors=True)
        (directory / 'quality').mkdir(parents=True)
        quality = {'pint': PINT, 'phpstan': PHPSTAN} if quality is None else quality
        checksums, configurations = {}, {}
        for tool, data in quality.items():
            (directory / 'quality' / tool).write_bytes(data)
            checksums[tool] = refs.digest(data)
            configurations[tool] = refs.digest((self.root / project / refs.QUALITY[tool][0]).read_bytes())
        manifest = {'schema': 3, 'project': project, 'commit': self.commit, 'run_id': '123',
                    'run_attempt': '1', 'configurations': configurations, 'php': PHP_MINOR, 'quality': checksums}
        (directory / 'manifest.json').write_text(json.dumps({**manifest, **(changes or {})}, indent=2) + '\n')
        return directory

    def publish_ref(self, run_id='123', attempt='1'):
        with patch.dict(os.environ, {'GITHUB_RUN_ID': run_id, 'GITHUB_RUN_ATTEMPT': attempt}), \
                redirect_stdout(io.StringIO()):
            refs.publish(self.root, self.exports, self.commit, 'origin')
        return refs.NAMESPACE + self.commit

    def import_ref(self, commit=None, root=None):
        output = io.StringIO()
        with redirect_stdout(output):
            refs.import_ref(root or self.root, commit or self.commit)
        return output.getvalue()

    def remote_refs(self):
        return git(self.remote, 'for-each-ref', '--format=%(refname)', refs.NAMESPACE).splitlines()

    def corrupt_ref_file(self, path, data, mode='100644'):
        ref = refs.NAMESPACE + self.commit
        environment = {'GIT_INDEX_FILE': str(Path(self.temporary.name) / 'corrupt-index')}
        refs.git(self.root, 'read-tree', ref, environment=environment)
        oid = refs.git(self.root, 'hash-object', '-w', '--stdin', data=data).decode().strip()
        refs.git(self.root, 'update-index', '--add', '--cacheinfo', f'{mode},{oid},{path}', environment=environment)
        tree = refs.git(self.root, 'write-tree', environment=environment).decode().strip()
        commit = refs.git(self.root, 'commit-tree', tree, data=b'corrupted fixture\n').decode().strip()
        git(self.root, 'update-ref', ref, commit)

    def caches(self, root=None):
        return [(root or self.root) / project / cache for project in refs.PROJECTS
                for _configuration, cache in refs.QUALITY.values()]


class CiCacheRefTest(CiCacheRefFixture):
    def test_matching_ref_imports_pint_and_phpstan_without_source_checkout_changes(self):
        ref = self.publish_ref()
        self.assertNotEqual(git(self.root, 'rev-parse', ref), self.commit)
        self.assertEqual(1, len(git(self.root, 'rev-list', '--parents', '-n', '1', ref).split()))
        self.assertEqual(sorted(f'{project}/{name}' for project in refs.PROJECTS
                                for name in ('manifest.json', 'quality/phpstan', 'quality/pint')),
                         sorted(git(self.root, 'ls-tree', '-r', '--name-only', ref).splitlines()))
        output = self.import_ref()
        for project in refs.PROJECTS:
            self.assertIn(f'[{project}] imported CI pint cache\n', output)
            self.assertIn(f'[{project}] imported CI phpstan cache; PHPStan may still run cold if the PHP build differs',
                          output)
            self.assertEqual(PINT, (self.root / project / 'vendor/pint.cache').read_bytes())
            self.assertEqual(PHPSTAN, (self.root / project / 'vendor/phpstan/cache/resultCache.php').read_bytes())
        self.assertEqual('', git(self.root, 'status', '--porcelain'))
        self.assertEqual(self.commit, git(self.root, 'rev-parse', 'HEAD'))
        (self.root / self.project / 'vendor/pint.cache').write_bytes(b'private progress')
        output = self.import_ref()
        self.assertIn(f'[{self.project}] kept existing pint cache', output)
        self.assertIn(f'[{self.project}] kept existing phpstan cache', output)
        self.assertEqual(b'private progress', (self.root / self.project / 'vendor/pint.cache').read_bytes())

    def test_a_project_without_exported_caches_publishes_and_imports_nothing_for_itself(self):
        self.export('apps/cli', quality={})
        self.export('apps/e2e', quality={'pint': PINT})
        ref = self.publish_ref()
        self.assertEqual(['apps/cli/manifest.json'],
                         git(self.root, 'ls-tree', '-r', '--name-only', ref, 'apps/cli').splitlines())
        self.import_ref()
        self.assertFalse((self.root / 'apps/cli/vendor').exists())
        self.assertTrue((self.root / 'apps/e2e/vendor/pint.cache').exists())
        self.assertFalse((self.root / 'apps/e2e/vendor/phpstan').exists())
        self.assertTrue((self.root / self.project / 'vendor/phpstan/cache/resultCache.php').exists())

    def fresh_clone_setup(self, present):
        ref = self.publish_ref()
        # CI may initialize bare repositories with a different default branch.
        git(self.remote, 'symbolic-ref', 'HEAD', 'refs/heads/master')
        resources = Path(refs.__file__).resolve().parent.parent / 'apps/gateway/resources/task-vms'
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
        self.addCleanup(lambda: [path.chmod(0o755) for path in [shared, *shared.rglob('*')] if path.is_dir()])
        workspace = Path(self.temporary.name) / ('warm' if present else 'cold')
        git(self.root, 'clone', '--depth', '1', '--branch', 'main', self.remote.as_uri(), str(workspace))
        self.assertEqual('true', git(workspace, 'rev-parse', '--is-shallow-repository'))
        self.assertEqual(self.commit, git(workspace, 'rev-parse', 'HEAD'))
        home = workspace / '.private-home'
        seed(shared, home, [artifact] if present else [])
        if present:
            git(workspace, 'fetch', 'origin', ref + ':' + ref)
        output = self.import_ref(root=workspace)
        self.assertIn('imported CI pint cache' if present else 'cold', output)
        setup = workspace / 'install'
        setup.mkdir()
        (setup / 'composer.json').write_text(json.dumps({'require': {'acme/demo': '1.0.0'}}))
        dist = dict(type='zip', url=url if present else 'file://' + str(archive), shasum=hashlib.sha1(data).hexdigest())
        (setup / 'composer.lock').write_text(json.dumps({'content-hash': 'fixture', 'packages': [
            dict(name='acme/demo', version='1.0.0', type='library', dist=dist)], 'packages-dev': []}))
        environment = {**os.environ, 'COMPOSER_CACHE_DIR': str(home / '.cache/composer'),
                       'COMPOSER_HOME': str(home / '.composer'), 'COMPOSER_ALLOW_SUPERUSER': '1'}
        result = subprocess.run(['composer', 'install', '--no-interaction', '--no-plugins', '--no-scripts',
                                 '--no-progress'], cwd=setup, env=environment, capture_output=True, check=False)
        self.assertEqual(0, result.returncode, result.stderr.decode())
        self.assertEqual('installed', (setup / 'vendor/acme/demo/proof.txt').read_text())
        self.assertEqual(present, (workspace / self.project / 'vendor/pint.cache').exists())
        self.assertEqual(data, (shared / artifact['key']).read_bytes())

    def test_fresh_clone_setup_imports_the_ci_ref_and_uses_the_private_package_cache(self):
        self.fresh_clone_setup(present=True)

    def test_fresh_clone_setup_without_the_ref_or_package_cache_falls_back_cold(self):
        self.fresh_clone_setup(present=False)

    def test_missing_ref_and_ref_for_another_sha_run_cold(self):
        self.assertIn(f'CI caches cold for {self.commit}: ', self.import_ref())
        self.publish_ref()
        other = self.advance('new baseline without a ref')
        self.assertIn(f'CI caches cold for {other}: ', self.import_ref(other))
        # A baseline outside the history of HEAD is never imported.
        git(self.root, 'checkout', '-q', '--detach', 'HEAD~1')
        self.assertIn(f'selected baseline {other} is not an ancestor of HEAD', self.import_ref(other))
        self.assertFalse(any(path.exists() for path in self.caches()))

    def test_ref_suffix_and_payload_sha_must_agree(self):
        ref = self.publish_ref()
        other = self.advance('other selected baseline')
        git(self.root, 'update-ref', refs.NAMESPACE + other, git(self.root, 'rev-parse', ref))
        self.assertIn('identity differs', self.import_ref(other))
        self.assertFalse(any(path.exists() for path in self.caches()))
        with self.assertRaisesRegex(ValueError, 'Invalid tested code SHA'):
            self.import_ref('main')

    def test_manifests_must_name_the_schema_project_run_runtime_and_configurations(self):
        manifest = json.loads((self.export('packages/php-sdk') / 'manifest.json').read_text())
        for field, value in [('schema', 2), ('project', 'apps/cli'), ('commit', 'a' * 40), ('run_id', ''),
                             ('run_attempt', '0'), ('configurations', None), ('php', '8'), ('quality', [])]:
            with self.subTest(field=field):
                self.export('packages/php-sdk', changes={field: value})
                with self.assertRaisesRegex(ValueError, 'identity differs|quality manifest is invalid|duplicate project'):
                    self.publish_ref()
        self.export('packages/php-sdk', changes={'configurations': {'pint': manifest['configurations']['pint']}})
        with self.assertRaisesRegex(ValueError, 'phpstan configuration is missing'):
            self.publish_ref()
        self.export('packages/php-sdk', changes={'configurations': {**manifest['configurations'], 'pint': 'c' * 64}})
        with self.assertRaisesRegex(ValueError, 'configuration differs from the tested commit'):
            self.publish_ref()
        export = self.export('packages/php-sdk', quality={'pint': PINT})
        (export / 'quality/phpstan').write_bytes(PHPSTAN)
        with self.assertRaisesRegex(ValueError, 'payload differs from its manifest'):
            self.publish_ref()
        self.assertEqual([], self.remote_refs())

    def test_bad_checksum_in_any_project_rejects_the_entire_payload_before_writes(self):
        source = self.exports / 'ci-cache-4' / 'quality/phpstan'
        source.write_bytes(b'<?php // corrupt')
        with self.assertRaisesRegex(ValueError, 'phpstan checksum differs'):
            self.publish_ref()
        self.assertEqual([], self.remote_refs())

    def test_import_rejects_corrupt_executable_cache_before_any_project_is_written(self):
        self.publish_ref()
        self.corrupt_ref_file('packages/php-sdk/quality/phpstan', b'<?php // wrong bytes')
        self.assertIn('phpstan checksum differs', self.import_ref())
        self.assertFalse(any(path.exists() for path in self.caches()))

    def test_import_rejects_symlinks_unknown_paths_and_limits_before_writes(self):
        self.publish_ref()
        original = git(self.root, 'rev-parse', refs.NAMESPACE + self.commit)
        for path, mode in (('apps/docs/manifest.json', '120000'), ('apps/docs/quality/pint', '100755'),
                           ('apps/docs/unknown.php', '100644'), ('apps/docs/graph.json', '100644'),
                           ('apps/docs/quality/tia', '100644'), ('apps/web/manifest.json', '100644')):
            with self.subTest(path=path, mode=mode):
                self.corrupt_ref_file(path, b'unsafe', mode)
                self.assertIn('unsafe or unknown', self.import_ref())
                git(self.root, 'update-ref', refs.NAMESPACE + self.commit, original)
        for name in ('MAX_FILES', 'MAX_BYTES'):
            with self.subTest(limit=name), patch.object(refs, name, 1):
                self.assertIn('payload limits', self.import_ref())
        self.assertFalse(any(path.exists() for path in self.caches()))

    def test_required_cache_writes_are_not_disguised_as_a_cache_miss(self):
        self.publish_ref()
        with patch.object(refs, 'write_new', side_effect=OSError('fixture disk full')), \
                self.assertRaisesRegex(OSError, 'disk full'):
            self.import_ref()

    def test_publisher_refuses_incomplete_or_other_run_exports(self):
        self.export(self.project, changes={'run_id': '456'})
        with self.assertRaisesRegex(ValueError, 'same workflow run'):
            self.publish_ref()
        self.export(self.project)
        with self.assertRaisesRegex(ValueError, 'from this workflow run or a completed attempt'):
            self.publish_ref(run_id='999')
        for project in refs.PROJECTS:
            self.export(project, changes={'run_attempt': '2'})
        with self.assertRaisesRegex(ValueError, 'from this workflow run or a completed attempt'):
            self.publish_ref(attempt='1')
        for project in refs.PROJECTS:
            self.export(project)
        duplicate = self.exports / 'copy'
        shutil.copytree(self.exports / 'ci-cache-1', duplicate)
        with self.assertRaisesRegex(ValueError, 'Unknown or duplicate project export'):
            self.publish_ref()
        shutil.rmtree(duplicate)
        shutil.rmtree(self.exports / 'ci-cache-1')
        with self.assertRaisesRegex(ValueError, 'every Composer project'):
            self.publish_ref()
        self.assertEqual([], self.remote_refs())

    def test_publisher_refuses_symlinks_and_unknown_export_files(self):
        export = self.exports / 'ci-cache-1'
        (export / 'graph.json').write_text('{}')
        with self.assertRaisesRegex(ValueError, 'Unsafe export path'):
            self.publish_ref()
        (export / 'graph.json').unlink()
        (export / 'quality/pint').unlink()
        (export / 'quality/pint').symlink_to(self.root / 'source.php')
        with self.assertRaisesRegex(ValueError, 'Unsafe export path'):
            self.publish_ref()
        self.assertEqual([], self.remote_refs())

    def test_invalid_existing_publication_fails_without_overwriting(self):
        ref = refs.NAMESPACE + self.commit
        git(self.root, 'push', 'origin', 'HEAD:' + ref)
        before = git(self.remote, 'rev-parse', ref)
        with self.assertRaisesRegex(ValueError, 'unsafe or unknown'):
            self.publish_ref()
        self.assertEqual(before, git(self.remote, 'rev-parse', ref))

    def test_retry_keeps_existing_valid_publication(self):
        ref = self.publish_ref()
        first = git(self.remote, 'rev-parse', ref)
        for project in refs.PROJECTS:
            self.export(project, quality={'pint': pint_cache('8.5.99-retry')}, changes={'run_attempt': '2'})
        self.publish_ref(attempt='2')
        self.assertEqual(first, git(self.remote, 'rev-parse', ref))
        self.assertEqual([ref], self.remote_refs())

    def test_prune_keeps_only_the_newest_code_ancestor_and_touches_only_the_cache_namespace(self):
        ref = self.publish_ref()
        middle = self.advance('middle main')
        newest = self.advance('newest main')
        git(self.root, 'push', 'origin', 'main')
        tree = git(self.root, 'rev-parse', ref + '^{tree}')
        dates = {middle: '2026-10-20T00:00:00Z', newest: '2020-01-01T00:00:00Z'}
        for tested, date in dates.items():
            # The publication for the newest code is the oldest cache commit, as a slow run finishes first.
            cache_commit = refs.git(self.root, 'commit-tree', tree, data=b'cache\n', environment={
                'GIT_AUTHOR_DATE': date, 'GIT_COMMITTER_DATE': date}).decode().strip()
            git(self.root, 'push', 'origin', f'{cache_commit}:{refs.NAMESPACE}{tested}')
        git(self.root, 'push', 'origin', f'{self.commit}:refs/tags/keep')
        git(self.root, 'push', 'origin', f'{self.commit}:refs/orbit/other/{self.commit}')
        refs.prune(self.root, 'origin')
        self.assertEqual([refs.NAMESPACE + newest], self.remote_refs())
        self.assertEqual(self.commit, git(self.remote, 'rev-parse', 'refs/tags/keep'))
        self.assertEqual(self.commit, git(self.remote, 'rev-parse', f'refs/orbit/other/{self.commit}'))
        self.assertEqual(newest, git(self.remote, 'rev-parse', 'refs/heads/main'))
        # Pruning again is a no-op, and an older publication for this SHA's ancestor is pruned on publish.
        refs.prune(self.root, 'origin')
        self.assertEqual([refs.NAMESPACE + newest], self.remote_refs())

    def test_publishing_an_older_sha_after_a_newer_one_keeps_only_the_newer(self):
        older = self.commit
        self.commit = self.advance('newer main')
        git(self.root, 'push', 'origin', 'main')
        for project in refs.PROJECTS:
            self.export(project)
        self.publish_ref()
        self.commit = older
        for project in refs.PROJECTS:
            self.export(project)
        self.publish_ref()
        self.assertEqual([refs.NAMESPACE + git(self.root, 'rev-parse', 'main')], self.remote_refs())


class QualityImportTest(CiCacheRefFixture):
    """The per-tool rules of import_quality: only a cache the tool would use is written, and never over a cache."""

    def payload(self, quality):
        directory = Path(tempfile.mkdtemp(dir=self.temporary.name, prefix='payload-'))
        (directory / 'quality').mkdir()
        for tool, data in quality.items():
            (directory / 'quality' / tool).write_bytes(data)
        return directory, {'quality': {tool: refs.digest(data) for tool, data in quality.items()}}

    def import_quality(self, quality, manifest=None):
        directory, checksums = self.payload(quality)
        output = io.StringIO()
        with redirect_stdout(output):
            refs.import_quality(self.root, self.project, directory, manifest or checksums, self.commit)
        return output.getvalue()

    def test_absent_caches_are_written_and_existing_ones_kept(self):
        project = self.root / self.project
        (project / 'vendor').mkdir()
        (project / 'vendor/pint.cache').write_text('local pint')
        output = self.import_quality({'pint': PINT, 'phpstan': PHPSTAN})
        self.assertIn(f'[{self.project}] kept existing pint cache', output)
        self.assertIn(f'[{self.project}] imported CI phpstan cache; PHPStan may still run cold if the PHP build differs',
                      output)
        self.assertEqual('local pint', (project / 'vendor/pint.cache').read_text())
        self.assertEqual(PHPSTAN, (project / 'vendor/phpstan/cache/resultCache.php').read_bytes())
        # A dangling symlink is an existing cache too.
        (project / 'vendor/pint.cache').unlink()
        (project / 'vendor/pint.cache').symlink_to(project / 'missing')
        self.assertIn('kept existing pint cache', self.import_quality({'pint': PINT}))
        self.assertTrue((project / 'vendor/pint.cache').is_symlink())

    def test_caches_that_do_not_fit_the_checkout_run_cold(self):
        project = self.root / self.project
        for name, tool in (('phpstan.neon', 'phpstan'), ('pint.json', 'pint'), ('composer.lock', 'pint')):
            with self.subTest(name=name):
                original = (project / name).read_bytes()
                (project / name).write_text('changed after CI')
                output = self.import_quality({tool: PINT if tool == 'pint' else PHPSTAN})
                self.assertIn(f'[{self.project}] {tool} cache not imported, it runs cold: {name} differs from CI', output)
                (project / name).write_bytes(original)
        (project / 'composer.lock').unlink()
        self.assertIn('composer.lock differs from CI', self.import_quality({'pint': PINT}))
        self.assertFalse(any(path.exists() for path in self.caches()))

    def test_another_php_version_runs_cold_for_each_tool(self):
        cases = [
            ('pint', pint_cache('8.5.0-fixture'), 'differs from CI PHP 8.5.0-fixture'),
            ('pint', b'{"version":"3.95.25"}', 'the cache records no PHP version'),
            ('pint', b'not json', 'the cache records no PHP version'),
            ('pint', b'[]', 'the cache records no PHP version'),
            ('phpstan', phpstan_cache(phpVersion=80000), 'differs from CI PHP 8.0.0'),
            ('phpstan', b'<?php return; ?>\nlastFullAnalysisTime 13\ni:1;', 'the cache records no PHP version'),
        ]
        for tool, data, reason in cases:
            with self.subTest(tool=tool, reason=reason):
                output = self.import_quality({tool: data})
                self.assertIn(f'[{self.project}] {tool} cache not imported, it runs cold: ', output)
                self.assertIn(reason, output)
                self.assertFalse(any(path.exists() for path in self.caches()))

    def test_unreadable_runtime_runs_cold(self):
        with patch.object(refs, 'php', side_effect=RuntimeError('php is missing')):
            output = self.import_quality({'pint': PINT, 'phpstan': PHPSTAN})
        for tool in ('pint', 'phpstan'):
            self.assertIn(f'{tool} cache not imported, it runs cold: its metadata cannot be read: php is missing', output)
        self.assertFalse(any(path.exists() for path in self.caches()))

    def test_empty_or_tampered_cache_is_rejected(self):
        directory, manifest = self.payload({'phpstan': PHPSTAN})
        (directory / 'quality/phpstan').write_bytes(b'tampered')
        with redirect_stdout(io.StringIO()), self.assertRaisesRegex(ValueError, 'phpstan cache checksum differs'):
            refs.import_quality(self.root, self.project, directory, manifest, self.commit)
        with redirect_stdout(io.StringIO()), self.assertRaisesRegex(ValueError, 'pint cache checksum differs'):
            self.import_quality({'pint': b''})
        self.assertFalse(any(path.exists() for path in self.caches()))

    def test_one_project_that_does_not_fit_leaves_the_others_imported(self):
        self.publish_ref()
        (self.root / self.project / 'composer.lock').write_text('{"packages":["local change"]}')
        output = self.import_ref()
        self.assertIn(f'[{self.project}] pint cache not imported, it runs cold: composer.lock differs from CI', output)
        self.assertFalse((self.root / self.project / 'vendor').exists())
        for project in set(refs.PROJECTS) - {self.project}:
            self.assertTrue((self.root / project / 'vendor/pint.cache').exists(), project)


if __name__ == '__main__':
    unittest.main(verbosity=2)
