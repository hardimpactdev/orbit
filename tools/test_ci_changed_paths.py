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

    def cli(self, script, *args, python_args=()):
        return json.loads(subprocess.check_output([sys.executable, *python_args, str(ROOT / 'bin' / script), *args],
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

    def test_clean_python_plans_a_non_yaml_fixture_input(self):
        path = 'swamp/tests/instance_complete_workflow_test.ts'
        base = self.git('rev-parse', 'HEAD')
        self.commit(path, '// candidate fixture change')
        plan = self.cli('ci-units', 'plan', '--base', base, '--head', 'HEAD', python_args=('-I', '-S'))
        self.assertIn(path, [unit for shard in plan['shards'] for unit in shard['units']])

    def test_yaml_comment_changes_skip_only_fixtures_that_consume_parsed_values(self):
        path = 'swamp/ansible/tasks/instance-fleet.yml'
        fleet = 'swamp/tests/ansible_instance_fleet_test.ts'
        container = 'swamp/tests/ansible_instance_container_test.ts'
        source = (ROOT / path).read_text()
        self.commit(path, source)
        base = self.git('rev-parse', 'HEAD')
        self.commit(path, '# Explain the fleet\n' + source)
        fixtures = self.cli('ci-units', 'plan', '--base', base, '--head', 'HEAD')
        units = self.selected_files(fixtures)
        self.assertNotIn(fleet, units)
        self.assertIn(container, units)
        self.assertIn('swamp/tests/proof_verify_copy_test.ts', units)
        self.assertTrue(any(row['file'] == container for row in fixtures['mandatory']))
        clean = self.cli('ci-units', 'plan', '--base', base, '--head', 'HEAD', python_args=('-I', '-S'))
        self.assertIn(fleet, self.selected_files(clean))
        result = self.cli('ci-build-plan', '--base', base, '--head', 'HEAD')
        self.assertTrue(result['swamp_checks']['tests'])
        self.git('update-index', '--chmod=+x', path)
        self.git('-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', 'commit', '-qm', 'Change the mode as well')
        mode_change = self.cli('ci-units', 'plan', '--base', base, '--head', 'HEAD')
        self.assertIn(fleet, self.selected_files(mode_change))

        # A change to the fixture still selects it even when its input edit is a comment.
        self.git('reset', '--hard', 'HEAD^')
        self.commit(fleet, '// fixture implementation changed')
        fixture_change = self.cli('ci-units', 'plan', '--base', base, '--head', 'HEAD')
        self.assertIn(fleet, self.selected_files(fixture_change))

    def selected_files(self, plan):
        return {unit.split('#', 1)[0] for shard in plan['shards'] + plan['self_hosted_shards'] for unit in shard['units']}

    def test_yaml_comments_remain_inputs_for_registered_raw_source_checks(self):
        scenarios = [
            ('swamp/workflows/workflow-dependency-results-prune.yaml',
             '# This workflow does not use /serve-tls/.',
             'swamp/tests/swamp_node_schedules_test.ts',
             'a workflow with a built-in trigger reads nothing of the production Swamp Node'),
            ('swamp/workflows/workflow-operation-instance-connection-environment.yaml',
             '# The vault.get() expression supplies the connection environment.',
             'swamp/tests/ansible_instance_env_keys_test.py',
             'test_playbook_keeps_values_out_of_argv_and_logs'),
        ]
        for path, comment, fixture, case in scenarios:
            with self.subTest(path=path):
                source = (ROOT / path).read_text()
                self.commit(path, source)
                base = self.git('rev-parse', 'HEAD')
                self.commit(path, comment + '\n' + source)
                report = self.repo / 'plan.json'
                plan = self.cli('ci-units', 'plan', '--base', base, '--head', 'HEAD', '--plan-json', str(report))
                self.assertIn(fixture, self.selected_files(plan))
                # Python fixtures run as a whole file. Collection verifies the named method is in that unit.
                names = (self.cli('ci-units', 'collect', '--file', fixture)['cases'] if fixture.endswith('.py')
                         else json.loads(report.read_text())['units'][fixture]['cases'])
                self.assertIn(case, names)

    def test_unmapped_yaml_comment_changes_keep_default_fixtures(self):
        path = 'swamp/unknown-ci-input.yml'
        self.commit(path, 'name: unknown\n')
        base = self.git('rev-parse', 'HEAD')
        self.commit(path, '# Still unmapped\nname: unknown\n')
        plan = self.cli('ci-units', 'plan', '--base', base, '--head', 'HEAD')
        self.assertEqual([path], plan['unmapped'])
        self.assertTrue(plan['shards'])

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

    def test_yaml_block_scalar_chomping_can_change_at_a_comment(self):
        path = 'swamp/workflows/workflow-operation-instance-complete.yaml'
        fixture = 'swamp/tests/instance_complete_workflow_test.ts'
        source = (ROOT / path).read_text()
        description = next(line for line in source.splitlines(keepends=True) if line.startswith('description:'))
        for style in ('|', '>'):
            for chomp, selects in (('+', True), ('', False), ('-', False)):
                with self.subTest(style=style, chomp=chomp):
                    block = f'description: {style}{chomp}\n  First line\n  Second line\n'
                    before = source.replace(description, block + '\n', 1)
                    after = source.replace(description, block + '# Boundary after the description\n\n', 1)
                    self.commit(path, before)
                    base = self.git('rev-parse', 'HEAD')
                    self.commit(path, after)
                    plan = self.cli('ci-units', 'plan', '--base', base, '--head', 'HEAD')
                    units = self.selected_files(plan)
                    self.assertEqual(selects, fixture in units)
                    self.assertIn('swamp/bin/source_test.ts', units)

    def test_yaml_meaning_changes_and_uncertain_comparisons_keep_fixtures(self):
        path = 'swamp/ansible/tasks/instance-fleet.yml'
        fleet = 'swamp/tests/ansible_instance_fleet_test.ts'
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
                self.assertIn(fleet, self.selected_files(fixtures))

    def test_yaml_additions_and_deletions_keep_fixtures(self):
        path = 'swamp/ansible/tasks/instance-fleet.yml'
        fleet = 'swamp/tests/ansible_instance_fleet_test.ts'
        base = self.git('rev-parse', 'HEAD')
        self.commit(path, '# new file\n')
        fixtures = self.cli('ci-units', 'plan', '--base', base, '--head', 'HEAD')
        self.assertIn(fleet, self.selected_files(fixtures))
        base = self.git('rev-parse', 'HEAD')
        self.git('rm', path)
        self.git('-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', 'commit', '-qm', 'Delete YAML')
        fixtures = self.cli('ci-units', 'plan', '--base', base, '--head', 'HEAD')
        self.assertIn(fleet, self.selected_files(fixtures))


if __name__ == '__main__':
    unittest.main()
