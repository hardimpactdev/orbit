"""Converted lifecycle kinds cannot enter the retained Node-worker callback DAG."""

import sys
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase


class RetiredLifecycleAdmission(NativeTestCase):
    def test_old_control_refuses_create_and_destroy_before_any_action(self):
        source = ROOT / "workflows/workflow-operation-process-control.yaml"
        workflow = yaml.safe_load(source.read_text())
        self.assertEqual(
            workflow["inputs"]["properties"]["kind"]["enum"],
            ["process.start", "process.restart"],
        )
        self.assertFalse(
            any(
                "create" in step["name"]
                for job in workflow["jobs"]
                for step in job["steps"]
            )
        )
        (self.repo / "workflows" / source.name).write_text(source.read_text())
        for kind in ["process.create", "process.destroy"]:
            reply = self.cli(
                "workflow",
                "run",
                "operation-process-control",
                "--input",
                "operation_id=17",
                "--input",
                "node_id=9",
                "--input",
                "kind=" + kind,
                success=False,
            )
            self.assertIn("kind", reply.stdout + reply.stderr)
