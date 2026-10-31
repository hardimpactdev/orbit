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
        behavior = self.repo / "extensions/behaviors"
        behavior.mkdir(parents=True)
        (behavior / "rejected.ts").write_text(
            "import {z} from 'npm:zod@4.4.3';"
            "export const behavior={type:'@nckrtl/tool-direct',version:'2026.11.01.1',"
            "methods:{apply:{description:'Refuse before the first effect',"
            "arguments:z.object({}).passthrough(),execute:()=>{"
            "throw new Error('fixture pre-effect failure');}}}};"
        )
        flow = yaml.safe_load(
            (ROOT / "workflows/workflow-operation-tool-install-direct.yaml").read_text()
        )
        for job in flow["jobs"]:
            job.pop("target", None)
            job.pop("labels", None)
        (self.repo / "workflows/workflow-failure.yaml").write_text(
            yaml.safe_dump(flow, sort_keys=False)
        )

    def test_failed_family_retains_exact_native_root_without_a_failure_reporter(self):
        reply = self.cli(
            "workflow",
            "run",
            "operation-tool-install-direct",
            "--stdin",
            input=json.dumps(
                {"operation_id": 71, "node_id": 209, "kind": "tool.install"}
            ),
            success=False,
        )
        run = json.loads(reply.stdout)
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        self.assertEqual(history["status"], "failed")
        self.assertEqual(
            history["inputs"],
            {"operation_id": 71, "node_id": 209, "kind": "tool.install"},
        )
        self.assertEqual([job["name"] for job in history["jobs"]], ["tools"])
        self.assertEqual(history["jobs"][0]["steps"][0]["status"], "failed")


if __name__ == "__main__":
    unittest.main()
