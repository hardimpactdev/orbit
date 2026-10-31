"""Pinned native DAG integration; run separately from the plain Deno shards."""

import importlib.util
import json
import os
import shutil
import subprocess
import sys
import threading
from http.server import ThreadingHTTPServer
from pathlib import Path

import yaml
from actions_test import TOKEN, PiHandler

ROOT = Path(__file__).resolve().parents[4]
spec = importlib.util.spec_from_file_location(
    "native_harness", ROOT / "tests/native_harness.py"
)
harness = importlib.util.module_from_spec(spec)
spec.loader.exec_module(harness)


class TurnHandler(PiHandler):
    def do_POST(self):
        if self.path.endswith("/messages"):
            self.server.workspace.joinpath("file").write_text(
                self.server.agent_text + "\n"
            )
            outcome = (
                "approved" if self.server.role == "reviewer" else "ready_for_review"
            )
            subprocess.run(
                [
                    str(self.server.workspace / ".git/orbit/turn"),
                    "--thread=14",
                    "--outcome=" + outcome,
                    "--summary=" + self.server.agent_text,
                ],
                check=True,
                capture_output=True,
            )
        super().do_POST()


class NativeTurn(harness.NativeTestCase):
    native_timeout = 90

    def setUp(self):
        super().setUp()
        self.secrets = (TOKEN,)
        source = ROOT / "extensions/behaviors/operations/task-turn"
        target = self.repo / "extensions/behaviors/operations/task-turn"
        shutil.copytree(
            source, target, ignore=shutil.ignore_patterns("*test*", "__pycache__")
        )
        module = self.repo / "extensions/behaviors/operations/tasks/turn.ts"
        module.parent.mkdir(parents=True)
        shutil.copy(ROOT / "extensions/behaviors/operations/tasks/turn.ts", module)
        official = self.repo / ".swamp/pulled-extensions/@swamp/ssh"
        official.parent.mkdir(parents=True)
        shutil.copytree(ROOT / ".swamp/pulled-extensions/@swamp/ssh", official)
        inventory = self.repo / "extensions/models/upstream_extensions.json"
        inventory.parent.mkdir(parents=True)
        inventory.write_text(
            json.dumps(
                {
                    "@swamp/ssh": json.loads(
                        (
                            ROOT / "extensions/models/upstream_extensions.json"
                        ).read_text()
                    )["@swamp/ssh"]
                }
            )
        )
        self.workspace = self.repo / "workspace"
        self.workspace.mkdir()
        self.git("init", "-qb", "task-10")
        self.git("config", "user.email", "test@example.test")
        self.git("config", "user.name", "Test")
        self.workspace.joinpath("file").write_text("original\n")
        self.git("add", ".")
        self.git("commit", "-qm", "fixture")
        self.head = self.git("rev-parse", "HEAD")
        self.server = ThreadingHTTPServer(("127.0.0.1", 0), TurnHandler)
        self.server.workspace, self.server.role = self.workspace, "implementer"
        self.server.calls, self.server.disconnect = [], False
        self.server.agent_text = "agent changed"
        threading.Thread(target=self.server.serve_forever, daemon=True).start()
        self.addCleanup(self.server.server_close)
        self.addCleanup(self.server.shutdown)
        tools = self.repo / "tools"
        tools.mkdir()
        ssh = tools / "ssh"
        ssh.write_text(
            "#!"
            + sys.executable
            + '\nimport shlex,subprocess,sys\nif "-M" in sys.argv or "-O" in sys.argv:raise SystemExit(0)\nr=subprocess.run(shlex.split(sys.argv[-1]),input=sys.stdin.buffer.read(),capture_output=True)\nsys.stdout.buffer.write(r.stdout);sys.stderr.buffer.write(r.stderr)\nraise SystemExit(r.returncode)\n'
        )
        ssh.chmod(0o755)
        scp = tools / "scp"
        scp.write_text(
            "#!"
            + sys.executable
            + '\nimport shutil,sys\nfrom pathlib import Path\nsrc=Path(sys.argv[-2]);dst=Path(sys.argv[-1].split(":",1)[1])\nshutil.copytree(src,dst/src.name,dirs_exist_ok=True)\n'
        )
        scp.chmod(0o755)
        self.native_env["PATH"] = str(tools) + ":" + os.environ["PATH"]
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        self.cli(
            "vault",
            "put",
            "orbit-control-inputs",
            "task-vm-pi-token-1",
            input=TOKEN,
        )
        self.inputs = {
            "operation_id": 15,
            "node_id": 30,
            "kind": "tasks.turn",
            "execution_secrets": {
                "pi_token": {
                    "vault": "orbit-control-inputs",
                    "key": "task-vm-pi-token-1",
                }
            },
            "intent": {
                "operation_id": 15,
                "new_session": True,
                "group_id": 10,
                "instance_id": 12,
                "node_id": 30,
                "subtask": 11,
                "thread_id": 14,
                "checkout_path": str(self.workspace),
                "session_id": "session-14",
                "role": "implementer",
                "candidate": None,
                "deliverables": [],
                "analysis_evidence": None,
                "model": "orbit-task-vm/gpt-6.1-sol",
                "effort": "high",
                "prompt": "Complete the task $(touch never).",
                "pi_host": "127.0.0.1",
                "pi_port": self.server.server_port,
                "ssh_execution": {
                    "identity_file": "/dev/null",
                    "target": {
                        "user": "orbit",
                        "host": "192.0.2.30",
                        "port": 22,
                        "host_keys": [
                            "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND"
                        ],
                    },
                },
            },
        }
        self.flow = yaml.safe_load(
            (ROOT / "workflows/workflow-operation-task-turn.yaml").read_text()
        )
        self.flow["name"] = "task-turn-fixture"
        for job in self.flow["jobs"]:
            self.assertEqual(job.pop("target"), "orbit-node-209")
            self.assertEqual(job.pop("labels"), {"node": "209"})
            for step in job["steps"]:
                self.assertEqual(step.pop("target"), "orbit-node-209")
                self.assertEqual(step.pop("labels"), {"node": "209"})
                args = step["task"].get("inputs", {}).get("argv")
                if args:
                    step["task"]["inputs"]["argv"] = [
                        v.replace("/tmp/orbit-task-turn-", str(self.repo / "remote-"))
                        for v in args
                    ]
                inputs = step["task"].get("inputs", {})
                if "dst" in inputs:
                    inputs["dst"] = inputs["dst"].replace(
                        "/tmp/orbit-task-turn-", str(self.repo / "remote-")
                    )
        self.write_workflow()

    def git(self, *args):
        return subprocess.check_output(
            ["git", "-C", str(self.workspace), *args],
            text=True,
            stderr=subprocess.DEVNULL,
        ).strip()

    def write_workflow(self):
        (self.repo / "workflows/workflow-task-turn-fixture.yaml").write_text(
            yaml.safe_dump(self.flow, sort_keys=False)
        )

    def run_workflow(self, success=True):
        self.cli("workflow", "validate", "task-turn-fixture")
        raw = self.cli(
            "workflow",
            "run",
            "task-turn-fixture",
            "--stdin",
            input=json.dumps(self.inputs),
            success=success,
        ).stdout
        return self.decode(raw)

    def decode(self, raw):
        decoder = json.JSONDecoder()
        for index, char in enumerate(raw):
            if char == "{":
                try:
                    return decoder.raw_decode(raw[index:])[0]
                except ValueError:
                    pass
        self.fail("Native run JSON missing")

    def result(self, run):
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        result = next(
            step for step in history["jobs"][0]["steps"] if step["name"] == "result"
        )
        return json.loads(result["outputs"]["stdout"])

    def test_native_turn_uses_ssh_workspace_and_one_pi_send(self):
        run = self.run_workflow()
        self.assertEqual(run["status"], "succeeded")
        self.assertEqual(self.result(run)["receipt"]["outcome"], "ready_for_review")
        self.assertEqual(
            sum(path.endswith("/messages") for path, _ in self.server.calls), 1
        )
        self.assertFalse((self.workspace / "never").exists())

    def test_native_reviewer_restores_candidate(self):
        self.server.role = "reviewer"
        credential = "a1" * 32
        self.server.agent_text = "MODEL_KEY=" + credential
        self.inputs["intent"].update(role="reviewer", candidate=self.head)
        run = self.run_workflow()
        self.assertEqual(run["status"], "succeeded")
        result = self.result(run)
        self.assertEqual(result["receipt"]["outcome"], "approved")
        history = self.cli("workflow", "history", "get", run["id"]).stdout
        self.assertNotIn(credential, history)
        self.assertIn("[REDACTED]", result["receipt"]["summary"])
        self.assertIn("[REDACTED]", result["difference"]["diff"])
        self.assertEqual(result["after_reset"]["head"], self.head)
        self.assertEqual(self.workspace.joinpath("file").read_text(), "original\n")

    def test_moved_candidate_retains_its_public_refusal_without_pi_calls(self):
        self.inputs["intent"].update(role="reviewer", candidate="f" * 40)
        run = self.run_workflow(success=False)
        self.assertEqual(run["status"], "failed")
        self.assertEqual(self.result(run)["error_code"], "tasks.head_moved")
        self.assertEqual(self.server.calls, [])

    def test_prompt_overflow_retains_its_public_refusal_without_pi_calls(self):
        self.inputs["intent"]["prompt"] = "x" * 100000
        run = self.run_workflow(success=False)
        self.assertEqual(run["status"], "failed")
        self.assertEqual(
            self.result(run)["error_code"], "tasks.analysis_prompt_too_large"
        )
        self.assertEqual(self.server.calls, [])

    def test_failed_review_difference_keeps_failed_result_and_restores_candidate(self):
        self.server.role = "reviewer"
        self.inputs["intent"].update(role="reviewer", candidate=self.head)
        difference = next(
            step
            for step in self.flow["jobs"][0]["steps"]
            if step["name"] == "difference"
        )
        difference["task"]["inputs"]["argv"] = ["false"]
        self.write_workflow()
        run = self.run_workflow(success=False)
        self.assertEqual(run["status"], "failed")
        self.assertEqual(self.result(run)["state"], "failed")
        self.assertEqual(self.workspace.joinpath("file").read_text(), "original\n")

    def test_native_resume_reuses_completed_message_send(self):
        marker = self.repo / "result-ready"
        result_step = next(
            step for step in self.flow["jobs"][0]["steps"] if step["name"] == "result"
        )
        inputs = result_step["task"]["inputs"]
        inputs["env"]["ORBIT_READY"] = str(marker)
        inputs["run"] = 'set -e\ntest -f "$ORBIT_READY"\n' + inputs["run"]
        self.write_workflow()
        run = self.run_workflow(success=False)
        self.assertEqual(run["status"], "failed")
        marker.touch()
        resumed = self.decode(
            self.cli(
                "workflow", "resume", "task-turn-fixture", "--run", run["id"]
            ).stdout
        )
        self.assertEqual(resumed["status"], "succeeded")
        self.assertEqual(
            sum(path.endswith("/messages") for path, _ in self.server.calls), 1
        )

    def test_native_disconnected_stream_fails_and_interrupts_once(self):
        self.server.disconnect = True
        run = self.run_workflow(success=False)
        self.assertEqual(run["status"], "failed")
        self.assertEqual(self.result(run)["state"], "failed")
        self.assertEqual(
            sum(path.endswith("/interrupt") for path, _ in self.server.calls), 1
        )


if __name__ == "__main__":
    import unittest

    unittest.main()
