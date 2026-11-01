"""Preparation handles all role variants in one topology without direct DB writes."""

import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location("role_prepare", ROOT / "prepare.py")
PREPARE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PREPARE)


class Preparation(unittest.TestCase):
    def setUp(self):
        self.role = "app-prod"
        self.assignment_id = 1
        self.calls = []
        self.failure = None

    def cli(self, command, node, *arguments):
        self.calls.append([command, node, *arguments])
        if command == "node:show":
            return {"id": 7, "status": "active", "platform": "linux"}
        if command == "node:role:list":
            return {
                "assignments": []
                if self.role is None
                else [{"id": self.assignment_id, "role": self.role, "status": "active"}]
            }
        if command == "node:role:remove":
            if self.failure == "remove":
                return {"kind": "node.role_remove", "status": "failed"}
            self.assertEqual(arguments, (self.role, "--force"))
            self.role = None
            return {"kind": "node.role_remove", "status": "succeeded"}
        if command == "node:role:add":
            self.assertIsNone(self.role)
            self.role = arguments[0]
            self.assignment_id += 1
            return {"kind": "node.role_add", "status": "succeeded"}
        raise AssertionError("Unexpected CLI " + command)

    def test_all_variants_in_filename_order_then_repeat_preserve_correct_assignment(
        self,
    ):
        for role in ["app-prod", "database", "ingress", "websocket", "app-prod"]:
            result = PREPARE.prepare(role, 7, self.cli)
            self.assertEqual(
                result,
                {"role": role, "node_id": 7, "assignment_id": self.assignment_id},
            )
        before = self.assignment_id
        self.calls = []
        PREPARE.prepare("app-prod", 7, self.cli)
        self.assertEqual(self.assignment_id, before)
        self.assertNotIn("node:role:remove", [call[0] for call in self.calls])
        self.assertNotIn("node:role:add", [call[0] for call in self.calls])

    def test_existing_app_dev_assignment_needs_no_role_mutation(self):
        self.role = "app-dev"
        result = PREPARE.prepare("app-dev", 7, self.cli)
        self.assertEqual(result, {"role": "app-dev", "node_id": 7, "assignment_id": 1})
        self.assertNotIn("node:role:remove", [call[0] for call in self.calls])
        self.assertNotIn("node:role:add", [call[0] for call in self.calls])

    def test_appdev_never_replaces_a_role_or_assigns_a_seeded_roleless_node(self):
        for existing in [None, "app-prod", "database"]:
            with self.subTest(existing=existing):
                self.role = existing
                self.calls = []
                with self.assertRaisesRegex(
                    ValueError, "prepare-needs-bootstrapped-app-dev"
                ):
                    PREPARE.prepare("app-dev", 7, self.cli)
                self.assertEqual(
                    [call[0] for call in self.calls], ["node:show", "node:role:list"]
                )
                self.assertEqual(self.role, existing)

    def test_appdev_refuses_incomplete_enrollment_before_any_role_mutation(self):
        self.role = "app-dev"
        for changes in [{"status": "provisioning"}, {"platform": None}]:
            with self.subTest(changes=changes):
                self.calls = []

                def cli(command, node, *arguments, changes=changes):
                    result = self.cli(command, node, *arguments)
                    return {**result, **changes} if command == "node:show" else result

                with self.assertRaisesRegex(
                    ValueError, "prepare-needs-bootstrapped-app-dev"
                ):
                    PREPARE.prepare("app-dev", 7, cli)
                self.assertEqual(
                    [call[0] for call in self.calls], ["node:show", "node:role:list"]
                )

    def test_failed_removal_does_not_assign_target_role(self):
        self.failure = "remove"
        with self.assertRaisesRegex(ValueError, "prepare-role-removal-failed"):
            PREPARE.prepare("websocket", 7, self.cli)
        self.assertEqual(self.role, "app-prod")
        self.assertNotIn("node:role:add", [call[0] for call in self.calls])

    def test_unrelated_role_is_never_removed(self):
        self.role = "gateway"
        with self.assertRaisesRegex(ValueError, "refuse-unrelated-role-removal"):
            PREPARE.prepare("database", 7, self.cli)
        self.assertNotIn("node:role:remove", [call[0] for call in self.calls])

    def test_evidence_path_rejects_escaping_the_proof_directory(self):
        with self.assertRaisesRegex(ValueError, "invalid-proof-run"):
            PREPARE.precondition_path("websocket", "../outside", ROOT)


if __name__ == "__main__":
    unittest.main()
