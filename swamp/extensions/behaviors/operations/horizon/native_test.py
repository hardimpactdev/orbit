"""Horizon's production DAG on unmodified cfgmgmt/SSH; reuse the Process host fixture."""

import copy
import hashlib
import importlib.util
import json
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
spec = importlib.util.spec_from_file_location(
    "process_native",
    ROOT / "extensions/behaviors/operations/process-commands/process_native_test.py",
)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class HorizonNative(module.ProcessFixture):
    def setUp(self):
        super().setUp()
        self.php = self.repo / "horizon-command"
        self.php.write_text("#!/bin/sh\ntouch " + str(self.repo / "terminated") + "\n")
        self.php.chmod(0o700)
        self.unit_name = self.unit.name
        workflow = yaml.safe_load(
            (
                ROOT / "workflows/workflow-operation-process-horizon-handoff.yaml"
            ).read_text()
        )
        process = yaml.safe_load(
            (self.repo / "workflows/workflow-operation-process-create.yaml").read_text()
        )
        ssh = next(
            s["task"]["globalArgs"]
            for s in process["jobs"][0]["steps"]
            if s["task"].get("behaviorType") == "@swamp/ssh"
        )
        owner = next(
            s["task"]["globalArgs"]
            for s in process["jobs"][0]["steps"]
            if s["task"].get("behaviorType") == "@swamp/cfgmgmt/file"
        )
        for job in workflow["jobs"]:
            self.assertEqual(job.pop("labels"), {"role": "swamp"})
            for step in job["steps"]:
                self.assertEqual(step.pop("labels"), {"role": "swamp"})
                task = step["task"]
                if task.get("behaviorType") == "@swamp/ssh":
                    task["globalArgs"].update(
                        sshBinary=ssh["sshBinary"], scpBinary=ssh["scpBinary"]
                    )
        (
            self.repo / "workflows/workflow-operation-process-horizon-handoff.yaml"
        ).write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.inputs = {
            "operation_id": 17,
            "node_id": 9,
            "kind": "process.horizon_handoff",
            "execution_secrets": {},
            "intent": {
                "process_id": 17,
                "unit": self.unit_name,
                "content": self.content,
                "content_hash": hashlib.sha256(self.content.encode()).hexdigest(),
                "command": [str(self.php), "artisan", "horizon"],
                "directory": str(self.repo),
                "user": owner["owner"],
                "created": True,
                "ssh_execution": copy.deepcopy(self.inputs["intent"]["ssh_execution"]),
            },
        }

    def handoff(self, success=True):
        self.cli("workflow", "validate", "operation-process-horizon-handoff")
        run = self.cli(
            "workflow",
            "run",
            "operation-process-horizon-handoff",
            "--stdin",
            input=json.dumps(self.inputs),
            success=success,
        )
        decoder = json.JSONDecoder()
        for i, char in enumerate(run.stdout):
            if char == "{":
                try:
                    value, end = decoder.raw_decode(run.stdout[i:])
                    if not run.stdout[i + end :].strip():
                        return value
                except ValueError:
                    pass
        self.fail("Native run result missing")

    def load(self):
        return json.loads(self.state.read_text())

    def active(self):
        self.unit.write_text(self.content)
        self.save(
            units={self.unit_name: {"active": "active", "enabled": True}},
            calls=[],
            failures=[],
        )

    def test_missing_unit_is_written_enabled_and_started(self):
        self.handoff()
        self.assertEqual(self.unit.read_text(), self.content)
        self.assertIn(["daemon-reload"], self.load()["calls"])
        self.assertIn(["start", self.unit_name], self.load()["calls"])
        self.assertFalse((self.repo / "terminated").exists())

    def test_active_matching_unit_only_hands_off(self):
        self.active()
        self.handoff()
        self.assertTrue((self.repo / "terminated").exists())
        self.assertFalse(
            any(
                c[0] in ["daemon-reload", "enable", "start", "restart", "stop"]
                for c in self.load()["calls"]
            )
        )

    def test_changed_active_unit_is_reloaded_and_handed_off_without_restart(self):
        self.active()
        self.unit.write_text("[Unit]\nX-Orbit-Process-ID=99\n")
        self.handoff()
        self.assertEqual(self.unit.read_text(), self.content)
        self.assertIn(["daemon-reload"], self.load()["calls"])
        self.assertTrue((self.repo / "terminated").exists())
        self.assertFalse(
            any(c[0] in ["start", "restart", "stop"] for c in self.load()["calls"])
        )

    def test_failed_enable_keeps_the_unit_and_rerun_converges(self):
        self.save(units={}, calls=[], failures=["enable"])
        self.handoff(success=False)
        self.assertEqual(self.unit.read_text(), self.content)
        self.assertFalse(any(c[0] == "start" for c in self.load()["calls"]))
        self.handoff()
        self.assertEqual(self.load()["units"][self.unit_name]["active"], "active")

    def test_failed_termination_fails_native_run_and_rerun_hands_off(self):
        self.active()
        original = self.php.read_text()
        self.php.write_text("#!/bin/sh\nexit 1\n")
        self.handoff(success=False)
        self.assertFalse((self.repo / "terminated").exists())
        self.php.write_text(original)
        self.handoff()
        self.assertTrue((self.repo / "terminated").exists())
