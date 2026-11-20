"""The handoff check keeps the part table of the delivery receipt its own Project check wrote, and removes every receipt.

Its own file: the check file holds no headroom for another case within the whole-file bound."""
import json
import runpy
import unittest
from pathlib import Path

SUPPORT = runpy.run_path(str(Path(__file__).parent / "_support/task_instance_workspace.py"))


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
        self.check['command'] = self.write_receipt({'commit': self.base, 'parts': parts, 'marker': 'unrelated-marker'})
        # A deliverable that writes a receipt of its own leaves none behind.
        self.check['deliverables'][0]['command'] = self.write_receipt({'commit': 'late', 'parts': parts}) + ' && sh tests/feature.sh'
        evidence, local = self.checked()
        self.assertEqual(local['parts'], parts, local)
        self.assertFalse(receipt.exists())
        # Only the validated part fields leave the guest.
        self.assertNotIn('unrelated-marker', json.dumps([evidence, local, self.steps]))
        # The handed-off tree is the work as it stood before any command, uncommitted work included.
        self.assertEqual(local['handed_off_tree'], local['tree'])
        self.assertNotEqual(local['handed_off_tree'], self.git('rev-parse', 'HEAD^{tree}'))

        # A command that writes none, or no usable table, leaves no part table; a folder at the path fails nothing.
        for command in ['true', self.write_receipt({'commit': self.base, 'parts': [{'name': 'pest', 'result': 'failed', 'seconds': 1}]}),
                        self.write_receipt({'commit': self.base, 'parts': {}})]:
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

    def test_a_check_whose_candidate_was_not_pinned_keeps_its_part_table(self):
        parts = [{'name': 'pest', 'result': 'passed', 'seconds': 1}]
        steps = {'local': {'outputs': {'result': {'outcome': 'passed', 'parts': parts}}},
                 'candidate': {'outputs': {'result': {'outcome': 'passed', 'commit': 'a' * 40, 'parts': parts}}}}
        self.assertEqual(self.cel('finish', 'local', steps=steps),
                         {'error_code': 'tasks.repository_execution_unknown', 'check_id': self.check['check_id'], 'parts': parts})

if __name__ == '__main__':
    unittest.main()
