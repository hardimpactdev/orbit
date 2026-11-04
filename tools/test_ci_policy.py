"""Keep fast required CI gates and merge-queue coverage while slow jobs are paused."""
import unittest
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / '.github/workflows/swamp-shape.yml').read_text()


class RequiredGateTest(unittest.TestCase):
    def test_merge_queue_has_required_checks_and_batch_scopes(self):
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        self.assertIn('  merge_group:\n    types: [checks_requested]', caller)
        self.assertIn('  merge_group:\n    types: [checks_requested]', SOURCE)
        self.assertIn('    name: Required checks\n    if: always()', caller)
        for source in [caller, SOURCE, (ROOT / '.github/actions/ci-scope/action.yml').read_text()]:
            self.assertNotIn('github.event.pull_request.base.sha || github.event.before', source)
            self.assertIn('github.event.merge_group.base_sha', source)
        for job in ['shape', 'validate', 'tests']:
            block = SOURCE.split(f'  {job}:\n')[1].split('    name:')[0]
            self.assertIn("if: github.event_name != 'merge_group' || github.workflow == 'CI'", block)
        secrets = caller.split('  swamp:\n')[1].split('  gateway-release:\n')[0]
        self.assertEqual(secrets.count("github.event_name == 'merge_group'"), 2)

    def test_merge_queue_run_for_main_builds_and_publishes_the_release_web_build(self):
        # Required checks pass in the merge queue run first, so it must hold the Gateway release's web build.
        main = "github.ref == 'refs/heads/main' || github.event.merge_group.base_ref == 'refs/heads/main'"
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        for source in [caller.split('  changes:\n')[1].split('\n  swamp:\n')[0], (ROOT / '.github/actions/ci-scope/action.yml').read_text()]:
            self.assertIn(f'MAIN: ${{{{ {main} }}}}', source)
        publish = caller.split('      - name: Publish web build\n')[1].split('\n')[0]
        self.assertEqual(publish, f"        if: steps.scope.outputs.selected == 'true' && github.event_name != 'pull_request' && ({main})")

    def test_registry_credentials_allow_only_existing_events_and_same_repo_handoffs(self):
        repository = 'hardimpactdev/orbit'
        cases = [
            ('pull_request', 'refs/pull/42/merge', repository, True),
            ('merge_group', 'refs/heads/gh-readonly-queue/main/pr-42', repository, True),
            ('push', 'refs/heads/main', repository, True),
            ('push', 'refs/heads/task-3187-handoff', repository, True),
            ('push', 'refs/heads/task-3187-handoff', 'other/fork', False),
            ('push', 'refs/heads/task-3187-handoff', '', False),
            ('push', 'refs/heads/arbitrary', repository, False),
            ('push', 'refs/heads/task-3187', repository, False),
            ('push', 'refs/heads/other-handoff', repository, False),
            ('push', 'refs/heads/task-3187-handoff-extra', repository, False),
            ('push', 'refs/tags/task-3187-handoff', repository, False),
            ('workflow_dispatch', 'refs/heads/task-3187-handoff', repository, False),
            ('workflow_dispatch', 'refs/heads/main', repository, False),
            ('pull_request_target', 'refs/heads/main', repository, False),
            ('schedule', 'refs/heads/main', repository, None),
        ]
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        self.assertIn("branches: [main, 'task-*-handoff']", caller)
        for workflow, source, count in [('ci.yml', caller, 2), ('swamp-shape.yml', SOURCE, 4)]:
            # Evaluate the actual event predicates, never the secret expressions or values.
            guards = re.findall(r"SWAMP_(?:API_KEY|SIGNIN_TOKEN): \$\{\{ \((.*?)\) && secrets\.", source)
            self.assertEqual(len(guards), count, workflow)
            for guard in guards:
                for event, ref, event_repository, expected in cases:
                    with self.subTest(workflow=workflow, guard=guard, event=event, ref=ref,
                                      repository=event_repository):
                        values = {'github.event_name': event, 'github.ref': ref,
                                  'github.repository': repository,
                                  'github.event.repository.full_name': event_repository}
                        predicate = re.sub(r'github\.[\w.]+', lambda match: repr(values[match[0]]), guard)
                        predicate = predicate.replace('&&', ' and ').replace('||', ' or ')
                        allowed = eval(predicate, {'__builtins__': {}}, {
                            'startsWith': lambda value, prefix: value.startswith(prefix),
                            'endsWith': lambda value, suffix: value.endswith(suffix),
                        })
                        self.assertEqual(allowed, workflow == 'swamp-shape.yml' if expected is None else expected)

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
