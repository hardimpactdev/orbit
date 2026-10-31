"""Run no-secret retention selection and its public JSON result on the native runtime."""

import json
import shutil
import sys
import time
import unittest
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parent))
from native_harness import ROOT, NativeTestCase


class ObservationRetentionNativeTest(NativeTestCase):
    def setUp(self):
        super().setUp()
        destination = self.repo / "extensions/behaviors/observation"
        destination.mkdir(parents=True)
        for name in ("maintenance_selection.ts", "retention.ts"):
            shutil.copy(ROOT / "extensions/behaviors/observation" / name, destination)
        workflow = yaml.safe_load(
            (
                ROOT / "workflows/workflow-observation-retention-selection.yaml"
            ).read_text()
        )
        for step in workflow["jobs"][0]["steps"]:
            step.pop("target")
            step.pop("labels")
        (
            self.repo / "workflows/workflow-observation-retention-selection.yaml"
        ).write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.intent = {
            "routine": "retention",
            "tasks_enabled": True,
            "activity_seconds": 900,
            "batch_hours": 24,
            "failed_hours": 168,
            "redis": None,
            "activities": [],
            "batches": [
                {
                    "id": "old-finished",
                    "created_at": 0,
                    "finished_at": 1,
                    "cancelled_at": None,
                },
                {
                    "id": "old-unfinished",
                    "created_at": 0,
                    "finished_at": None,
                    "cancelled_at": None,
                },
                {
                    "id": "old-cancelled",
                    "created_at": 0,
                    "finished_at": int(time.time()) + 60,
                    "cancelled_at": 1,
                },
                {
                    "id": "recent",
                    "created_at": int(time.time()) + 60,
                    "finished_at": None,
                    "cancelled_at": None,
                },
            ],
            "failed": [
                {"id": 7, "failed_at": "2020-01-01 00:00:00"},
                {"id": 8, "failed_at": "2099-01-01 00:00:00"},
            ],
        }

    def run_retention(self, success=True):
        self.cli("workflow", "validate", "observation-retention-selection")
        inputs = {
            "operation_id": 7,
            "node_id": 14,
            "kind": "observation.maintain",
            "intent": self.intent,
            "execution_secrets": {},
        }
        completed = self.cli(
            "workflow",
            "run",
            "observation-retention-selection",
            "--input",
            json.dumps(inputs),
            success=success,
        )
        return json.loads(
            self.cli(
                "workflow", "history", "get", json.loads(completed.stdout)["id"]
            ).stdout
        )

    def test_public_terminal_stdout_selects_only_expired_records_without_any_vault(
        self,
    ):
        history = self.run_retention()
        self.assertEqual(history["status"], "succeeded")
        result = json.loads(history["jobs"][0]["steps"][-1]["outputs"]["stdout"])
        self.assertEqual(
            result,
            {
                "activity_ids": [],
                "batch_ids": ["old-finished", "old-unfinished", "old-cancelled"],
                "failed_ids": [7],
                "snapshot_jobs": 0,
                "snapshot_queues": 0,
            },
        )

    def test_disabled_tasks_retains_batches_and_still_selects_expired_failed_jobs(self):
        self.intent["tasks_enabled"] = False
        history = self.run_retention()
        result = json.loads(history["jobs"][0]["steps"][-1]["outputs"]["stdout"])
        self.assertEqual(result["batch_ids"], [])
        self.assertEqual(result["failed_ids"], [7])

    def test_invalid_expiry_refuses_before_emitting_any_terminal_result(self):
        self.intent["failed"][0]["failed_at"] = "invalid"
        history = self.run_retention(success=False)
        self.assertEqual(history["status"], "failed")
        self.assertEqual(history["jobs"][0]["steps"][-1]["status"], "skipped")


if __name__ == "__main__":
    unittest.main()
