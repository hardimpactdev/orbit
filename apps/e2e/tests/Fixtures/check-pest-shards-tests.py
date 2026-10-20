"""Exercise class coverage using Pest's --list-tests output format."""
import importlib.machinery
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import unittest
import xml.etree.ElementTree as ET
from unittest.mock import patch

HELPER = Path(sys.argv.pop(1)).resolve()
loader = importlib.machinery.SourceFileLoader('check_pest_shards', str(HELPER))
spec = importlib.util.spec_from_loader(loader.name, loader)
checker = importlib.util.module_from_spec(spec)
loader.exec_module(checker)

# Captured from real Gateway --list-tests output. Stock shards own whole classes.
CASES = [
    r'P\Tests\Feature\Tasks\WorkspaceLeaseStatusTest::__pest_evaluable_it_reports_the_workspace_holder_and_ordered_waiters_without_changing_ownership_while_tasks_is_disabled',
    r'P\Tests\Feature\TestTemporaryDirectoryTest::__pest_evaluable_it_gives_a_test_process_its_own_directory__removes_it_at_exit__and_removes_those_of_ended_processes',
    r'P\Tests\Feature\VitePortAllocatorTest::__pest_evaluable_it_keeps_node_scoped_assignments_and_retains_both_placements_until_transfer_cleanup',
    r'P\Tests\Unit\Configuration\RecipeSnapshotWorkflowTest::__pest_evaluable_it_runs_the_shared_Ubuntu_snapshot_check_only_for_recipe_changes_in_the_Gateway_CI_job',
]
CLASSES = [case.split('::', 1)[0] for case in CASES]
IDENTIFIERS = dict(zip(CLASSES, CASES))


def listing(classes, code=0):
    return subprocess.CompletedProcess([], code, '\n   INFO  Available tests:\n\n' +
                                       ''.join(f' - {IDENTIFIERS.get(name, name + "::test_example")}\n'
                                               for name in classes), '')


class PestShardsTest(unittest.TestCase):
    def outputs(self, partitions=None):
        return [listing(CLASSES), *(listing(classes) for classes in
                                   (partitions if partitions is not None else [[name] for name in CLASSES]))]

    def test_uses_only_stock_full_and_sharded_listings(self):
        with patch.object(checker.subprocess, 'run', side_effect=self.outputs()) as run:
            checker.check()
        commands = [call.args[0] for call in run.call_args_list]
        common = ['vendor/bin/pest', '--no-tia', '--exclude-group=privileged']
        self.assertEqual([common + ['--list-tests', '--colors=never'], *[
            common + [f'--shard={index}/4', '--list-tests', '--colors=never'] for index in range(1, 5)
        ]], commands)

    def test_fails_if_a_class_lands_in_no_shard(self):
        with patch.object(checker.subprocess, 'run', side_effect=self.outputs([
            [CLASSES[0]], [CLASSES[1]], [CLASSES[2]], [CLASSES[0]],
        ])), self.assertRaisesRegex(ValueError, 'Missing:.*RecipeSnapshotWorkflowTest'):
            checker.check()

    def test_fails_if_two_shards_own_the_same_class(self):
        with patch.object(checker.subprocess, 'run', side_effect=self.outputs([
            [CLASSES[0]], [CLASSES[1]], [CLASSES[2]], [CLASSES[3], CLASSES[0]],
        ])), self.assertRaisesRegex(ValueError, 'Repeated:.*WorkspaceLeaseStatusTest'):
            checker.check()

    def test_fails_if_a_shard_lists_an_unexpected_class(self):
        with patch.object(checker.subprocess, 'run', side_effect=self.outputs([
            [CLASSES[0]], [CLASSES[1]], [CLASSES[2]], [CLASSES[3], 'Tests\\Unexpected'],
        ])), self.assertRaisesRegex(ValueError, 'Unexpected:.*Unexpected'):
            checker.check()

    def test_fails_if_pest_cannot_list_a_shard(self):
        outputs = self.outputs()
        outputs[3] = listing([], code=1)
        with patch.object(checker.subprocess, 'run', side_effect=outputs), \
                self.assertRaisesRegex(ValueError, 'Pest listing failed'):
            checker.check()

    def test_refuses_empty_and_unreadable_listings(self):
        for output in ('', ' - not a test identifier\n'):
            with self.subTest(output=output), patch.object(checker.subprocess, 'run', return_value=
                                                       subprocess.CompletedProcess([], 0, output, '')), \
                    self.assertRaisesRegex(ValueError, 'empty or unreadable'):
                checker.check()

    def test_ignores_dataset_counts_and_shortened_method_names_within_a_class(self):
        outputs = self.outputs()
        outputs[0].stdout += f' - {CLASSES[0]}::test_dataset"(1)"\n'
        outputs[1].stdout = f' - {CLASSES[0]}::test_example.\n'
        with patch.object(checker.subprocess, 'run', side_effect=outputs):
            checker.check()


class PrivilegedShardsTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='orbit-privileged-shards-')
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.summary = self.root / 'summary.md'
        self.environment = {**os.environ, 'GITHUB_STEP_SUMMARY': str(self.summary)}
        (self.root / 'vendor/bin').mkdir(parents=True)
        self.tests = [CASES[0], CASES[0] + ' with data set "(root)"', CASES[1]]

    def run_check(self, shards, whole=None, status=0, count=2):
        listings = {'whole': self.tests if whole is None else whole, **shards}
        pest = self.root / 'vendor/bin/pest'
        pest.write_text('#!/usr/bin/env python3\nimport sys\n'
                        f'listings = {listings!r}\n'
                        'assert all(option in sys.argv for option in '
                        '["--no-tia", "--group=privileged", "--fail-on-empty-test-suite", '
                        '"--list-tests", "--colors=never"])\n'
                        'key = next((arg.split("=", 1)[1] for arg in sys.argv if arg.startswith("--shard=")), "whole")\n'
                        'print("   INFO  Available tests:\\n")\n'
                        'print("\\n".join(" - " + test for test in listings[key]) + ".")\n'
                        f'sys.exit({status})\n')
        pest.chmod(0o755)
        return subprocess.run([str(HELPER), '--group=privileged', f'--shards={count}'], cwd=self.root,
                              env=self.environment, capture_output=True, text=True)

    def test_checks_the_exact_union_of_stock_privileged_shards(self):
        result = self.run_check({'1/2': self.tests[:2], '2/2': self.tests[2:]})
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn('3 privileged tests', result.stdout)
        summary = self.summary.read_text()
        self.assertIn('| 1/2 | 2 |', summary)
        self.assertIn('| 2/2 | 1 |', summary)
        self.assertIn('Union: 3 / 3', summary)

    def test_rejects_incomplete_extra_overlapping_and_empty_privileged_shards(self):
        root, dataset, acl = self.tests
        for shards, message in (
            ({'1/2': [root, dataset], '2/2': []}, 'empty'),
            ({'1/2': [root, dataset], '2/2': [root]}, 'overlap'),
            ({'1/2': [root], '2/2': [acl]}, 'missing'),
            ({'1/2': [root, dataset], '2/2': [acl + ' changed']}, 'missing'),
            ({'1/2': [root, dataset], '2/2': [acl, acl + ' extra']}, 'extra'),
        ):
            with self.subTest(message=message):
                result = self.run_check(shards)
                self.assertNotEqual(0, result.returncode)
                self.assertIn(message, result.stderr)
                self.assertFalse(self.summary.exists())

    def test_rejects_empty_unreadable_or_failed_privileged_group_discovery(self):
        for whole, status, count, message in (([], 0, 2, 'empty'), (['unreadable'], 0, 2, 'unreadable'),
                                              (None, 3, 2, 'Pest listing failed'), (None, 0, 0, 'positive')):
            with self.subTest(message=message):
                result = self.run_check({}, whole=whole, status=status, count=count)
                self.assertNotEqual(0, result.returncode)
                self.assertIn(message, result.stderr)

    def test_privileged_workflow_runs_every_shard_on_disposable_runners(self):
        repository = HELPER.parent.parent
        workflow = json.loads(subprocess.run([
            'php', '-r', 'require $argv[1]; echo json_encode(Symfony\\Component\\Yaml\\Yaml::parseFile($argv[2]));',
            str(repository / 'apps/e2e/vendor/autoload.php'), str(repository / '.github/workflows/ci.yml'),
        ], check=True, capture_output=True, text=True).stdout)
        job = workflow['jobs']['privileged']
        self.assertEqual('ubuntu-26.04', job['runs-on'])
        self.assertNotIn('if', job)
        self.assertFalse(job['strategy']['fail-fast'])
        self.assertEqual([1, 2, 3, 4], job['strategy']['matrix']['shard'])
        commands = [step['run'] for step in job['steps'] if 'run' in step]
        execution = next(command for command in commands if command.startswith('vendor/bin/pest '))
        for option in ('--no-tia', '--group=privileged', '--fail-on-empty-test-suite',
                       '--shard=${{ matrix.shard }}/${{ strategy.job-total }}'):
            self.assertIn(option, execution)
        check = next(step for step in job['steps'] if 'check-pest-shards' in step.get('run', ''))
        self.assertEqual('matrix.shard == 1', check['if'])
        self.assertIn('--group=privileged', check['run'])
        self.assertIn('--shards=${{ strategy.job-total }}', check['run'])
        self.assertEqual('1', next(step for step in job['steps'] if step.get('run') == execution)['env']['ORBIT_TEST_DOCKER'])
        self.assertIn('privileged', workflow['jobs']['required']['needs'])
        self.assertIn('PRIVILEGED_RESULT', json.dumps(workflow['jobs']['required']['steps']))
        self.assertFalse(any('Homebrew' in step.get('uses', '') or 'setup-node' in step.get('uses', '')
                             for step in job['steps']))
        self.assertFalse(any('brew install' in command or 'docker pull' in command for command in commands))


class GatewaySelectionTest(unittest.TestCase):
    """Run CI commands with Gateway's real group configuration and installed Pest, never host sudo."""

    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='orbit-gateway-selection-')
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.project = self.root / 'apps/gateway'
        repository = HELPER.parent.parent
        for directory in ('tests/Unit', 'tests/Feature', 'app', 'extensions'):
            (self.project / directory).mkdir(parents=True)
        # Pest resolves the project root from the real autoloader path, not a vendor symlink.
        shutil.copytree(repository / 'packages/php-sdk/vendor', self.project / 'vendor',
                        ignore=shutil.ignore_patterns('cache', 'pint.cache'))
        (self.root / '.gitignore').write_text('**/vendor\n**/.orbit-tia/\n**/.executed\n')
        configuration = ET.parse(repository / 'apps/gateway/phpunit.xml')
        configuration.getroot().set('bootstrap', 'vendor/autoload.php')
        configuration.write(self.project / 'phpunit.xml')
        for manifest in ('composer.json', 'composer.lock'):
            (self.project / manifest).write_bytes((repository / 'packages/php-sdk' / manifest).read_bytes())
        (self.project / 'tests/Pest.php').write_text(
            "<?php\npest()->tia()->directory(dirname(__DIR__).'/.orbit-tia');\n"
            "require_once dirname(__DIR__).'/app/Value.php';\n")
        (self.project / 'app/Value.php').write_text('<?php\nfunction gatewaySelectionValue(): int { return 1; }\n')
        (self.project / 'tests/Unit/MixedTest.php').write_text('''<?php
it('runs an ordinary case', function (): void {
    file_put_contents(dirname(__DIR__, 2).'/.executed', "ordinary\\n", FILE_APPEND);
    expect(gatewaySelectionValue())->toBe(1);
});
it('runs an ordinary subprocess case', function (): void {
    file_put_contents(dirname(__DIR__, 2).'/.executed', "subprocess\\n", FILE_APPEND);
    expect(gatewaySelectionValue())->toBe(1);
})->group('subprocess');
it('runs a Unix user case', function (): void {
    file_put_contents(dirname(__DIR__, 2).'/.executed', "privileged\\n", FILE_APPEND);
    expect(gatewaySelectionValue())->toBe(1);
})->group('privileged');
it('runs a privileged subprocess case', function (): void {
    file_put_contents(dirname(__DIR__, 2).'/.executed', "privileged-subprocess\\n", FILE_APPEND);
    expect(gatewaySelectionValue())->toBe(1);
})->group('privileged', 'subprocess');
''')
        (self.project / 'tests/Unit/UnrelatedTest.php').write_text('''<?php
it('runs an unrelated ordinary case', function (): void {
    file_put_contents(dirname(__DIR__, 2).'/.executed', "unrelated\\n", FILE_APPEND);
    expect(true)->toBeTrue();
});
''')
        (self.project / 'tests/Unit/PrivilegedOnlyTest.php').write_text('''<?php
it('runs a privileged only case', function (): void {
    file_put_contents(dirname(__DIR__, 2).'/.executed', "privileged-only\\n", FILE_APPEND);
    expect(true)->toBeTrue();
})->group('privileged');
''')
        for args in (('init', '-q', '-b', 'main'), ('config', 'user.name', 'Orbit'),
                     ('config', 'user.email', 'orbit@example.test'), ('remote', 'add', 'origin', str(self.root)),
                     ('add', '-A'), ('commit', '-q', '-m', 'base'),
                     ('update-ref', 'refs/remotes/origin/main', 'HEAD'),
                     ('symbolic-ref', 'refs/remotes/origin/HEAD', 'refs/remotes/origin/main')):
            subprocess.run(['git', *args], cwd=self.root, check=True, capture_output=True)
        self.base = subprocess.run(['git', 'rev-parse', 'HEAD'], cwd=self.root, check=True,
                                   capture_output=True, text=True).stdout.strip()
        self.workflow = json.loads(subprocess.run([
            'php', '-r', 'require $argv[1]."/apps/e2e/vendor/autoload.php"; '
            'echo json_encode(Symfony\\Component\\Yaml\\Yaml::parseFile($argv[1]."/.github/workflows/ci.yml"));',
            str(repository),
        ], check=True, capture_output=True, text=True).stdout)
        environment = {key: value for key, value in os.environ.items()
                       if not key.startswith('PEST_') and key not in
                       ('PARATEST', 'TEST_TOKEN', 'UNIQUE_TEST_TOKEN', 'LARAVEL_PARALLEL_TESTING')}
        self.environment = {**environment, 'RUNNER_TEMP': str(self.root), 'ORBIT_TIA_DIRECTORY': '',
                            'CI': '', 'GITHUB_ACTIONS': '', 'GITHUB_OUTPUT': str(self.root / 'output')}

    def command(self, step_name):
        step = next(step for step in self.workflow['jobs']['project']['steps'] if step.get('name') == step_name)
        return re.sub(r'\$\{\{ runner.environment .*?\}\}', '2', step['run'])

    def run_command(self, command):
        result = subprocess.run(['bash', '-eo', 'pipefail', '-c', command], cwd=self.project,
                                env=self.environment, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        return result.stdout + result.stderr

    def executed(self):
        marker = self.project / '.executed'
        cases = marker.read_text().splitlines() if marker.exists() else []
        marker.unlink(missing_ok=True)
        return sorted(cases)

    def test_full_ci_run_excludes_privileged_and_records_a_complete_graph(self):
        self.run_command(self.command('Run full test suite and refresh Pest TIA graph'))
        self.assertEqual(['ordinary', 'subprocess', 'unrelated'], self.executed())
        graph = json.loads((self.project / '.orbit-tia/graph.json').read_text())
        self.assertEqual(self.base, graph['baselines']['main']['sha'])
        self.assertEqual(3, len(graph['baselines']['main']['results']))
        self.assertEqual({'tests/Unit/MixedTest.php', 'tests/Unit/UnrelatedTest.php'}, set(graph['edges']))

    def test_local_affected_and_subprocess_runs_keep_the_xml_exclusion(self):
        self.run_command(self.command('Run full test suite and refresh Pest TIA graph'))
        self.executed()
        (self.project / 'app/Value.php').write_text('<?php\nfunction gatewaySelectionValue(): int { return 1 + 0; }\n')
        for args in (('add', '-A'), ('commit', '-q', '-m', 'change covered source')):
            subprocess.run(['git', *args], cwd=self.root, check=True, capture_output=True)
        self.run_command('vendor/bin/pest --parallel --processes=2 --tia --compact')
        self.assertEqual(['ordinary', 'subprocess'], self.executed())
        self.run_command('vendor/bin/pest --no-tia --group=subprocess --compact')
        self.assertEqual(['subprocess'], self.executed())

    def test_explicit_privileged_selection_and_discovery_keep_every_privileged_case(self):
        job = self.workflow['jobs']['privileged']
        command = next(step['run'] for step in job['steps'] if step.get('run', '').startswith('vendor/bin/pest '))
        command = command.replace('${{ matrix.shard }}/${{ strategy.job-total }}', '1/1')
        self.run_command(command)
        self.assertEqual(['privileged', 'privileged-only', 'privileged-subprocess'], self.executed())
        listing = self.run_command('vendor/bin/pest --no-tia --all --list-tests --colors=never')
        tests = [line for line in listing.splitlines() if line.startswith(' - ')]
        self.assertEqual(6, len(tests))
        self.assertEqual([], self.executed())


if __name__ == '__main__':
    unittest.main()
