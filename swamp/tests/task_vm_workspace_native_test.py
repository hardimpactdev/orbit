"""Official SSH workspace child in a disposable runtime; never contacts a fleet VM."""

import json
import sys
import unittest
from pathlib import Path

import yaml
from native_harness import ROOT, NativeTestCase


class TaskVmWorkspaceNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.cli("extension", "pull", "@swamp/ssh@2026.10.23.1")
        self.copy_behaviors("operations/task-vm-workspace")
        self.calls = self.repo / "calls.jsonl"
        self.block = self.repo / "refuse"
        fake = self.repo / "ssh"
        fake.write_text(
            "#!"
            + sys.executable
            + "\n"
            + """import json,shlex,subprocess,sys
from pathlib import Path
args=sys.argv[1:]
body=sys.stdin.buffer.read()
argv=shlex.split(args[-1])
with Path(CALLS).open('a') as output:
    output.write(json.dumps({'argv':argv,'stdin':body.decode()})+'\\n')
if Path(BLOCK).exists():raise SystemExit(255)
result=subprocess.run(argv,input=body,capture_output=True)
sys.stdout.buffer.write(result.stdout)
sys.stderr.buffer.write(result.stderr)
raise SystemExit(result.returncode)
""".replace("CALLS", repr(str(self.calls))).replace("BLOCK", repr(str(self.block)))
        )
        fake.chmod(0o755)
        self.input = {
            "operation_id": 7001,
            "node_id": 14,
            "kind": "tasks.provision",
            "intent": {
                "task_vm_id": 184,
                "group_id": 55,
                "instance_id": 66,
                "project_id": 77,
                "checkout_path": str(self.repo / "apps/project/workspace"),
                "ssh_execution": {
                    "control_node_id": 209,
                    "identity_file": "/run/credentials/orbit-node-worker.service/proxy-ssh-identity",
                    "public_key": "ssh-ed25519 PUBLIC",
                    "target": {
                        "name": "target",
                        "host": "192.0.2.184",
                        "user": "orbit",
                        "port": 22,
                        "host_keys": [
                            "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"
                        ],
                    },
                },
            },
        }
        child = yaml.safe_load(
            (ROOT / "workflows/workflow-task-vm-workspace.yaml").read_text()
        )
        for job in child["jobs"]:
            self.assertEqual(job.pop("target"), "orbit-node-209")
            self.assertNotIn("labels", job)
            for step in job["steps"]:
                self.assertEqual(step.pop("target"), "orbit-node-209")
                self.assertNotIn("labels", step)
                task = step["task"]
                if task.get("behaviorType") == "@swamp/ssh":
                    task["globalArgs"]["sshBinary"] = str(fake)
        (self.repo / "workflows/workflow-task-vm-workspace.yaml").write_text(
            yaml.safe_dump(child, sort_keys=False)
        )

    def run_child(self, success=True):
        args = ["workflow", "run", "task-vm-workspace"]
        for key, value in self.input.items():
            args += ["--input", key + "=" + json.dumps(value)]
        result = self.cli(*args, success=success)
        self.assertTrue(result.stdout, result.stderr)
        return json.loads(result.stdout)

    def test_directory_success_is_idempotent_and_creates_no_guest_worker(self):
        self.cli("workflow", "validate", "task-vm-workspace")
        for _ in range(2):
            run = self.run_child()
            self.assertEqual(run["status"], "succeeded")
            history = json.loads(
                self.cli("workflow", "history", "get", run["id"]).stdout
            )
            result = history["jobs"][0]["steps"][-1]["outputs"]
            self.assertEqual(result["task_vm_id"], 184)
            self.assertEqual(result["group_id"], 55)
            self.assertEqual(
                result["checkout_path"], self.input["intent"]["checkout_path"]
            )
            self.assertTrue(result["ready"])
        calls = [json.loads(line) for line in self.calls.read_text().splitlines()]
        self.assertEqual(len(calls), 6)
        self.assertEqual(sum(row["argv"][0] == "/usr/bin/mkdir" for row in calls), 2)
        self.assertNotIn("worker connect", self.calls.read_text())
        self.assertTrue(Path(self.input["intent"]["checkout_path"]).is_dir())

    def test_connection_failure_stops_before_directory_then_native_resume(self):
        self.block.touch()
        run = self.run_child(success=False)
        self.assertEqual(run["status"], "failed")
        self.assertFalse(Path(self.input["intent"]["checkout_path"]).exists())
        self.block.unlink()
        resumed = json.loads(
            self.cli(
                "workflow", "resume", "task-vm-workspace", "--run", run["id"]
            ).stdout
        )
        self.assertEqual(resumed["status"], "succeeded")
        self.assertTrue(Path(self.input["intent"]["checkout_path"]).is_dir())

    def test_native_parent_preserves_original_envelope_and_consumes_child(self):
        parent = {
            "id": "e31d830f-f76e-446a-a277-a30a01728711",
            "name": "workspace-parent",
            "inputs": {
                "type": "object",
                "properties": {"envelope": {"type": "object"}},
                "required": ["envelope"],
            },
            "jobs": [
                {
                    "name": "parent",
                    "steps": [
                        {
                            "name": "workspace",
                            "task": {
                                "type": "workflow",
                                "workflowIdOrName": "task-vm-workspace",
                                "inputs": {
                                    key: "${{ inputs.envelope." + key + " }}"
                                    for key in self.input
                                },
                            },
                        }
                    ],
                }
            ],
        }
        (self.repo / "workflows/workflow-workspace-parent.yaml").write_text(
            yaml.safe_dump(parent, sort_keys=False)
        )
        parent["jobs"][0]["steps"].append(
            {
                "name": "consume",
                "dependsOn": [
                    {"step": "workspace", "condition": {"type": "succeeded"}}
                ],
                "task": {
                    "type": "assert",
                    "expr": "steps.workspace.outputs.result.ready && steps.workspace.outputs.result.task_vm_id == 184 && steps.workspace.outputs.result.group_id == 55",
                    "message": "The parent must receive the child receipt",
                },
            }
        )
        (self.repo / "workflows/workflow-workspace-parent.yaml").write_text(
            yaml.safe_dump(parent, sort_keys=False)
        )
        run = json.loads(
            self.cli(
                "workflow",
                "run",
                "workspace-parent",
                "--input",
                "envelope=" + json.dumps(self.input),
            ).stdout
        )
        self.assertEqual(run["status"], "succeeded")
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        output = history["jobs"][0]["steps"][0]["outputs"]
        self.assertEqual(
            output["result"],
            {
                **{
                    key: value
                    for key, value in self.input["intent"].items()
                    if key != "ssh_execution"
                },
                "ready": True,
            },
        )
        self.assertTrue(Path(self.input["intent"]["checkout_path"]).is_dir())

    def test_missing_pin_refuses_before_connect(self):
        self.input["intent"]["ssh_execution"]["target"]["host_keys"] = []
        run = self.run_child(success=False)
        self.assertEqual(run["status"], "failed")
        self.assertFalse(self.calls.exists())


if __name__ == "__main__":
    unittest.main()
