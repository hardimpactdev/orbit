"""Actual native remote-only delivery of the shared installer and its vault value."""

import importlib.util
import json
import os
import socket
import subprocess
import time
import urllib.request
from pathlib import Path

spec = importlib.util.spec_from_file_location(
    "process_native_installation",
    Path(__file__).with_name("native_installation_test.py"),
)
assert spec is not None and spec.loader is not None
fixture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture)


class RemoteInstallation(fixture.NativeInstallation):
    def test_remote_shipped_installer_vault_and_terminal_outputs(self):
        for step in self.steps:
            step["target"] = "orbit-node-99998"
            step["labels"] = {"node": "99998"}
        self.cli(
            "access", "token", "mint", "fixture-admin", "--principal", "user:fixture"
        )
        admin = self.repo / "admin"
        admin.write_text(
            fixture.reply(
                self.cli("access", "token", "reveal", "fixture-admin", "--yes").stdout
            )["token"]
        )
        admin.chmod(0o600)
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        url = f"ws://127.0.0.1:{port}"
        env = dict(os.environ, SWAMP_NO_UPDATE_CHECK="1", SWAMP_NO_TELEMETRY="1")
        for key in (
            "SWAMP_SERVER_URL",
            "SWAMP_SERVE_URL",
            "SWAMP_SERVER_TOKEN",
            "SWAMP_SERVER_TOKEN_FILE",
            "SWAMP_REPO_DIR",
            "SWAMP_CA_CERT",
            "SWAMP_EXTENSIONS_DIR",
        ):
            env.pop(key, None)
        cli = os.environ.get("SWAMP_BIN", "swamp")
        serve = worker = None
        with (
            (self.repo / "serve.log").open("w") as log,
            (self.repo / "worker.log").open("w") as worker_log,
        ):
            try:
                serve = subprocess.Popen(
                    [
                        cli,
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
                    cwd=self.repo,
                    env=env,
                    stdout=log,
                    stderr=log,
                )
                for _ in range(100):
                    try:
                        urllib.request.urlopen(
                            f"http://127.0.0.1:{port}/ready", timeout=1
                        ).close()
                        break
                    except OSError:
                        self.assertIsNone(serve.poll())
                        time.sleep(0.1)
                flags = ("--server", url, "--token-file", str(admin))
                enrollment = self.repo / "enrollment"
                enrollment.write_text(
                    fixture.reply(
                        self.cli(
                            "worker",
                            "token",
                            "create",
                            "orbit-node-99998",
                            "--duration",
                            "10m",
                            *flags,
                        ).stdout
                    )["token"]
                )
                enrollment.chmod(0o600)
                self.cli(
                    "access",
                    "token",
                    "mint",
                    "fixture-worker",
                    "--principal",
                    "worker:orbit-node-99998",
                    *flags,
                )
                token = self.repo / "worker-access"
                token.write_text(
                    fixture.reply(
                        self.cli(
                            "access", "token", "reveal", "fixture-worker", "--yes"
                        ).stdout
                    )["token"]
                )
                token.chmod(0o600)
                worker = subprocess.Popen(
                    [
                        cli,
                        "worker",
                        "connect",
                        url,
                        "--token-file",
                        str(enrollment),
                        "--server-token-file",
                        str(token),
                        "--cache-dir",
                        str(self.repo / "worker-cache"),
                        "--label",
                        "node=99998",
                    ],
                    cwd=self.repo,
                    env=env,
                    stdout=worker_log,
                    stderr=worker_log,
                )
                run = self.run_installation(flags=flags)
                self.assertEqual(run["status"], "succeeded")
                history = fixture.reply(
                    self.cli("workflow", "history", "get", run["id"], *flags).stdout
                )
                steps = history["jobs"][0]["steps"]
                self.assertIn("worker-cache", steps[0]["outputs"]["stage_unit_script"])
                self.assertEqual(
                    json.loads(steps[-1]["outputs"]["stdout"]),
                    {"ready": True, "state": "active"},
                )
                self.assertTrue(all(step["status"] == "succeeded" for step in steps))
                self.assert_private_absent(json.dumps(history))
                evidence = os.environ.get("PROCESS_COMMAND_EVIDENCE")
                if evidence:
                    Path(evidence).write_text(json.dumps(history, indent=2) + "\n")
            finally:
                for process in (worker, serve):
                    if process is not None:
                        process.terminate()
                        try:
                            process.wait(timeout=10)
                        except subprocess.TimeoutExpired:
                            process.kill()
                            process.wait(timeout=5)
