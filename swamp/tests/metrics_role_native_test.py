"""Actual role DAG, official schemas and native vault delivery; no fleet access."""

import copy
import hashlib
import json
import os
import socket
import subprocess
import time
import urllib.request
import shutil
import sys
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from tests.native_harness import NativeTestCase


class MetricsRoleNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.secret = "fixture-role-password-" + self.repo.name
        self.secrets = (self.secret,)
        self.state = self.repo / "host.json"
        self.state.write_text(
            json.dumps(
                {
                    "calls": [],
                    "fail": "",
                    "passwordHash": hashlib.sha256(self.secret.encode()).hexdigest(),
                }
            )
        )
        fixture = (
            (ROOT / "tests/_support/metrics_role_host.ts")
            .read_text()
            .replace("FIXTURE_STATE_PATH", json.dumps(str(self.state)))
            .replace(
                "OFFICIAL_CFG_PATH",
                json.dumps(
                    (ROOT / ".swamp/pulled-extensions/@swamp/cfgmgmt/models").as_uri()
                    + "/"
                ),
            )
            .replace(
                '"OFFICIAL_SCHEMA_PATH"',
                json.dumps(
                    (
                        ROOT
                        / ".swamp/pulled-extensions/@swamp/ssh/models/_lib/schemas.ts"
                    ).as_uri()
                ),
            )
        )
        (self.repo / "extensions/behaviors").mkdir(parents=True)
        (self.repo / "extensions/behaviors/fixture.ts").write_text(fixture)
        (self.repo / "extensions/behaviors/integrations").mkdir()
        for name in ["metrics_role_plan.ts", "metrics_runtime_spec.ts"]:
            shutil.copy(
                ROOT / "extensions/behaviors/operations/integrations" / name,
                self.repo / "extensions/behaviors/integrations" / name,
            )
        workflow = yaml.safe_load(
            (ROOT / "workflows/workflow-operation-metrics-role.yaml").read_text()
        )
        for step in [step for job in workflow["jobs"] for step in job["steps"]]:
            self.assertEqual(step.pop("target"), "orbit-node-209")
            step["task"] = copy.deepcopy(step["task"])
            task = step["task"]
            if task.get("type") == "workflow":
                name = task["workflowIdOrName"]
                child = yaml.safe_load(
                    (ROOT / "workflows" / ("workflow-" + name + ".yaml")).read_text()
                )
                child["jobs"] = [
                    {
                        "name": "fixture",
                        "steps": [
                            {
                                "name": "child",
                                "task": {
                                    "type": "assert",
                                    "expr": "true",
                                    "message": "child schema accepted",
                                },
                            }
                        ],
                    }
                ]
                (self.repo / "workflows" / ("workflow-" + name + ".yaml")).write_text(
                    yaml.safe_dump(child)
                )
            if task.get("behaviorType", "").startswith("@swamp/"):
                task["inputs"] = dict(
                    task.get("inputs", {}),
                    _fixture_type=task["behaviorType"],
                    _fixture_step=step["name"],
                    _fixture_method=task["methodName"],
                )
                task["behaviorType"] = "@fixture/metrics-role-host"
        (self.repo / "workflows/workflow-metrics-role.yaml").write_text(
            yaml.safe_dump(workflow, sort_keys=False)
        )
        ssh = {
            "identity_file": "/approved",
            "target": {
                "host": "192.0.2.9",
                "user": "orbit",
                "port": 22,
                "host_keys": [
                    "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND"
                ],
            },
        }
        self.intent = {
            "enabled": True,
            "assignment_id": 41,
            "purge_data": False,
            "ssh_execution": ssh,
            "gateway_ssh": ssh,
            "directories": ["/etc/orbit/metrics", "/etc/orbit/metrics/grafana"],
            "files": [
                {
                    "path": "/etc/orbit/metrics/prometheus.yml",
                    "content": "global: {}\nscrape_configs: []\n",
                }
            ],
            "certificates": {"leaves": [], "withdrawals": []},
            "fleet": [],
            "dns": [],
            "caddy": {"node_id": 9},
            "firewall": {
                "port": 3000,
                "source": "192.0.2.2",
                "action": "allow",
                "ensure": "present",
            },
            "purge_volumes": [],
        }
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        self.cli(
            "vault",
            "put",
            "orbit-control-inputs",
            "metrics-role-operation-12",
            input=self.secret,
        )

    def run_role(self, fail="", success=True, flags=()):
        state = json.loads(self.state.read_text())
        state["fail"] = fail
        self.state.write_text(json.dumps(state))
        inputs = {
            "operation_id": 12,
            "node_id": 9,
            "kind": "metrics.enable" if self.intent["enabled"] else "metrics.disable",
            "intent": self.intent,
            "execution_secrets": {
                "password": {
                    "vault": "orbit-control-inputs",
                    "key": "metrics-role-operation-12",
                }
            }
            if self.intent["enabled"]
            else {},
        }
        result = json.loads(
            self.cli(
                "workflow",
                "run",
                "operation-metrics-role",
                "--input",
                json.dumps(inputs),
                *flags,
                success=success,
            ).stdout
        )
        history = json.loads(
            self.cli("workflow", "history", "get", result["id"], *flags).stdout
        )
        return history, json.loads(self.state.read_text())

    def test_enable_delivers_password_without_public_bytes_and_uses_official_models(
        self,
    ):
        history, state = self.run_role()
        self.assertEqual(history["status"], "succeeded")
        calls = [c["step"] for c in state["calls"]]
        self.assertLess(calls.index("validate"), calls.index("publish"))
        self.assertLess(calls.index("password"), calls.index("start-unit"))
        self.assertIn("grafana", calls)
        self.assertTrue(all(c["args"]["argv"][1:3] == ["volume", "create"] for c in state["calls"] if c["step"] == "create-volume"))
        self.assertEqual(calls.count("start-unit"), 2)
        self.assertEqual(calls.count("image"), 2)

    def test_disable_preserves_volumes_and_skips_secret_and_start(self):
        self.intent["enabled"] = False
        self.intent["firewall"]["ensure"] = "absent"
        history, state = self.run_role()
        self.assertEqual(history["status"], "succeeded")
        self.assertFalse(
            any(
                c["step"] in ["password", "validate", "create-volume", "grafana", "start-unit"]
                for c in state["calls"]
            )
        )
        self.assertTrue(
            all(
                c["globals"]["ensure"] == "absent"
                for c in state["calls"]
                if c["type"] == "@swamp/cfgmgmt/docker_container"
            )
        )

    def test_purge_is_explicit_after_container_and_file_removal(self):
        self.intent.update(
            enabled=False,
            purge_data=True,
            purge_volumes=[
                "orbit-metrics-prometheus-data",
                "orbit-metrics-grafana-data",
            ],
        )
        history, state = self.run_role()
        self.assertEqual(history["status"], "succeeded")
        calls = [c["step"] for c in state["calls"]]
        self.assertLess(
            calls.index("container"),
            max(i for i, call in enumerate(calls) if call == "purge-volume"),
        )
        self.assertEqual(calls.count("purge-volume"), 2)

    def test_invalid_configuration_prevents_password_containers_and_publication(self):
        history, state = self.run_role("validate", False)
        self.assertEqual(history["status"], "failed")
        self.assertFalse(
            any(
                c["step"] in ["password", "publish", "container", "port"]
                for c in state["calls"]
            )
        )

    def test_failed_health_cannot_reopen_later_jobs(self):
        history, state = self.run_role("grafana", False)
        self.assertEqual(history["status"], "failed")
        self.assertFalse(any(c["step"] == "port" for c in state["calls"]))

    def test_server_backed_resume_restarts_after_publication_failure(self):
        # Reuse the official local serve fixture path used by database.destroy.
        # It forwards globalArgs, unlike e459's local CLI resume resolver.
        self.cli(
            "access", "token", "mint", "fixture-admin", "--principal", "user:fixture"
        )
        token = self.repo / "fixture-admin-token"
        token.write_text(
            json.loads(
                self.cli("access", "token", "reveal", "fixture-admin", "--yes").stdout
            )["token"]
        )
        token.chmod(0o600)
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        env = dict(os.environ, SWAMP_NO_UPDATE_CHECK="1", SWAMP_NO_TELEMETRY="1")
        for name in (
            "SWAMP_SERVER_URL",
            "SWAMP_SERVE_URL",
            "SWAMP_SERVER_TOKEN",
            "SWAMP_SERVER_TOKEN_FILE",
            "SWAMP_REPO_DIR",
            "SWAMP_CA_CERT",
            "SWAMP_EXTENSIONS_DIR",
        ):
            env.pop(name, None)
        with (self.repo / "serve.log").open("w") as log:
            server = subprocess.Popen(
                [
                    os.environ.get("SWAMP_BIN", "swamp"),
                    "serve",
                    "--host",
                    "127.0.0.1",
                    "--port",
                    str(port),
                    "--auth-mode",
                    "token",
                    "--admins",
                    "user:fixture",
                    "--no-schedule",
                ],
                cwd=self.repo,
                env=env,
                stdout=log,
                stderr=log,
            )
            try:
                for _ in range(100):
                    try:
                        urllib.request.urlopen(
                            f"http://127.0.0.1:{port}/ready", timeout=1
                        ).close()
                        break
                    except OSError:
                        self.assertIsNone(
                            server.poll(), (self.repo / "serve.log").read_text()[-2000:]
                        )
                        time.sleep(0.1)
                else:
                    self.fail("Scratch serve did not become ready")
                flags = (
                    "--server",
                    f"ws://127.0.0.1:{port}",
                    "--token-file",
                    str(token),
                )
                history, state = self.run_role(
                    "grafana", success=False, flags=flags
                )
                state["fail"] = ""
                self.state.write_text(json.dumps(state))
                resumed = json.loads(
                    self.cli(
                        "workflow",
                        "resume",
                        "operation-metrics-role",
                        "--run",
                        history["id"],
                        "--from",
                        "grafana",
                        *flags,
                    ).stdout
                )
                self.assertEqual(resumed["status"], "succeeded")
                state = json.loads(self.state.read_text())
                self.assertEqual(sum(c["step"] == "password" for c in state["calls"]), 1)
                self.assertEqual(sum(c["step"] == "grafana" for c in state["calls"]), 2)
            finally:
                server.terminate()
                try:
                    server.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait(timeout=5)
