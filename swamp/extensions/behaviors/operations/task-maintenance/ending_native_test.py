"""Exercise the Tasks ending parent with official SSH and a typed canonical-child fixture."""

import importlib.util
import json
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
spec = importlib.util.spec_from_file_location(
    "repository_fixture",
    ROOT / "extensions/behaviors/operations/task-repository/native_test.py",
)
fixture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture)


class NativeEnding(fixture.NativeRepository):
    test_analysis_native_dag_retains_exact_report = None
    test_private_analysis_is_refused_before_native_history = None
    test_script_native_steps_return_business_failure_with_literal_arguments = None
    test_native_resume_after_result_failure_never_reexecutes_program = None
    native_timeout = 150

    def setUp(self):
        super().setUp()
        models = self.repo / "extensions/models"
        models.mkdir(parents=True, exist_ok=True)
        (models / "destroy_fixture.ts").write_text("""import {z} from "npm:zod@4.4.3";
export const behavior={type:"@fixture/destroy",version:"2026.11.01.1",resources:{result:{schema:z.object({result_json:z.string()}),lifetime:"1d",garbageCollection:10}},methods:{result:{description:"Fixed typed child fixture",arguments:z.object({intent:z.record(z.string(),z.unknown())}),execute:async(args,ctx)=>({dataHandles:[await ctx.writeResource("result","result",{result_json:JSON.stringify(args.intent.fixture_result)})]})}}};""")
        child = {
            "id": "a5dc9328-9812-4f24-9055-d874830f9bb7",
            "name": "task-vm-destroy",
            "inputs": {
                "properties": {
                    "operation_id": {"type": "integer"},
                    "node_id": {"type": "integer"},
                    "kind": {"type": "string"},
                    "intent": {"type": "object"},
                    "execution_secrets": {"type": "object"},
                }
            },
            "jobs": [
                {
                    "name": "destroy",
                    "steps": [
                        {
                            "name": "result",
                            "task": {
                                "type": "model_method",
                                "modelType": "@fixture/destroy",
                                "modelName": "destroy-fixture",
                                "methodName": "result",
                                "inputs": {"intent": "${{ inputs.intent }}"},
                            },
                        }
                    ],
                }
            ],
        }
        (self.repo / "workflows/workflow-destroy-fixture.yaml").write_text(
            yaml.safe_dump(child)
        )
        self.inputs["kind"] = "tasks.cancel"
        self.inputs["intent"].update(
            project_id=5,
            teardown=[],
            fixture_result={
                "task_vm_id": 10,
                "guest_node_id": 3,
                "destroyed": True,
                "cleanup_complete": True,
            },
        )
        self.workflow("cancel")
        flow = yaml.safe_load(
            (ROOT / "workflows/workflow-task-workspace-teardown.yaml").read_text()
        )
        for job in flow["jobs"]:
            job.pop("target", None)
            for step in job["steps"]:
                self.assertEqual(step.pop("target"), "orbit-node-209")
                step.pop("labels", None)
                for key, value in list(step["task"].get("inputs", {}).items()):
                    if isinstance(value, str):
                        step["task"]["inputs"][key] = value.replace(
                            "/tmp/orbit-task-repository-",
                            str(self.repo / "remote-assets-"),
                        )
                    elif isinstance(value, list):
                        step["task"]["inputs"][key] = [
                            v.replace(
                                "/tmp/orbit-task-repository-",
                                str(self.repo / "remote-assets-"),
                            )
                            if isinstance(v, str)
                            else v
                            for v in value
                        ]
        (self.repo / "workflows/workflow-teardown.yaml").write_text(
            yaml.safe_dump(flow, sort_keys=False)
        )

    def workflow(self, kind):
        self.flow = yaml.safe_load(
            (ROOT / f"workflows/workflow-operation-task-{kind}.yaml").read_text()
        )
        self.flow["name"] = "task-repository-fixture"
        for job in self.flow["jobs"]:
            self.assertEqual(job.pop("target"), "orbit-node-209")
            job.pop("labels", None)
            for step in job["steps"]:
                self.assertEqual(step.pop("target"), "orbit-node-209")
                step.pop("labels", None)
                inputs = step["task"].get("inputs", {})
                for key, value in list(inputs.items()):
                    if isinstance(value, str):
                        inputs[key] = value.replace(
                            "/tmp/orbit-task-repository-",
                            str(self.repo / "remote-assets-"),
                        )
                    elif isinstance(value, list):
                        inputs[key] = [
                            v.replace(
                                "/tmp/orbit-task-repository-",
                                str(self.repo / "remote-assets-"),
                            )
                            if isinstance(v, str)
                            else v
                            for v in value
                        ]
        self.write_workflow()

    def receipt(self, run):
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        return json.loads(
            next(s for s in history["jobs"][0]["steps"] if s["name"] == "result")[
                "outputs"
            ]["stdout"]
        )

    def test_empty_workspace_requires_no_guest_or_child_effect(self):
        self.inputs["intent"].update(instance_id=None, ssh_execution=None)
        run = self.run_workflow()
        self.assertEqual(
            self.receipt(run),
            {"group_id": 1, "instance_id": None, "cleanup_complete": True, "vm": {}},
        )

    def test_owned_workspace_receipt_preserves_the_canonical_child_result(self):
        run = self.run_workflow()
        self.assertTrue(self.receipt(run)["cleanup_complete"])
        self.assertEqual(
            self.receipt(run)["vm"], self.inputs["intent"]["fixture_result"]
        )

    def test_successful_teardown_precedes_the_canonical_destroy_child(self):
        self.inputs["intent"]["teardown"] = [
            {
                "timeout_seconds": 30,
                "input_json": json.dumps(
                    {
                        "name": "teardown",
                        "command": "printf complete > teardown-complete",
                        "timeout_seconds": 30,
                    }
                ),
            }
        ]
        run = self.run_workflow()
        self.assertEqual((self.workspace / "teardown-complete").read_text(), "complete")
        self.assertTrue(self.receipt(run)["cleanup_complete"])

    def test_teardown_failure_does_not_claim_vm_cleanup(self):
        self.inputs["intent"]["teardown"] = [
            {
                "timeout_seconds": 30,
                "input_json": json.dumps(
                    {
                        "name": "teardown",
                        "command": "printf started > teardown-started; exit 23",
                        "directory": ".",
                        "timeout_seconds": 30,
                        "required": True,
                    }
                ),
            }
        ]
        run = self.run_workflow(success=False)
        self.assertEqual(run["status"], "failed")
        self.assertEqual((self.workspace / "teardown-started").read_text(), "started")
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        states = {s["name"]: s["status"] for s in history["jobs"][0]["steps"]}
        self.assertNotEqual(states.get("vm"), "succeeded")
        self.assertNotEqual(states.get("result"), "succeeded")


if __name__ == "__main__":
    import unittest

    unittest.main()
