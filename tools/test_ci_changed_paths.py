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
        self.assertEqual([], fixtures['self_hosted_shards'])
        self.assertNotIn('capacity', fixtures)

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

    def test_yaml_comment_only_changes_skip_fixtures_and_keep_build_checks(self):
        path = 'swamp/ansible/tasks/instance-fleet.yml'
        self.commit(path, '- name: Read fleet\n  command: "incus list"\n')
        base = self.git('rev-parse', 'HEAD')
        self.commit(path, '# Explain the fleet\n- name: Read fleet # a comment\n  command: "incus list"\n')
        fixtures = self.cli('ci-units', 'plan', '--base', base, '--head', 'HEAD')
        self.assertEqual([], fixtures['shards'])
        self.assertEqual([], fixtures['self_hosted_shards'])
        result = self.cli('ci-build-plan', '--base', base, '--head', 'HEAD')
        self.assertTrue(result['swamp_checks']['tests'])

    def test_yaml_scalar_and_key_ambiguity_selects_the_workflow_fixture(self):
        path = 'swamp/workflows/workflow-operation-instance-complete.yaml'
        fixture = 'swamp/tests/instance_complete_workflow_test.ts'
        source = (ROOT / path).read_text()
        marker = '  properties:\n'
        self.assertIn(marker, source)
        changes = [
            ('"1000": {}\n    "1e3": {}', '"1000": {}\n    1e3: {}'),
            ('example: "2e4"', 'example: 2e4'),
            ('"1000": {}\n    1e3: {}', '"1000": {}\n    1e3: {} # duplicate for Deno'),
            ('"true": {}\n    True: {}', '"true": {}\n    True: {} # duplicate for Deno'),
            ('"1": {}\n    1: {}', '"1": {}\n    1: {} # duplicate for Deno'),
        ]
        for before, after in changes:
            with self.subTest(before=before, after=after):
                self.commit(path, source.replace(marker, f'{marker}    {before}\n', 1))
                base = self.git('rev-parse', 'HEAD')
                self.commit(path, source.replace(marker, f'{marker}    {after}\n', 1))
                plan = self.cli('ci-units', 'plan', '--base', base, '--head', 'HEAD')
                units = [unit for shard in plan['shards'] for unit in shard['units']]
                self.assertIn(fixture, [unit.split('#', 1)[0] for unit in units])

    def test_yaml_meaning_changes_and_uncertain_comparisons_keep_fixtures(self):
        path = 'swamp/ansible/tasks/instance-fleet.yml'
        changes = [
            ('command: incus list\n', 'command: incus stop\n'),
            ('value: 1\n', 'value: true\n'),
            ('value: "1"\n', 'value: 1\n'),
            ('value: "incus list # literal"\n', 'value: "incus info # literal"\n'),
            ('value: |\n  # kept in the command\n', 'value: |\n  # changed command\n'),
            ('- first\n- second\n', '- second\n- first\n'),
            ('first: 1\nsecond: 2\n', 'second: 2\nfirst: 1\n'),
            ('value: [\n', 'value: [\n# still invalid\n'),
            ('value: !!int invalid\n', '# comment\nvalue: !!int invalid\n'),
            ('value: !custom x\n', '# comment\nvalue: !custom x\n'),
            ('value: 1\nvalue: 2\n', '# comment\nvalue: 1\nvalue: 2\n'),
            ('1: first\n01: second\n', '# comment\n1: first\n01: second\n'),
            ('true: first\nyes: second\n', '# comment\ntrue: first\nyes: second\n'),
            ('value: &loop [*loop]\n', '# comment\nvalue: &loop [*loop]\n'),
        ]
        for before, after in changes:
            with self.subTest(before=before, after=after):
                self.commit(path, before)
                base = self.git('rev-parse', 'HEAD')
                self.commit(path, after)
                fixtures = self.cli('ci-units', 'plan', '--base', base, '--head', 'HEAD')
                self.assertTrue(fixtures['shards'])

    def test_yaml_additions_and_deletions_keep_fixtures(self):
        path = 'swamp/ansible/tasks/instance-fleet.yml'
        base = self.git('rev-parse', 'HEAD')
        self.commit(path, '# new file\n')
        fixtures = self.cli('ci-units', 'plan', '--base', base, '--head', 'HEAD')
        self.assertTrue(fixtures['shards'])
        base = self.git('rev-parse', 'HEAD')
        self.git('rm', path)
        self.git('-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', 'commit', '-qm', 'Delete YAML')
        fixtures = self.cli('ci-units', 'plan', '--base', base, '--head', 'HEAD')
        self.assertTrue(fixtures['shards'])


if __name__ == '__main__':
    unittest.main()
