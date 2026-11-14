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
        # Runner internals and a non-git directory a job created must survive, even next to a broken checkout.
        for kept in ('_temp/x/y/keep', '_actions/o/r/keep', 'orbit/cache/keep'):
            (self.work / kept).parent.mkdir(parents=True)
            (self.work / kept).write_text('kept\n')
        (self.work / '_actions/o/r/.git').write_text('gitdir: elsewhere\n')

    def git(self, *args):
        subprocess.run(['git', *args], cwd=self.workspace, check=True, capture_output=True)

    def assertKept(self):
        for kept in ('_temp/x/y/keep', '_actions/o/r/keep', 'orbit/cache/keep'):
            self.assertEqual((self.work / kept).read_text(), 'kept\n', kept)

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
        self.assertKept()

    def test_empties_a_checkout_that_git_cannot_reset_and_still_succeeds(self):
        (self.workspace / '.git/HEAD').write_text('not a ref\n')
        (self.workspace / 'tracked.txt').write_text('dirty\n')
        result = self.hook()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(list(self.workspace.iterdir()), [])
        self.assertIn('emptying', result.stderr)
        self.assertKept()

    def test_empties_a_sparse_cone_checkout_with_stale_files(self):
        (self.workspace / 'sub').mkdir()
        (self.workspace / 'sub/kept.txt').write_text('in cone\n')
        (self.workspace / 'other').mkdir()
        (self.workspace / 'other/tracked.txt').write_text('outside the cone\n')
        self.git('add', '.')
        self.git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', '-c', 'commit.gpgsign=false',
                 'commit', '-qm', 'Cone file')
        self.git('sparse-checkout', 'set', '--cone', 'sub')
        (self.workspace / 'other').mkdir(exist_ok=True)
        (self.workspace / 'other/tracked.txt').write_text('stale, outside the cone\n')
        self.assertEqual(subprocess.check_output(['git', 'config', 'core.sparseCheckout'], cwd=self.workspace,
                                                 text=True).strip(), 'true')
        result = self.hook()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(list(self.workspace.iterdir()), [])
        self.assertIn('sparse', result.stderr)
        self.assertKept()

    def test_empties_a_checkout_with_skip_worktree_entries_even_without_the_config(self):
        self.git('update-index', '--skip-worktree', 'tracked.txt')
        (self.workspace / 'tracked.txt').write_text('stale\n')
        result = self.hook()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(list(self.workspace.iterdir()), [])

    def test_keeps_a_non_sparse_checkout_and_its_git_directory(self):
        result = self.hook()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue((self.workspace / '.git').is_dir())
        self.assertEqual((self.workspace / 'tracked.txt').read_text(), 'clean\n')
        self.assertEqual(result.stderr, '')

    def test_succeeds_without_a_checkout(self):
        for path in sorted(self.workspace.rglob('*'), reverse=True):
            path.unlink() if path.is_file() or path.is_symlink() else path.rmdir()
        self.workspace.rmdir()
        self.assertEqual(self.hook().returncode, 0)

    def test_setup_installs_the_hook_through_the_runner_environment(self):
        self.assertIn('ACTIONS_RUNNER_HOOK_JOB_STARTED=/home/runner/actions-runner/job-started-hook.sh', SETUP)
        self.assertIn('container | actions | hook | proof_hook | register | user_manager | verify', SETUP)
        container = SETUP.split('\ncontainer() {', 1)[1].split('\n}\n', 1)[0]
        self.assertTrue(container.rstrip().endswith('hook "$name"'))

    def test_proof_hook_installs_the_script_and_environment_idempotently(self):
        runner = self.work / 'proof-runner'
        runner.mkdir()
        (runner / '.env').write_text('KEEP=fixture\nORBIT_RUNNER_WORK=/old\n')
        shims = self.work / 'shims'
        shims.mkdir()
        sudo = shims / 'sudo'
        sudo.write_text('#!/bin/bash\nshift  # -n\n[ "$1" != -u ] || shift 2\n'
                        '[ "$1" != install ] || { shift; exec install -m 0755 "${@: -2}"; }\nexec "$@"\n')
        sudo.chmod(0o755)
        program = 'proof_hook() {' + SETUP.split('\nproof_hook() {', 1)[1].split('\n}\n', 1)[0] + '\n}\nproof_hook'
        env = {**os.environ, 'PATH': f'{shims}:{os.environ["PATH"]}', 'REPOSITORY': str(HOOK.parents[2]),
               'ORBIT_PROOF_RUNNER_DIR': str(runner)}
        for _ in range(2):
            result = subprocess.run(['bash', '-euo', 'pipefail', '-c', program], env=env, text=True,
                                    capture_output=True, check=False)
            self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual((runner / '.env').read_text(),
                         f'KEEP=fixture\nACTIONS_RUNNER_HOOK_JOB_STARTED={runner}/job-started-hook.sh\n'
                         f'ORBIT_RUNNER_WORK={runner}/_work\n')
        self.assertEqual((runner / 'job-started-hook.sh').read_text(), HOOK.read_text())


if __name__ == '__main__':
    unittest.main()
