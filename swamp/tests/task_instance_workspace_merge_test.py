"""The handoff check of a copy merges the default head it brought in when clean, and refuses a conflict with the tree untouched.

The base phase that brings the head in has its own tests (task_instance_workspace_base_test.py); these start from its result."""
import json
import runpy
import subprocess
import unittest
from pathlib import Path

HARNESS = runpy.run_path(str(Path(__file__).parent / '_support/task_instance_workspace_base.py'))
SUPPORT = HARNESS['SUPPORT']
CHECK = HARNESS['ROOT'] / 'workflows/workflow-task-instance-check.yaml'
MESSAGE = 'Implement the change\n'


class TaskInstanceWorkspaceMergeTest(HARNESS['BaseHarness']):
    def merge(self, ok=True):
        return self.phase('base-merge', {**self.intent, 'message': MESSAGE}, ok)

    def bring_in(self):
        """What the base phase leaves, which its own tests prove: the default head in refs/remotes/origin/main, nothing else."""
        self.git('fetch', '--quiet', '--no-tags', str(self.origin), '+refs/heads/main:refs/remotes/origin/main')

    def main_changes(self, name, content):
        """A new default head on origin that changes `name`; the next base brings it in."""
        work = self.root / 'work'
        (work / name).parent.mkdir(parents=True, exist_ok=True)
        self.head = self.commit(work, name, content)
        self.git('push', '--quiet', 'origin', 'HEAD:main', cwd=work)
        self.git('update-server-info', cwd=self.origin)

    def worktree(self):
        """The tree of the working tree, uncommitted and untracked work included, as the receipt's `bin/task-check` reads it."""
        return runpy.run_path(str(HARNESS['ROOT'].parent / 'bin/review-check'))['working_tree'](self.checkout)

    def test_a_clean_merge_commits_the_uncommitted_work_first_then_merges_the_head(self):
        self.bring_in()
        result = self.merge()
        self.assertEqual(self.evidence('base-merge'), result)
        commit = self.git('rev-parse', 'HEAD')
        # The tree of the work as the implementer handed it off, uncommitted work included: the tree its delivery receipt names.
        first, second = self.git('rev-parse', 'HEAD^1'), self.git('rev-parse', 'HEAD^2')
        self.assertEqual(result, {'handed_head': self.work, 'handed_tree': self.git('rev-parse', first + '^{tree}'), 'default_commit': self.head, 'merged_default': self.head,
                                  'commit': commit, 'changed': True})
        self.assertEqual(self.git('symbolic-ref', 'HEAD'), 'refs/heads/task-42')
        self.assertEqual(second, self.head)
        # The uncommitted and the staged work are a commit on the agent's own, with the check's message: nothing is lost.
        self.assertEqual(self.git('rev-parse', first + '^'), self.work)
        self.assertEqual(self.git('show', first + ':work.txt'), 'uncommitted')
        self.assertEqual(self.git('show', first + ':untracked.txt'), 'untracked')
        self.assertEqual(self.git('log', '-1', '--format=%B', first), MESSAGE.strip())
        self.assertEqual(self.git('log', '-1', '--format=%s', 'HEAD'), f'Merge main {self.head} before the check')
        self.assertEqual(self.git('log', '-1', '--format=%an <%ae> %cn <%ce>', 'HEAD'),
                         'Orbit <orbit@users.noreply.github.com> Orbit <orbit@users.noreply.github.com>')
        self.assertEqual(self.git('status', '--porcelain'), '')
        self.assertEqual(self.git('rev-list', '--count', 'HEAD..refs/remotes/origin/main'), '0')

    def test_a_branch_that_holds_the_head_merges_nothing(self):
        self.bring_in()
        merged = self.merge()['commit']
        before = self.state()
        again = self.merge()
        self.assertEqual(again, {'handed_head': merged, 'handed_tree': self.git('rev-parse', merged + '^{tree}'), 'default_commit': self.head, 'merged_default': None, 'changed': False})
        self.assertEqual(self.evidence('base-merge'), again)
        self.assertEqual(self.state(), before)

    def test_a_conflict_names_the_paths_and_leaves_the_workspace_exactly_as_it_was(self):
        self.main_changes('work.txt', 'main changed it\n')
        self.bring_in()
        before = self.state()
        objects_before = self.git('for-each-ref')
        work = self.worktree()
        result = self.merge()
        self.assertEqual(result, {'error_code': 'tasks.check_conflict', 'handed_head': self.work, 'handed_tree': work, 'default_commit': self.head, 'conflicting_paths': ['work.txt'],
                                  'conflicting_more': 0, 'changed': False})
        # The typed reader keeps the paths: the plain refusal shape would drop them.
        self.assertEqual(self.evidence('base-merge'), result)
        self.assertEqual(self.state(), before)
        self.assertEqual(self.git('for-each-ref'), objects_before)

    def test_a_merge_that_would_overwrite_an_ignored_file_refuses_and_changes_nothing(self):
        self.main_changes('build/out.txt', 'main\n')
        (self.checkout / '.git/info/exclude').write_text('build/\n')
        (self.checkout / 'build').mkdir()
        (self.checkout / 'build/out.txt').write_text('local ignored\n')
        self.bring_in()
        before = self.state()
        self.assertIn('tasks.workspace_phase_failed', self.merge(ok=False))
        self.assertEqual(self.state(), before)
        self.assertEqual((self.checkout / 'build/out.txt').read_text(), 'local ignored\n')

    def test_a_main_only_workflow_change_merges_and_is_never_the_copys_own(self):
        self.main_changes('.github/workflows/ci.yml', 'on: push\n')
        self.bring_in()
        result = self.merge()
        self.assertEqual(result['merged_default'], self.head)
        # The own-change rule of publish: the copy's own workflow paths since the merge base with the default head.
        base = self.git('merge-base', 'HEAD', self.head)
        self.assertEqual(self.git('diff', '--name-only', base, 'HEAD', '--', '.github/workflows/'), '')
        self.assertEqual(self.git('rev-list', '--count', 'HEAD..' + self.head), '0')
        # So publish of this checked commit is not behind a main that did not move, and needs no merge of its own.
        self.assertEqual(self.git('diff', '--name-only', self.head, 'HEAD', '--', '.github/workflows/'), '')

    def test_only_a_copy_handoff_check_brings_in_the_base(self):
        cel = self.root / 'cel.ts'
        cel.write_text(SUPPORT['CEL'])
        workspace = {'runtime': self.intent['runtime'], 'branch': 'task-42', 'snapshot_id': self.snapshot_id}
        authorization = {'vault': 'orbit-control-inputs', 'key': 'task-sync-token-' + self.snapshot_id}
        base = {'default_branch': 'main', 'repository': 'https://github.com/example/orbit.git', 'start': self.seed,
                'mirror': '/var/lib/orbit/instance-repositories/12.git', 'source_mirror': '', 'git_authorization': authorization}
        ssh = {'target': {'node_id': 7}}

        def value(step, field, intent, steps=None):
            context = self.root / 'context.json'
            context.write_text(json.dumps({'inputs': {'operation_id': 1, 'node_id': 7, 'kind': 'tasks.check', 'intent': intent,
                                                      'execution_secrets': {}, 'run_id': self.snapshot_id},
                                           'steps': steps or {'prepare': {'outputs': {'valid': True}}}, 'run': {'id': self.snapshot_id}}))
            done = subprocess.run(['deno', 'run', '-A', str(cel), str(CHECK), step, field, str(context)],
                                  capture_output=True, text=True, check=False, timeout=60)
            self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
            return json.loads(done.stdout)['value']

        handoff = {'group_id': 42, 'instance_id': self.instance_id, 'phase': 'handoff', 'message': MESSAGE, 'workspace': workspace,
                   'ssh_execution': ssh, 'copy_base': base}
        baseline = {key: item for key, item in handoff.items() if key != 'copy_base'} | {'phase': 'baseline'}
        self.assertFalse(value('Bring in the base', 'guard', handoff))
        self.assertTrue(value('Bring in the base', 'guard', baseline))
        self.assertTrue(value('Discard the base scratch', 'guard', baseline))
        self.assertEqual(value('Bring in the base', 'workflow', handoff), 'task-copy-base')
        self.assertEqual(value('Bring in the base', 'git_authorization', handoff), authorization)
        # The sync's child with the sync's own intent shape: the same phase, never a copy of its logic.
        self.assertEqual(value('Bring in the base', 'intent', handoff), {
            'runtime': workspace['runtime'], 'branch': 'task-42', 'snapshot_id': self.snapshot_id, 'instance_id': self.instance_id,
            'default_branch': 'main', 'repository': base['repository'], 'mirror': base['mirror'], 'source_mirror': '',
            'start': self.seed, 'ssh_execution': ssh})
        # A check without a base reads none of its keys, though Swamp resolves the skipped step's inputs.
        self.assertEqual(value('Bring in the base', 'intent', baseline), {})
        # A failed base skips the merge, and the check runs on the tree as it is.
        prepared = {'prepare': {'outputs': {'valid': True}}}
        self.assertTrue(value('merge input', 'guard', handoff, {**prepared, 'Bring in the base': {'status': 'failed'}}))
        self.assertFalse(value('merge input', 'guard', handoff, {**prepared, 'Bring in the base': {'status': 'succeeded'}}))
        skipped_merge = {**prepared, 'merge input': {'status': 'skipped'}}
        self.assertFalse(value('input', 'guard', handoff, skipped_merge))
        # A conflict ends the check before the long parts; a clean merge lets it run.
        merge = {**prepared, 'merge input': {'status': 'succeeded'}}
        conflict = {'error_code': 'tasks.check_conflict', 'default_commit': self.head, 'conflicting_paths': ['a'], 'conflicting_more': 0,
                    'changed': False}
        self.assertTrue(value('input', 'guard', handoff, {**merge, 'merged': {'outputs': {'evidence': conflict}}}))
        clean = {'default_commit': self.head, 'merged_default': None, 'changed': False}
        self.assertFalse(value('input', 'guard', handoff, {**merge, 'merged': {'outputs': {'evidence': clean}}}))


if __name__ == '__main__':
    unittest.main()
