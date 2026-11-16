"""After the handoff check of a copy merged the default head, deliverables compare with the start plus that head.

Its own file: the check file holds no headroom for another case within the whole-file bound."""
import runpy
import subprocess
import unittest
from pathlib import Path

SUPPORT = runpy.run_path(str(Path(__file__).parent / "_support/task_instance_workspace.py"))


class TaskInstanceCheckMergeBaseTest(SUPPORT["CheckHarness"]):
    def test_after_a_merge_of_main_the_deliverable_diff_is_the_subtasks_own(self):
        # The handoff merged a main that added a file of its own; the working tree holds it as the merge left it.
        (self.root / 'main-only.txt').write_text('main\n')
        blob = self.git('hash-object', '-w', str(self.root / 'main-only.txt'))
        listing = self.git('ls-tree', self.base) + f'\n100644 blob {blob}\tmain-only.txt\n'
        tree = subprocess.run(['git', 'mktree'], cwd=self.checkout, input=listing, capture_output=True, text=True, check=True).stdout.strip()
        main = self.git('commit-tree', tree, '-p', self.base, '-m', 'main moved')
        (self.checkout / 'main-only.txt').write_text('main\n')
        self.run_phase('check', self.ansible_intent(merged_default=main))
        diff = self.evidence('check')['check']['steps']['diff']['outputs']['stdout']
        self.assertIn('app.txt', diff)
        self.assertNotIn('main-only.txt', diff)


if __name__ == '__main__':
    unittest.main()
