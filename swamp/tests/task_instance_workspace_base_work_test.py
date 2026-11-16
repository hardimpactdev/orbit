"""The content read at the end of a copy's base (docs/reference/tasks.md#published-work-of-a-copy): the copy's committed work is
published when the default head holds the same content at every path HEAD changed since their merge base, whatever the commit ids.
Uncommitted and untracked work never is."""
import runpy
import unittest
from pathlib import Path

HARNESS = runpy.run_path(str(Path(__file__).parent / '_support/task_instance_workspace_base.py'))


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

    def test_a_squash_merged_branch_reads_clean(self):
        default = self.land({'work.txt': 'agent work\n'})
        self.tidy()
        work = self.phase('base')['work']
        self.assertEqual(work, {'state': 'clean', 'head': self.work, 'default_commit': default, 'empty': False,
                                'paths': {'count': 0, 'names': []}, 'uncommitted': 0, 'untracked': 0, 'stashes': 0,
                                'approved_held': None})
        self.assertEqual(self.evidence('base')['work']['state'], 'clean')

    def test_an_empty_handoff_commit_adds_nothing(self):
        self.land({'work.txt': 'agent work\n'})
        self.tidy()
        self.git('commit', '--quiet', '--allow-empty', '-m', 'Task check handoff')
        work = self.phase('base')['work']
        self.assertEqual((work['state'], work['empty'], work['paths']['count']), ('clean', False, 0))

    def test_one_path_that_differs_reads_dirty(self):
        self.land({'work.txt': 'a reviewer changed it\n'})
        self.tidy()
        work = self.phase('base')['work']
        self.assertEqual((work['state'], work['paths']), ('dirty', {'count': 1, 'names': ['work.txt']}))

    def test_a_delete_on_main_of_a_file_the_copy_also_deleted_is_equal(self):
        self.tidy()
        self.git('rm', '--quiet', 'seed.txt')
        self.git('commit', '--quiet', '-m', 'Remove the seed')
        self.land({'work.txt': 'agent work\n', 'seed.txt': None})
        work = self.phase('base')['work']
        self.assertEqual((work['state'], work['paths']['count']), ('clean', 0))

    def test_uncommitted_work_stays_dirty(self):
        self.land({'work.txt': 'agent work\n'})
        work = self.phase('base')['work']
        # work.txt is changed and untracked.txt is staged: both uncommitted, though every committed path is on main.
        self.assertEqual((work['state'], work['paths']['count'], work['uncommitted']), ('dirty', 0, 2))

    def test_an_untracked_file_and_a_stash_stay_dirty(self):
        self.land({'work.txt': 'agent work\n'})
        self.git('stash', '--quiet')
        (self.checkout / 'new.txt').write_text('untracked\n')
        work = self.phase('base')['work']
        self.assertEqual((work['state'], work['uncommitted'], work['untracked'], work['stashes']), ('dirty', 0, 1, 1))

    def test_a_published_commit_counts_and_holds_the_approved_commit(self):
        self.tidy()
        work = self.phase('base', {**self.intent, 'published': [self.work], 'approved': self.work})['work']
        self.assertEqual((work['state'], work['approved_held']), ('clean', True))
        unpublished = self.phase('base', {**self.intent, 'approved': self.head})['work']
        self.assertEqual((unpublished['state'], unpublished['paths']['names'], unpublished['approved_held']), ('dirty', ['work.txt'], False))

    def test_a_copy_that_changed_nothing_is_empty(self):
        self.tidy()
        self.git('reset', '--quiet', '--hard', self.seed)
        self.git('commit', '--quiet', '--allow-empty', '-m', 'Task check handoff')
        work = self.phase('base')['work']
        self.assertEqual((work['state'], work['empty']), ('clean', True))


if __name__ == '__main__':
    unittest.main()
