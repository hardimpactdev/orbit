"""Actual exporter workflow and official model schemas with synthetic host effects."""

import copy
import json
import os
import socket
import subprocess
import sys
import time
import urllib.request
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from tests.native_harness import NativeTestCase


class MetricsExporterNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.state = self.repo / "host.json"
        self.state.write_text(
            json.dumps(
                {
                    "fail": "",
                    "calls": [],
                    "candidate": None,
                    "published": "old",
                    "loaded": "old",
                }
            )
        )
        fixture = (
            (ROOT / "tests/_support/metrics_exporter_host.ts")
            .read_text()
            .replace("FIXTURE_STATE_PATH", json.dumps(str(self.state)))
        )
        fixture = fixture.replace(
            "OFFICIAL_CFG_PATH",
            json.dumps(
                (ROOT / ".swamp/pulled-extensions/@swamp/cfgmgmt/models").as_uri() + "/"
            ),
        )
        fixture = fixture.replace(
            '"OFFICIAL_SCHEMA_PATH"',
            json.dumps(
                (
                    ROOT / ".swamp/pulled-extensions/@swamp/ssh/models/_lib/schemas.ts"
                ).as_uri()
            ),
        )
        (self.repo / "extensions/behaviors").mkdir(parents=True)
        (self.repo / "extensions/behaviors/fixture.ts").write_text(fixture)
        workflow = yaml.safe_load(
            (ROOT / "workflows/workflow-operation-metrics-exporter.yaml").read_text()
        )
        for step in [step for job in workflow["jobs"] for step in job["steps"]]:
            self.assertEqual(step.pop("target"), "orbit-node-209")
            step["task"] = copy.deepcopy(step["task"])
            task = step["task"]
            if task.get("type") == "workflow":
                child_name = task["workflowIdOrName"]
                child = yaml.safe_load(
                    (
                        ROOT / "workflows" / ("workflow-" + child_name + ".yaml")
                    ).read_text()
                )
                child["jobs"] = [
                    {
                        "name": "fixture",
                        "steps": [
                            {
                                "name": "accepted",
                                "task": {
                                    "type": "assert",
                                    "expr": "true",
                                    "message": "fixture child accepted",
                                },
                            }
                        ],
                    }
                ]
                (
                    self.repo / "workflows" / ("workflow-" + child_name + ".yaml")
                ).write_text(yaml.safe_dump(child))
            if task.get("behaviorType", "").startswith("@swamp/"):
                task["inputs"] = dict(
                    task.get("inputs", {}),
                    _fixture_type=task["behaviorType"],
                    _fixture_name=step["name"],
                )
                task["behaviorType"] = "@fixture/metrics-host"
        (self.repo / "workflows/workflow-metrics-exporter.yaml").write_text(
            yaml.safe_dump(workflow, sort_keys=False)
        )
        self.connection = {
            "nodeHost": "192.0.2.9",
            "nodeUser": "orbit",
            "nodePort": 22,
            "nodeIdentityFile": "/approved",
            "become": True,
        }
        ssh = {
            "control_node_id": 209,
            "identity_file": "/approved",
            "public_key": "public",
            "target": {
                "name": "target",
                "host": "192.0.2.9",
                "user": "orbit",
                "port": 22,
                "host_keys": [
                    "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND"
                ],
            },
        }
        self.intent = {
            "exporter_node_id": 9,
            "enabled": True,
            "ssh_execution": ssh,
            "metrics_address": "192.0.2.3",
            "runtime": {
                "assignment_id": 1,
                "ssh_execution": ssh,
                "configuration": "global: {}\nscrape_configs: []\n",
            },
            "service": {
                "files": [
                    dict(
                        self.connection,
                        path="/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf",
                        ensure="present",
                        content="public unit",
                        mode="0644",
                    )
                ],
                "downloads": [
                    dict(
                        self.connection,
                        url="https://example.com/cadvisor",
                        path="/usr/local/bin/orbit-cadvisor",
                        checksum="a" * 64,
                        mode="0755",
                    )
                ],
                "units": [
                    dict(
                        self.connection,
                        service="prometheus-node-exporter.service",
                        ensure="running",
                        enabled=True,
                    )
                ],
                "rules": [
                    dict(
                        self.connection,
                        port=p,
                        source="192.0.2.3",
                        action="allow",
                        ensure="present",
                    )
                    for p in [9100, 9102]
                ],
                "checks": [
                    "http://192.0.2.9:9100/metrics",
                    "http://192.0.2.9:9102/metrics",
                ],
                "pools": [],
                "caddy": None,
            },
        }

        for value in (
            self.intent["service"]["files"] + self.intent["service"]["downloads"]
        ):
            value.update(owner="root", group="root")

    def run_exporter(self, fail="", success=True, flags=()):
        state = json.loads(self.state.read_text())
        state["fail"] = fail
        self.state.write_text(json.dumps(state))
        inputs = {
            "operation_id": 12,
            "node_id": 9,
            "kind": "metrics.exporter_enable"
            if self.intent["enabled"]
            else "metrics.exporter_disable",
            "intent": self.intent,
        }
        started = json.loads(
            self.cli(
                "workflow",
                "run",
                "operation-metrics-exporter",
                "--input",
                json.dumps(inputs),
                *flags,
                success=success,
            ).stdout
        )
        return json.loads(
            self.cli("workflow", "history", "get", started["id"], *flags).stdout
        ), json.loads(self.state.read_text())

    def test_enable_uses_official_models_and_restarts_existing_prometheus(self):
        history, state = self.run_exporter()
        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(state["loaded"], self.intent["runtime"]["configuration"])
        calls = [c["step"] for c in state["calls"]]
        self.assertLess(calls.index("validate"), calls.index("publish"))
        self.assertLess(calls.index("publish"), calls.index("prometheus"))
        self.assertIn("@swamp/cfgmgmt/firewall", [c["type"] for c in state["calls"]])
        self.assertIn("@swamp/cfgmgmt/systemd", [c["type"] for c in state["calls"]])
        self.assertIn(
            "@swamp/cfgmgmt/docker_container", [c["type"] for c in state["calls"]]
        )

    def test_disable_stops_units_and_removes_official_port_rules(self):
        self.intent["enabled"] = False
        self.intent["service"]["downloads"] = []
        self.intent["service"]["checks"] = []
        for unit in self.intent["service"]["units"]:
            unit.update(ensure="stopped", enabled=False)
        for rule in self.intent["service"]["rules"]:
            rule["ensure"] = "absent"
        for file in self.intent["service"]["files"]:
            file["ensure"] = "absent"
        history, state = self.run_exporter()
        self.assertEqual(history["status"], "succeeded")
        calls = [c["step"] for c in state["calls"]]
        self.assertIn("stopped", calls)
        self.assertNotIn("started", calls)
        self.assertNotIn("package", calls)
        self.assertTrue(
            all(
                c["globals"]["ensure"] == "absent"
                for c in state["calls"]
                if c["step"] == "firewall"
            )
        )

    def test_preference_only_runs_no_host_effect(self):
        self.intent["runtime"] = None
        self.intent["service"] = None
        history, state = self.run_exporter()
        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(state["calls"], [])

    def test_bad_config_does_not_publish_or_restart(self):
        history, state = self.run_exporter("validate", success=False)
        self.assertEqual(history["status"], "failed")
        self.assertEqual(state["published"], "old")
        self.assertEqual(state["loaded"], "old")
        self.assertNotIn("prometheus", [c["step"] for c in state["calls"]])

    def test_firewall_failure_stops_publication(self):
        history, state = self.run_exporter("firewall", success=False)
        self.assertEqual(history["status"], "failed")
        self.assertEqual(state["published"], "old")

    def test_failed_readiness_retains_published_intent_for_native_resume(self):
        history, state = self.run_exporter("verify", success=False)
        self.assertEqual(history["status"], "failed")
        self.assertEqual(state["loaded"], self.intent["runtime"]["configuration"])
        self.assertEqual(state["published"], state["loaded"])

    def test_canonical_child_schemas_accept_public_service_intent(self):
        connection = {
            "control_node_id": 209,
            "host_keys": self.intent["ssh_execution"]["target"]["host_keys"],
            "connection": self.connection,
        }
        self.intent["service"]["caddy"] = {
            "node_id": 9,
            "user": "orbit",
            **connection,
            "caddy": {
                "content": "public config",
                "version": "a" * 64,
                "listen_addresses": ["192.0.2.9"],
                "unserved": False,
            },
        }
        self.intent["service"]["pools"] = [
            {"node_id": 9, **connection, "php_runtime": {"mode": "monitor"}}
        ]
        history, _ = self.run_exporter()
        self.assertEqual(history["status"], "succeeded")
        states = {
            s["name"]: s["status"] for job in history["jobs"] for s in job["steps"]
        }
        self.assertEqual(states["caddy"], "succeeded")
        self.assertEqual(states["pools-0"], "succeeded")

    def test_official_firewall_schema_refuses_invalid_port_before_effect(self):
        self.intent["service"]["rules"] = self.intent["service"]["rules"][:1]
        self.intent["service"]["rules"][0]["port"] = 0
        history, state = self.run_exporter(success=False)
        self.assertEqual(history["status"], "failed")
        self.assertEqual(state["published"], "old")
        self.assertNotIn("firewall", [c["step"] for c in state["calls"]])

    def test_metrics_owner_and_exporter_keep_their_captured_ssh_ports(self):
        self.intent["runtime"]["ssh_execution"] = copy.deepcopy(
            self.intent["ssh_execution"]
        )
        owner = self.intent["runtime"]["ssh_execution"]["target"]
        owner.update(host="192.0.2.3", user="metrics", port=2202)
        _, state = self.run_exporter()
        for call in state["calls"]:
            if call["type"] != "@swamp/ssh":
                continue
            globals = call["globals"]
            remote = call["step"] in ("validate", "verify")
            self.assertEqual(globals["transport"]["port"], 2202 if remote else 22)
            self.assertEqual(
                globals["hosts"][0]["address"],
                "192.0.2.3" if remote else "192.0.2.9",
            )

    def test_workflow_stays_within_requested_budget(self):
        workflow = ROOT / "workflows/workflow-operation-metrics-exporter.yaml"
        self.assertLessEqual(len(workflow.read_text().splitlines()), 300)
        self.assertFalse(
            (
                ROOT / "extensions/behaviors/operations/metrics-exporter/scripts/ufw.py"
            ).exists()
        )

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
                history, state = self.run_exporter(
                    "prometheus", success=False, flags=flags
                )
                self.assertEqual(state["loaded"], "old")
                self.assertEqual(
                    state["published"], self.intent["runtime"]["configuration"]
                )
                state["fail"] = ""
                self.state.write_text(json.dumps(state))
                resumed = json.loads(
                    self.cli(
                        "workflow",
                        "resume",
                        "operation-metrics-exporter",
                        "--run",
                        history["id"],
                        "--from",
                        "prometheus",
                        *flags,
                    ).stdout
                )
                self.assertEqual(resumed["status"], "succeeded")
                state = json.loads(self.state.read_text())
                self.assertEqual(state["loaded"], state["published"])
            finally:
                server.terminate()
                try:
                    server.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait(timeout=5)
