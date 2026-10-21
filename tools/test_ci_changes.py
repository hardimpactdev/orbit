"""Path selection and real merge-base diffs for PR and full CI runs."""
import importlib.machinery
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
loader = importlib.machinery.SourceFileLoader('ci_changes', str(ROOT / 'bin/ci-changes'))
spec = importlib.util.spec_from_loader(loader.name, loader)
ci = importlib.util.module_from_spec(spec)
loader.exec_module(ci)


class SelectionTest(unittest.TestCase):
    def test_docs_only_starts_only_docs(self):
        result = ci.selection(['docs/reference/implementation-loop.md'])
        self.assertEqual(result['matrix']['project'], [{'name': 'Docs', 'directory': 'apps/docs'}])
        self.assertEqual([job for job, selected in result['jobs'].items() if selected], ['project'])

    def test_sdk_fixtures_start_sdk_cli_and_gateway(self):
        result = ci.selection(['packages/php-sdk/fixtures/nodes/show.json'])
        self.assertEqual([p['name'] for p in result['matrix']['project']], ['CLI', 'Gateway', 'PHP SDK'])
        self.assertEqual([job for job, selected in result['jobs'].items() if selected],
                         ['project', 'gateway-shards', 'privileged', 'remote-programs'])

    def test_sdk_sources_also_check_the_generators_that_read_them(self):
        result = ci.selection(['packages/php-sdk/src/Requests/Nodes/GetNodeRequest.php'])
        self.assertEqual([p['name'] for p in result['matrix']['project']],
                         ['CLI', 'Docs', 'Gateway', 'PHP SDK'])
        self.assertTrue(result['jobs']['api-reference'])

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
        self.assertEqual([p['name'] for p in api['matrix']['project']], ['CLI', 'Docs', 'Gateway', 'PHP SDK'])
        self.assertTrue(api['jobs']['web'])
        self.assertTrue(api['jobs']['api-reference'])

    def test_shared_agent_fixtures_recipe_and_desktop_web_dependency(self):
        self.assertTrue(ci.selection(['apps/agent/tests/redaction_cases.json'])['jobs']['gateway-shards'])
        self.assertTrue(ci.selection(['apps/gateway/resources/machine-software/recipe.json'])['jobs']['agent'])
        web = ci.selection(['apps/web/src/main.tsx'])
        self.assertTrue(web['jobs']['desktop'])
        self.assertTrue(web['jobs']['web'])

    def test_a_non_composer_project_has_an_empty_skipped_matrix(self):
        result = ci.selection(['apps/pi-server/src/main.ts'])
        self.assertEqual(result['matrix'], {'project': []})
        self.assertFalse(result['jobs']['project'])
        self.assertTrue(result['jobs']['pi-server'])

    def test_no_changed_paths_select_no_jobs(self):
        self.assertFalse(any(ci.selection([])['jobs'].values()))

    def test_full_events_select_everything_without_reading_a_diff(self):
        for event, ref in [('push', 'refs/heads/main'), ('schedule', 'refs/heads/main'),
                           ('workflow_dispatch', 'refs/heads/task-example-handoff')]:
            with self.subTest(event=event, ref=ref):
                result = subprocess.run(['python3', str(ROOT / 'bin/ci-changes'), '--event', event,
                                         '--ref', ref, '--base', 'does-not-exist'],
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
                result = subprocess.run(['python3', str(ROOT / 'bin/ci-changes'), '--event', event,
                                         '--ref', ref, '--base', 'main', '--head', head,
                                         '--github-output', str(output)], cwd=root,
                                        env=env, text=True, capture_output=True, check=True)
                selected = json.loads(result.stdout)
                self.assertEqual([p['name'] for p in selected['matrix']['project']], ['Docs'])
                self.assertTrue(selected['jobs']['pi-server'])
                self.assertFalse(selected['jobs']['agent'])
                self.assertEqual(json.loads(dict(line.split('=', 1) for line in
                                                output.read_text().splitlines())['jobs']), selected['jobs'])

    def test_an_invalid_diff_fails_instead_of_selecting_nothing(self):
        result = subprocess.run(['python3', str(ROOT / 'bin/ci-changes'), '--event', 'pull_request',
                                 '--base', 'does-not-exist'], text=True, capture_output=True)
        self.assertNotEqual(result.returncode, 0)


if __name__ == '__main__':
    unittest.main()
