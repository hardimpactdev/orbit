"""task:sync of a copy brings only what it lacks into refs/remotes/origin/<default>; HEAD, the tree and the branches stay."""
import json
import os
import runpy
import subprocess
import unittest
from pathlib import Path

HARNESS = runpy.run_path(str(Path(__file__).parent / '_support/task_instance_workspace_base.py'))
SUPPORT, CHILD = HARNESS['SUPPORT'], HARNESS['CHILD']
SYNC = HARNESS['ROOT'] / 'workflows/workflow-operation-task-sync.yaml'


class TaskInstanceWorkspaceBaseTest(HARNESS['BaseHarness']):
    def test_a_copy_behind_main_gets_only_what_it_lacks_as_origin_main(self):
        before = self.state()
        result = self.phase('base')
        self.assertEqual(result, {'default_branch': 'main', 'default_commit': self.head, 'branch': 'task-42',
                                  'branch_commit': self.work, 'behind_by': 2, 'start': self.seed, 'bundled': True, 'changed': True})
        self.assertEqual(self.evidence('base'), result)
        self.assertEqual(self.git('rev-parse', 'refs/remotes/origin/main'), self.head)
        self.assertEqual(self.state(), before)
        # The missing mirror was made from the default's mirror, then fetched the default branch.
        self.assertEqual(self.git('rev-parse', 'refs/heads/main', cwd=Path(self.intent['mirror'])), self.head)
        # One bundle of B and C alone: the seed, which the copy holds, is its prerequisite.
        [push] = self.pushes()
        self.assertEqual(push[4:10], ['--uid', str(os.getuid()), '--gid', str(os.getgid()), '--mode', '0600'])
        self.assert_nothing_left()
        self.assert_no_credential_in_copy()

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

    def test_only_the_base_step_of_the_child_reads_the_credential(self):
        cel = self.root / 'cel.ts'
        cel.write_text(SUPPORT['CEL'])

        def value(step, field, phase):
            context = self.root / 'context.json'
            context.write_text(json.dumps({'inputs': {'node_id': 7, 'phase': phase, 'intent': self.intent}, 'steps': {},
                                           'run': {'id': self.snapshot_id}}))
            done = subprocess.run(['deno', 'run', '-A', str(cel), str(CHILD), step, field, str(context)],
                                  capture_output=True, text=True, check=False, timeout=60)
            self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
            return json.loads(done.stdout)['value']

        self.assertEqual((value('Run workspace phase', 'guard', 'base'), value('Bring in the base', 'guard', 'base')), (True, False))
        self.assertEqual((value('Run workspace phase', 'guard', 'export'), value('Bring in the base', 'guard', 'export')), (False, True))
        self.assertEqual((value('Run workspace phase', 'guard', 'base-cleanup'), value('Bring in the base', 'guard', 'base-cleanup')),
                         (False, True))
        text = CHILD.read_text()
        self.assertEqual(text.count('vault.get('), 1)
        self.assertIn("ORBIT_GIT_CREDENTIAL: '${{ vault.get(inputs.git_credential.vault, inputs.git_credential.key) }}'", text)

    def test_the_sync_takes_the_copy_path_by_its_copy_key_and_keeps_the_task_vm_path(self):
        cel = self.root / 'cel.ts'
        cel.write_text(SUPPORT['CEL'])
        credential = {'vault': 'orbit-control-inputs', 'key': 'task-sync-credential-' + self.snapshot_id}
        workspace = {'repository': 'https://github.com/example/orbit.git', 'default_branch': 'main', 'branch': 'task-42'}
        common = {'group_id': 42, 'instance_id': self.instance_id, 'project_id': 3, 'node_id': 7, 'workspace': workspace,
                  'ssh_execution': {'target': {'node_id': 7}}}
        copy = {**common, 'copy': {'runtime': self.intent['runtime'], 'branch': 'task-42', 'snapshot_id': self.snapshot_id,
                                   'start': self.seed, 'mirror': '/var/lib/orbit/instance-repositories/12.git',
                                   'source_mirror': '/var/lib/orbit/instance-repositories/11.git', 'git_credential': credential}}
        # What an older Gateway admits: a task VM intent without `copy`.
        vm = {**common, 'checkout_path': '/home/orbit/projects/orbit', 'git_authorization': {'vault': 'orbit-control-inputs', 'key': 'x'}}

        def value(step, field, intent, steps=None):
            context = self.root / 'context.json'
            context.write_text(json.dumps({'inputs': {'operation_id': 1, 'node_id': 7, 'kind': 'tasks.sync', 'intent': intent,
                                                      'execution_secrets': {}}, 'steps': steps or {}, 'run': {'id': self.snapshot_id}}))
            done = subprocess.run(['deno', 'run', '-A', str(cel), str(SYNC), step, field, str(context)],
                                  capture_output=True, text=True, check=False, timeout=60)
            self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
            return json.loads(done.stdout)['value']

        for step in ('Bring in the base', 'Discard the base scratch'):
            self.assertEqual((value(step, 'guard', copy), value(step, 'guard', vm)), (False, True), step)
        for step in ('Clone the refs', 'Bundle the refs', 'Prepare the VM directory', 'Copy the bundle', 'Fetch the refs', 'Read the refs',
                     'Clean up the scratch', 'Clean up the VM directory'):
            self.assertEqual((value(step, 'guard', copy), value(step, 'guard', vm)), (True, False), step)
        self.assertEqual(value('Bring in the base', 'workflow', copy), 'task-instance-workspace')
        self.assertEqual(value('Bring in the base', 'phase', copy), 'base')
        self.assertEqual(value('Bring in the base', 'git_credential', copy), credential)
        # The child intent is the one the base phase runs with here, with the copy's own target and mirrors.
        self.assertEqual(value('Bring in the base', 'intent', copy), {
            'runtime': self.intent['runtime'], 'branch': 'task-42', 'snapshot_id': self.snapshot_id, 'instance_id': self.instance_id,
            'default_branch': 'main', 'repository': workspace['repository'], 'mirror': copy['copy']['mirror'],
            'source_mirror': copy['copy']['source_mirror'], 'start': self.seed, 'ssh_execution': common['ssh_execution']})
        self.assertEqual(value('Discard the base scratch', 'phase', copy), 'base-cleanup')
        self.assertEqual(value('Discard the base scratch', 'intent', copy), {
            'runtime': self.intent['runtime'], 'branch': 'task-42', 'snapshot_id': self.snapshot_id, 'instance_id': self.instance_id,
            'ssh_execution': common['ssh_execution']})
        ok = {'status': 'succeeded'}
        self.assertTrue(value('Confirm the sync', 'expr', copy, {'Bring in the base': ok, 'Discard the base scratch': ok}))
        self.assertFalse(value('Confirm the sync', 'expr', copy, {'Bring in the base': ok, 'Discard the base scratch': {'status': 'failed'}}))
        self.assertTrue(value('Confirm the sync', 'expr', vm, {'Read the refs': ok, 'Clean up the scratch': ok, 'Clean up the VM directory': ok}))
        self.assertFalse(value('Confirm the sync', 'expr', vm, {'Bring in the base': ok, 'Discard the base scratch': ok}))


if __name__ == '__main__':
    unittest.main()
