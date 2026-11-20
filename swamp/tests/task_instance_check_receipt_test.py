"""The handoff check keeps the part table of the delivery receipt its own Project check wrote, and removes every receipt.

Its own file: the check file holds no headroom for another case within the whole-file bound."""
import json
import runpy
import unittest
from pathlib import Path

SUPPORT = runpy.run_path(str(Path(__file__).parent / "_support/task_instance_workspace.py"))


class TaskInstanceCheckReceiptTest(SUPPORT["CheckHarness"]):
    def test_the_check_keeps_the_part_table_of_its_own_receipt_and_removes_every_receipt(self):
        # A receipt the implementer's turn left is never this check's: the check removes it before its command.
        receipt = self.checkout / '.git/orbit-handoff/delivery.json'
        receipt.parent.mkdir()
        receipt.write_text('{"commit": "stale", "parts": [{"name": "stale", "result": "passed", "seconds": 1}]}')
        parts = [{'name': 'ci-plan', 'result': 'passed', 'seconds': 0.5}, {'name': 'pest', 'result': 'FAILED', 'seconds': 3.1}]
        self.check['command'] = ('f=$(git rev-parse --path-format=absolute --git-path orbit-handoff/delivery.json) && '
                                 + "printf '%s' " + repr(json.dumps({'commit': self.base, 'parts': parts})) + ' > "$f"')
        _, local = self.checked()
        self.assertEqual(local['parts'], parts, local)
        self.assertFalse(receipt.exists())
        # A command that writes none leaves no part table, and a stale receipt is gone all the same.
        receipt.write_text('{"commit": "stale", "parts": []}')
        self.check['command'] = 'true'
        self.steps = {}
        _, local = self.checked()
        self.assertIsNone(local['parts'], local)
        self.assertFalse(receipt.exists())


if __name__ == '__main__':
    unittest.main()
