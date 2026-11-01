"""The fixture refuses successful-looking results for a different assignment."""

import importlib.util
import unittest
from pathlib import Path

SPEC = importlib.util.spec_from_file_location(
    "swamp_parity", Path(__file__).with_name("swamp_parity.py")
)
parity = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(parity)


class Receipt(unittest.TestCase):
    def operation(self):
        return {
            "kind": "node.role_add",
            "status": "succeeded",
            "input": {
                "intent": {
                    "target": {
                        "node_id": 22,
                        "assignment_id": 13,
                        "role": "swamp",
                        "created": True,
                    }
                }
            },
            "result": {"target_completed": True},
        }

    def test_real_assigned_swamp_identity_is_preserved(self):
        self.assertEqual(13, parity.receipt(self.operation())["assignment_id"])

    def test_unproven_target_is_refused(self):
        operation = self.operation()
        operation["result"]["target_completed"] = False
        with self.assertRaisesRegex(ValueError, "target-incomplete"):
            parity.receipt(operation)

    def test_wrong_kind_role_or_created_receipt_is_refused(self):
        for key, value in (("role", "websocket"), ("created", False)):
            operation = self.operation()
            operation["input"]["intent"]["target"][key] = value
            with self.assertRaisesRegex(ValueError, "wrong-assignment"):
                parity.receipt(operation)
        operation = self.operation()
        operation["kind"] = "node.role_relocate"
        with self.assertRaisesRegex(ValueError, "unexpected-kind"):
            parity.receipt(operation)


if __name__ == "__main__":
    unittest.main()
