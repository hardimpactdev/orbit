"""task:sync of a copy brings only what it lacks into refs/remotes/origin/<default>; HEAD, the tree and the branches stay."""
import json
import os
import runpy
import subprocess
import unittest
from pathlib import Path

HARNESS = runpy.run_path(str(Path(__file__).parent / '_support/task_instance_workspace_base.py'))
SUPPORT, CHILD, SECRET = HARNESS['SUPPORT'], HARNESS['CHILD'], HARNESS['SECRET']
SYNC_COPY = HARNESS['ROOT'] / 'workflows/workflow-operation-task-sync-copy.yaml'
SHARED = HARNESS['ROOT'] / 'workflows/workflow-task-instance-workspace.yaml'


class TaskInstanceWorkspaceBaseTest(HARNESS['BaseHarness']):
    def test_a_copy_behind_main_gets_only_what_it_lacks_as_origin_main(self):
        before = self.state()
        result = self.phase('base')
        self.assertEqual(result, {'default_branch': 'main', 'default_commit': self.head, 'branch': 'task-42',
                                  'branch_commit': self.work, 'behind_by': 2, 'start': self.seed, 'bundled': True, 'changed': True,
                                  # The copy's own commit is not on main, and its uncommitted work never is.
                                  'work': {'state': 'dirty', 'head': self.work, 'default_commit': self.head, 'empty': False,
                                           'paths': {'count': 1, 'names': ['work.txt']}, 'uncommitted': 2, 'untracked': 0,
                                           'stashes': 0, 'approved_held': None}})
        self.assertEqual(self.evidence('base'), result)
        self.assertEqual(self.git('rev-parse', 'refs/remotes/origin/main'), self.head)
        self.assertEqual(self.state(), before)
        # The missing mirror was made from the default's mirror, then fetched the default branch over HTTPS with the header.
        self.assertEqual(self.git('rev-parse', 'refs/heads/main', cwd=Path(self.intent['mirror'])), self.head)
        self.assertIn('basic ' + SECRET, self.server.headers)
        # One bundle of B and C alone, owned by root and readable by agent's group: agent cannot swap it.
        [push] = self.pushes()
        self.assertEqual(push[4:10], ['--uid', '0', '--gid', str(os.getgid()), '--mode', '0640'])
        self.assert_nothing_left()
        self.assert_no_credential_anywhere()

    def test_nothing_new_makes_no_bundle_and_a_merged_branch_is_not_behind(self):
        self.phase('base')
        again = self.phase('base')
        self.assertEqual((again['bundled'], again['changed'], again['behind_by']), (False, False, 2))
        self.assertEqual(len(self.pushes()), 1)
        # The agent merges main itself; the next sync finds the branch level with main.
        (self.checkout / 'work.txt').write_text('agent work\n')
        self.git('rm', '--quiet', '--cached', 'untracked.txt')
        self.git('merge', '--quiet', '--no-edit', 'refs/remotes/origin/main')
        merged = self.phase('base')
        self.assertEqual((merged['bundled'], merged['behind_by'], merged['default_commit']), (False, 0, self.head))
        self.assertEqual(len(self.pushes()), 1)
        self.assert_nothing_left()

    def test_a_copy_whose_mirror_exists_syncs_without_the_default(self):
        self.phase('base')
        # The default is gone: no source mirror, and the copy's own mirror is enough.
        result = self.phase('base', {**self.intent, 'source_mirror': ''})
        self.assertEqual(result['default_commit'], self.head)
        # Without either mirror the phase refuses before any fetch.
        intent = {**self.intent, 'source_mirror': '', 'mirror': str(self.mirrors / '13.git')}
        self.assertIn('tasks.workspace_phase_failed', self.phase('base', intent, ok=False))
        self.assertFalse((self.mirrors / '13.git').exists())

    def test_only_the_base_child_reads_the_credential_and_the_shared_child_has_no_base(self):
        cel = self.root / 'cel.ts'
        cel.write_text(SUPPORT['CEL'])

        def value(step, field, phase):
            context = self.root / 'context.json'
            context.write_text(json.dumps({'inputs': {'node_id': 7, 'phase': phase, 'intent': self.intent,
                                                      'git_authorization': {'vault': 'orbit-control-inputs', 'key': 'k'}},
                                           'steps': {}, 'run': {'id': self.snapshot_id}}))
            done = subprocess.run(['deno', 'run', '-A', str(cel), str(CHILD), step, field, str(context)],
                                  capture_output=True, text=True, check=False, timeout=60)
            self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
            return json.loads(done.stdout)['value']

        self.assertEqual((value('Bring in the base', 'guard', 'base'), value('Discard the base scratch', 'guard', 'base')), (False, True))
        self.assertEqual((value('Bring in the base', 'guard', 'base-cleanup'), value('Discard the base scratch', 'guard', 'base-cleanup')),
                         (True, False))
        text = CHILD.read_text()
        self.assertEqual(text.count('vault.get('), 1)
        self.assertIn("ORBIT_GIT_AUTHORIZATION: '${{ vault.get(inputs.git_authorization.vault, inputs.git_authorization.key) }}'", text)
        # The shared workspace child that every copy turn, check and publish calls holds no secret, input or phase of the base.
        shared = SHARED.read_text()
        for word in ('vault.get(', 'git_authorization', 'git_credential', 'base-cleanup', 'Bring in the base', 'instance_id'):
            self.assertNotIn(word, shared)

    def test_the_copy_sync_workflow_calls_the_base(self):
        cel = self.root / 'cel.ts'
        cel.write_text(SUPPORT['CEL'])
        authorization = {'vault': 'orbit-control-inputs', 'key': 'task-sync-token-' + self.snapshot_id}
        workspace = {'repository': 'https://github.com/example/orbit.git', 'default_branch': 'main', 'branch': 'task-42'}
        copy = {'group_id': 42, 'instance_id': self.instance_id, 'project_id': 3, 'node_id': 7, 'workspace': workspace,
                'git_authorization': authorization, 'ssh_execution': {'target': {'node_id': 7}},
                'copy': {'runtime': self.intent['runtime'], 'branch': 'task-42', 'snapshot_id': self.snapshot_id, 'start': self.seed,
                         'mirror': '/var/lib/orbit/instance-repositories/12.git', 'source_mirror': '/var/lib/orbit/instance-repositories/11.git'}}

        def value(step, field, steps=None, copy=copy):
            context = self.root / 'context.json'
            context.write_text(json.dumps({'inputs': {'operation_id': 1, 'node_id': 7, 'kind': 'tasks.sync', 'intent': copy,
                                                      'execution_secrets': {}}, 'steps': steps or {}, 'run': {'id': self.snapshot_id}}))
            done = subprocess.run(['deno', 'run', '-A', str(cel), str(SYNC_COPY), step, field, str(context)],
                                  capture_output=True, text=True, check=False, timeout=60)
            self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
            return json.loads(done.stdout)['value']

        for step in ('Bring in the base', 'Discard the base scratch'):
            self.assertEqual(value(step, 'workflow'), 'task-copy-base')
            self.assertEqual(value(step, 'git_authorization'), authorization)
        self.assertEqual(value('Bring in the base', 'phase'), 'base')
        # The child intent is the one the base phase runs with here, with the copy's own target and mirrors.
        self.assertEqual(value('Bring in the base', 'intent'), {
            'runtime': self.intent['runtime'], 'branch': 'task-42', 'snapshot_id': self.snapshot_id, 'instance_id': self.instance_id,
            'default_branch': 'main', 'repository': workspace['repository'], 'mirror': copy['copy']['mirror'],
            'source_mirror': copy['copy']['source_mirror'], 'start': self.seed, 'published': [], 'approved': '',
            'ssh_execution': copy['ssh_execution']})
        # An intent recorded before the content read had no published or approved commit: the base gets none, as above. A current
        # intent passes the group's own.
        published = {**copy, 'copy': {**copy['copy'], 'published': [self.work], 'approved': self.seed}}
        self.assertEqual({key: value('Bring in the base', 'intent', copy=published)[key] for key in ('published', 'approved')},
                         {'published': [self.work], 'approved': self.seed})
        self.assertEqual(value('Discard the base scratch', 'phase'), 'base-cleanup')
        self.assertEqual(value('Discard the base scratch', 'intent'), {
            'runtime': self.intent['runtime'], 'branch': 'task-42', 'snapshot_id': self.snapshot_id, 'instance_id': self.instance_id,
            'ssh_execution': copy['ssh_execution']})
        ok = {'status': 'succeeded'}
        self.assertTrue(value('Confirm the sync', 'expr', {'Bring in the base': ok, 'Discard the base scratch': ok}))
        self.assertFalse(value('Confirm the sync', 'expr', {'Bring in the base': ok, 'Discard the base scratch': {'status': 'failed'}}))
if __name__ == '__main__':
    unittest.main()
