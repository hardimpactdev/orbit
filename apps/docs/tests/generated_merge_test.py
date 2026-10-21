"""Marker branch artifacts; real generators, Git drivers and two-parent merges."""
import argparse
import importlib.machinery
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[3]
FIXTURES = Path(__file__).resolve().parent / 'Fixtures/GeneratedMerge'
loader = importlib.machinery.SourceFileLoader('generated_merge', str(ROOT / 'bin/generated-merge'))
spec = importlib.util.spec_from_loader(loader.name, loader)
TOOL = importlib.util.module_from_spec(spec)
loader.exec_module(TOOL)


def run(root, *args):
    result = subprocess.run(args, cwd=root, text=True, capture_output=True)
    if result.returncode:
        raise AssertionError(f'{args}: {result.returncode}\n{result.stdout}\n{result.stderr}')
    return result.stdout.strip()


def git(root, *args):
    return run(root, 'git', *args)


class GeneratedMergeTest(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory(prefix='orbit-merge-test-')
        self.tree = Path(self.scratch.name) / 'repo'
        shutil.copytree(self.fixture, self.tree, symlinks=True)
        self.assertFalse((self.tree / '.git/orbit/generated-merge').exists(),
                         'each case must start without a generated-merge cache')
        self.base = git(self.tree, 'rev-parse', 'HEAD')
        self.scenarios = json.loads((self.tree / '.git/orbit/source-fixtures.json').read_text())

    @classmethod
    def prepare_fixture(cls, destination):
        cls.tree = destination
        cls.tree.mkdir()
        git(cls.tree, 'init', '--quiet')
        # Prepare source inputs once, not generator results or a warm cache.
        # Every case copies this whole repository, including its own Git data.
        # Copy current sources, including uncommitted changes, not stale HEAD.
        paths = [
            '.gitignore', 'apps/docs/.gitignore', 'apps/gateway/.gitignore',
            '.gitattributes', 'bin/generated-merge', 'bin/docs-merge-check', 'bin/docs-openapi',
            'bin/mcp-tools', 'bin/docs-nav',
            'apps/docs/app', 'apps/docs/artisan', 'apps/docs/bootstrap/app.php',
            'apps/docs/bootstrap/providers.php', 'apps/docs/config',
            'apps/docs/composer.json', 'apps/docs/composer.lock',
            'apps/gateway/artisan', 'apps/gateway/public/index.php',
            'apps/gateway/config',
            'apps/gateway/composer.json', 'apps/gateway/composer.lock',
            'apps/gateway/extensions/conn',
            'apps/gateway/app/Providers/ExtensionServiceProvider.php',
            'apps/gateway/app/Support', 'apps/gateway/app/Data/Activities',
            'apps/gateway/app/Data/Routes',
            'apps/gateway/app/Http/Middleware', 'apps/gateway/app/Http/Authorization',
            'apps/gateway/app/Http/Controllers/Controller.php',
            'apps/gateway/app/Http/Controllers/Api/ActivitiesController.php',
            'apps/gateway/app/Http/Controllers/Api/RoutesController.php',
            'apps/gateway/app/Http/Requests/Activities/ListActivitiesRequest.php',
            'apps/gateway/app/Http/Requests/Routes/StoreRouteRequest.php',
            'apps/gateway/app/Domain/Extensions',
            'apps/gateway/app/Domain/Routes/RoutePublication.php',
            'apps/cli/app/Commands/Activities', 'apps/cli/app/Commands/Routes',
            'packages/php-sdk/src/Requests/Activities',
            'packages/php-sdk/src/Requests/Routes',
            'packages/php-sdk/src/Responses/Activities',
            'packages/php-sdk/src/Responses/Routes',
            'apps/web/bun.lock',
        ]
        for path in paths:
            source, target = ROOT / path, cls.tree / path
            target.parent.mkdir(parents=True, exist_ok=True)
            if source.is_dir():
                shutil.copytree(source, target)
            else:
                shutil.copy2(source, target)
        for project in ('apps/docs', 'apps/gateway'):
            for folder in ('bootstrap/cache', 'storage/logs'):
                target = cls.tree / project / folder
                target.mkdir(parents=True)
                shutil.copy2(ROOT / project / folder / '.gitignore', target / '.gitignore')
        # Boot real Laravel route discovery and the real Conn provider. Queue,
        # deployment and unrelated third-party providers are not merge inputs.
        for project in ('apps/docs', 'apps/gateway'):
            composer_path = cls.tree / project / 'composer.json'
            composer = json.loads(composer_path.read_text())
            composer['extra']['laravel']['dont-discover'] = ['*']
            composer_path.write_text(json.dumps(composer, indent=2) + '\n')
        (cls.tree / 'apps/docs/bootstrap/providers.php').write_text(r'''<?php
return [App\Providers\AppServiceProvider::class, HardImpact\Librarian\LibrarianServiceProvider::class];
''')
        shutil.copy2(ROOT / 'apps/docs/tests/Fixtures/GeneratedMerge/app.php',
                     cls.tree / 'apps/gateway/bootstrap/app.php')
        (cls.tree / 'apps/gateway/bootstrap/providers.php').write_text('<?php return [];\n')
        (cls.tree / 'apps/gateway/routes').mkdir()
        for path in TOOL.ARTIFACTS:
            (cls.tree / path).parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(FIXTURES / 'base' / path, cls.tree / path)
        for key, value in {'user.name': 'Merge test', 'user.email': 'merge@example.test',
                           'commit.gpgsign': 'false', 'core.hooksPath': '/dev/null',
                           'gc.auto': '0', 'maintenance.auto': 'false'}.items():
            git(cls.tree, 'config', key, value)
        TOOL.dependencies(cls.tree, ROOT)
        # Generators need runtime dependencies, not Pest/PHPUnit bootstrap files
        # or development class maps. Composer writes only these private loaders.
        for project in ('apps/docs', 'apps/gateway'):
            run(cls.tree / project, 'composer', 'dump-autoload', '--no-dev',
                '--no-scripts', '--no-plugins', '--optimize')
        # Build the locked CLI, not any schema output. Each timed driver still
        # starts a cold Bun process; independent freshness uses the original CLI.
        modules = cls.tree / 'apps/web/node_modules'
        modules.unlink()
        (modules / '.bin').mkdir(parents=True)
        package = modules / 'openapi-typescript'
        (package / 'bin').mkdir(parents=True)
        shutil.copy2(ROOT / 'apps/web/node_modules/openapi-typescript/package.json', package / 'package.json')
        run(cls.tree, 'bun', 'build', '--minify', '--target=bun',
            str(ROOT / 'apps/web/node_modules/openapi-typescript/bin/cli.js'),
            '--outfile', str(package / 'bin/cli.js'))
        (modules / '.bin/openapi-typescript').symlink_to('../openapi-typescript/bin/cli.js')
        # Keep all private web dependencies out of the source fixture's index.
        with (cls.tree / '.git/info/exclude').open('a') as excluded:
            excluded.write('\n/apps/web/node_modules\n')
        TOOL.install(cls.tree)
        # Keep real core and extension discovery without generating every core
        # endpoint. Activities anchors the navigation assertion; route:create is
        # also required by the generator's own request-schema checks.
        (cls.tree / 'apps/gateway/routes/api.php').write_text(r'''<?php
use App\Http\Controllers\Api\ActivitiesController;
use App\Http\Controllers\Api\RoutesController;
use Illuminate\Support\Facades\Route;

Route::get('v1/activities', [ActivitiesController::class, 'index'])->name('activity:list');
Route::post('v1/routes', [RoutesController::class, 'store'])->name('route:create');
''')
        for route_file in (cls.tree / 'apps/gateway/extensions').glob('*/routes/api.php'):
            route_file.write_text('<?php\nuse Illuminate\\Support\\Facades\\Route;\n')
        (cls.tree / 'apps/gateway/extensions/conn/routes/api.php').write_text(r'''<?php
use App\Http\Middleware\RequireEnabledExtension;
use Illuminate\Support\Facades\Route;
use Orbit\Extensions\Conn\Http\Controllers\ConnNodesController;

Route::get('api/v1/conn/me', [ConnNodesController::class, 'show'])
    ->middleware(RequireEnabledExtension::class.':conn')->name('conn:me');
''')
        # The static generator evaluates request constants in PHP subprocesses.
        # Conn's settings requests are not inputs to this route corpus.
        shutil.rmtree(cls.tree / 'apps/gateway/extensions/conn/src/Http/Requests')
        # Keep the real Gateway, CLI, SDK and all generators. The documentation
        # corpus only needs the core pages and ADR table used by these merges;
        # linting unrelated product guides belongs to `composer docs-lint`.
        for folder in ('decisions', 'domains', 'solutions'):
            (cls.tree / 'docs' / folder).mkdir(parents=True, exist_ok=True)
        for page in ('README', 'mission', 'architecture', 'tech-stack', 'concepts',
                     'decisions/README', 'domains/README', 'solutions/README'):
            (cls.tree / f'docs/{page}.md').write_text('# Documentation\n\nOrbit checks documentation.\n')
        (cls.tree / 'docs/mission.md').write_text(
            '# Mission\n\nOrbit checks documentation.\n\n## Principles\n\nAgents operate, humans steer.\n')
        for config in ('adr-legacy-allowlist', 'adr-retired-slugs', 'docs-covers-ratchet'):
            (cls.tree / f'apps/docs/config/{config}.php').write_text('<?php return [];\n')
        git(cls.tree, 'add', '-A')
        git(cls.tree, 'add', '-f', 'docs/generated/context.json')
        git(cls.tree, 'commit', '--quiet', '--allow-empty', '-m', 'fixture baseline')
        cls.base = git(cls.tree, 'rev-parse', 'HEAD')
        git(cls.tree, 'update-ref', 'refs/remotes/origin/main', cls.base)
        # Branch commits and expected trees are immutable source inputs. Build
        # them once; the timed tests still exercise conflicts and cold drivers.
        fixture = cls('test_base_openapi_freshness')
        scenarios = {}
        for kind in ('adr', 'endpoint'):
            scenarios[kind] = {'commits': fixture.changes(kind), 'tree': fixture.combined(kind)}
        fixture.fresh(cls.base)
        (cls.tree / '.git/orbit').mkdir()
        (cls.tree / '.git/orbit/source-fixtures.json').write_text(json.dumps(scenarios) + '\n')

    def tearDown(self):
        self.scratch.cleanup()

    def commit(self, message):
        git(self.tree, 'add', '-A')
        git(self.tree, 'commit', '--quiet', '-m', message)
        return git(self.tree, 'rev-parse', 'HEAD')

    def fresh(self, commit):
        git(self.tree, 'checkout', '--quiet', '--detach', '--force', commit)
        git(self.tree, 'clean', '-fd', '--', 'docs/decisions')

    def mark_derived(self, label, paths):
        # These files are outputs, not maintained merge inputs. Invalid marker
        # bytes force a content conflict and cannot pass any freshness gate.
        for path in paths:
            (self.tree / path).write_bytes(f'not generated: {label}\n'.encode())

    def check(self, gate):
        tree = self.tree
        # Each test runs one independent, cold freshness gate. ParaTest owns
        # CPU allocation, not a nested pool of generator subprocesses.
        before = {path: (tree / path).read_bytes() for path in TOOL.ARTIFACTS}
        env = dict(os.environ, ORBIT_DOCS_ROOT=str(tree / 'docs'),
                   APP_CONFIG_CACHE=str(tree / 'private-config.php'))
        schema = Path(self.scratch.name) / 'fresh-schema.d.ts'
        commands = {
            'openapi': ['bin/docs-openapi', '--check'],
            'mcp': ['bin/mcp-tools', '--check'],
            'lint': ['php', 'apps/docs/artisan', 'orbit:docs-lint', '--strict', '--format=text'],
            'navigation': ['bin/docs-nav'],
            'web_types': ['bun', '--bun', str(ROOT / 'apps/web/node_modules/.bin/openapi-typescript'),
                          str(tree / 'docs/openapi.json'), '-o', str(schema)],
        }
        command = commands[gate]
        process = subprocess.run(command, cwd=tree, env=env, text=True, capture_output=True)
        self.assertEqual(process.returncode, 0, f'{command}:\n{process.stdout}{process.stderr}')
        if gate == 'web_types':
            self.assertEqual(before['apps/web/src/api/schema.d.ts'], schema.read_bytes(),
                             'web types differ from a fresh build')
        for path, contents in before.items():
            self.assertTrue(contents == (tree / path).read_bytes(), f'{path} changed during freshness checks')

    def combined(self, kind):
        # Apply both edits directly, without a merge driver, then install the
        # recorded real generator output. Both merge orders must equal this
        # complete tree. Separate read-only tests rebuild every recorded byte.
        self.fresh(self.base)
        self.changes(kind, branches=False)
        for path in TOOL.ARTIFACTS:
            shutil.copy2(FIXTURES / kind / path, self.tree / path)
        git(self.tree, 'add', '-A')
        return git(self.tree, 'write-tree')

    def changes(self, kind, branches=True):
        return (self.adr_additions if kind == 'adr' else self.endpoint_changes)(branches)

    def merge_order(self, kind, reverse=False, cache_lookups=False):
        first, second = self.scenarios[kind]['commits']
        if reverse:
            first, second = second, first
        expected_tree = self.scenarios[kind]['tree']
        self.fresh(first)
        vanilla = subprocess.run(['git', '-c', 'merge.orbit-generated.driver=git merge-file %A %O %B',
                                  'merge-tree', '--write-tree', first, second],
                                 cwd=self.tree, text=True, capture_output=True)
        self.assertEqual(vanilla.returncode, 1, 'fixture must reproduce a real generated-file conflict')
        conflicts = TOOL.ARTIFACTS if kind == 'adr' else [TOOL.ARTIFACTS[i] for i in (1, 3, 4, 5)]
        for path in conflicts:
            self.assertIn(f'CONFLICT (content): Merge conflict in {path}', vanilla.stdout)
        cached = {}
        if cache_lookups:
            from unittest import mock

            # Prime one cold build; Git must read the same bytes from separate
            # driver processes. The spy delegates to the real generator.
            previous = Path.cwd()
            try:
                os.chdir(self.tree)
                with mock.patch.object(TOOL, 'regenerate', wraps=TOOL.regenerate) as build:
                    for path in TOOL.ARTIFACTS:
                        output = Path(self.scratch.name) / 'artifact'
                        TOOL.merge(['unused', str(output), 'unused', path, first, second])
                        cached[path] = output.read_bytes()
                    self.assertEqual(build.call_count, 1)
            finally:
                os.chdir(previous)
        result = subprocess.run(['git', 'merge', '--quiet', '--no-edit', '--no-ff', second],
                                cwd=self.tree, text=True, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(git(self.tree, 'diff', '--name-only', '--diff-filter=U'), '')
        for path in TOOL.ARTIFACTS:
            contents = (self.tree / path).read_bytes()
            self.assertEqual(contents, (FIXTURES / kind / path).read_bytes(), path)
            if cache_lookups:
                self.assertEqual(contents, cached[path], path)
        self.assertEqual(git(self.tree, 'rev-parse', 'HEAD^{tree}'), expected_tree,
                         'merge order changed maintained sources or generated output')

    def preview_order(self, kind, reverse=False):
        first, second = self.scenarios[kind]['commits']
        if reverse:
            first, second = second, first
        result = subprocess.run([str(self.tree / 'bin/docs-merge-check'), '--base', first, '--head', second],
                                cwd=self.tree, text=True, capture_output=True)
        self.assertEqual(result.returncode, 2, result.stdout + result.stderr)
        self.assertIn('merge_conflict:', result.stderr)
        self.assertIn('local_driver_clean:', result.stderr)

    def adr_additions(self, branches=True):
        commits = []
        for number in ('0998', '0999'):
            if branches:
                self.fresh(self.base)
            slug = f'{number}-merge-fixture'
            (self.tree / f'docs/decisions/{slug}.md').write_text(
                f'---\ntitle: "ADR {number}: Merge fixture"\n'
                f'description: "An independent ADR addition for merge verification."\n---\n\n'
                f'# ADR {number}: Merge fixture\n\n## Status\n\nIn progress.\n\n'
                'Principle: [Agents operate, humans steer](/mission#principles).\n\n'
                '## Context\n\nTwo contributors add decisions independently.\n\n'
                '## Decision\n\nKeep independent documentation changes.\n\n'
                '## Rejected alternatives\n\nDiscard the other decision.\n\n'
                '## Consequences\n\nBoth decisions remain readable.\n\n'
                '## Affects\n\n- Components: apps/docs\n'
                '- ADRs: None.\n- Detail: The contributor guide.\n'
                '- Verify: The generated merge regression test.\n')
            config_path = self.tree / 'docs/docs.json'
            config = json.loads(config_path.read_text())
            groups = [group for tab in config['navigation']['tabs'] for group in tab.get('groups', [])]
            next(group for group in groups if group['group'] == 'Records')['pages'].append(f'decisions/{slug}')
            config_path.write_text(json.dumps(config, indent=2, ensure_ascii=False) + '\n')
            overview = self.tree / 'docs/decisions/overview.mdx'
            text = overview.read_text()
            text = text.replace('\n## Retired decisions',
                                f'| [{number}](/decisions/{slug}) | Merge fixture | In progress |\n\n## Retired decisions')
            # Append to the table, not to its following blank paragraph.
            text = text.replace('\n\n| [' + number, '\n| [' + number)
            overview.write_text(text)
            self.mark_derived(number, [TOOL.ARTIFACTS[0], *TOOL.ARTIFACTS[3:]])
            if branches:
                commits.append(self.commit(f'add ADR {number}'))
        return commits

    def test_independent_adr_additions(self):
        self.merge_order('adr')

    def test_reversed_adr_additions(self):
        self.merge_order('adr', reverse=True)

    def test_independent_adr_previews(self):
        self.preview_order('adr')

    def test_reversed_adr_previews(self):
        self.preview_order('adr', reverse=True)

    def endpoint_changes(self, branches=True):
        commits = []
        # Separate route sources and maintained nav entries must survive;
        # conflicting derived markers must be replaced, never text-unioned.
        for name, file, prefix in (('alpha', 'apps/gateway/routes/api.php', 'v1'),
                                   ('beta', 'apps/gateway/extensions/conn/routes/api.php', 'api/v1')):
            if branches:
                self.fresh(self.base)
            path = self.tree / file
            path.write_text(path.read_text() + '\n'
                            f"Route::get('{prefix}/merge-fixture-{name}', "
                            "[\\App\\Http\\Controllers\\Api\\ActivitiesController::class, 'index'])"
                            f"->name('activity:merge-fixture-{name}');\n")
            endpoint = f'/api/v1/merge-fixture-{name}'
            config_path = self.tree / 'docs/docs.json'
            config = json.loads(config_path.read_text())
            groups = [group for tab in config['navigation']['tabs'] for group in tab.get('groups', [])]
            next(group for group in groups if 'GET /api/v1/activities' in group.get('pages', []))['pages'].append(
                f'GET {endpoint}')
            config_path.write_text(json.dumps(config, indent=2) + '\n')
            self.mark_derived(name, TOOL.ARTIFACTS[3:])
            if branches:
                commits.append(self.commit(f'add endpoint {name}'))
        return commits

    def test_independent_endpoint_changes(self):
        self.merge_order('endpoint', cache_lookups=True)

    def test_reversed_endpoint_changes(self):
        self.merge_order('endpoint', reverse=True)

    def test_independent_endpoint_previews(self):
        self.preview_order('endpoint')

    def test_reversed_endpoint_previews(self):
        self.preview_order('endpoint', reverse=True)

    def test_runtime_only_autoloaders(self):
        for project, provider in (('apps/docs', 'AppServiceProvider'),
                                  ('apps/gateway', 'ExtensionServiceProvider')):
            with self.subTest(project=project):
                result = json.loads(run(self.tree / project, 'php', '-r', r'''
$loader = require 'vendor/autoload.php';
echo json_encode([
    'laravel' => class_exists(Illuminate\Foundation\Application::class),
    'pest' => class_exists(Pest\TestSuite::class),
    'phpunit' => class_exists(PHPUnit\Framework\TestCase::class),
    'tests' => isset($loader->getPrefixesPsr4()['Tests\\']),
    'provider' => (new ReflectionClass($argv[1]))->getFileName(),
], JSON_THROW_ON_ERROR);
''', 'App\\Providers\\' + provider))
                self.assertEqual(result, {
                    'laravel': True, 'pest': False, 'phpunit': False, 'tests': False,
                    'provider': str(self.tree / project / f'app/Providers/{provider}.php'),
                })

    def test_changed_merged_locks_refuse_old_dependencies(self):
        from unittest import mock

        for lock in ('apps/docs/composer.lock', 'apps/gateway/composer.lock', 'apps/web/bun.lock'):
            with self.subTest(lock=lock):
                self.fresh(self.base)
                file = self.tree / lock
                file.write_text(file.read_text() + '\n')
                changed = self.commit(f'change {lock}')
                self.fresh(self.base)
                previous = Path.cwd()
                try:
                    os.chdir(self.tree)
                    with mock.patch.object(TOOL, 'regenerate') as build:
                        for current, other in ((self.base, changed), (changed, self.base)):
                            # Also refuse when the checkout lock matches the
                            # merge: vendor may still predate that lock change.
                            self.fresh(current)
                            for _ in range(2):  # failures must not publish a cache
                                with self.assertRaisesRegex(ValueError, f'dependency_lock_changed: {lock}'):
                                    TOOL.merge(['unused', str(Path(self.scratch.name) / 'artifact'),
                                                'unused', TOOL.ARTIFACTS[0], current, other])
                        build.assert_not_called()
                finally:
                    os.chdir(previous)

    def test_maintained_conflicts_are_not_unioned(self):
        for path in TOOL.ARTIFACTS:
            if path not in TOOL.MAINTAINED:
                with self.assertRaisesRegex(ValueError, 'unexpected maintained path:'):
                    TOOL.sources(['unused', 'unused', 'unused', path])
        with self.assertRaises(ValueError):
            TOOL.merge_value({'title': 'base'}, {'title': 'one'}, {'title': 'two'})
        with self.assertRaises(ValueError):
            TOOL.merge_value(['a', 'b'], ['b', 'a', 'c'], ['a', 'b', 'd'])
        self.assertEqual(TOOL.merge_value({'a': 1}, {}, {'a': 1}), {})
        self.assertEqual(TOOL.merge_value(['a', 'b'], ['b', 'z'], ['a', 'b', 'y']), ['b', 'y', 'z'])
        base = '| Record | Decision | Status |\n| --- | --- | --- |\n'
        with self.assertRaises(ValueError):
            TOOL.overview(base, base + '| [0999](/decisions/0999-one) | One | In progress |\n',
                          base + '| [0999](/decisions/0999-two) | Two | In progress |\n')
        with self.assertRaises(ValueError):
            TOOL.merge_value({'a': 1}, {}, {'a': 2})

    def test_generators_run_in_caller_sequence(self):
        import threading
        from unittest import mock

        caller = threading.get_ident()
        commands = []

        def record(*arguments, cwd, env):
            self.assertEqual(threading.get_ident(), caller, 'nested generator pool bypassed the caller')
            self.assertEqual(cwd, self.tree)
            self.assertEqual(env['APP_CONFIG_CACHE'], str(self.tree / 'private-config.php'))
            self.assertEqual(env['ORBIT_DOCS_ROOT'], str(self.tree / 'docs'))
            commands.append(arguments)

        with mock.patch.object(TOOL, 'run', side_effect=record):
            TOOL.regenerate(self.tree)
        self.assertEqual(commands, [
            ('php', str(self.tree / 'apps/docs/artisan'), 'orbit:docs-index'),
            (str(self.tree / 'bin/docs-openapi'),),
            (str(self.tree / 'bin/mcp-tools'),),
            ('bun', '--bun', str(self.tree / 'apps/web/node_modules/.bin/openapi-typescript'),
             str(self.tree / 'docs/openapi.json'), '-o', str(self.tree / 'apps/web/src/api/schema.d.ts')),
        ])

    def test_source_conflict_stays_unresolved(self):
        commits = []
        for value in ('one', 'two'):
            self.fresh(self.base)
            path = self.tree / 'apps/cli/app/Commands/Activities/ListActivitiesCommand.php'
            path.write_text(path.read_text().replace('List recent', f'List {value} recent'))
            self.mark_derived(value, TOOL.ARTIFACTS[3:])
            commits.append(self.commit(f'conflicting source {value}'))
        self.fresh(commits[0])
        with subprocess.Popen(
                [str(ROOT / 'bin/docs-merge-check'), '--base', commits[0], '--head', commits[1]],
                cwd=self.tree, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE) as preview:
            result = subprocess.run(['git', 'merge', '--no-edit', commits[1]], cwd=self.tree,
                                    text=True, capture_output=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('apps/cli/app/Commands/Activities/ListActivitiesCommand.php',
                          git(self.tree, 'diff', '--name-only', '--diff-filter=U'))
            stdout, stderr = preview.communicate()
            self.assertEqual(preview.returncode, 2, stdout + stderr)
            self.assertIn('merge_conflict:', stderr)
            self.assertIn('local_driver_failed:', stderr)
        git(self.tree, 'merge', '--abort')


def freshness_case(kind, gate):
    def test(self):
        if kind != 'base':
            self.combined(kind)
        self.check(gate)
    return test


for kind in ('base', 'adr', 'endpoint'):
    for gate in ('openapi', 'mcp', 'lint', 'navigation', 'web_types'):
        setattr(GeneratedMergeTest, f'test_{kind}_{gate}_freshness', freshness_case(kind, gate))


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--prepare-fixture', type=Path)
    mode.add_argument('--fixture', type=Path)
    arguments, tests = parser.parse_known_args()
    if arguments.prepare_fixture is not None:
        if tests:
            parser.error('fixture preparation does not accept test selections')
        GeneratedMergeTest.prepare_fixture(arguments.prepare_fixture)
    else:
        GeneratedMergeTest.fixture = arguments.fixture
        unittest.main(argv=[__file__, *tests])
