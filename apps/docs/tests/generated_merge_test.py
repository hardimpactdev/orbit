"""Marker branch artifacts; real generators, Git drivers and two-parent merges."""
from contextlib import ExitStack
import importlib.machinery
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[3]
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
    @classmethod
    def setUpClass(cls):
        cls.scratch = tempfile.TemporaryDirectory(prefix='orbit-merge-test-')
        cls.tree = Path(cls.scratch.name) / 'repo'
        git(ROOT, 'clone', '--quiet', '--shared', str(ROOT), str(cls.tree))
        for key, value in {'user.name': 'Merge test', 'user.email': 'merge@example.test',
                           'commit.gpgsign': 'false', 'core.hooksPath': '/dev/null',
                           'gc.auto': '0', 'maintenance.auto': 'false'}.items():
            git(cls.tree, 'config', key, value)
        # Include the candidate driver even when the suite runs before commit.
        for path in ('.gitattributes', 'bin/generated-merge'):
            shutil.copy2(ROOT / path, cls.tree / path)
        TOOL.dependencies(cls.tree, ROOT)
        TOOL.install(cls.tree)
        # Unrelated suites and apps are not generator inputs. Do not copy their
        # thousands of files into every private merge checkout.
        for path in ('apps/gateway/tests', 'apps/cli/tests', 'apps/docs/tests',
                     'apps/e2e', 'apps/desktop', 'apps/agent', 'apps/pi-server',
                     'apps/web/tests', 'apps/web/fixtures', 'packages/php-sdk/tests',
                     'packages/php-sdk/fixtures', '.agents'):
            shutil.rmtree(cls.tree / path)
        git(cls.tree, 'add', '-u')
        # Keep the real Gateway, CLI, SDK and all generators. The documentation
        # corpus only needs the core pages and ADR table used by these merges;
        # linting unrelated product guides belongs to `composer docs-lint`.
        api_groups = [group for tab in json.loads((cls.tree / 'docs/docs.json').read_text())['navigation']['tabs']
                      if tab['tab'] == 'API' for group in tab['groups'] if 'openapi' in group]
        openapi = (cls.tree / 'docs/openapi.json').read_bytes()
        shutil.rmtree(cls.tree / 'docs')
        for folder in ('decisions', 'domains', 'solutions', 'generated'):
            (cls.tree / 'docs' / folder).mkdir(parents=True)
        for page in ('README', 'mission', 'architecture', 'tech-stack', 'concepts',
                     'decisions/README', 'domains/README', 'solutions/README'):
            (cls.tree / f'docs/{page}.md').write_text('# Documentation\n\nOrbit checks documentation.\n')
        (cls.tree / 'docs/mission.md').write_text(
            '# Mission\n\nOrbit checks documentation.\n\n## Principles\n\nAgents operate, humans steer.\n')
        (cls.tree / 'docs/decisions/overview.mdx').write_text(
            '# Decisions\n\n## Records\n\n| Record | Decision | Status |\n| --- | --- | --- |\n\n'
            '## Retired decisions\n\n| Record | Decision | Now in |\n| --- | --- | --- |\n')
        config = {'navigation': {'tabs': [
            {'tab': 'Guides', 'groups': [
                {'group': 'Overview', 'pages': ['mission', 'architecture', 'tech-stack', 'concepts']},
                {'group': 'Records', 'pages': ['decisions/overview']},
            ]},
            {'tab': 'API', 'groups': api_groups},
        ]}, 'redirects': []}
        (cls.tree / 'docs/docs.json').write_text(json.dumps(config, indent=2) + '\n')
        (cls.tree / 'docs/openapi.json').write_bytes(openapi)
        (cls.tree / 'docs/generated/context.json').write_text(
            json.dumps({'schema_version': 1, 'documents': []}, indent=4) + '\n')
        for config in ('adr-legacy-allowlist', 'adr-retired-slugs', 'docs-covers-ratchet'):
            (cls.tree / f'apps/docs/config/{config}.php').write_text('<?php return [];\n')
        env = dict(os.environ, APP_CONFIG_CACHE=str(cls.tree / 'private-config.php'),
                   ORBIT_DOCS_ROOT=str(cls.tree / 'docs'))
        subprocess.run(['php', 'apps/docs/artisan', 'orbit:docs-index'], cwd=cls.tree,
                       env=env, check=True, capture_output=True)
        git(cls.tree, 'add', '-A', 'docs', 'apps/docs/config', '.gitattributes', 'bin/generated-merge')
        git(cls.tree, 'commit', '--quiet', '--allow-empty', '-m', 'fixture baseline')
        cls.base = git(cls.tree, 'rev-parse', 'HEAD')
        git(cls.tree, 'update-ref', 'refs/remotes/origin/main', cls.base)

    @classmethod
    def tearDownClass(cls):
        cls.scratch.cleanup()

    def commit(self, message):
        git(self.tree, 'add', '-u')
        git(self.tree, 'add', 'docs/decisions')
        git(self.tree, 'commit', '--quiet', '-m', message)
        return git(self.tree, 'rev-parse', 'HEAD')

    def fresh(self, commit):
        git(self.tree, 'reset', '--hard', commit)
        git(self.tree, 'checkout', '--quiet', '--detach', commit)
        git(self.tree, 'clean', '-fd', '--', 'docs/decisions')

    def mark_derived(self, label, paths):
        # These files are outputs, not maintained merge inputs. Invalid marker
        # bytes force a content conflict and cannot pass any freshness gate.
        for path in paths:
            (self.tree / path).write_bytes(f'not generated: {label}\n'.encode())

    def check(self):
        tree = self.tree
        # Read-only gates independently rebuild their expected output. None
        # consumes another gate's writes, so check the result concurrently.
        before = {path: (tree / path).read_bytes() for path in TOOL.ARTIFACTS}
        env = dict(os.environ, ORBIT_DOCS_ROOT=str(tree / 'docs'),
                   APP_CONFIG_CACHE=str(tree / 'private-config.php'))
        schema = Path(self.scratch.name) / 'fresh-schema.d.ts'
        commands = [
            ['bin/docs-openapi', '--check'],
            ['bin/mcp-tools', '--check'],
            ['php', 'apps/docs/artisan', 'orbit:docs-lint', '--strict', '--format=text'],
            ['bin/docs-nav'],
            [str(tree / 'apps/web/node_modules/.bin/openapi-typescript'),
             str(tree / 'docs/openapi.json'), '-o', str(schema)],
        ]
        with ExitStack() as processes:
            checks = [processes.enter_context(subprocess.Popen(
                command, cwd=tree, env=env, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE))
                for command in commands]
            for command, process in zip(commands, checks):
                stdout, stderr = process.communicate()
                self.assertEqual(process.returncode, 0, f'{command}:\n{stdout}{stderr}')
        self.assertEqual(before['apps/web/src/api/schema.d.ts'], schema.read_bytes(),
                         'web types differ from a fresh build')
        for path, contents in before.items():
            self.assertTrue(contents == (tree / path).read_bytes(), f'{path} changed during freshness checks')

    def merge_both_orders(self, first, second, conflicts, cache_lookups=False):
        vanilla = subprocess.run(['git', '-c', 'merge.orbit-generated.driver=git merge-file %A %O %B',
                                  'merge-tree', '--write-tree', first, second],
                                 cwd=self.tree, text=True, capture_output=True)
        self.assertEqual(vanilla.returncode, 1, 'fixture must reproduce a real generated-file conflict')
        for path in conflicts:
            self.assertIn(f'CONFLICT (content): Merge conflict in {path}', vanilla.stdout)
        results = []
        trees = []
        orders = ((first, second), (second, first))
        # Every command uses immutable SHAs and its own checkout. These are
        # independent real integrations, not sequential setup for one another.
        with tempfile.TemporaryDirectory(prefix='reverse-', dir=self.scratch.name) as directory, ExitStack() as processes:
            reverse = Path(directory) / 'repo'
            git(self.tree, 'clone', '--quiet', '--shared', '--no-checkout', str(self.tree), str(reverse))
            for key in ('user.name', 'user.email', 'commit.gpgsign', 'core.hooksPath', 'gc.auto', 'maintenance.auto'):
                git(reverse, 'config', key, git(self.tree, 'config', key))
            TOOL.install(reverse)
            git(reverse, 'checkout', '--quiet', '--detach', second)
            self.fresh(first)
            previews = [processes.enter_context(subprocess.Popen(
                [str(ROOT / 'bin/docs-merge-check'), '--base', current, '--head', other],
                cwd=self.tree, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE))
                for current, other in orders]
            env = dict(os.environ, ORBIT_GENERATED_MERGE_DEPENDENCIES=str(ROOT))
            backward = processes.enter_context(subprocess.Popen(
                ['git', 'merge', '--quiet', '--no-edit', '--no-ff', first], cwd=reverse,
                env=env, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE))
            cached = {}
            if cache_lookups:
                # Prime the real driver's cold cache once, then let the actual
                # Git merge exercise its process-independent cache reads.
                shutil.rmtree(self.tree / '.git/orbit/generated-merge', ignore_errors=True)
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
            forward = processes.enter_context(subprocess.Popen(
                ['git', 'merge', '--quiet', '--no-edit', '--no-ff', second], cwd=self.tree,
                text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE))
            for tree, merge, preview in zip((self.tree, reverse), (forward, backward), previews):
                stdout, stderr = merge.communicate()
                self.assertEqual(merge.returncode, 0, stdout + stderr)
                self.assertEqual(git(tree, 'diff', '--name-only', '--diff-filter=U'), '')
                stdout, stderr = preview.communicate()
                self.assertEqual(preview.returncode, 2, stdout + stderr)
                self.assertIn('merge_conflict:', stderr)
                self.assertIn('local_driver_clean:', stderr)
                results.append({path: (tree / path).read_bytes() for path in TOOL.ARTIFACTS})
                trees.append(git(tree, 'rev-parse', 'HEAD^{tree}'))
            for path, contents in cached.items():
                self.assertEqual(contents, (self.tree / path).read_bytes())
        self.assertTrue(results[0] == results[1], 'merge order changed generated output')
        self.assertEqual(trees[0], trees[1], 'merge order changed maintained sources')
        # Identical whole trees have identical lint and generation inputs.
        self.check()

    def test_independent_adr_additions(self):
        commits = []
        for number in ('0998', '0999'):
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
            self.mark_derived(number, [TOOL.ARTIFACTS[0]])
            commits.append(self.commit(f'add ADR {number}'))
        self.merge_both_orders(*commits, conflicts=TOOL.ARTIFACTS[:3])

    def test_independent_endpoint_changes(self):
        commits = []
        # Separate route sources and maintained nav entries must survive;
        # conflicting derived markers must be replaced, never text-unioned.
        for name, file, prefix in (('alpha', 'apps/gateway/routes/api.php', 'v1'),
                                   ('beta', 'apps/gateway/extensions/conn/routes/api.php', 'api/v1')):
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
            commits.append(self.commit(f'add endpoint {name}'))
        self.merge_both_orders(*commits, conflicts=[TOOL.ARTIFACTS[index] for index in (1, 3, 4, 5)],
                               cache_lookups=True)

    def test_changed_merged_locks_refuse_old_dependencies(self):
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


if __name__ == '__main__':
    unittest.main()
