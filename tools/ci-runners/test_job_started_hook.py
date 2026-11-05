"""Exercise the runner job-started hook against a dirty checkout and the setup step that installs it."""
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

HOOK = Path(__file__).with_name('job-started-hook.sh')
SETUP = Path(__file__).with_name('setup.sh').read_text()


class JobStartedHookTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.work = Path(temporary.name)
        self.workspace = self.work / 'orbit/orbit'
        self.workspace.mkdir(parents=True)
        self.git('init', '-q')
        (self.workspace / 'tracked.txt').write_text('clean\n')
        self.git('add', '.')
        self.git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', '-c', 'commit.gpgsign=false',
                 'commit', '-qm', 'Tracked file')
        (self.work / '_temp').mkdir()
        (self.work / '_temp/keep').write_text('runner internals\n')

    def git(self, *args):
        subprocess.run(['git', *args], cwd=self.workspace, check=True, capture_output=True)

    def hook(self):
        return subprocess.run(['bash', str(HOOK)], env={**os.environ, 'ORBIT_RUNNER_WORK': str(self.work)},
                              text=True, capture_output=True, check=False)

    def test_resets_modified_tracked_and_untracked_files_but_not_runner_internals(self):
        (self.workspace / 'tracked.txt').write_text('dirty\n')
        (self.workspace / 'leftover.txt').write_text('untracked\n')
        result = self.hook()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual((self.workspace / 'tracked.txt').read_text(), 'clean\n')
        self.assertFalse((self.workspace / 'leftover.txt').exists())
        self.assertEqual((self.work / '_temp/keep').read_text(), 'runner internals\n')

    def test_empties_a_checkout_that_git_cannot_reset_and_still_succeeds(self):
        (self.workspace / '.git/HEAD').write_text('not a ref\n')
        (self.workspace / 'tracked.txt').write_text('dirty\n')
        result = self.hook()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(list(self.workspace.iterdir()), [])
        self.assertIn('emptying', result.stderr)

    def test_succeeds_without_a_checkout(self):
        for path in sorted(self.workspace.rglob('*'), reverse=True):
            path.unlink() if path.is_file() or path.is_symlink() else path.rmdir()
        self.workspace.rmdir()
        self.assertEqual(self.hook().returncode, 0)

    def test_setup_installs_the_hook_through_the_runner_environment(self):
        self.assertIn('ACTIONS_RUNNER_HOOK_JOB_STARTED=/home/runner/actions-runner/job-started-hook.sh', SETUP)
        self.assertIn('container | actions | hook | register | verify', SETUP)
        container = SETUP.split('\ncontainer() {', 1)[1].split('\n}\n', 1)[0]
        self.assertTrue(container.rstrip().endswith('hook "$name"'))


if __name__ == '__main__':
    unittest.main()
