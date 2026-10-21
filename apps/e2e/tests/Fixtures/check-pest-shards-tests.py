"""Check capacity-sized ordinary shard coverage and the CI no-sudo boundary."""
import importlib.machinery
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
import xml.etree.ElementTree as ET
from unittest.mock import patch

HELPER = Path(__file__).resolve().parents[4] / 'bin/check-pest-shards'
loader = importlib.machinery.SourceFileLoader('check_pest_shards', str(HELPER))
spec = importlib.util.spec_from_loader(loader.name, loader)
checker = importlib.util.module_from_spec(spec)
loader.exec_module(checker)

# Captured from real Gateway --list-tests output. Stock shards own whole classes.
CASES = [
    r'P\Tests\Feature\Tasks\WorkspaceLeaseStatusTest::__pest_evaluable_it_reports_the_workspace_holder_and_ordered_waiters_without_changing_ownership_while_tasks_is_disabled',
    r'P\Tests\Feature\TestTemporaryDirectoryTest::__pest_evaluable_it_gives_a_test_process_its_own_directory__removes_it_at_exit__and_removes_those_of_ended_processes',
    r'P\Tests\Feature\VitePortAllocatorTest::__pest_evaluable_it_keeps_node_scoped_assignments_and_retains_both_placements_until_transfer_cleanup',
    r'P\Tests\Feature\Api\ActivitiesTest::test_example',
    r'P\Tests\Feature\Api\AdoptToolTest::test_example',
    r'P\Tests\Unit\Configuration\RecipeSnapshotWorkflowTest::__pest_evaluable_it_runs_the_shared_Ubuntu_snapshot_check_only_for_recipe_changes_in_the_Gateway_CI_job',
]
CLASSES = [case.split('::', 1)[0] for case in CASES]
IDENTIFIERS = dict(zip(CLASSES, CASES))


def listing(classes, code=0):
    return subprocess.CompletedProcess([], code, '\n   INFO  Available tests:\n\n' +
                                       ''.join(f' - {IDENTIFIERS.get(name, name + "::test_example")}\n'
                                               for name in classes), '')


class PestShardsTest(unittest.TestCase):
    def setUp(self):
        self.enterContext(patch.object(checker, 'shard_count', return_value=len(CLASSES)))

    def outputs(self, partitions=None):
        return [listing(CLASSES), *(listing(classes) for classes in
                                   (partitions if partitions is not None else [[name] for name in CLASSES]))]

    def test_uses_only_stock_full_and_sharded_listings(self):
        with patch.object(checker.subprocess, 'run', side_effect=self.outputs()) as run:
            checker.check()
        commands = [call.args[0] for call in run.call_args_list]
        common = ['vendor/bin/pest', '--no-tia', '--exclude-group=privileged']
        self.assertEqual([common + ['--list-tests', '--colors=never'], *[
            common + [f'--shard={index}/{checker.shard_count()}', '--list-tests', '--colors=never'] for index in range(1, checker.shard_count() + 1)
        ]], commands)

    def test_fails_if_a_class_lands_in_no_shard(self):
        with patch.object(checker.subprocess, 'run', side_effect=self.outputs([
            [CLASSES[0]], [CLASSES[1]], [CLASSES[2]], [CLASSES[3]], [CLASSES[4]], [CLASSES[0]],
        ])), self.assertRaisesRegex(ValueError, 'Missing:.*RecipeSnapshotWorkflowTest'):
            checker.check()

    def test_fails_if_two_shards_own_the_same_class(self):
        with patch.object(checker.subprocess, 'run', side_effect=self.outputs([
            [CLASSES[0]], [CLASSES[1]], [CLASSES[2]], [CLASSES[3]], [CLASSES[4]], [CLASSES[5], CLASSES[0]],
        ])), self.assertRaisesRegex(ValueError, 'Repeated:.*WorkspaceLeaseStatusTest'):
            checker.check()

    def test_fails_if_a_shard_lists_an_unexpected_class(self):
        with patch.object(checker.subprocess, 'run', side_effect=self.outputs([
            [CLASSES[0]], [CLASSES[1]], [CLASSES[2]], [CLASSES[3]], [CLASSES[4]], [CLASSES[5], 'Tests\\Unexpected'],
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


class ShardCapacityTest(unittest.TestCase):
    def test_matrix_is_the_only_count_source_and_is_read_only(self):
        with tempfile.TemporaryDirectory() as directory, patch.object(checker, 'ROOT', Path(directory)):
            workflow = Path(directory) / '.github/workflows/ci.yml'
            workflow.parent.mkdir(parents=True)
            for count in (3, 7):
                contents = ('jobs:\n  gateway-shards:\n    strategy:\n      matrix:\n'
                            f'        shard: {list(range(1, count + 1))}\n')
                workflow.write_text(contents)
                self.assertEqual(count, checker.shard_count())
                self.assertEqual(contents, workflow.read_text())

            for matrix in ([], [1], [1, 3], [2, 1], [1, 2, 2], [True, 2], [1, '2'], [1.0, 2]):
                workflow.write_text('jobs:\n  gateway-shards:\n    strategy:\n      matrix:\n'
                                    f'        shard: {json.dumps(matrix)}\n')
                with self.subTest(matrix=matrix), self.assertRaisesRegex(ValueError, '1..count'):
                    checker.shard_count()


def read_yaml(path):
    repository = HELPER.parent.parent
    return json.loads(subprocess.run([
        'php', '-r', 'require $argv[1]; echo json_encode(Symfony\\Component\\Yaml\\Yaml::parseFile($argv[2]));',
        str(repository / 'apps/e2e/vendor/autoload.php'), str(repository / path),
    ], check=True, capture_output=True, text=True).stdout)


class NoSudoRunnerTest(unittest.TestCase):
    def test_every_ordinary_gateway_lane_requires_no_sudo_after_setup(self):
        workflow = read_yaml('.github/workflows/ci.yml')
        for lane, first_check in (('project', 'Verify Gateway shard coverage'),
                                  ('gateway-shards', 'Run Gateway shard ${{ matrix.shard }}')):
            steps = workflow['jobs'][lane]['steps']
            index = next(i for i, step in enumerate(steps) if step['name'] == first_check)
            guard = steps[index - 1]
            self.assertEqual('./.github/actions/no-sudo', guard['uses'])
            self.assertNotIn('continue-on-error', guard)
            self.assertLess(next(i for i, step in enumerate(steps)
                                 if step.get('name') == 'Install Gateway system dependencies'), index - 1)
            if lane == 'project':
                self.assertEqual("matrix.project.directory == 'apps/gateway'", guard['if'])
                redaction = next(step for step in steps if step.get('name') == 'Run redaction cases without PCRE JIT')
                self.assertIn("steps.gateway-no-sudo.outcome == 'success'", redaction['if'])
                self.assertEqual('gateway-no-sudo', guard['id'])
            else:
                self.assertNotIn('if', guard)

    def test_guard_revokes_only_hosted_sudo_and_rejects_any_remaining_access(self):
        action = read_yaml('.github/actions/no-sudo/action.yml')
        self.assertEqual('composite', action['runs']['using'])
        step, = action['runs']['steps']
        self.assertEqual('${{ runner.environment }}', step['env']['RUNNER_ENVIRONMENT'])

        # Exercise the actual step: only a hosted runner may mutate policy, and
        # a broken revocation or a sudo-enabled self-hosted runner must fail closed.
        with tempfile.TemporaryDirectory(prefix='orbit-no-sudo-step-') as directory:
            root = Path(directory)
            sudo = root / 'sudo'
            sudo.write_text('''#!/bin/sh
printf '%s\\n' "$*" >> "$CALLS"
if [ "$1" = sh ]; then
    test "$REVOKE_WORKS" = 1 && touch "$DENIED"
    exit 0
fi
test "$SUDO_WORKS" = 1 && test ! -f "$DENIED"
''')
            sudo.chmod(0o700)
            for environment, works, revokes, expected in (
                ('self-hosted', '1', '1', 1), ('self-hosted', '0', '1', 0),
                ('github-hosted', '1', '1', 0), ('github-hosted', '1', '0', 1),
            ):
                with self.subTest(environment=environment, works=works, revokes=revokes):
                    calls, denied = root / 'calls', root / 'denied'
                    calls.unlink(missing_ok=True)
                    denied.unlink(missing_ok=True)
                    env = dict(os.environ, PATH=f'{root}:/usr/bin:/bin', CALLS=str(calls),
                               DENIED=str(denied), SUDO_WORKS=works, REVOKE_WORKS=revokes,
                               RUNNER_ENVIRONMENT=environment)
                    result = subprocess.run([step['shell'], '-e', '-o', 'pipefail', '-c', step['run']],
                                            env=env, capture_output=True, text=True)
                    self.assertEqual(expected, result.returncode, result.stdout + result.stderr)
                    logged = calls.read_text()
                    self.assertEqual(environment == 'github-hosted', 'sh -c' in logged)
                    if environment == 'github-hosted':
                        self.assertIn('ALL=(ALL:ALL) !ALL', logged)
                        self.assertIn('/etc/sudoers.d/zz-orbit-no-sudo', logged)
                        self.assertIn('chmod 0440', logged)


class GatewaySelectionTest(unittest.TestCase):
    def test_only_the_shards_run_the_ordinary_gateway_suite(self):
        configuration = ET.parse(HELPER.parent.parent / 'apps/gateway/phpunit.xml')
        self.assertIn('privileged', [group.text for group in configuration.findall('./groups/exclude/group')])
        workflow = read_yaml('.github/workflows/ci.yml')
        suite = next(step for step in workflow['jobs']['project']['steps'] if step.get('name') == 'Run full test suite')
        self.assertEqual("matrix.project.directory != 'apps/gateway'", suite['if'])
        shard = next(step['run'] for step in workflow['jobs']['gateway-shards']['steps']
                     if step.get('name') == 'Run Gateway shard ${{ matrix.shard }}')
        self.assertIn('--exclude-group=privileged', shard.split())
        self.assertNotIn('if', workflow['jobs']['gateway-shards'])


if __name__ == '__main__':
    unittest.main()
