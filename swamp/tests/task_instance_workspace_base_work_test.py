"""The content read at the end of a copy's base (docs/reference/tasks.md#published-work-of-a-copy): the copy's committed work is
published when the default head holds the same content at every path HEAD changed since their merge base, whatever the commit ids.
Uncommitted and untracked work never is.

One case runs the whole base phase through real Ansible. The others run the same production guest program, as the Incus shim runs it
in the checkout, on a default head brought in as the base's import brings it: each Ansible run costs seconds, the program a fraction."""
import json
import runpy
import subprocess
import sys
import unittest
from pathlib import Path

import yaml

HARNESS = runpy.run_path(str(Path(__file__).parent / '_support/task_instance_workspace_base.py'))
PROGRAM = next(task for task in yaml.safe_load(HARNESS['TASKS'].read_text())
               if task.get('name') == 'Name the guest boundary')['ansible.builtin.set_fact']['workspace_guest_program']


class TaskInstanceWorkspaceBaseWorkTest(HARNESS['BaseHarness']):
    def land(self, changes, message='squash'):
        """One commit on origin's main, as a squash merge or a hand-carry makes it: a file's content, or None to delete it."""
        work = self.root / 'work'
        self.git('pull', '--quiet', '--ff-only', 'origin', 'main', cwd=work)
        for name, content in changes.items():
            if content is None:
                self.git('rm', '--quiet', name, cwd=work)
            else:
                (work / name).write_text(content)
                self.git('add', name, cwd=work)
        self.git('commit', '--quiet', '-m', message, cwd=work)
        self.git('push', '--quiet', 'origin', 'HEAD:main', cwd=work)
        self.git('update-server-info', cwd=self.origin)
        return self.git('rev-parse', 'HEAD', cwd=work)

    def tidy(self):
        """The copy without the harness's uncommitted and untracked work."""
        self.git('reset', '--quiet', '--hard')
        (self.checkout / 'untracked.txt').unlink(missing_ok=True)

    def read_work(self, **intent):
        """The guest program's base-work read, after origin's main came in as refs/remotes/origin/main, as the base's import does."""
        self.git('fetch', '--quiet', '--no-tags', str(self.origin), '+refs/heads/main:refs/remotes/origin/main')
        done = subprocess.run([sys.executable, '-I', '-c', PROGRAM, 'base-work', json.dumps({**self.intent, **intent})],
                              cwd=self.checkout, capture_output=True, text=True, check=False, timeout=60)
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        return json.loads(done.stdout)

    def test_a_squash_merged_branch_reads_clean(self):
        default = self.land({'work.txt': 'agent work\n'})
        self.tidy()
        work = self.phase('base')['work']
        self.assertEqual(work, {'state': 'clean', 'head': self.work, 'default_commit': default, 'empty': False,
                                'paths': {'count': 0, 'names': []}, 'uncommitted': 0, 'untracked': 0, 'stashes': 0,
                                'approved_held': None})

    def test_an_empty_handoff_commit_adds_nothing(self):
        self.land({'work.txt': 'agent work\n'})
        self.tidy()
        self.git('commit', '--quiet', '--allow-empty', '-m', 'Task check handoff')
        work = self.read_work()
        self.assertEqual((work['state'], work['empty'], work['paths']['count']), ('clean', False, 0))

    def test_one_path_that_differs_reads_dirty(self):
        self.land({'work.txt': 'a reviewer changed it\n'})
        self.tidy()
        work = self.read_work()
        self.assertEqual((work['state'], work['paths']), ('dirty', {'count': 1, 'names': ['work.txt']}))

    def test_a_delete_on_main_of_a_file_the_copy_also_deleted_is_equal(self):
        self.tidy()
        self.git('rm', '--quiet', 'seed.txt')
        self.git('commit', '--quiet', '-m', 'Remove the seed')
        self.land({'work.txt': 'agent work\n', 'seed.txt': None})
        work = self.read_work()
        self.assertEqual((work['state'], work['paths']['count']), ('clean', 0))

    def test_uncommitted_work_stays_dirty(self):
        self.land({'work.txt': 'agent work\n'})
        work = self.read_work()
        # work.txt is changed and untracked.txt is staged: both uncommitted, though every committed path is on main.
        self.assertEqual((work['state'], work['paths']['count'], work['uncommitted']), ('dirty', 0, 2))

    def test_an_untracked_file_and_a_stash_stay_dirty(self):
        self.land({'work.txt': 'agent work\n'})
        self.git('stash', '--quiet')
        (self.checkout / 'new.txt').write_text('untracked\n')
        work = self.read_work()
        self.assertEqual((work['state'], work['uncommitted'], work['untracked'], work['stashes']), ('dirty', 0, 1, 1))

    def test_a_published_commit_counts_and_holds_the_approved_commit(self):
        self.tidy()
        work = self.read_work(published=[self.work], approved=self.work)
        self.assertEqual((work['state'], work['approved_held']), ('clean', True))
        unpublished = self.read_work(approved=self.head)
        self.assertEqual((unpublished['state'], unpublished['paths']['names'], unpublished['approved_held']),
                         ('dirty', ['work.txt'], False))

    def test_a_copy_that_changed_nothing_is_empty(self):
        self.tidy()
        self.git('reset', '--quiet', '--hard', self.seed)
        self.git('commit', '--quiet', '--allow-empty', '-m', 'Task check handoff')
        work = self.read_work()
        self.assertEqual((work['state'], work['empty']), ('clean', True))


if __name__ == '__main__':
    unittest.main()
