"""The handoff check keeps the part table of the delivery receipt its own Project check wrote, and removes every receipt.

Its own file: the check file holds no headroom for another case within the whole-file bound."""
import json
import runpy
import unittest
from pathlib import Path

import yaml

SUPPORT = runpy.run_path(str(Path(__file__).parent / "_support/task_instance_workspace.py"))
SECRET = 'ghp_' + 'a1B2' * 9


class TaskInstanceCheckReceiptTest(SUPPORT["CheckHarness"]):
    def write_receipt(self, receipt):
        """A shell command that writes RECEIPT where `bin/task-check` writes its delivery receipt."""
        return ('f=$(git rev-parse --path-format=absolute --git-path orbit-handoff/delivery.json) && mkdir -p "$(dirname "$f")" && '
                + "printf '%s' " + repr(json.dumps(receipt)) + ' > "$f"')

    def test_the_check_keeps_the_part_table_of_its_own_receipt_and_removes_every_receipt(self):
        # A receipt the implementer's turn left is never this check's: the check removes it before its command.
        receipt = self.checkout / '.git/orbit-handoff/delivery.json'
        receipt.parent.mkdir()
        receipt.write_text('{"commit": "stale", "parts": [{"name": "stale", "result": "passed", "seconds": 1}]}')
        parts = [{'name': 'ci-plan', 'result': 'passed', 'seconds': 0.5}, {'name': 'pest', 'result': 'FAILED', 'seconds': 3.1}]
        self.check['command'] = self.write_receipt({'commit': self.base, 'parts': [*parts, {'name': SECRET, 'result': 'passed', 'seconds': 1}],
                                                    'marker': 'unrelated-marker'})
        # A deliverable that writes a receipt of its own leaves none behind.
        self.check['deliverables'][0]['command'] = self.write_receipt({'commit': 'late', 'parts': parts}) + ' && sh tests/feature.sh'
        evidence, local = self.checked()
        self.assertEqual(local['parts'][:2], parts, local)
        self.assertFalse(receipt.exists())
        # Only the validated part fields leave the guest, and a part name is redacted before the first stored resource.
        self.assertNotIn('unrelated-marker', json.dumps([evidence, local, self.steps]))
        self.assertNotIn(SECRET, json.dumps([evidence, local, self.steps]))
        self.assertEqual(local['handed_off_head'], self.git('rev-parse', 'HEAD'))
        # The handed-off tree is the work as it stood before any command, uncommitted work included.
        self.assertEqual(local['handed_off_tree'], local['tree'])
        self.assertNotEqual(local['handed_off_tree'], self.git('rev-parse', 'HEAD^{tree}'))

        # A command that writes none, or no usable table, leaves no part table; a folder at the path fails nothing.
        for command in ['true', self.write_receipt({'commit': self.base, 'parts': [{'name': 'pest', 'result': 'failed', 'seconds': 1}]}),
                        self.write_receipt({'commit': self.base, 'parts': {}}),
                        # A duration too large for a float.
                        self.write_receipt({'commit': self.base, 'parts': [{'name': 'pest', 'result': 'passed', 'seconds': 0}]})
                        .replace('"seconds": 0', '"seconds": ' + '9' * 400)]:
            with self.subTest(command=command):
                receipt.write_text('{"commit": "stale", "parts": []}')
                self.check['command'] = command
                self.steps = {}
                _, local = self.checked()
                self.assertIsNone(local['parts'], local)
                self.assertFalse(receipt.exists())
        receipt.mkdir()
        self.check['command'] = 'true'
        self.steps = {}
        _, local = self.checked()
        self.assertNotIn('error_code', local)
        self.assertFalse(receipt.exists())

    def test_a_project_command_that_moves_the_handed_off_work_ends_changed(self):
        self.check['command'] = 'echo moved >> app.txt'
        _, local = self.checked()
        self.assertEqual(local['outcome'], 'changed', local)
        self.assertNotEqual(local['handed_off_tree'], local['tree_before'])

    def test_a_guest_failure_after_the_table_keeps_the_table(self):
        # The entry playbook's own rescue records the refusal, so the production template carries the table.
        entry = yaml.safe_load((SUPPORT['ROOT'] / 'ansible/task-instance-workspace.yml').read_text())
        rescue = next(task['rescue'] for play in entry for task in play.get('tasks', []) if 'rescue' in task)
        self.play.write_text(json.dumps([{'name': 'Exercise the production phase and its rescue', 'hosts': 'localhost', 'connection': 'local',
            'gather_facts': False, 'tasks': [{'name': 'Run one workspace phase', 'block': [
                {'name': 'Run workspace phase', 'ansible.builtin.import_tasks': str(SUPPORT['TASKS'])}], 'rescue': rescue}]}]))
        parts = [{'name': 'pest', 'result': 'passed', 'seconds': 1}]
        self.check['command'] = self.write_receipt({'commit': self.base, 'parts': parts})
        # A command deliverable whose folder is missing fails the phase after the table was collected.
        self.check['deliverables'][0]['directory'] = 'missing'
        self.ran('prepare', self.behavior('task-repository/check.ts', 'prepare', {'intent': self.check, 'node_id': self.check['node_id']}))
        self.run_phase('check', self.write_input('input'), ok=False)
        self.ran('checked', self.behavior('task-instance-workspace/assets.ts', 'result', {'folder': str(self.folder), 'phase': 'check'}))
        local = self.ran('local', self.behavior('task-repository/check.ts', 'local', {
            'intent': self.check, 'prepared': self.cel('local', 'prepared'), 'steps': self.cel('local', 'steps'),
            'outputs': self.cel('local', 'outputs')}))['result']
        self.assertEqual(local, {'error_code': 'tasks.workspace_phase_failed', 'check_id': self.check['check_id'], 'parts': parts})

    def test_a_check_whose_candidate_was_not_pinned_keeps_its_part_table(self):
        parts = [{'name': 'pest', 'result': 'passed', 'seconds': 1}]
        steps = {'local': {'outputs': {'result': {'outcome': 'passed', 'parts': parts}}},
                 'candidate': {'outputs': {'result': {'outcome': 'passed', 'commit': 'a' * 40, 'parts': parts}}}}
        self.assertEqual(self.cel('finish', 'local', steps=steps),
                         {'error_code': 'tasks.repository_execution_unknown', 'check_id': self.check['check_id'], 'parts': parts})

if __name__ == '__main__':
    unittest.main()
