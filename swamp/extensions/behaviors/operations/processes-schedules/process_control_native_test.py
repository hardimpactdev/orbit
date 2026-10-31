"""Run the retained Process control workflow through native binding and recovery."""

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase


class DirectControlRecovery:
    def test_native_dag_executes_one_local_action_without_gateway_execution(self):
        self.run_workflow()
        state = self.read()
        self.assertEqual(state["actions"], 1)
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertTrue(
            all("/direct-operations/17" in call["url"] for call in state["calls"])
        )

    def test_lost_committed_projection_is_read_without_another_action(self):
        self.state["loseProjection"] = True
        self.save()
        self.run_workflow(success=False)
        self.cli(
            "workflow",
            "resume",
            "operation-process-control",
            "--run",
            self.run_id,
            "--from",
            "control",
        )
        self.assertEqual(self.read()["actions"], 1)
        self.assertEqual(self.read()["operation"]["status"], "succeeded")

    def test_native_refusal_is_projected_before_the_native_run_fails(self):
        self.state["refuse"] = True
        self.save()
        self.run_workflow(success=False)
        self.assertEqual(self.read()["operation"]["status"], "failed")
        self.assertEqual(self.read()["actions"], 1)

    def test_foreign_binding_refuses_until_corrected_then_native_resume_succeeds(self):
        self.state["unbound"] = True
        self.save()
        self.run_workflow(success=False)
        state = self.read()
        self.assertEqual(state["actions"], 0)
        self.assertEqual(len(state["calls"]), self.binding_failure_calls)
        if self.binding_failure_calls == 2:
            self.assertTrue(state["calls"][-1]["url"].endswith("/failure"))
        state["unbound"] = False
        self.state = state
        self.save()
        self.cli(
            "workflow",
            "resume",
            "operation-process-control",
            "--run",
            self.run_id,
            "--from",
            "control",
        )
        self.assertEqual(self.read()["actions"], 1)
        self.assertEqual(self.read()["operation"]["status"], "succeeded")


class ProcessNative(DirectControlRecovery, NativeTestCase):
    binding_failure_calls = 1

    def setUp(self):
        super().setUp()
        self.copy_behaviors(
            "automatic-deploy",
            "operation-runner",
            "operations/_lib",
            "operations/processes-schedules",
        )
        helper = self.repo / "extensions/behaviors/operations/node/system_command.ts"
        helper.parent.mkdir(parents=True, exist_ok=True)
        helper.write_bytes(
            (
                ROOT / "extensions/behaviors/operations/node/system_command.ts"
            ).read_bytes()
        )
        module = (
            self.repo
            / "extensions/behaviors/operations/processes-schedules/process-control.ts"
        )
        module.write_text(
            'import {http, action} from "./process-control_native_fixture.ts";\n'
            + module.read_text().replace(
                "export const behavior = processControl();",
                "export const behavior = processControl(() => http, action);",
            )
        )
        source = ROOT / "workflows/workflow-operation-process-control.yaml"
        text = (
            "\n".join(
                line
                for line in source.read_text().splitlines()
                if not line.lstrip().startswith(("labels:", "target:"))
            )
            + "\n"
        )
        (self.repo / "workflows" / source.name).write_text(text)
        self.state = {
            "operation": {
                "id": 17,
                "node_id": 9,
                "kind": "process.destroy",
                "status": "queued",
                "cancel_requested": False,
                "result": None,
                "input": {
                    "process_id": 7,
                    "agent_sha256": "a" * 64,
                    "runtime": "systemd",
                    "name": "orbit-process-7-proof.service",
                    "on_demand": False,
                    "vite": None,
                },
            },
            "actions": 0,
            "calls": [],
        }
        self.save()

    def save(self):
        (self.repo / "schedule-native.json").write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / "schedule-native.json").read_text())

    def run_workflow(self, success=True):
        result = self.cli(
            "workflow",
            "run",
            "operation-process-control",
            "--input",
            "operation_id=17",
            "--input",
            "node_id=9",
            "--input",
            "kind=process.destroy",
            success=success,
        )
        self.run_id = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]["id"]
