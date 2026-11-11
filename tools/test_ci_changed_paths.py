"""Exercise both selector CLIs against real divergent Git histories."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


class ChangedPathsTest(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory()
        self.addCleanup(self.scratch.cleanup)
        self.repo = Path(self.scratch.name)
        self.env = {**os.environ, 'GIT_AUTHOR_NAME': 'CI test', 'GIT_AUTHOR_EMAIL': 'ci@example.test',
                    'GIT_COMMITTER_NAME': 'CI test', 'GIT_COMMITTER_EMAIL': 'ci@example.test'}
        self.git('init', '-q', '-b', 'main')
        self.commit('.github/ci/checks.toml', '[budget]\ndefault_cost = 30\n')
        self.fork = self.git('rev-parse', 'HEAD')
        self.git('branch', 'candidate')
        self.commit('.github/ci/checks.toml', '[budget]\ndefault_cost = 31\n')
        self.commit('swamp/ansible/instance-container.yml', 'main-only fixture input')
        self.git('checkout', '-q', 'candidate')

    def git(self, *args):
        return subprocess.check_output(['git', *args], cwd=self.repo, env=self.env, text=True,
                                       stderr=subprocess.PIPE).strip()

    def commit(self, path, text):
        file = self.repo / path
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_text(text)
        self.git('add', path)
        self.git('-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', 'commit', '-qm', text)

    def cli(self, script, *args):
        return json.loads(subprocess.check_output([sys.executable, str(ROOT / 'bin' / script), *args],
                                                 cwd=self.repo, env=self.env, text=True))

    def test_docs_candidate_ignores_main_only_workflow_and_fixture_changes(self):
        self.commit('docs/reference/development-containers.md', 'candidate docs')
        result = self.cli('ci-build-plan', '--base', 'main', '--head', 'candidate', '--proof-cases')
        self.assertEqual(['docs'], [name for name, selected in result['jobs'].items() if selected])
        self.assertFalse(result['gateway_release'])
        self.assertFalse(result['proof_run'])
        fixtures = self.cli('ci-units', 'plan', '--base', 'main', '--head', 'candidate')
        self.assertEqual([], fixtures['shards'])
        self.assertEqual([], fixtures['deferred'])

    def test_candidate_ci_change_still_selects_checks_and_fixtures(self):
        self.commit('.github/ci/checks.toml', '[budget]\ndefault_cost = 32\n')
        result = self.cli('ci-build-plan', '--base', 'main', '--head', 'candidate')
        self.assertTrue(result['jobs']['agent'])
        self.assertTrue(result['jobs']['gateway-tests'])
        fixtures = self.cli('ci-units', 'plan', '--base', 'main', '--head', 'candidate')
        self.assertTrue(fixtures['shards'])

    def test_main_push_keeps_all_changes_since_previous_tip(self):
        result = self.cli('ci-build-plan', '--base', self.fork, '--head', 'main', '--main')
        self.assertTrue(result['jobs']['agent'])
        self.assertTrue(result['gateway_release'])
        fixtures = self.cli('ci-units', 'plan', '--base', self.fork, '--head', 'main')
        self.assertTrue(fixtures['shards'])


if __name__ == '__main__':
    unittest.main()
