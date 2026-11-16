"""After the handoff check of a copy merged the default head, deliverables compare with the start plus that head.

Its own file: the check file holds no headroom for another case within the whole-file bound."""
import runpy
import subprocess
import unittest
from pathlib import Path

SUPPORT = runpy.run_path(str(Path(__file__).parent / "_support/task_instance_workspace.py"))


class TaskInstanceCheckMergeBaseTest(SUPPORT["CheckHarness"]):
    def main_moved(self):
        """A default head M on the start that adds a file of its own; the working tree holds it as a merge of M left it."""
        (self.root / 'main-only.txt').write_text('main\n')
        blob = self.git('hash-object', '-w', str(self.root / 'main-only.txt'))
        listing = self.git('ls-tree', self.base) + f'\n100644 blob {blob}\tmain-only.txt\n'
        tree = subprocess.run(['git', 'mktree'], cwd=self.checkout, input=listing, capture_output=True, text=True, check=True).stdout.strip()
        main = self.git('commit-tree', tree, '-p', self.base, '-m', 'main moved')
        (self.checkout / 'main-only.txt').write_text('main\n')
        return main, tree

    def test_after_a_merge_of_main_the_deliverable_diff_is_the_subtasks_own(self):
        main, _tree = self.main_moved()
        self.run_phase('check', self.ansible_intent(default_commit=main))
        diff = self.evidence('check')['check']['steps']['diff']['outputs']['stdout']
        self.assertIn('app.txt', diff)
        self.assertNotIn('main-only.txt', diff)


    def test_a_recheck_whose_head_already_holds_main_compares_with_the_start_plus_that_head(self):
        # An earlier check, or a task:sync and a hand merge, left HEAD a merge of M: this run's base-merge has nothing to merge.
        main, tree = self.main_moved()
        merge = self.git('commit-tree', tree, '-p', self.base, '-p', main, '-m', 'Merge main before the check')
        self.git('update-ref', 'HEAD', merge)
        self.steps = {'prepare': {'outputs': {'valid': True}}, 'merge input': {'status': 'succeeded'},
                      'merged': {'outputs': {'evidence': {'default_commit': main, 'merged_default': None, 'changed': False}}}}
        self.assertFalse(self.cel('input', 'guard'))
        intent = self.write_input('input')
        # The production step passes the head HEAD holds, not only a head this run merged.
        self.assertEqual(intent, self.ansible_intent(default_commit=main))
        self.run_phase('check', intent)
        diff = self.evidence('check')['check']['steps']['diff']['outputs']['stdout']
        self.assertIn('app.txt', diff)
        self.assertNotIn('main-only.txt', diff)
        # A conflict, or no base, passes no head: the check keeps the bare start.
        self.steps['merged'] = {'outputs': {'evidence': {'error_code': 'tasks.check_conflict', 'default_commit': main,
                                                         'conflicting_paths': ['a'], 'conflicting_more': 0, 'changed': False}}}
        self.assertEqual(self.cel('input', 'intent')['default_commit'], '')


if __name__ == '__main__':
    unittest.main()
