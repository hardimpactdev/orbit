"""Focused coverage for published-source agent build selection."""
import importlib.machinery
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

loader = importlib.machinery.SourceFileLoader('ci_agent_delivery', str(Path(__file__).resolve().parents[1] / 'bin/ci-agent-delivery'))
spec = importlib.util.spec_from_loader(loader.name, loader)
delivery = importlib.util.module_from_spec(spec)
loader.exec_module(delivery)


class AgentSelectionTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.git('init', '-q')
        self.git('config', 'user.name', 'CI fixture')
        self.git('config', 'user.email', 'ci@example.invalid')
        (self.root / 'apps/agent').mkdir(parents=True)
        self.source = self.commit('apps/agent/source.rs', 'original')
        self.release = {'tag_name': 'cli-v0.1.0', 'draft': False, 'prerelease': False,
                        'assets': [{'name': 'manifest.json', 'id': 1}]}

    def git(self, *args):
        return subprocess.check_output(['git', *args], cwd=self.root, text=True).strip()

    def commit(self, path, contents):
        file = self.root / path
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_text(contents)
        self.git('add', path)
        self.git('commit', '-qm', path)
        return self.git('rev-parse', 'HEAD')

    def selection(self, releases=None):
        releases = [self.release] if releases is None else releases
        def github(*args):
            if '/releases/assets/' in args[-1]:
                return json.dumps({'commit': self.source})
            return json.dumps(releases)
        with patch.object(delivery, 'ROOT', self.root), patch.object(delivery, 'run', github):
            return delivery.select()

    def test_docs_only_commit_reuses_the_published_delivery(self):
        self.commit('docs/ci.md', 'Builds and static checks')
        self.assertEqual({'build': False, 'release': 'cli-v0.1.0'}, self.selection())

    def test_agent_change_builds_even_after_a_later_docs_push(self):
        self.commit('apps/agent/source.rs', 'changed')
        self.commit('docs/ci.md', 'Later push')
        self.assertTrue(self.selection()['build'])

    def test_restored_agent_tree_reuses_the_existing_bytes(self):
        self.commit('apps/agent/source.rs', 'changed')
        self.commit('apps/agent/source.rs', 'original')
        self.assertFalse(self.selection()['build'])

    def test_no_published_delivery_bootstraps_a_build(self):
        self.assertEqual({'build': True, 'release': ''}, self.selection([]))

    def test_drafts_and_non_cli_releases_are_not_deliveries(self):
        draft = self.release | {'draft': True}
        mirror = self.release | {'tag_name': 'php-build-mirror'}
        self.assertEqual({'build': True, 'release': ''}, self.selection([draft, mirror]))


if __name__ == '__main__':
    unittest.main()
