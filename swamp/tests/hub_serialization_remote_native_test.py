"""Two distinct served runs, two worker slots, one stable hub executing model."""

import json
import os
import socket
import subprocess
import sys
import time
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from native_harness import NativeTestCase


class HubRemoteSerialization(NativeTestCase):
    native_timeout = 30

    def test_two_served_parent_runs_cannot_overlap_the_same_hub(self):
        root = self.repo
        cli_binary = os.environ.get("SWAMP_BIN", "swamp")
        command = root / "context_mutation.py"
        command.write_text("""import os
from pathlib import Path
root=Path(__file__).parent
busy=root/'busy'
busy.mkdir()
try:
    if os.environ['ORBIT_SLOT']=='first':
        (root/'entered').write_text('ready')
        with (root/'release').open() as stream:stream.read(1)
    with (root/'calls').open('a') as stream:stream.write(os.environ['ORBIT_SLOT']+'\\n')
finally:busy.rmdir()
print('{"projected":true}')
""")
        os.mkfifo(root / "release", 0o600)
        step = {
            "name": "mutate",
            "target": "orbit-node-1",
            "labels": {"node": "1"},
            "writes": True,
            "task": {
                "type": "behavior",
                "behaviorType": "command/shell",
                "behaviorName": "wireguard-hub-1",
                "methodName": "execute",
                "inputs": {
                    "run": "/usr/bin/python3 " + str(command),
                    "env": {"ORBIT_SLOT": "${{ inputs.slot }}"},
                },
            },
        }
        workflow = {
            "id": "2a709157-1b38-41c4-bf10-88b4be314cdd",
            "name": "hub-parent",
            "inputs": {
                "properties": {"slot": {"type": "string"}},
                "required": ["slot"],
            },
            "jobs": [{"name": "hub", "steps": [step]}],
        }
        (root / "workflows/workflow-hub-parent.yaml").write_text(json.dumps(workflow))
        self.cli("workflow", "validate", "hub-parent")
        self.cli(
            "access", "token", "mint", "fixture-admin", "--principal", "user:fixture"
        )
        admin = root / "admin"
        admin.write_text(
            json.loads(
                self.cli("access", "token", "reveal", "fixture-admin", "--yes").stdout
            )["token"]
        )
        admin.chmod(0o600)
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        url = f"ws://127.0.0.1:{port}"
        env = dict(os.environ, SWAMP_NO_UPDATE_CHECK="1", SWAMP_NO_TELEMETRY="1")
        for name in (
            "SWAMP_SERVER_URL",
            "SWAMP_SERVE_URL",
            "SWAMP_SERVER_TOKEN",
            "SWAMP_SERVER_TOKEN_FILE",
            "SWAMP_REPO_DIR",
            "SWAMP_CA_CERT",
        ):
            env.pop(name, None)
        flags = ("--server", url, "--token-file", str(admin))
        serve = worker = None

        def data(*args):
            return json.loads(self.cli(*args).stdout)

        def until(predicate):
            deadline = time.monotonic() + 20
            while time.monotonic() < deadline:
                if predicate():
                    return
                time.sleep(0.05)
            self.fail("Scratch native synchronization deadline exceeded")

        def ready():
            try:
                urllib.request.urlopen(
                    f"http://127.0.0.1:{port}/ready", timeout=1
                ).close()
                return True
            except OSError:
                self.assertIsNone(serve.poll())
                return False

        with (
            (root / "serve.log").open("w") as server_log,
            (root / "worker.log").open("w") as worker_log,
        ):
            try:
                serve = subprocess.Popen(
                    [
                        cli_binary,
                        "serve",
                        "--host",
                        "127.0.0.1",
                        "--port",
                        str(port),
                        "--auth-mode",
                        "token",
                        "--admins",
                        "user:fixture",
                        "--remote-only",
                    ],
                    cwd=root,
                    env=env,
                    stdout=server_log,
                    stderr=server_log,
                )
                until(ready)
                enrollment = root / "enrollment"
                enrollment.write_text(
                    data(
                        "worker",
                        "token",
                        "create",
                        "orbit-node-1",
                        "--duration",
                        "10m",
                        *flags,
                    )["token"]
                )
                enrollment.chmod(0o600)
                self.cli(
                    "access",
                    "token",
                    "mint",
                    "fixture-worker",
                    "--principal",
                    "worker:orbit-node-1",
                    *flags,
                )
                worker_token = root / "worker-token"
                worker_token.write_text(
                    data("access", "token", "reveal", "fixture-worker", "--yes")[
                        "token"
                    ]
                )
                worker_token.chmod(0o600)
                # Test-only capacity=2 proves model serialization, not accidental single-slot queuing.
                worker = subprocess.Popen(
                    [
                        cli_binary,
                        "worker",
                        "connect",
                        url,
                        "--token-file",
                        str(enrollment),
                        "--server-token-file",
                        str(worker_token),
                        "--cache-dir",
                        str(root / "worker-cache"),
                        "--label",
                        "node=1",
                        "--concurrency",
                        "2",
                    ],
                    cwd=root,
                    env=env,
                    stdout=worker_log,
                    stderr=worker_log,
                )
                first = data(
                    "workflow",
                    "run",
                    "hub-parent",
                    "--input",
                    "slot=first",
                    "--detach",
                    *flags,
                )
                until(lambda: (root / "entered").exists())
                second = data(
                    "workflow",
                    "run",
                    "hub-parent",
                    "--input",
                    "slot=second",
                    "--detach",
                    *flags,
                )
                first_id = first.get("runId", first.get("id"))
                second_id = second.get("runId", second.get("id"))
                self.assertIsNotNone(first_id)
                self.assertNotEqual(first_id, second_id)
                with (root / "release").open("w") as release:
                    release.write("x")
                until(
                    lambda: (
                        (root / "calls").exists()
                        and (root / "calls").read_text().count("\n") == 2
                    )
                )
                self.assertEqual((root / "calls").read_text(), "first\nsecond\n")
                for run_id in [first_id, second_id]:
                    history = data("workflow", "history", "get", run_id, *flags)
                    self.assertEqual(
                        history["status"], "succeeded", json.dumps(history)
                    )
            finally:
                for process in [worker, serve]:
                    if process is not None:
                        process.terminate()
                        try:
                            process.wait(timeout=10)
                        except subprocess.TimeoutExpired:
                            process.kill()
                            process.wait(timeout=5)
