"""Run the Instance step-list DAG natively with official SSH and a local transport fixture."""

import hashlib
import json
import os
import pwd
import shutil
import sys
import tempfile
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase

WORKFLOW = "operation-instance-setup-direct"


class RunStepsNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors("automatic-deploy", "operations")
        official = self.repo / ".swamp/pulled-extensions/@swamp/ssh"
        official.parent.mkdir(parents=True)
        shutil.copytree(ROOT / ".swamp/pulled-extensions/@swamp/ssh", official)
        inventory = json.loads((ROOT / "extensions/models/upstream_extensions.json").read_text())
        path = self.repo / "extensions/models/upstream_extensions.json"
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps({"@swamp/ssh": inventory["@swamp/ssh"]}))
        self.calls = self.repo / "calls.jsonl"
        self.ssh = self.repo / "ssh-fixture"
        self.ssh.write_text(
            "#!" + sys.executable + "\n"
            + """import json,shlex,subprocess,sys
from pathlib import Path
args=sys.argv[1:]
if '-M' in args or '-O' in args:raise SystemExit(0)
argv=shlex.split(args[-1])
with Path(CALLS).open('a') as f:f.write(json.dumps(argv)+'\\n')
if argv[0] in ('sudo','/usr/bin/sudo'):argv=argv[argv.index('--')+1:]
r=subprocess.run(argv,input=sys.stdin.buffer.read(),capture_output=True)
sys.stdout.buffer.write(r.stdout);sys.stderr.buffer.write(r.stderr)
raise SystemExit(r.returncode)
""".replace("CALLS", repr(str(self.calls)))
        )
        self.ssh.chmod(0o755)
        (self.repo / "existing-key").write_text("fixture identity not consumed")
        workflow = yaml.safe_load((ROOT / f"workflows/workflow-{WORKFLOW}.yaml").read_text())
        for job in workflow["jobs"]:
            self.assertEqual(job.pop("labels"), {"role": "swamp"})
            for step in job["steps"]:
                self.assertEqual(step.pop("labels"), {"role": "swamp"})
                if step["task"].get("behaviorType") == "@swamp/ssh":
                    step["task"]["globalArgs"]["sshBinary"] = str(self.ssh)
        (self.repo / f"workflows/workflow-{WORKFLOW}.yaml").write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.checkout = Path(tempfile.mkdtemp(prefix="orbit-steps-node-"))
        self.addCleanup(shutil.rmtree, self.checkout)
        self.inputs = {
            "operation_id": 41, "node_id": 9, "kind": "instance.run_steps",
            "intent": {
                "instance_id": 42, "list": "setup", "user": pwd.getpwuid(os.getuid()).pw_name, "directory": str(self.checkout), "steps": [],
                "ssh_execution": {
                    "control_node_id": 209, "identity_file": str(self.repo / "existing-key"), "public_key": "ssh-ed25519 public-fixture",
                    "target": {"name": "target", "host": "198.51.100.9", "user": "orbit", "port": 22,
                               "host_keys": ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ111111111111111111111111111111111111111111"]},
                },
            },
            "execution_secrets": {},
        }

    def steps(self, *commands):
        self.inputs["intent"]["steps"] = [
            {"name": name, "command": command, "sha256": hashlib.sha256(command.encode()).hexdigest(), "timeout_seconds": 10,
             "directory": self.inputs["intent"]["directory"], "instance_id": 42, "instance_name": "default", "seed_path": "", "seed_commit": ""}
            for name, command in commands]

    def run_list(self, success=True):
        reply = self.cli("workflow", "run", WORKFLOW, "--stdin", input=json.dumps(self.inputs), success=success)
        decoder = json.JSONDecoder()
        for n, char in enumerate(reply.stdout):
            if char == "{":
                try:
                    self.run = decoder.raw_decode(reply.stdout[n:])[0]
                    break
                except ValueError:
                    pass
        history = json.loads(self.cli("workflow", "history", "get", self.run["id"]).stdout)
        return {s["name"]: s for s in history["jobs"][0]["steps"]}

    def test_runs_each_command_in_order_in_the_checkout(self):
        self.cli("workflow", "validate", WORKFLOW)
        self.steps(("first", "printf 1 >> order; echo private-stdout"), ("second", "printf 2 >> order; test \"$ORBIT_INSTANCE_ID\" = 42"))
        steps = self.run_list()
        self.assertEqual(self.run["status"], "succeeded")
        self.assertEqual((self.checkout / "order").read_text(), "12")
        self.assertEqual(steps["Run the setup steps-0"]["outputs"]["stdout"], "")

    def test_an_empty_list_succeeds(self):
        self.run_list()
        self.assertEqual(self.run["status"], "succeeded")

    def test_the_first_failure_stops_later_steps(self):
        self.steps(("first", "printf 1 >> order"), ("second", "echo broke >&2; exit 3"), ("third", "printf 3 >> order"))
        steps = self.run_list(success=False)
        self.assertEqual(self.run["status"], "failed")
        self.assertEqual(steps["Run the setup steps-1"]["status"], "failed")
        self.assertNotIn("Run the setup steps-2", [k for k, v in steps.items() if v["status"] == "succeeded"])
        self.assertEqual((self.checkout / "order").read_text(), "1")

    def test_a_missing_checkout_runs_nothing(self):
        self.inputs["intent"]["directory"] = str(self.checkout / "absent")
        self.steps(("first", "printf 1 >> order"))
        self.run_list(success=False)
        self.assertEqual(self.run["status"], "failed")
        self.assertFalse((self.checkout / "order").exists())


if __name__ == "__main__":
    import unittest

    unittest.main()
