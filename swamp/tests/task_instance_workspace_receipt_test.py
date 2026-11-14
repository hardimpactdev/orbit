"""Real workspace proof; independent file with the shared, test-free harness."""
import json
import runpy
import unittest
from pathlib import Path

SUPPORT = runpy.run_path(str(Path(__file__).parent / "_support/task_instance_workspace.py"))
FIXTURE = SUPPORT["FIXTURE"]


class TaskInstanceWorkspaceTest(SUPPORT["WorkspaceHarness"]):
    def test_captured_evidence_and_bounded_bound_receipt(self):
        index = (self.checkout / '.git/index').read_bytes()
        before = self.prepare()
        self.assertEqual(before['head'], FIXTURE['expected']['head'])
        self.assertEqual(before['tree'], FIXTURE['expected']['tree'])
        self.assertEqual(self.git('ls-tree', '-r', before['tree']), FIXTURE['expected']['ls_tree'].strip())
        self.assertEqual((self.checkout / '.git/index').read_bytes(), index)
        self.check_typed_result(before)
        repeated, _ = self.phase('before')
        self.assertEqual(before, repeated)
        receipt = {'snapshot_id': self.snapshot_id, 'candidate': self.intent['candidate'], 'receipt': {'verdict': 'pass'}}
        (self.scratch / 'receipt.json').write_text(json.dumps(receipt))
        value, _ = self.phase('receipt')
        self.assertEqual(value['receipt'], {'verdict': 'pass'})
        # The limit applies before UTF-8/JSON decode, including bytes hidden by multi-byte characters.
        raw = json.dumps(receipt).encode()
        (self.scratch / 'receipt.json').write_bytes(raw + b' ' * (65536 - len(raw)))
        value, _ = self.phase('receipt')
        self.assertEqual(value['bytes'], 65536)
        receipt['receipt']['message'] = 'é' * 33000
        (self.scratch / 'receipt.json').write_text(json.dumps(receipt, ensure_ascii=False))
        self.assertIn('tasks.workspace_receipt_invalid', self.phase('receipt', ok=False))
        del receipt['receipt']['message']
        receipt['candidate'] = {**self.intent['candidate'], 'head': '0' * 40}
        (self.scratch / 'receipt.json').write_text(json.dumps(receipt))
        self.assertIn('tasks.workspace_receipt_invalid', self.phase('receipt', ok=False))
        (self.scratch / 'receipt.json').unlink()
        (self.scratch / 'receipt.json').symlink_to(self.checkout / 'base.txt')
        self.assertIn('tasks.workspace_receipt_invalid', self.phase('receipt', ok=False))

    def test_snapshot_and_receipt_refuse_wrong_digest_or_candidate_without_checkout_writes(self):
        self.prepare()
        index = (self.checkout / '.git/index').read_bytes()
        status = self.git('status', '--porcelain=v1', '-uall')
        wrong = {**self.intent, 'before_digest': '0' * 64}
        self.assertIn('tasks.workspace_snapshot_mismatch', self.phase('after', wrong, ok=False))
        self.assertFalse((self.scratch / 'after.json').exists())
        self.assertIn('tasks.workspace_snapshot_mismatch', self.phase('receipt', wrong, ok=False))
        wrong = {**self.intent, 'candidate': {**self.intent['candidate'], 'head': '0' * 40}}
        self.assertIn('tasks.workspace_snapshot_mismatch', self.phase('receipt', wrong, ok=False))
        self.assertEqual((self.checkout / '.git/index').read_bytes(), index)
        self.assertEqual(self.git('status', '--porcelain=v1', '-uall'), status)


if __name__ == "__main__":
    unittest.main()
