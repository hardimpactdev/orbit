"""Native Analytics parent and real Process child DAGs against a disposable host model."""

import hashlib
import json
import sys
import uuid
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from tests.native_harness import NativeTestCase


class AnalyticsRuntimeNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors(
            "operations/analytics-runtime", "operations/process-commands"
        )
        support = self.repo / "extensions/behaviors/operations/processes-schedules"
        support.mkdir()
        for name in (
            "container-input.ts",
            "installation-input.ts",
            "process-input.ts",
            "required-mount-paths.ts",
        ):
            (support / name).write_text(
                (
                    ROOT / "extensions/behaviors/operations/processes-schedules" / name
                ).read_text()
            )
        self.secret = "native-runtime-private-" + uuid.uuid4().hex
        self.secrets = (self.secret,)
        environment = json.dumps({"SECRET_KEY_BASE": self.secret})
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        self.cli(
            "vault",
            "put",
            "orbit-control-inputs",
            "process-create-operation-12",
            input=environment,
        )
        self.state = self.repo / "fixture-state.json"
        self.state.write_text(
            json.dumps(
                {
                    "mode": "",
                    "spec": "a" * 64,
                    "present": True,
                    "state": "running",
                    "calls": [],
                    "intent": {},
                }
            )
        )
        fixture = (
            Path(__file__).parent / "_support/analytics_runtime_host.ts"
        ).read_text()
        fixture = fixture.replace(
            "FIXTURE_STATE_PATH", json.dumps(str(self.state))
        ).replace("FIXTURE_ENV_HASH", hashlib.sha256(environment.encode()).hexdigest())
        (self.repo / "extensions/behaviors/fixture.ts").write_text(fixture)
        for filename in (
            "workflow-operation-analytics-update-direct.yaml",
            "workflow-operation-process-create.yaml",
            "workflow-operation-process-destroy.yaml",
        ):
            workflow = yaml.safe_load((ROOT / "workflows" / filename).read_text())
            for job in workflow["jobs"]:
                if "target" in job:
                    self.assertEqual(job.pop("target"), "orbit-node-209")
                for step in job["steps"]:
                    self.assertEqual(step.pop("target"), "orbit-node-209")
                    self.assertNotIn("labels", step)
                    task = step["task"]
                    if task.get("behaviorType", "").startswith(
                        ("@swamp/ssh", "@swamp/cfgmgmt/")
                    ):
                        task["behaviorType"] = "@fixture/analytics-host"
            (self.repo / "workflows" / filename).write_text(
                yaml.safe_dump(workflow, sort_keys=False)
            )
        definition = {
            "process_id": 9,
            "container": "orbit-process-9-plausible",
            "desired": "running",
            "spec": "a" * 64,
            "image": "ghcr.io/plausible/community-edition:v3.2.1",
            "command": ["/entrypoint.sh", "run"],
            "restart": "unless-stopped",
            "working_directory": None,
            "ports": [],
            "mounts": [],
        }
        self.inputs = {
            "operation_id": 12,
            "node_id": 3,
            "kind": "analytics.update",
            "intent": {
                "process_id": 9,
                "assignment_id": 1,
                "version": "3.3.0",
                "previous_version": "3.2.1",
                "before": definition,
                "after": {
                    **definition,
                    "image": "ghcr.io/plausible/community-edition:v3.3.0",
                    "spec": "b" * 64,
                },
                "health_url": "http://192.0.2.3:8000/api/health",
                "ssh_execution": {
                    "control_node_id": 209,
                    "identity_file": "/approved",
                    "target": {
                        "host": "192.0.2.3",
                        "user": "orbit",
                        "port": 22,
                        "host_keys": ["ssh-ed25519 fixture"],
                    },
                },
            },
            "execution_secrets": {
                "environment": {
                    "vault": "orbit-control-inputs",
                    "key": "process-create-operation-12",
                }
            },
        }

    def run_update(self, mode="", success=False):
        state = json.loads(self.state.read_text())
        state["mode"] = mode
        self.state.write_text(json.dumps(state))
        started = json.loads(
            self.cli(
                "workflow",
                "run",
                "operation-analytics-update-direct",
                "--input",
                json.dumps(self.inputs),
                success=success,
            ).stdout
        )
        history = json.loads(
            self.cli("workflow", "history", "get", started["id"]).stdout
        )
        step = next(s for s in history["jobs"][0]["steps"] if s["name"] == "result")
        return (
            history,
            json.loads(step["outputs"]["stdout"]),
            json.loads(self.state.read_text()),
        )

    def test_success_uses_distinct_native_initialization_and_real_process_children(
        self,
    ):
        _, result, state = self.run_update(success=True)
        self.assertEqual(
            result, {"ready": True, "version": "3.3.0", "previous_version": "3.2.1"}
        )
        self.assertEqual(state["spec"], "b" * 64)
        self.assertEqual(state["state"], "running")
        self.assertEqual(state["calls"].count("createdb"), 1)
        self.assertEqual(state["calls"].count("migrate"), 1)
        self.assertIn("create:b", state["calls"])
        self.assertIn("remove", state["calls"])

    def test_initialization_failure_preserves_original_and_does_not_replace(self):
        _, result, state = self.run_update("initialization")
        self.assertTrue(result["rolled_back"])
        self.assertEqual(state["spec"], "a" * 64)
        self.assertNotIn("remove", state["calls"])

    def test_health_failure_restores_captured_prior_definition(self):
        _, result, state = self.run_update("health")
        self.assertTrue(result["rolled_back"])
        self.assertEqual(state["spec"], "a" * 64)
        self.assertEqual(state["state"], "running")
        self.assertIn("create:b", state["calls"])
        self.assertIn("create:a", state["calls"])

    def test_restore_failure_is_not_reported_as_successful_recovery(self):
        _, result, state = self.run_update("restore")
        self.assertFalse(result["rolled_back"])
        self.assertNotEqual(state["state"], "running")

    def test_failed_install_from_absence_restores_original(self):
        _, result, state = self.run_update("install")
        self.assertTrue(result["rolled_back"])
        self.assertEqual(state["spec"], "a" * 64)

    def test_plain_resume_after_rollback_cannot_pin_the_old_container_as_new(self):
        history, _, _ = self.run_update("health")
        state = json.loads(self.state.read_text())
        state["mode"] = ""
        self.state.write_text(json.dumps(state))
        self.cli(
            "workflow",
            "resume",
            "operation-analytics-update-direct",
            "--run",
            history["id"],
            success=False,
        )
        resumed = json.loads(
            self.cli("workflow", "history", "get", history["id"]).stdout
        )
        result = next(s for s in resumed["jobs"][0]["steps"] if s["name"] == "result")
        self.assertNotIn("ready", json.loads(result["outputs"]["stdout"]))
        self.assertEqual(json.loads(self.state.read_text())["spec"], "a" * 64)

    def test_partial_removal_failure_restores_original(self):
        _, result, state = self.run_update("partial-remove")
        self.assertTrue(result["rolled_back"])
        self.assertEqual(state["spec"], "a" * 64)
        self.assertEqual(state["state"], "running")

    def test_unchanged_health_failure_never_removes_original(self):
        state = json.loads(self.state.read_text())
        state["spec"] = "b" * 64
        self.state.write_text(json.dumps(state))
        _, result, state = self.run_update("health")
        self.assertTrue(result["rolled_back"])
        self.assertNotIn("remove", state["calls"])
        self.assertNotIn("createdb", state["calls"])
        self.assertEqual(state["spec"], "b" * 64)
