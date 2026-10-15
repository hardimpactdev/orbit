"""Real generators and real two-parent merges; no mocked Git or output snapshots."""
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
                           'gc.auto': '0'}.items():
            git(cls.tree, 'config', key, value)
        # Include the candidate driver even when the suite runs before commit.
        for path in ('.gitattributes', 'bin/generated-merge'):
            shutil.copy2(ROOT / path, cls.tree / path)
        TOOL.dependencies(cls.tree, ROOT)
        TOOL.install(cls.tree)
        git(cls.tree, 'add', '.gitattributes', 'bin/generated-merge')
        git(cls.tree, 'commit', '--quiet', '--allow-empty', '-m', 'fixture baseline')
        cls.base = git(cls.tree, 'rev-parse', 'HEAD')

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

    def check(self):
        tree = self.tree
        # Compare all six artifacts with a fresh build, not just JSON validity.
        before = {path: (tree / path).read_bytes() for path in TOOL.ARTIFACTS}
        TOOL.regenerate(tree)
        for path, contents in before.items():
            self.assertTrue(contents == (tree / path).read_bytes(), f'{path} differs from a fresh build')
        run(tree, 'bin/docs-openapi', '--check')
        run(tree, 'bin/mcp-tools', '--check')
        env = dict(os.environ, ORBIT_DOCS_ROOT=str(tree / 'docs'),
                   APP_CONFIG_CACHE=str(tree / 'private-config.php'))
        result = subprocess.run(['php', 'apps/docs/artisan', 'orbit:docs-lint', '--strict', '--format=text'],
                                cwd=tree, env=env, text=True, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        run(tree, 'bin/docs-nav')

    def merge_both_orders(self, first, second, conflicts):
        vanilla = subprocess.run(['git', '-c', 'merge.orbit-generated.driver=git merge-file %A %O %B',
                                  'merge-tree', '--write-tree', first, second],
                                 cwd=self.tree, text=True, capture_output=True)
        self.assertEqual(vanilla.returncode, 1, 'fixture must reproduce a real generated-file conflict')
        for path in conflicts:
            self.assertIn(f'CONFLICT (content): Merge conflict in {path}', vanilla.stdout)
        results = []
        for current, other in ((first, second), (second, first)):
            self.fresh(current)
            run(self.tree, str(ROOT / 'bin/docs-merge-check'), '--base', current, '--head', other)
            git(self.tree, 'merge', '--quiet', '--no-edit', '--no-ff', other)
            self.assertEqual(git(self.tree, 'diff', '--name-only', '--diff-filter=U'), '')
            self.check()
            results.append({path: (self.tree / path).read_bytes() for path in TOOL.ARTIFACTS})
        self.assertTrue(results[0] == results[1], 'merge order changed generated output')

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
            TOOL.regenerate(self.tree)
            commits.append(self.commit(f'add ADR {number}'))
        self.merge_both_orders(*commits, conflicts=TOOL.ARTIFACTS[:3])

    def test_independent_endpoint_changes(self):
        commits = []
        # Separate route sources, adjacent new paths: ordinary text merging
        # conflicts in the OpenAPI, API nav, web types, and MCP manifest.
        for name, file, prefix in (('alpha', 'apps/gateway/routes/api.php', 'v1'),
                                   ('beta', 'apps/gateway/extensions/conn/routes/api.php', 'api/v1')):
            self.fresh(self.base)
            path = self.tree / file
            path.write_text(path.read_text() + '\n'
                            f"Route::get('{prefix}/merge-fixture-{name}', "
                            "[\\App\\Http\\Controllers\\Api\\ActivitiesController::class, 'index'])"
                            f"->name('activity:merge-fixture-{name}');\n")
            TOOL.regenerate(self.tree)
            spec = json.loads((self.tree / 'docs/openapi.json').read_text())
            self.assertTrue(f'/api/v1/merge-fixture-{name}' in spec['paths'], 'fixture endpoint is missing')
            commits.append(self.commit(f'add endpoint {name}'))
        self.merge_both_orders(*commits, conflicts=[TOOL.ARTIFACTS[index] for index in (1, 3, 4, 5)])

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
            TOOL.regenerate(self.tree)
            commits.append(self.commit(f'conflicting source {value}'))
        self.fresh(commits[0])
        result = subprocess.run(['git', 'merge', '--no-edit', commits[1]], cwd=self.tree,
                                text=True, capture_output=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('apps/cli/app/Commands/Activities/ListActivitiesCommand.php',
                      git(self.tree, 'diff', '--name-only', '--diff-filter=U'))
        git(self.tree, 'merge', '--abort')


if __name__ == '__main__':
    unittest.main()
