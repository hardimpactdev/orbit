"""Keep fast required CI gates and merge-queue coverage while slow jobs are paused."""
import os
import subprocess
import textwrap
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / '.github/workflows/swamp-shape.yml').read_text()


class NativeGateTest(unittest.TestCase):
    def test_merge_queue_has_required_checks_and_batch_scopes(self):
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        self.assertIn('  merge_group:\n    types: [checks_requested]', caller)
        self.assertIn('  merge_group:\n    types: [checks_requested]', SOURCE)
        self.assertIn('    name: Required checks\n    if: always()', caller)
        for source in [caller, SOURCE, (ROOT / '.github/actions/ci-scope/action.yml').read_text()]:
            self.assertNotIn('github.event.pull_request.base.sha || github.event.before', source)
            self.assertIn('github.event.merge_group.base_sha', source)
        for job in ['shape', 'validate', 'test-shards']:
            block = SOURCE.split(f'  {job}:\n')[1].split('    name:')[0]
            self.assertIn("if: github.event_name != 'merge_group' || github.workflow == 'CI'", block)
        secrets = caller.split('  swamp:\n')[1].split('  gateway-release:\n')[0]
        self.assertEqual(secrets.count("github.event_name == 'merge_group'"), 4)

    def test_slow_jobs_are_absent_from_required_ci(self):
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        self.assertNotIn('project-check', caller)
        self.assertNotIn('  native-plan:', SOURCE)
        self.assertNotIn('  native-tests:', SOURCE)
        self.assertIn('    needs: test-shards', SOURCE.split('  tests:\n')[1])

    def test_unit_aggregate_requires_success_and_rejects_every_other_result(self):
        block = SOURCE.split('  tests:\n')[1].split('  test-shards:\n')[0]
        command = textwrap.dedent(block.split('        run: |\n')[1])
        for status in ['success', 'failure', 'cancelled', 'skipped', '']:
            with self.subTest(status=status):
                result = subprocess.run(['bash', '-e', '-o', 'pipefail', '-c', command],
                    env={**os.environ, 'SHARDS_RESULT': status}, capture_output=True, text=True, check=False)
                self.assertEqual(result.returncode, 0 if status == 'success' else 1,
                                 result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main()
