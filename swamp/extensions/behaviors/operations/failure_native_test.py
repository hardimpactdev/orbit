"""Native root failure is retained without a step-side Gateway callback."""

import json
import sys
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase


class NativeFailureTest(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/tools', 'operations/node-bootstrap')
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        behavior = self.repo / "extensions/behaviors"
        behavior.mkdir(parents=True, exist_ok=True)
        (behavior / "rejected.ts").write_text(
            "import {z} from 'npm:zod@4.4.3';"
            "export const behavior={type:'@nckrtl/fixture-rejected',version:'2026.11.01.1',"
            "methods:{apply:{description:'Refuse before the first effect',"
            "arguments:z.object({}).passthrough(),execute:()=>{"
            "throw new Error('fixture pre-effect failure');}}}};"
        )
        flow = yaml.safe_load(
            (ROOT / "workflows/workflow-operation-tool-write-209.yaml").read_text()
        )
        for job in flow["jobs"]:
            self.assertEqual(job.pop("target"), "orbit-node-209")
            job.pop("labels", None)
            for step in job["steps"]:
                self.assertEqual(step.pop("target"), "orbit-node-209")
                if step["name"] == "initial_query":
                    step["task"] = {"type": "behavior", "behaviorType": "@nckrtl/fixture-rejected", "behaviorName": "rejected-${{ run.id }}", "behaviorVersion": "2026.11.01.1", "methodName": "apply"}
        (self.repo / "workflows/workflow-failure.yaml").write_text(
            yaml.safe_dump(flow, sort_keys=False)
        )

    def test_failed_family_retains_exact_native_root_without_a_failure_reporter(self):
        inputs = {"operation_id": 71, "node_id": 209, "kind": "tool.install", "execution_secrets": {},
                  "intent": {"kind": "tool.install", "node_id": 209, "manager": "apt", "package": "hello", "user": "orbit", "platform": "linux", "architecture": "x86_64", "setup": None},
                  "ssh": {"control_node_id": 209, "identity_file": "/unused/fixture", "target": {"name": "target", "host": "node.invalid", "port": 22, "user": "orbit", "host_keys": ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"]}}}
        reply = self.cli(
            "workflow",
            "run",
            "operation-tool-write-209",
            "--stdin",
            input=json.dumps(inputs),
            success=False,
        )
        self.assertTrue(reply.stdout.strip(), reply.stderr)
        run = json.loads(reply.stdout)
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        self.assertEqual(history["status"], "failed")
        self.assertEqual(
            history["inputs"],
            inputs,
        )
        self.assertEqual([job["name"] for job in history["jobs"]], ["tools"])
        steps = {step["name"]: step for step in history["jobs"][0]["steps"]}
        self.assertEqual(steps["initial_query-0"]["status"], "failed")
        self.assertEqual(json.loads(steps["result"]["outputs"]["stdout"])["error_code"], "tool.version_probe_failed")


if __name__ == "__main__":
    unittest.main()
