"""At the delivery gate a command that moves the handed-off work ends the check CHANGED, and a check that fails after it collected
its part table keeps the table.

Its own file: the receipt file holds no headroom for these cases within the whole-file bound."""
import json
import os
import runpy
import unittest
import unittest.mock
from pathlib import Path

import yaml

SUPPORT = runpy.run_path(str(Path(__file__).parent / "_support/task_instance_workspace.py"))
write_receipt = runpy.run_path(str(Path(__file__).parent / "task_instance_check_receipt_test.py"))['write_receipt']


class TaskInstanceCheckReceiptFailureTest(SUPPORT["CheckHarness"]):
    def test_a_project_command_that_moves_the_handed_off_work_ends_changed_only_at_the_delivery_gate(self):
        # A formatter that writes a file of the tree.
        self.check['command'] = 'echo formatted > formatted.txt'
        self.check['handoff_gate'] = True
        _, local = self.checked()
        self.assertEqual(local['outcome'], 'changed', local)
        self.assertNotEqual(local['handed_off_tree'], local['tree_before'])
        # A baseline or an ungated check keeps the post-Project snapshot: the formatter's edit belongs to the checked tree.
        for change in [{'handoff_gate': False}, {'handoff_gate': False, 'phase': 'baseline', 'deliverables': []}]:
            with self.subTest(change=change):
                (self.checkout / 'formatted.txt').unlink()
                self.check.update(change)
                self.steps = {}
                _, local = self.checked()
                self.assertEqual(local['outcome'], 'passed', local)

    def with_entry_rescue(self):
        """The phase wrapped in the entry playbook's own rescue, so the production template records the refusal."""
        entry = yaml.safe_load((SUPPORT['ROOT'] / 'ansible/task-instance-workspace.yml').read_text())
        rescue = next(task['rescue'] for play in entry for task in play.get('tasks', []) if 'rescue' in task)
        self.play.write_text(json.dumps([{'name': 'Exercise the production phase and its rescue', 'hosts': 'localhost', 'connection': 'local',
            'gather_facts': False, 'tasks': [{'name': 'Run one workspace phase', 'block': [
                {'name': 'Run workspace phase', 'ansible.builtin.import_tasks': str(SUPPORT['TASKS'])}], 'rescue': rescue}]}]))

    def failed_local(self):
        """The check phase run to its failure, then the evidence and the local result as the child workflow reads them."""
        self.ran('prepare', self.behavior('task-repository/check.ts', 'prepare', {'intent': self.check, 'node_id': self.check['node_id']}))
        self.run_phase('check', self.write_input('input'), ok=False)
        self.ran('checked', self.behavior('task-instance-workspace/assets.ts', 'result', {'folder': str(self.folder), 'phase': 'check'}))
        return self.ran('local', self.behavior('task-repository/check.ts', 'local', {
            'intent': self.check, 'prepared': self.cel('local', 'prepared'), 'steps': self.cel('local', 'steps'),
            'outputs': self.cel('local', 'outputs')}))['result']

    def test_a_guest_failure_after_the_table_keeps_the_table(self):
        self.with_entry_rescue()
        parts = [{'name': 'pest', 'result': 'passed', 'seconds': 1}]
        self.check['command'] = write_receipt({'commit': self.base, 'parts': parts})
        # A command deliverable whose folder is missing fails the phase after the table was collected.
        self.check['deliverables'][0]['directory'] = 'missing'
        local = self.failed_local()
        self.assertEqual(local, {'error_code': 'tasks.workspace_phase_failed', 'check_id': self.check['check_id'], 'parts': parts})

    def test_a_transfer_failure_after_the_guest_succeeded_keeps_the_table(self):
        self.with_entry_rescue()
        parts = [{'name': 'pest', 'result': 'passed', 'seconds': 1}]
        self.check['command'] = write_receipt({'commit': self.base, 'parts': parts})
        # The guest collects its table and succeeds; only the output pull to the Node fails.
        with unittest.mock.patch.dict(os.environ, {'T1_PULL_FAIL': '1'}):
            local = self.failed_local()
        self.assertEqual(local, {'error_code': 'tasks.workspace_phase_failed', 'check_id': self.check['check_id'], 'parts': parts})

    def test_a_check_whose_candidate_was_not_pinned_keeps_its_part_table(self):
        parts = [{'name': 'pest', 'result': 'passed', 'seconds': 1}]
        steps = {'local': {'outputs': {'result': {'outcome': 'passed', 'parts': parts}}},
                 'candidate': {'outputs': {'result': {'outcome': 'passed', 'commit': 'a' * 40, 'parts': parts}}}}
        self.assertEqual(self.cel('finish', 'local', steps=steps),
                         {'error_code': 'tasks.repository_execution_unknown', 'check_id': self.check['check_id'], 'parts': parts})


if __name__ == '__main__':
    unittest.main()
