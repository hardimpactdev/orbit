"""Exercise class coverage using Pest's --list-tests output format."""
import importlib.machinery
import importlib.util
from pathlib import Path
import subprocess
import sys
import unittest
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


if __name__ == '__main__':
    unittest.main()
