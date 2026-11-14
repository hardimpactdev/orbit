"""A task copy's publication export of the proposed pull request body: fetched as data, or nothing.

Real Ansible/Jinja and Git on the harness in _support/task_instance_workspace.py; never live containers. Its own whole-file unit, apart
from task_copy_publish_test.py, so each file stays within one unit's bound.
"""
import importlib.util
import json
import unittest
from pathlib import Path

# The runner starts this file as `python3 -I`, which keeps its folder off the import path: load the harness by its path.
_spec = importlib.util.spec_from_file_location('task_instance_workspace_harness', Path(__file__).parent / '_support/task_instance_workspace.py')
base = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(base)


class CopyProposedBodyExportTest(unittest.TestCase):
    """The export phase with `worker_proposed_body`, on the workspace harness."""

    setUp = base.WorkspaceHarness.setUp
    git = base.WorkspaceHarness.git
    phase = base.WorkspaceHarness.phase
    publication = base.WorkspaceHarness.publication
    assert_node_scratch_removed = base.WorkspaceHarness.assert_node_scratch_removed

    def proposed(self, scratch):
        self.intent.update({'proposed_body_path': '.git/orbit/proposed-pr-bodies/task-1.md', 'worker_proposed_body': str(scratch / 'proposed-body')})

    def test_a_publication_export_fetches_the_proposed_body_of_the_task_branch_as_data(self):
        (self.checkout / '.env').write_text('APP_KEY=base64:not-a-real-key\n')
        folder = self.checkout / '.git/orbit/proposed-pr-bodies'
        folder.mkdir(parents=True)
        (folder / 'task-1.md').write_text('Summary.\n\nDocs-unaffected: docs/reference/mcp.mdx: generated.\n')
        scratch = self.publication()
        self.intent['environment_path'] = '.env'
        self.proposed(scratch)
        exported, output = self.phase('export')
        self.assertEqual((scratch / 'proposed-body').read_text(), 'Summary.\n\nDocs-unaffected: docs/reference/mcp.mdx: generated.\n')
        self.assertNotIn('Docs-unaffected', json.dumps(exported))
        self.assert_node_scratch_removed()

    def test_a_publication_export_without_a_proposed_body_or_with_a_link_there_fetches_none(self):
        for link in [False, True]:
            with self.subTest(link=link):
                self.setUp()
                (self.checkout / '.env').write_text('APP_KEY=base64:not-a-real-key\n')
                if link:
                    folder = self.checkout / '.git/orbit/proposed-pr-bodies'
                    folder.mkdir(parents=True)
                    (folder / 'task-1.md').symlink_to(self.checkout / '.env')
                scratch = self.publication()
                self.intent['environment_path'] = '.env'
                self.proposed(scratch)
                self.phase('export')
                self.assertTrue((scratch / 'candidate.bundle').exists())
                self.assertFalse((scratch / 'proposed-body').exists())

    def test_a_publication_export_refuses_a_proposed_body_path_outside_the_task_branch_file(self):
        (self.checkout / '.env').write_text('APP_KEY=base64:not-a-real-key\n')
        scratch = self.publication()
        self.intent['environment_path'] = '.env'
        self.proposed(scratch)
        self.assertIn('tasks.workspace_target_invalid', self.phase('export', intent=dict(self.intent, proposed_body_path='.env'), ok=False))


if __name__ == "__main__":
    unittest.main()
