"""bin/task-check catches each real failure that CI caught after a task's own checks passed.

Each case clones this checkout into a scratch directory, commits the current bin/task-check as the start commit,
applies one change set as the task's commit, and runs the part that must fail. The PHP cases copy the vendor folder
of their project, so they run in a seeded checkout such as a task copy: python3 tools/test_task_check.py. CI does not
run them; tools/test_ci_task_check.py holds the cases that need no vendor folder."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDENTITY = {'GIT_AUTHOR_NAME': 'test', 'GIT_AUTHOR_EMAIL': 'test@orbit.invalid',
            'GIT_COMMITTER_NAME': 'test', 'GIT_COMMITTER_EMAIL': 'test@orbit.invalid'}


class TaskCheckFailureTest(unittest.TestCase):
    def setUp(self):
        self.scratch = Path(tempfile.mkdtemp(prefix='task-check-test-'))
        self.addCleanup(shutil.rmtree, self.scratch, ignore_errors=True)
        self.repo = self.scratch / 'repo'
        subprocess.run(['git', 'clone', '--quiet', '--shared', str(ROOT), str(self.repo)], check=True)
        subprocess.run(['git', '-C', str(self.repo), 'checkout', '--quiet', '--detach',
                        subprocess.check_output(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], text=True).strip()], check=True)
        # The start commit holds the command under test, so only the change set differs from it.
        shutil.copy2(ROOT / 'bin/task-check', self.repo / 'bin/task-check')
        self.start = self.commit('start')

    def git(self, *args):
        return subprocess.run(['git', '-C', str(self.repo), *args], check=True, capture_output=True, text=True,
                              env={**os.environ, **IDENTITY}).stdout.strip()

    def commit(self, message):
        self.git('add', '--all')
        self.git('commit', '--quiet', '--allow-empty', '-m', message)
        return self.git('rev-parse', 'HEAD')

    def vendor(self, project):
        source = ROOT / project / 'vendor'
        if not source.is_dir():
            self.fail(f'{project}/vendor is missing: run this test in a seeded checkout')
        # A copy, not a link: Composer's autoloader resolves links and would load the classes of this checkout.
        subprocess.run(['cp', '-a', str(source), str(self.repo / project / 'vendor')], check=True)

    def change(self, path, old, new):
        file = self.repo / path
        text = file.read_text()
        self.assertIn(old, text, f'{path} changed; update the change set')
        file.write_text(text.replace(old, new, 1))

    def check(self, part, commit=True):
        """Run the command after the change set; return the summary line of each part and the whole output."""
        if commit:
            self.commit('change set')
        result = subprocess.run(['python3', str(self.repo / 'bin/task-check'), '--base', self.start, '--only', part],
                                cwd=self.repo, capture_output=True, text=True, timeout=280)
        lines = result.stdout[result.stdout.rindex('Task check summary'):].splitlines()
        # Each part's line is its status, padded to twelve characters, then its name and seconds.
        summary = {line[15:].split(' (')[0]: line[2:14].strip() for line in lines[1:] if line.startswith('  ')}
        return result.returncode, summary, result.stdout

    def assertCaught(self, part, outcome, *evidence):
        code, summary, output = outcome
        self.assertEqual(code, 1, output)
        self.assertEqual(summary.get(part), 'FAILED', output)
        self.assertEqual(summary.get('ci-plan'), 'passed', output)
        for text in evidence:
            self.assertIn(text, output)
        self.assertIn(f'Task check FAILED against {self.start[:12]}', output.splitlines()[-1])

    def test_sdk_pest_from_3713(self):
        # The SDK request changed its body; its Pest test still states the earlier body.
        self.vendor('packages/php-sdk')
        self.change('packages/php-sdk/src/Requests/Tasks/CancelTaskRequest.php',
                    "json_encode($this->discard ? ['discard' => true] : new \\stdClass, JSON_THROW_ON_ERROR)",
                    "json_encode(['discard' => $this->discard], JSON_THROW_ON_ERROR)")
        # No test names the request's class by a rule of this command: the whole SDK suite runs.
        self.assertCaught('pest packages/php-sdk', self.check('pest'), 'TaskRequestsTest')

    def test_strict_docs_lint_from_3713(self):
        # A long sentence is a warning, and strict docs-lint fails on a warning.
        self.vendor('apps/docs')
        self.change('docs/reference/tasks.md', '### Task copy\n\n',
                    '### Task copy\n\nA cancel of a task copy reads the copy first and counts every commit that a succeeded publish '
                    'of the task pushed as on origin, together with its history, so a published copy with no later work reads '
                    'clean and cancels without the discard flag that a dirty copy needs.\n\n')
        self.assertCaught('docs-lint', self.check('docs-lint'), 'librarian.document_complexity', 'docs/reference/tasks.md')

    def test_phpstan_from_3715(self):
        self.vendor('packages/php-sdk')
        self.change('packages/php-sdk/src/Requests/Tasks/CancelTaskRequest.php',
                    'return "/api/v1/tasks/{$this->groupId}/cancel";', 'return $this->groupId;')
        self.assertCaught('phpstan packages/php-sdk', self.check('phpstan'), 'resolveEndpoint()')

    def test_stale_fixture_case_population_from_3715(self):
        # A new Deno case without its name in the fixture's stored cases: CI fails the unit before any case runs.
        file = 'swamp/tests/maintenance_resources_test.ts'
        with (self.repo / file).open('a') as source:
            source.write('\nDeno.test("a case the stored population lacks", () => {});\n')
        self.assertCaught('fixture-population', self.check('fixture-population'), file,
                          'not stored: ["a case the stored population lacks"]')

    def test_check_flows_from_3715(self):
        # A step whose text is longer than a chart line holds.
        with (self.repo / 'docs/cli/task/cancel.mmd').open('a') as chart:
            chart.write('  GW->>GH: Read the unpublished work of the copy and its stashes\n')
        self.assertCaught('check-flows', self.check('check-flows'), 'task/cancel.mmd', 'text too long')

    def test_an_uncommitted_swamp_file_that_breaks_a_shape_rule(self):
        # Orbit runs the task check before it commits. The Swamp checkers list tracked files only, so the new
        # workflow is checked only in a checkout of the candidate commit.
        source = (self.repo / 'swamp/workflows/workflow-cluster-router-select.yaml').read_text()
        self.assertIn('workflowIdOrName: route-dns-grace\n', source)
        (self.repo / 'swamp/workflows/workflow-task-check-untracked.yaml').write_text(
            source.replace('id: 3e352398-69b5-5d9f-bde5-169d23c088ee', 'id: 3e352398-69b5-5d9f-bde5-169d23c08800')
            .replace('name: cluster-router-select', 'name: task-check-untracked')
            .replace('workflowIdOrName: route-dns-grace\n', 'workflowIdOrName: route-dns-grace-missing\n'))
        self.assertCaught('swamp-shape', self.check('swamp-shape', commit=False), 'unknown-nested-workflow')
        self.assertEqual(self.git('worktree', 'list', '--porcelain').count('worktree '), 1)


if __name__ == '__main__':
    unittest.main()
