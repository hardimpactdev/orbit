"""Actual native graph and shell execution on disposable local source trees.

The remote pilot separately owns dispatch/vault proof. This fixture removes
placement only in its scratch copy; repository placement is linted unchanged.
"""

import copy
import getpass
import hashlib
import json
import subprocess
import sys
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[5]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase


def response(text):
    for index, char in enumerate(text):
        if char == "{":
            try:
                return json.loads(text[index:])
            except ValueError:
                pass
    raise AssertionError(text)


class NativeSourceRemoval(NativeTestCase):
    native_timeout = 60

    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/instance-lifecycle/removal")
        family = ROOT / "extensions/behaviors/operations/instance-lifecycle/removal"
        workflow = yaml.safe_load((family / "source-steps.yaml").read_text())
        workflow.update({
            "id": "10c1e646-19cd-4527-8be5-01f524976346",
            "name": "fixture-instance-source-removal",
            "inputs": {"type": "object", "properties": {
                "node_id": {"type": "integer"}, "intent": {"type": "object"},
                "execution_secrets": {"type": "object"},
            }, "required": ["node_id", "intent"]},
        })
        workflow["jobs"].insert(1, {
            "name": "cleanup", "dependsOn": [{"job": "capture_sources", "condition": {"type": "succeeded"}}],
            "steps": [{"name": "fixture-no-owned-publication-or-database", "task": {
                "type": "behavior", "behaviorType": "command/shell", "methodName": "execute",
                "behaviorName": '${{ "fixture-cleanup-" + run.id }}',
                "inputs": {"run": "printf '%s\\n' '{\"cleaned\":true}'"},
            }}],
        })
        for job in workflow["jobs"]:
            job.pop("target", None)
            job.pop("labels", None)
            for step in job["steps"]:
                step.pop("target", None)
                step.pop("labels", None)
        (self.repo / "workflows/workflow-fixture-instance-source-removal.yaml").write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.checkout = self.repo / "source/fixture/main"
        self.checkout.mkdir(parents=True)
        git = ["git", "-C", str(self.checkout)]
        for arguments in [
            ["init", "-q", "-b", "main"], ["config", "user.email", "fixture@example.invalid"],
            ["config", "user.name", "Removal fixture"],
            ["remote", "add", "origin", "https://github.com/hardimpactdev/fixture.git"],
        ]:
            subprocess.run(git + arguments, check=True, capture_output=True)
        (self.checkout / "file").write_text("fixture\n")
        subprocess.run(git + ["add", "."], check=True, capture_output=True)
        subprocess.run(git + ["commit", "-qm", "fixture"], check=True, capture_output=True)
        commit = subprocess.check_output(git + ["rev-parse", "HEAD"], text=True).strip()
        member = {"instance_id": 71, "project_id": 11, "environment": "development",
                  "user": getpass.getuser(), "checkout_path": str(self.checkout),
                  "source_layout": "checkout", "source_root": str(self.repo / "source"),
                  "starting_commit": commit, "branch": "main", "failed_creation": False,
                  "repository_identity": "github.com/hardimpactdev/fixture"}
        self.inputs = {"node_id": 9, "intent": {
            "instance_id": 71, "project_id": 11, "node_id": 9, "environment": "development",
            "removal_id": "1699c2db-8119-4439-a685-7e555f6a0641", "force": True,
            "repository": "https://github.com/hardimpactdev/fixture.git", "members": [member],
            "protected_source_roots": [], "direct_destroy": {"teardown": [], "runtime": {"processes": [], "schedules": []}},
        }, "execution_secrets": {}}

    def test_source_actions_execute_as_native_steps_without_callbacks(self):
        self.cli("workflow", "validate", "fixture-instance-source-removal")
        result = response(self.cli("workflow", "run", "fixture-instance-source-removal",
                                   "--input", json.dumps(self.inputs)).stdout)
        self.assertEqual(result["status"], "succeeded", result)
        self.assertFalse(self.checkout.exists())
        history = response(self.cli("workflow", "history", "get", result["id"]).stdout)
        self.assertEqual(history["inputs"], self.inputs)
        steps = {step["name"]: step for job in history["jobs"] for step in job["steps"]}
        for name in ["inspect-sources", "quarantine-71", "delete-tree-71"]:
            self.assertEqual(steps[name]["status"], "succeeded", steps[name])
        self.assertEqual(steps["delete-account-71"]["status"], "skipped")
        captured = json.loads(steps["inspect-sources"]["outputs"]["stdout"])["source_inspection"]
        self.assertEqual(captured["members"][0]["origin"], self.inputs["intent"]["repository"])

    def test_origin_refusal_skips_all_destructive_steps(self):
        self.inputs["intent"]["members"][0]["repository_identity"] = "github.com/another/fixture"
        result = response(self.cli("workflow", "run", "fixture-instance-source-removal",
                                   "--input", json.dumps(self.inputs), success=False).stdout)
        self.assertEqual(result["status"], "failed")
        self.assertTrue(self.checkout.exists())
        history = response(self.cli("workflow", "history", "get", result["id"]).stdout)
        final = next(job for job in history["jobs"] if job["name"] == "finalize_sources")
        self.assertTrue(all(step["status"] == "skipped" for step in final["steps"]))

    def test_linked_worktree_removal_preserves_the_common_checkout(self):
        primary = self.checkout
        linked = self.repo / "source/fixture/feature"
        subprocess.run(["git", "-C", str(primary), "worktree", "add", "-qb", "feature", str(linked)],
                       check=True, capture_output=True)
        self.inputs["intent"]["members"][0].update(checkout_path=str(linked), source_layout="worktree", branch="feature")
        result = response(self.cli("workflow", "run", "fixture-instance-source-removal",
                                   "--input", json.dumps(self.inputs)).stdout)
        self.assertEqual(result["status"], "succeeded", result)
        self.assertTrue(primary.is_dir())
        self.assertFalse(linked.exists())
        worktrees = subprocess.check_output(["git", "-C", str(primary), "worktree", "list", "--porcelain"], text=True)
        self.assertIn(str(primary), worktrees)
        self.assertNotIn(str(linked), worktrees)

    def test_checkout_with_an_unadmitted_linked_worktree_refuses_before_effects(self):
        linked = self.repo / "source/fixture/foreign"
        subprocess.run(["git", "-C", str(self.checkout), "worktree", "add", "-qb", "foreign", str(linked)],
                       check=True, capture_output=True)
        result = response(self.cli("workflow", "run", "fixture-instance-source-removal",
                                   "--input", json.dumps(self.inputs), success=False).stdout)
        self.assertEqual(result["status"], "failed", result)
        self.assertTrue(self.checkout.is_dir())
        self.assertTrue(linked.is_dir())

    def test_checkout_and_linked_worktree_are_removed_in_the_same_graph(self):
        linked = self.repo / "source/fixture/feature"
        subprocess.run(["git", "-C", str(self.checkout), "worktree", "add", "-qb", "feature", str(linked)],
                       check=True, capture_output=True)
        member = copy.deepcopy(self.inputs["intent"]["members"][0])
        member.update(instance_id=72, checkout_path=str(linked), source_layout="worktree", branch="feature")
        self.inputs["intent"]["members"].append(member)
        result = response(self.cli("workflow", "run", "fixture-instance-source-removal",
                                   "--input", json.dumps(self.inputs)).stdout)
        self.assertEqual(result["status"], "succeeded", result)
        self.assertFalse(linked.exists())
        self.assertFalse(self.checkout.exists())

    def test_vault_teardown_is_ordered_and_each_command_is_a_native_step(self):
        family = ROOT / "extensions/behaviors/operations/instance-lifecycle/removal"
        workflow_path = self.repo / "workflows/workflow-fixture-instance-source-removal.yaml"
        workflow = yaml.safe_load(workflow_path.read_text())
        middle = yaml.safe_load((family / "publication-step.yaml").read_text())["jobs"]
        middle += yaml.safe_load((family / "runtime-steps.yaml").read_text())["jobs"]
        for job in middle:
            job.pop("target", None)
            job.pop("labels", None)
            for step in job["steps"]:
                step.pop("target", None)
                step.pop("labels", None)
        workflow["vaults"] = ["orbit-control-inputs"]
        workflow["jobs"][1]["dependsOn"] = [{"job": "verify_sources", "condition": {"type": "succeeded"}}]
        workflow["jobs"][1:1] = middle
        workflow_path.write_text(yaml.safe_dump(workflow, sort_keys=False))
        order = self.repo / "teardown-order"
        steps = []
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        for index in range(12):
            script = f"printf '%s\\n' {index} >> '{order}'"
            key = "fixture-teardown-" + str(index)
            self.cli("vault", "put", "orbit-control-inputs", key, input=script)
            steps.append({"name": "step-" + str(index), "timeout_seconds": 30,
                          "sha256": hashlib.sha256(script.encode()).hexdigest()})
            self.inputs["execution_secrets"]["teardown_71_" + str(index)] = {"vault": "orbit-control-inputs", "key": key}
        self.inputs["intent"]["direct_destroy"]["teardown"] = [{
            "instance_id": 71, "checkout": str(self.checkout), "user": getpass.getuser(), "steps": steps,
        }]
        self.cli("workflow", "validate", "fixture-instance-source-removal")
        result = response(self.cli("workflow", "run", "fixture-instance-source-removal",
                                   "--input", json.dumps(self.inputs)).stdout)
        self.assertEqual(result["status"], "succeeded", result)
        self.assertEqual(order.read_text().splitlines(), [str(index) for index in range(12)])
        history = response(self.cli("workflow", "history", "get", result["id"]).stdout)
        teardown = next(job for job in history["jobs"] if job["name"] == "teardown")
        self.assertEqual(len(teardown["steps"]), 12)
        self.assertTrue(all(step["status"] == "succeeded" for step in teardown["steps"]))
        inspected = next(step for job in history["jobs"] for step in job["steps"] if step["name"] == "inspect-sources")
        original = json.loads(inspected["outputs"]["stdout"])["source_inspection"]
        self.inputs["intent"]["captured_sources"] = original
        retry = response(self.cli("workflow", "run", "fixture-instance-source-removal",
                                  "--input", json.dumps(self.inputs)).stdout)
        self.assertEqual(retry["status"], "succeeded", retry)
        self.assertEqual(order.read_text().splitlines(), [str(index) for index in range(12)])
        history = response(self.cli("workflow", "history", "get", retry["id"]).stdout)
        inspected = next(step for job in history["jobs"] for step in job["steps"] if step["name"] == "inspect-sources")
        self.assertEqual(json.loads(inspected["outputs"]["stdout"])["source_inspection"], original)


if __name__ == "__main__":
    unittest.main()
