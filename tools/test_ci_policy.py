"""Keep fast required CI gates while slow jobs are paused."""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / '.github/workflows/swamp-shape.yml').read_text()


class RequiredGateTest(unittest.TestCase):
    def test_there_is_no_merge_queue_trigger_or_handling(self):
        # PRs merge straight into main after their gate (2026-11-05); no workflow, action or job reads a queue event.
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        scope = (ROOT / '.github/actions/ci-scope/action.yml').read_text()
        self.assertIn('    name: Required checks\n    if: always()', caller)
        for source in [caller, SOURCE, scope]:
            self.assertNotIn('merge_group', source)
            self.assertIn('github.event.pull_request.base.sha || github.event.before', source)

    def test_run_on_main_builds_and_publishes_the_release_web_build(self):
        # Required checks pass in the run on main, so that run must hold the Gateway release's web build.
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        for source in [caller.split('  changes:\n')[1].split('\n  swamp:\n')[0], (ROOT / '.github/actions/ci-scope/action.yml').read_text()]:
            self.assertIn("MAIN: ${{ github.ref == 'refs/heads/main' }}", source)
        publish = caller.split('      - name: Publish web build\n')[1].split('\n')[0]
        self.assertEqual(publish, "        if: steps.scope.outputs.selected == 'true' && github.event_name != 'pull_request' && github.ref == 'refs/heads/main'")

    def test_slow_jobs_are_absent_from_required_ci(self):
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        self.assertNotIn('project-check', caller)
        self.assertNotIn('  native-plan:', SOURCE)
        self.assertNotIn('  native-tests:', SOURCE)

    def test_units_use_one_pool_job_with_deno_parallelism(self):
        block = SOURCE.split('  tests:\n')[1]
        self.assertNotIn('  test-shards:', SOURCE)
        self.assertNotIn('matrix:', block)
        self.assertNotIn('needs:', block)
        self.assertNotIn('continue-on-error', block)
        self.assertIn('DENO_JOBS: "4"', block)
        self.assertIn("deno test --parallel -A extensions/behaviors/operations", block)


if __name__ == '__main__':
    unittest.main()
