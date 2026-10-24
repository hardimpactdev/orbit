"""Path selection and real merge-base diffs for PR and full CI runs."""
import importlib.machinery
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
loader = importlib.machinery.SourceFileLoader('ci_changes', str(ROOT / 'bin/ci-changes'))
spec = importlib.util.spec_from_loader(loader.name, loader)
ci = importlib.util.module_from_spec(spec)
loader.exec_module(ci)


def expand_braces(pattern):
    """Expand the same comma-separated brace alternatives as DocsImpact."""
    match = re.search(r'\{([^{}]+)\}', pattern)
    if not match:
        return [pattern]
    return [expanded for choice in match[1].split(',')
            for expanded in expand_braces(pattern[:match.start()] + choice + pattern[match.end():])]


class SelectionTest(unittest.TestCase):
    def test_extension_native_changes_build_the_agent(self):
        for path in ['apps/gateway/extensions/documents/agent/src/lib.rs',
                     'apps/gateway/extensions/documents/agent/Cargo.toml']:
            self.assertTrue(ci.selection([path])['jobs']['agent'])
            self.assertTrue(ci.selection([path])['jobs']['agent-macos'])

    def test_docs_only_starts_only_docs(self):
        result = ci.selection(['docs/reference/implementation-loop.md'])
        self.assertEqual(result['matrix']['project'], [{'name': 'Docs', 'directory': 'apps/docs'}])
        self.assertEqual([job for job, selected in result['jobs'].items() if selected], ['project'])

    def test_sdk_fixtures_start_sdk_cli_gateway_and_their_real_consumers(self):
        result = ci.selection(['packages/php-sdk/fixtures/nodes/show.json'])
        self.assertEqual([p['name'] for p in result['matrix']['project']], ['CLI', 'Docs', 'Gateway', 'E2E', 'PHP SDK'])
        self.assertEqual([job for job, selected in result['jobs'].items() if selected],
                         ['project', 'gateway-shards', 'privileged', 'remote-programs', 'api-reference', 'web'])

    def test_sdk_sources_also_check_the_generators_that_read_them(self):
        result = ci.selection(['packages/php-sdk/src/Requests/Nodes/GetNodeRequest.php'])
        self.assertEqual([p['name'] for p in result['matrix']['project']],
                         ['CLI', 'Docs', 'Gateway', 'E2E', 'PHP SDK'])
        self.assertTrue(result['jobs']['api-reference'])

    def test_cross_project_contract_readers_are_selected(self):
        cases = {
            # GuestScriptContractTest, real bootstrap/gate inputs and delivery line tests.
            'apps/cli/app/Commands/NodeListCommand.php': ('e2e', 'docs', 'api-reference'),
            'apps/gateway/tests/Support/SubprocessFixture.php': ('e2e',),
            'apps/gateway/routes/api.php': ('cli',),
            # The Gateway's fake runner reads the contract, and its privileged tests run the built agent.
            'apps/agent/contract/commands.json': ('gateway',),
            'apps/docs/app/Console/Commands/DocsLintCommand.php': ('e2e',),
            'packages/php-sdk/composer.json': ('e2e', 'docs', 'api-reference'),
            'docs/openapi.json': ('e2e',),
            'docs/docs.json': ('e2e',),
            'docs/reference/delivery-line.md': ('e2e',),
            # Gateway parity/seed tests read these E2E inputs.
            'apps/e2e/resources/topology-nodes.json': ('gateway',),
            'apps/e2e/app/E2E/Value/TopologyRecipe.php': ('gateway',),
            'apps/e2e/app/E2E/IncusHost.php': ('gateway',),
            # CLI's command and guidance contract tests read maintained guides.
            'docs/cli/node.mdx': ('cli',),
            'docs/reference/cli-command-vocabulary.md': ('cli',),
            'docs/reference/cli-ux.md': ('cli',),
            # Whole-repository tooling/vocabulary checks and fixture validation.
            'apps/e2e/composer.json': ('docs', 'api-reference'),
            'apps/e2e/pint.json': ('docs', 'api-reference'),
            'apps/e2e/phpstan.neon': ('docs', 'api-reference'),
            'apps/e2e/rector.php': ('docs', 'api-reference'),
            'apps/e2e/phpunit.xml': ('docs', 'api-reference'),
            'apps/pi-server/src/main.ts': ('docs', 'api-reference'),
            'apps/web/src/main.tsx': ('docs', 'api-reference'),
            'apps/web/feature-map.json': ('docs', 'api-reference'),
            'apps/web/fixtures/fleet/nodes.json': ('docs',),
            'packages/php-sdk/fixtures/project-documents/list/default.json': ('docs', 'web'),
        }
        for path, consumers in cases.items():
            with self.subTest(path=path):
                result = ci.selection([path])
                projects = {key for key, value in ci.PROJECTS.items() if value in result['matrix']['project']}
                projects.update(job for job, selected in result['jobs'].items() if selected)
                self.assertTrue(set(consumers) <= projects, f'{path}: missing {set(consumers) - projects}')

    def test_documented_coverage_roots_always_start_docs(self):
        patterns = 0
        for section in ('reference', 'cli', 'solutions'):
            for page in sorted((ROOT / 'docs' / section).glob('*')):
                if page.suffix not in ('.md', '.mdx'):
                    continue
                frontmatter = re.match(r'\A---\r?\n(.*?)\r?\n---(?:\r?\n|$)', page.read_text(), re.S)
                if not frontmatter or not re.search(r'^covers\s*:', frontmatter[1], re.M):
                    continue
                # DocsImpact accepts an indented list of string globs, not arbitrary YAML.
                block = re.search(r'^covers\s*:\s*\n((?:[ \t]+[^\n]*\n)*)', frontmatter[1] + '\n', re.M)
                self.assertIsNotNone(block, str(page))
                self.assertTrue(block[1].strip(), f'{page}: covers: has no globs')
                for line in block[1].strip().splitlines():
                    match = re.fullmatch(r'\s*-\s*[\'\"]?([^\'\"]+?)[\'\"]?\s*', line)
                    self.assertIsNotNone(match, f'{page}: {line}')
                    pattern = match[1].strip().replace('\\', '/').strip('/')
                    for alternative in expand_braces(pattern):
                        parts = alternative.split('/')
                        # Probe each real project root, including brace and wildcard alternatives.
                        root = '/'.join(parts[:2] if parts[0] in ('apps', 'packages') else parts[:1])
                        roots = sorted(ROOT.glob(root))
                        self.assertTrue(roots, f'{page}: no roots match {alternative}')
                        for directory in roots:
                            probe = directory.relative_to(ROOT).as_posix()
                            if directory.is_dir():
                                probe += '/__ci_docs_coverage_probe__'
                                self.assertTrue(any(ci.fnmatchcase(probe, rule) for rule, _ in ci.RULES),
                                                f'{page}: {probe} has no explicit rule')
                            with self.subTest(page=str(page.relative_to(ROOT)), pattern=pattern, path=probe):
                                selected = ci.selection([probe])
                                self.assertIn({'name': 'Docs', 'directory': 'apps/docs'}, selected['matrix']['project'])
                    patterns += 1
        self.assertGreater(patterns, 0, 'No documented covers: globs were checked')

    def test_desktop_changes_start_desktop_and_docs(self):
        result = ci.selection(['apps/desktop/src-tauri/src/lib.rs'])
        self.assertEqual(result['matrix']['project'], [{'name': 'Docs', 'directory': 'apps/docs'}])
        self.assertEqual([job for job, selected in result['jobs'].items() if selected], ['project', 'desktop'])

    def test_shared_and_unknown_paths_start_everything(self):
        for path in ['.github/workflows/ci.yml', '.github/actions/php/action.yml', 'composer.json',
                     'composer.lock', '.node-version', '.editorconfig', 'bin/test', 'tools/phpstan/rule.php',
                     'apps/new-project/src/main.ts']:
            with self.subTest(path=path):
                self.assertTrue(all(ci.selection([path])['jobs'].values()))

    def test_api_docs_and_contract_consumers(self):
        docs = ci.selection(['docs/api/overview.mdx'])
        self.assertTrue(docs['jobs']['api-reference'])
        self.assertFalse(docs['jobs']['web'])
        api = ci.selection(['docs/openapi.json'])
        self.assertEqual([p['name'] for p in api['matrix']['project']], ['CLI', 'Docs', 'Gateway', 'E2E', 'PHP SDK'])
        self.assertTrue(api['jobs']['web'])
        self.assertTrue(api['jobs']['api-reference'])

    def test_shared_agent_fixtures_built_agent_and_desktop_web_dependency(self):
        self.assertTrue(ci.selection(['apps/agent/tests/redaction_cases.json'])['jobs']['gateway-shards'])
        self.assertTrue(ci.selection(['apps/agent/src/commands/instances.rs'])['jobs']['privileged'])
        web = ci.selection(['apps/web/src/main.tsx'])
        self.assertTrue(web['jobs']['desktop'])
        self.assertTrue(web['jobs']['web'])

    def test_pi_server_changes_also_start_docs(self):
        result = ci.selection(['apps/pi-server/package.json'])
        self.assertEqual(result['matrix']['project'], [{'name': 'Docs', 'directory': 'apps/docs'}])
        self.assertTrue(result['jobs']['project'])
        self.assertTrue(result['jobs']['pi-server'])

    def test_no_changed_paths_select_no_jobs(self):
        result = ci.selection([])
        self.assertFalse(any(result['jobs'].values()))
        self.assertEqual(result['matrix'], {'project': []})

    def test_full_events_select_everything_without_reading_a_diff(self):
        for event, ref in [('push', 'refs/heads/main'), ('schedule', 'refs/heads/main'),
                           ('workflow_dispatch', 'refs/heads/task-example-handoff')]:
            with self.subTest(event=event, ref=ref):
                result = subprocess.run(['python3', str(ROOT / 'bin/ci-changes'), '--base', 'does-not-exist'],
                                        env={**os.environ, 'CHANGE_RUN': 'false', 'GITHUB_EVENT_NAME': event, 'GITHUB_REF': ref},
                                        text=True, capture_output=True, check=True)
                self.assertTrue(all(json.loads(result.stdout)['jobs'].values()))


class DiffTest(unittest.TestCase):
    def test_pr_and_handoff_diff_the_merge_base_and_include_both_sides_of_a_move(self):
        with tempfile.TemporaryDirectory(prefix='ci-changes-') as scratch:
            root = Path(scratch)
            env = {**os.environ, 'GIT_AUTHOR_NAME': 'CI test', 'GIT_COMMITTER_NAME': 'CI test',
                   'GIT_AUTHOR_EMAIL': 'ci@example.test', 'GIT_COMMITTER_EMAIL': 'ci@example.test'}

            def git(*args):
                return subprocess.check_output(['git', *args], cwd=root, env=env, text=True).strip()

            git('init', '--quiet', '--initial-branch=main')
            (root / 'apps/pi-server').mkdir(parents=True)
            (root / 'apps/pi-server/moved.ts').write_text('source\n')
            git('add', '.')
            git('-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'base')
            git('checkout', '--quiet', '-b', 'change')
            (root / 'docs').mkdir()
            git('mv', 'apps/pi-server/moved.ts', 'docs/moved.md')
            # Include a newline to prove the NUL-delimited diff preserves path names.
            (root / 'docs/line\nbreak.md').write_text('docs\n')
            git('add', '.')
            git('-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'move')
            head = git('rev-parse', 'HEAD')
            git('checkout', '--quiet', 'main')
            (root / 'composer.json').write_text('{}\n')
            git('add', '.')
            git('-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'base advances')
            self.assertEqual(set(ci.changed_paths('main', head, root)),
                             {'apps/pi-server/moved.ts', 'docs/moved.md', 'docs/line\nbreak.md'})
            for event, ref in [('pull_request', 'refs/pull/1/merge'),
                               ('push', 'refs/heads/task-123-handoff')]:
                output = root / 'github-output'
                result = subprocess.run(['python3', str(ROOT / 'bin/ci-changes'), '--change-run', 'true',
                                         '--base', 'main', '--head', head,
                                         '--github-output', str(output)], cwd=root,
                                        env=env, text=True, capture_output=True, check=True)
                selected = json.loads(result.stdout)
                self.assertEqual([p['name'] for p in selected['matrix']['project']], ['Docs'])
                self.assertTrue(selected['jobs']['pi-server'])
                self.assertFalse(selected['jobs']['agent'])
                self.assertEqual(json.loads(dict(line.split('=', 1) for line in
                                                output.read_text().splitlines())['jobs']), selected['jobs'])

    def test_an_invalid_diff_fails_instead_of_selecting_nothing(self):
        result = subprocess.run(['python3', str(ROOT / 'bin/ci-changes'), '--change-run', 'true',
                                 '--base', 'does-not-exist'], text=True, capture_output=True)
        self.assertNotEqual(result.returncode, 0)


if __name__ == '__main__':
    unittest.main()
