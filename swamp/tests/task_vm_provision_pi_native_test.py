"""Prove parent identity and public Pi results through a real nested native workflow."""
import json

import task_vm_pi_install_native_test as pi_fixture
import yaml
from task_vm_model_shell_native_test import reply
from task_vm_pi_scripts_test import CONTENT, SHA


class ProvisionPiNativeTest(pi_fixture.PiInstallNativeTest):
    def setUp(self):
        super().setUp()
        parent = {
            "id": "4f477c01-3acc-4bf9-9d98-ab91bf4d0f75",
            "name": "provision-pi-fixture",
            "inputs": {
                "type": "object",
                "properties": {"operation_id": {"type": "integer"}, "kind": {"type": "string"},
                               "node_id": {"type": "integer"}, "intent": {"type": "object"}},
                "required": ["operation_id", "kind", "node_id", "intent"],
                "additionalProperties": False,
            },
            "jobs": [{
                "name": "pi", "target": '${{ "orbit-node-" + string(inputs.intent.guest_node_id) }}',
                "labels": {"node": '${{ string(inputs.intent.guest_node_id) }}'},
                "steps": [{
                    "name": "install", "target": '${{ "orbit-node-" + string(inputs.intent.guest_node_id) }}',
                    "labels": {"node": '${{ string(inputs.intent.guest_node_id) }}'},
                    "task": {"type": "workflow", "workflowIdOrName": "task-vm-pi-install",
                             "inputs": {name: "${{ inputs." + name + " }}"
                                        for name in ("operation_id", "kind", "node_id", "intent")}},
                }, {
                    "name": "receipt", "target": '${{ "orbit-node-" + string(inputs.intent.guest_node_id) }}',
                    "labels": {"node": '${{ string(inputs.intent.guest_node_id) }}'},
                    "dependsOn": [{"step": "install", "condition": {"type": "succeeded"}}],
                    "task": {"type": "behavior", "behaviorType": "command/shell",
                             "behaviorName": '${{ "provision-pi-proof-" + run.id }}', "methodName": "execute",
                             "inputs": {"run": 'printf "%s\\n" "$ORBIT_PI_PUBLIC_RESULT"',
                                        "env": {"ORBIT_PI_PUBLIC_RESULT": "${{ steps.install.outputs.result.stdout }}"}}},
                }],
            }],
        }
        (self.repo / "workflows/workflow-provision-pi-fixture.yaml").write_text(yaml.safe_dump(parent, sort_keys=False))
        self.cli("workflow", "validate", "provision-pi-fixture")

    def run_parent(self, kind, success=True):
        inputs = {"operation_id": 7001, "kind": kind, "node_id": 9,
                  "intent": {"task_vm_id": 42, "guest_node_id": 209, "user": "orbit", "runtime": {"pi_sha256": SHA}}}
        result = reply(self.cli("workflow", "run", "provision-pi-fixture", "--input", json.dumps(inputs),
                                *self.flags, success=success).stdout)
        history = reply(self.cli("workflow", "history", "get", result["id"], *self.flags).stdout)
        return history, {step["name"]: step for step in history["jobs"][0]["steps"]}

    def test_task_parent_keeps_root_identity_and_exposes_only_verified_pi_result(self):
        history, steps = self.run_parent("tasks.provision")
        self.assertEqual(history["inputs"]["node_id"], 9)
        self.assertEqual(history["inputs"]["kind"], "tasks.provision")
        self.assertEqual(history["status"], "succeeded")
        self.assertEqual((self.home / ".local/bin/pi-server").read_bytes(), CONTENT)
        self.assertEqual(json.loads(steps["receipt"]["outputs"]["stdout"]),
                         {"task_vm_id": 42, "sha256": SHA, "installed": True})
        child = reply(self.cli("workflow", "history", "get", steps["install"]["nestedRun"]["runId"],
                               *self.flags).stdout)
        self.assertEqual(child["inputs"]["node_id"], 9)
        self.assertEqual(child["inputs"]["kind"], "tasks.provision")
        self.assertEqual(child["inputs"]["intent"]["guest_node_id"], 209)

    def test_corrupt_artifact_stops_vm_parent_without_a_completion_receipt(self):
        self.source.write_bytes(b"unadmitted artifact")
        history, steps = self.run_parent("task_vm.provision", success=False)
        self.assertEqual(history["status"], "failed")
        self.assertEqual(steps["install"]["status"], "failed")
        self.assertEqual(steps["receipt"]["status"], "skipped")
        self.assertFalse((self.home / ".local/bin/pi-server").exists())


if __name__ == "__main__":
    import unittest

    unittest.main()
