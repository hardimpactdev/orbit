"""Actual native graph and shell execution on disposable local source trees.

The remote pilot separately owns dispatch/vault proof. This fixture removes
placement only in its scratch copy; repository placement is linted unchanged.
"""

import getpass
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
            "protected_source_roots": [], "direct_destroy": {"teardown": []},
        }}

    def test_source_actions_execute_as_native_steps_without_callbacks(self):
        self.cli("workflow", "validate", "fixture-instance-source-removal")
        result = response(self.cli("workflow", "run", "fixture-instance-source-removal",
                                   "--input", json.dumps(self.inputs)).stdout)
        self.assertEqual(result["status"], "succeeded", result)
        self.assertFalse(self.checkout.exists())
        history = response(self.cli("workflow", "history", "get", result["id"]).stdout)
        self.assertEqual(history["inputs"], self.inputs)
        steps = {step["name"]: step for job in history["jobs"] for step in job["steps"]}
        for name in ["inspect-sources", "quarantine-71", "delete-tree-71", "delete-worktree-metadata-71"]:
            self.assertEqual(steps[name]["status"], "succeeded", steps[name])
        self.assertEqual(steps["delete-account-71"]["status"], "skipped")
        captured = json.loads(steps["inspect-sources"]["outputs"]["stdout"])
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


if __name__ == "__main__":
    unittest.main()
