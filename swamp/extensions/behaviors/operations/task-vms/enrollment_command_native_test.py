"""Real native command DAG with an isolated Incus boundary; no fleet effects."""

import base64
import hashlib
import json
import os
import shutil
import subprocess
import sys
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase


class EnrollmentCommandsNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.native_timeout = 90
        behavior = self.repo / "extensions/behaviors/enrollment_command_input.ts"
        behavior.parent.mkdir(parents=True)
        shutil.copy(Path(__file__).with_name("enrollment_command_input.ts"), behavior)
        generated = subprocess.run(
            [
                "/home/nckrtl/.swamp/deno/deno",
                "eval",
                'import {commandIntent} from "./extensions/behaviors/operations/task-vms/enrollment_command_input_test.ts"; console.log(JSON.stringify(commandIntent()));',
            ],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=True,
            timeout=30,
        )
        self.intent = json.loads(generated.stdout)
        self.observer = self.repo / "guest-observer"
        self.observer.write_bytes(b"disposable-pinned-observer")
        self.intent["agent_sha256"] = hashlib.sha256(
            self.observer.read_bytes()
        ).hexdigest()
        self.intent["runtime"]["agent_sha256"] = self.intent["agent_sha256"]
        blob = (
            bytes.fromhex("0000000b")
            + b"ssh-ed25519"
            + bytes.fromhex("00000020")
            + b"x" * 32
        )
        self.public_key = self.repo / "guest-host-key.pub"
        self.public_key.write_text(
            "ssh-ed25519 " + base64.b64encode(blob).decode() + " fixture\n"
        )
        self.expected_fingerprint = "SHA256:" + base64.b64encode(
            hashlib.sha256(blob).digest()
        ).decode().rstrip("=")
        self.calls = self.repo / "incus-calls.json"
        self.calls.write_text("[]")
        self.boundary = self.repo / "incus-boundary.py"
        self.boundary.write_text("""
import json, os, pathlib, pwd, sys, types
root = pathlib.Path(__file__).parent
args = sys.argv[1:]
calls = json.loads((root / "incus-calls.json").read_text())
calls.append(args)
(root / "incus-calls.json").write_text(json.dumps(calls))
assert args[:2] == ["--project", "orbit-tasks"]
assert args[2:10] == ["exec", "--mode=non-interactive", "--user", "0", "--group", "0", "--", "tvm-184"]
command = args[10:]
if command == ["/usr/bin/cloud-init", "status", "--wait"]:
    sys.exit(1 if (root / "fail-cloud-init").exists() else 0)
assert command[:2] == ["/usr/bin/python3", "-c"]
code = command[2].replace("/usr/local/bin/orbit-agent", str(root / "guest-observer")).replace("/etc/ssh/ssh_host_ed25519_key.pub", str(root / "guest-host-key.pub"))
original = os.lstat
def metadata(path, *args, **kwargs):
    result = original(path, *args, **kwargs)
    if str(path) in [str(root / "guest-observer"), str(root / "guest-host-key.pub")]:
        return types.SimpleNamespace(st_mode=result.st_mode, st_uid=0)
    return result
os.lstat = metadata
pwd.getpwnam = lambda user: types.SimpleNamespace(pw_uid=1000,pw_gid=1000,pw_dir="/home/orbit")
sys.argv = ["guest", *command[3:]]
exec(compile(code, "inline-guest-action", "exec"), {"__name__": "__main__"})
""")
        source = ROOT / "workflows/workflow-task-vm-enrollment-incus-public.yaml"
        workflow = yaml.safe_load(source.read_text())
        expected_target = (
            "$" + '{{ "orbit-node-" + string(inputs.intent.execution_node_id) }}'
        )
        expected_labels = {
            "node": "$" + "{{ string(inputs.intent.execution_node_id) }}"
        }
        for job in workflow["jobs"]:
            self.assertEqual(job.pop("target"), expected_target)
            self.assertEqual(job.pop("labels"), expected_labels)
            for step in job["steps"]:
                self.assertEqual(step.pop("target"), expected_target)
                self.assertEqual(step.pop("labels"), expected_labels)
                task = step["task"]
                if task["behaviorType"] == "command/shell":
                    task["inputs"]["run"] = task["inputs"]["run"].replace(
                        "/usr/bin/sudo -n -- /usr/bin/incus",
                        "/usr/bin/python3 " + str(self.boundary),
                    )
        (self.repo / "workflows" / source.name).write_text(
            yaml.safe_dump(workflow, sort_keys=False)
        )
        self.inputs = self.repo / "inputs.json"
        self.inputs.write_text(json.dumps({"intent": self.intent}))

    def run_workflow(self, success=True):
        self.inputs.write_text(json.dumps({"intent": self.intent}))
        return self.cli(
            "workflow",
            "run",
            "task-vm-enrollment-incus-public",
            "--input-file",
            str(self.inputs),
            success=success,
        )

    def test_native_steps_keep_public_observations_and_the_exact_host_vm(self):
        self.cli("workflow", "validate", "task-vm-enrollment-incus-public")
        self.run_workflow()
        calls = json.loads(self.calls.read_text())
        self.assertEqual(len(calls), 4)
        self.assertTrue(all(call[9] == "tvm-184" for call in calls))
        results = []
        for path in (self.repo / ".swamp/data").rglob("raw"):
            try:
                value = json.loads(path.read_text())
            except (ValueError, UnicodeError):
                continue
            if isinstance(value, dict) and isinstance(value.get("stdout"), str):
                try:
                    results.append(json.loads(value["stdout"]))
                except ValueError:
                    pass
        self.assertIn({"agent_sha256": self.intent["agent_sha256"]}, results)
        self.assertTrue(
            any(
                v.get("ssh_host_fingerprint") == self.expected_fingerprint
                for v in results
            )
        )
        self.assertTrue(any(v.get("cloud_init_ready") is True for v in results))

    def test_failed_cloud_init_skips_all_later_guest_commands(self):
        (self.repo / "fail-cloud-init").touch()
        self.run_workflow(success=False)
        calls = json.loads(self.calls.read_text())
        self.assertEqual(len(calls), 1)
        self.assertEqual(calls[0][10:], ["/usr/bin/cloud-init", "status", "--wait"])

    def test_wrong_pin_stops_before_host_key_observation(self):
        self.intent["agent_sha256"] = "f" * 64
        self.intent["runtime"]["agent_sha256"] = "f" * 64
        self.run_workflow(success=False)
        self.assertEqual(len(json.loads(self.calls.read_text())), 3)

    def test_wrong_identity_refuses_before_any_shell_action(self):
        self.intent["node"]["node_id"] = 213
        self.run_workflow(success=False)
        self.assertEqual(json.loads(self.calls.read_text()), [])


class RuntimeConfirmNative(NativeTestCase):
    """Keep real native dependencies, vault resolution, shell and HTTP boundary."""

    def setUp(self):
        super().setUp()
        self.native_timeout = 90
        behavior = self.repo / "extensions/behaviors/enrollment_command_input.ts"
        behavior.parent.mkdir(parents=True)
        shutil.copy(Path(__file__).with_name("enrollment_command_input.ts"), behavior)
        generated = subprocess.run(
            [
                "/home/nckrtl/.swamp/deno/deno",
                "eval",
                'import {commandIntent} from "./extensions/behaviors/operations/task-vms/enrollment_command_input_test.ts"; console.log(JSON.stringify(commandIntent()));',
            ],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=True,
            timeout=30,
        )
        self.intent = json.loads(generated.stdout)
        self.pi = self.repo / "guest/home/orbit/.local/bin/pi-server"
        self.pi.parent.mkdir(parents=True)
        self.pi.write_bytes(b"synthetic-pinned-pi")
        self.pi.chmod(0o700)
        self.intent["runtime"]["pi_sha256"] = hashlib.sha256(
            self.pi.read_bytes()
        ).hexdigest()
        self.token = os.urandom(32).hex()
        self.secrets = (self.token,)
        self.requests = []
        self.http_status = 200
        case = self

        import threading
        from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def do_GET(self):
                case.requests.append(
                    (
                        self.path,
                        self.headers.get("Authorization") == "Bearer " + case.token,
                    )
                )
                self.send_response(case.http_status)
                self.end_headers()

        self.server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.addCleanup(self.stop_http)
        self.intent["node"]["wireguard_ip"] = "127.0.0.1"
        self.intent["pi_url"] = f"http://127.0.0.1:{self.server.server_port}"
        self.calls = self.repo / "service-calls.json"
        self.calls.write_text("[]")
        boundary = self.repo / "systemctl.py"
        boundary.write_text("""
import json, pathlib, sys
root = pathlib.Path(__file__).parent
calls = json.loads((root / "service-calls.json").read_text())
calls.append(sys.argv[1:])
(root / "service-calls.json").write_text(json.dumps(calls))
print("inactive" if (root / "inactive").exists() else "active")
""")
        source = ROOT / "workflows/workflow-task-vm-runtime-command-confirm.yaml"
        workflow = yaml.safe_load(source.read_text())
        target = '${{ "orbit-node-" + string(inputs.intent.guest_node_id) }}'
        labels = {"node": "${{ string(inputs.intent.guest_node_id) }}"}
        for job in workflow["jobs"]:
            self.assertEqual(job.pop("target"), target)
            self.assertEqual(job.pop("labels"), labels)
            for step in job["steps"]:
                self.assertEqual(step.pop("target"), target)
                self.assertEqual(step.pop("labels"), labels)
                task = step["task"]
                if task["behaviorType"] != "command/shell":
                    continue
                script = task["inputs"]["run"]
                script = script.replace(
                    'pwd.getpwnam("orbit")', "pwd.getpwuid(os.geteuid())"
                )
                script = script.replace("socket.gethostname()", '"tvm-184"')
                script = script.replace('"/home', '"' + str(self.repo / "guest/home"))
                script = script.replace(
                    "/usr/bin/sudo -n -- /usr/bin/true", "/usr/bin/true"
                )
                script = script.replace(
                    "/usr/bin/sudo -n -- /usr/bin/systemctl",
                    "/usr/bin/python3 " + str(boundary),
                )
                task["inputs"]["run"] = script
        self.workflow = self.repo / "workflows" / source.name
        self.workflow.write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        self.cli(
            "vault",
            "put",
            "orbit-control-inputs",
            "task-vm-pi-token-184",
            input=self.token,
        )

    def stop_http(self):
        self.server.shutdown()
        self.thread.join(timeout=2)
        self.server.server_close()

    def run_confirm(self, success=True):
        inputs = {
            "intent": self.intent,
            "execution_secrets": {
                "pi_token": {
                    "vault": "orbit-control-inputs",
                    "key": "task-vm-pi-token-184",
                }
            },
        }
        path = self.repo / "inputs.json"
        path.write_text(json.dumps(inputs))
        return self.cli(
            "workflow",
            "run",
            "task-vm-runtime-command-confirm",
            "--input-file",
            str(path),
            success=success,
        )

    def test_native_confirm_resolves_vault_only_into_environment_and_records_public_result(
        self,
    ):
        self.cli("workflow", "validate", "task-vm-runtime-command-confirm")
        self.run_confirm()
        self.assertEqual(self.requests, [("/capabilities", True)])
        outputs = []
        for path in (self.repo / ".swamp/data").rglob("raw"):
            try:
                raw = json.loads(path.read_text())
                if isinstance(raw, dict) and isinstance(raw.get("stdout"), str):
                    outputs.append(json.loads(raw["stdout"]))
            except (ValueError, UnicodeError):
                continue
        self.assertIn(
            {
                "ready": True,
                "guest_node_id": 212,
                "process": {
                    "process_id": 55,
                    "status": "active",
                    "content_hash": "c" * 64,
                },
            },
            outputs,
        )

    def test_native_wrong_pi_digest_skips_services_and_authentication(self):
        self.intent["runtime"]["pi_sha256"] = "f" * 64
        self.run_confirm(success=False)
        self.assertEqual(self.requests, [])
        self.assertEqual(json.loads(self.calls.read_text()), [])

    def test_native_inactive_observer_skips_process_and_authentication(self):
        (self.repo / "inactive").touch()
        self.run_confirm(success=False)
        self.assertEqual(self.requests, [])
        self.assertEqual(
            json.loads(self.calls.read_text()),
            [["is-active", "--", "orbit-agent.service"]],
        )

    def test_native_authentication_failure_does_not_retry_or_publish_ready(self):
        self.http_status = 401
        self.run_confirm(success=False)
        self.assertEqual(self.requests, [("/capabilities", True)])


if __name__ == "__main__":
    unittest.main()
