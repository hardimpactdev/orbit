"""Real workspace proof; independent file with the shared, test-free harness."""
import runpy
import unittest
from pathlib import Path

SUPPORT = runpy.run_path(str(Path(__file__).parent / "_support/task_instance_workspace.py"))


class TaskInstanceWorkspaceTest(SUPPORT["WorkspaceHarness"]):
    def test_snapshots_include_force_added_ignored_work_without_changing_index(self):
        path = self.checkout / 'ignored/staged-user.txt'
        path.write_text('original user staged data\n')
        self.git('add', '-f', 'ignored/staged-user.txt')
        index = (self.checkout / '.git/index').read_bytes()
        before = self.prepare()
        self.assertEqual(self.git('show', before['tree'] + ':ignored/staged-user.txt'),
                         'original user staged data')
        self.assertEqual((self.checkout / '.git/index').read_bytes(), index)
        path.write_text('later unstaged data\n')
        after, _ = self.phase('after')
        self.assertEqual(self.git('show', after['tree'] + ':ignored/staged-user.txt'), 'later unstaged data')
        self.assertEqual(self.git('show', after['index_tree'] + ':ignored/staged-user.txt'),
                         'original user staged data')
        self.assertEqual((self.checkout / '.git/index').read_bytes(), index)
        self.assertEqual(path.read_text(), 'later unstaged data\n')

    def test_immutable_after_evidence_and_discard_leave_checkout_unchanged(self):
        self.prepare()
        (self.checkout / 'base.txt').write_text('turn edit\n')
        (self.checkout / 'link').unlink()
        (self.checkout / 'link').symlink_to('staged.txt')
        (self.checkout / ':(glob)*.txt').write_text('literal filename\n')
        self.git('add', '--all')
        index = (self.checkout / '.git/index').read_bytes()
        after, output = self.phase('after')
        self.assertEqual(after['before_digest'], self.intent['before_digest'])
        self.assertEqual(self.git('show', after['tree'] + ':base.txt'), 'turn edit')
        self.assertEqual(self.git('show', after['tree'] + ':link'), 'staged.txt')
        self.assertEqual(self.git('show', after['tree'] + '::(glob)*.txt'), 'literal filename')
        self.assertIn('changed=0', output)
        (self.checkout / 'base.txt').write_text('later user edit\n')
        status = self.git('status', '--porcelain=v1', '-uall')
        repeated, output = self.phase('after')
        self.assertEqual(repeated, after)
        self.assertIn('changed=0', output)
        self.assertEqual((self.checkout / '.git/index').read_bytes(), index)
        for _ in range(2):
            value, output = self.phase('discard')
            self.assertEqual(value, {'discarded': True, 'changed': False})
            self.assertIn('changed=0', output)
            self.assertFalse(self.scratch.exists())
            self.assertEqual((self.checkout / '.git/index').read_bytes(), index)
            self.assertEqual(self.git('status', '--porcelain=v1', '-uall'), status)
            self.assertEqual((self.checkout / 'base.txt').read_text(), 'later user edit\n')
            self.assertEqual((self.checkout / 'ignored/user.txt').read_text(), 'preserved ignored data\n')


if __name__ == "__main__":
    unittest.main()
