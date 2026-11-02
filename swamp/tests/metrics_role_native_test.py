"""Actual metrics.enable and metrics.disable parents and children, official schemas and native vault delivery; no fleet access."""

import copy
import hashlib
import json
import os
import posixpath
import shutil
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

HOST_KEYS = [
    "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND"
]
# Real workflows under test. Only their @swamp/* behaviors are faked.
REAL = (
    "operation-metrics-enable",
    "operation-metrics-disable",
    "metrics-docker",
    "metrics-configuration",
    "metrics-containers",
    "metrics-containers-remove",
    "metrics-publication",
    "metrics-withdrawal",
    "metrics-exporter-node",
    "metrics-exporter-allows",
)
# Children owned by other kinds keep their input schema and record one call.
STUBS = (
    "node-role-certificate-publish",
    "operation-route-caddy",
    "route-dns-publish",
    "operation-php-monitor",
)
PROMETHEUS = "/etc/orbit/metrics/prometheus.yml"
ENABLE_STEPS = [
    "Install Docker",
    "Write the configuration",
    "Start the containers",
    "Add the fleet exporters",
    "Allow the fleet scrapes",
    "Sign metrics.orbit cert",
    "Allow the Gateway in",
    "Publish metrics.orbit",
]
DISABLE_STEPS = [
    "Withdraw metrics.orbit",
    "Remove the containers",
    "Remove the fleet exporters",
    "Remove the fleet allows",
    "Remove the Grafana rules",
    "Remove the configuration",
    "Check it is gone",
]
# Which top-level chart step each called workflow belongs to.
OWNER = {
    "operation-metrics-enable": {
        "metrics-docker": "Install Docker",
        "metrics-configuration": "Write the configuration",
        "metrics-containers": "Start the containers",
        "metrics-exporter-node": "Add the fleet exporters",
        "metrics-exporter-allows": "Allow the fleet scrapes",
        "node-role-certificate-publish": "Sign metrics.orbit cert",
        "operation-route-caddy": "Publish metrics.orbit",
        "route-dns-publish": "Publish metrics.orbit",
        "metrics-publication": "Publish metrics.orbit",
    },
    "operation-metrics-disable": {
        "metrics-withdrawal": "Withdraw metrics.orbit",
        "operation-route-caddy": "Withdraw metrics.orbit",
        "route-dns-publish": "Withdraw metrics.orbit",
        "metrics-containers-remove": "Remove the containers",
        "metrics-exporter-node": "Remove the fleet exporters",
        "metrics-exporter-allows": "Remove the fleet allows",
    },
}


def ssh_input(node_id, host):
    """SwampSshExecutionInput::forNode."""
    return {
        "control_node_id": 209,
        "identity_file": "/approved",
        "public_key": "public",
        "target": {
            "name": "target",
            "host": host,
            "user": "orbit",
            "port": 22,
            "host_keys": HOST_KEYS,
        },
    }


def connection(host):
    """NativeCaddyPublicationInput::connectionForNode."""
    return {
        "control_node_id": 209,
        "host_keys": HOST_KEYS,
        "connection": {
            "nodeHost": host,
            "nodeUser": "orbit",
            "nodePort": 22,
            "nodeIdentityFile": "/approved",
            "become": True,
        },
    }


class MetricsRoleNative(NativeTestCase):
    native_timeout = 300

    def setUp(self):
        super().setUp()
        self.secret = "fixture-role-password-" + self.repo.name
        self.secrets = (self.secret,)
        self.state = self.repo / "host.json"
        self.calls = self.repo / "calls.jsonl"
        self.state.write_text(json.dumps({"fail": []}))
        fixture = (
            (ROOT / "tests/_support/metrics_role_host.ts")
            .read_text()
            .replace("FIXTURE_STATE_PATH", json.dumps(str(self.state)))
            .replace("FIXTURE_CALLS_PATH", json.dumps(str(self.calls)))
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
        # The public plan is real code.
        (self.repo / "extensions/behaviors/integrations").mkdir()
        for name in ["metrics_role_plan.ts", "metrics_runtime_spec.ts"]:
            shutil.copy(
                ROOT / "extensions/behaviors/operations/integrations" / name,
                self.repo / "extensions/behaviors/integrations" / name,
            )
        for name in REAL:
            self.install(name)
        for name in STUBS:
            self.stub(name)
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        self.cli(
            "vault",
            "put",
            "orbit-control-inputs",
            "metrics-role-operation-12",
            input=self.secret,
        )
        self.intent = self.capture(enabled=True, purge=False)

    def load(self, name):
        return yaml.safe_load(
            (ROOT / "workflows" / ("workflow-" + name + ".yaml")).read_text()
        )

    def install(self, name):
        workflow = self.load(name)
        for step in [step for job in workflow["jobs"] for step in job["steps"]]:
            self.assertEqual(step.pop("labels"), {"role": "swamp"})
            step["task"] = copy.deepcopy(step["task"])
            task = step["task"]
            if task.get("type") == "workflow":
                self.assertIn(task["workflowIdOrName"], REAL + STUBS, name)
            if task.get("behaviorType", "").startswith("@swamp/"):
                task["inputs"] = dict(
                    task.get("inputs", {}),
                    _fixture_type=task["behaviorType"],
                    _fixture_workflow=name,
                    _fixture_step=step["name"],
                    _fixture_method=task["methodName"],
                )
                task["behaviorType"] = "@fixture/metrics-role-host"
        (self.repo / "workflows" / ("workflow-" + name + ".yaml")).write_text(
            yaml.safe_dump(workflow, sort_keys=False)
        )

    def stub(self, name):
        child = self.load(name)
        child["jobs"] = [
            {
                "name": "fixture",
                "steps": [
                    {
                        "name": "accepted",
                        "task": {
                            "type": "behavior",
                            "behaviorType": "@fixture/metrics-role-host",
                            "behaviorName": '${{ ("stub-" + run.id).split(".").join("-") }}',
                            "methodName": "exec",
                            "inputs": {
                                "_fixture_type": "stub",
                                "_fixture_workflow": name,
                                "_fixture_step": "accepted",
                                "node_id": "${{ inputs.node_id }}",
                                "kind": "${{ inputs.kind }}",
                            },
                        },
                    }
                ],
            }
        ]
        if name == "node-role-certificate-publish":
            child["jobs"][0]["steps"][0]["task"]["inputs"]["ssh_name"] = "${{ inputs.ssh.name }}"
        (self.repo / "workflows" / ("workflow-" + name + ".yaml")).write_text(
            yaml.safe_dump(child, sort_keys=False)
        )

    def capture(self, enabled, purge):
        """MetricsRoleInput::capture for Metrics Node 9, Gateway Node 2 and exporter Node 10."""
        files = {
            PROMETHEUS: "global: {}\nscrape_configs: []\n",
            "/etc/orbit/metrics/grafana/grafana.ini": "[server]\nroot_url = https://metrics.orbit/\n",
            "/etc/orbit/metrics/grafana/provisioning/datasources/prometheus.yml": "apiVersion: 1\n",
            "/etc/orbit/metrics/grafana/provisioning/dashboards/provider.yml": "apiVersion: 1\n",
            "/etc/orbit/metrics/grafana/dashboards/orbit-node-resources.json": "{}\n",
            "/etc/orbit/metrics/grafana/dashboards/orbit-caddy.json": "{}\n",
            "/etc/orbit/metrics/grafana/dashboards/orbit-fpm.json": "{}\n",
        }
        dirs = list(dict.fromkeys(posixpath.dirname(path) for path in files))
        dirs.sort(key=lambda path: path.count("/"))
        ssh = ssh_input(9, "192.0.2.9")
        exporter = connection("192.0.2.10")["connection"]
        service = {
            "files": [
                dict(
                    exporter,
                    path="/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf",
                    ensure="present" if enabled else "absent",
                    content="public unit",
                    mode="0644",
                    owner="root",
                    group="root",
                )
            ],
            "downloads": [
                dict(
                    exporter,
                    url="https://example.com/cadvisor",
                    path="/usr/local/bin/orbit-cadvisor",
                    checksum="a" * 64,
                    mode="0755",
                    owner="root",
                    group="root",
                )
            ]
            if enabled
            else [],
            "units": [
                dict(
                    exporter,
                    service="prometheus-node-exporter.service",
                    ensure="running" if enabled else "stopped",
                    enabled=enabled,
                )
            ],
            "rules": [
                dict(
                    exporter,
                    port=port,
                    source="192.0.2.9",
                    action="allow",
                    ensure="present" if enabled else "absent",
                )
                for port in [9100, 9102]
            ],
            "checks": ["http://192.0.2.10:9100/metrics"] if enabled else [],
            "pools": [],
            "caddy": None,
        }
        certificates = (
            {
                "leaves": [
                    {
                        "node_id": 2,
                        "scope": "role-metrics",
                        "domain": "metrics.orbit",
                        "version": "0123456789abcdef",
                        "root_hash": "b" * 64,
                    }
                ],
                "withdrawals": [],
            }
            if enabled
            else {
                "leaves": [],
                "withdrawals": [{"node_id": 2, "scope": "role-metrics"}],
            }
        )
        return {
            "enabled": enabled,
            "purge_data": purge,
            "assignment_id": 41,
            "ssh_execution": ssh,
            "directories": dirs,
            "files": [{"path": p, "content": c} for p, c in files.items()],
            "fleet": [
                {
                    "exporter_node_id": 10,
                    "enabled": enabled,
                    "metrics_address": "192.0.2.9",
                    "ssh_execution": ssh_input(10, "192.0.2.10"),
                    "runtime": {
                        "ssh_execution": ssh,
                        "configuration": files[PROMETHEUS],
                    },
                    "service": service,
                }
            ],
            "caddy": {
                "node_id": 2,
                "user": "orbit",
                "caddy": {
                    "content": "public config",
                    "version": "c" * 64,
                    "listen_addresses": ["192.0.2.2"],
                    "unserved": False,
                },
                **connection("192.0.2.2"),
            },
            "dns": [
                {
                    "node_id": 2,
                    **connection("192.0.2.2"),
                    "before": [],
                    "after": [],
                    "alternatives": [],
                }
            ],
            "certificates": certificates,
            "gateway_ssh": ssh_input(2, "192.0.2.2"),
            "firewall": {
                "port": 3000,
                "source": "192.0.2.2",
                "action": "allow",
                "ensure": "present" if enabled else "absent",
            },
            "purge_volumes": [
                "orbit-metrics-prometheus-data",
                "orbit-metrics-grafana-data",
            ]
            if purge
            else [],
        }

    def workflow(self):
        return "operation-metrics-" + (
            "enable" if self.intent["enabled"] else "disable"
        )

    def run_role(self, *fail, success=True, flags=()):
        self.state.write_text(json.dumps({"fail": list(fail)}))
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
                self.workflow(),
                "--input",
                json.dumps(inputs),
                *flags,
                success=success,
            ).stdout
        )
        history = self.cli("workflow", "history", "get", result["id"], *flags).stdout
        self.assertNotIn(self.secret, history)
        return json.loads(history), self.recorded()

    def recorded(self):
        if not self.calls.exists():
            return []
        return [json.loads(line) for line in self.calls.read_text().splitlines()]

    def chart(self, calls):
        """Top-level chart step of each call, in call order, without repeats."""
        parent = self.workflow()
        labels = []
        for call in calls:
            label = (
                call["step"]
                if call["workflow"] == parent
                else OWNER[parent][call["workflow"]]
            )
            if not labels or labels[-1] != label:
                labels.append(label)
        return labels

    def named(self, calls, workflow, step=None):
        return [
            c
            for c in calls
            if c["workflow"] == workflow and (step is None or c["step"] == step)
        ]

    def index(self, calls, workflow, step):
        return next(
            i
            for i, c in enumerate(calls)
            if c["workflow"] == workflow and c["step"] == step
        )

    def test_enable_runs_the_chart_in_order(self):
        history, calls = self.run_role()
        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(self.chart(calls), ENABLE_STEPS)
        leaf = self.named(calls, "node-role-certificate-publish")
        self.assertEqual([c["args"]["node_id"] for c in leaf], [9])
        # The child prefixes the SSH name; Swamp refuses a behavior name over 64 characters.
        for c in leaf:
            self.assertLessEqual(len("role-cert-work-gone-" + c["args"]["ssh_name"]), 64)
        firewall = self.named(calls, "operation-metrics-enable", "Allow the Gateway in")
        self.assertEqual(firewall[0]["globals"]["ensure"], "present")
        self.assertEqual(firewall[0]["globals"]["port"], 3000)
        caddy = self.named(calls, "operation-route-caddy")
        self.assertEqual([c["args"]["node_id"] for c in caddy], [2])
        # The fleet exporter is applied on its own Node, never the Metrics Node.
        for call in self.named(calls, "metrics-exporter-node") + self.named(
            calls, "metrics-exporter-allows"
        ):
            self.assertEqual(call["globals"].get("nodeHost", "192.0.2.10"), "192.0.2.10")

    def test_enable_validates_before_the_in_place_publish(self):
        _, calls = self.run_role()
        config = "metrics-configuration"
        self.assertLess(self.index(calls, config, "files"), self.index(calls, config, "validate"))
        self.assertLess(self.index(calls, config, "validate"), self.index(calls, config, "publish"))
        self.assertLess(self.index(calls, config, "publish"), self.index(calls, config, "password"))
        validate = self.named(calls, config, "validate")[0]["args"]["argv"]
        self.assertEqual(validate[0], "/usr/bin/docker")
        self.assertIn("/bin/promtool", validate)
        candidate = next(
            c["globals"]["path"]
            for c in self.named(calls, config, "files")
            if c["globals"]["path"].startswith("/etc/orbit/metrics/prometheus-")
        )
        self.assertIn(candidate + ":/etc/prometheus/prometheus.yml:ro", validate)
        self.assertNotIn(PROMETHEUS, [c["globals"]["path"] for c in self.named(calls, config, "files")])
        publish = self.named(calls, config, "publish")[0]["args"]["argv"]
        # In place: Prometheus bind-mounts the file, so a rename would leave it on the old inode.
        self.assertEqual(publish, ["/usr/bin/cp", "--no-preserve=all", candidate, PROMETHEUS])
        removed = self.named(calls, config, "remove_candidate")
        self.assertEqual([(c["globals"]["path"], c["globals"]["ensure"]) for c in removed], [(candidate, "absent")])

    def test_enable_delivers_the_password_only_on_stdin(self):
        _, calls = self.run_role()
        password = self.named(calls, "metrics-configuration", "password")
        self.assertEqual(len(password), 1)
        self.assertEqual(
            password[0]["args"]["argv"],
            ["/usr/bin/install", "-m", "0400", "-o", "472", "-g", "472", "/dev/stdin", "/etc/orbit/metrics/grafana/admin-password"],
        )
        self.assertEqual(password[0]["stdinSha256"], hashlib.sha256(self.secret.encode()).hexdigest())
        # Only the install step receives stdin, and no argv or recorded argument holds the secret.
        self.assertEqual([c["step"] for c in calls if c["stdinSha256"] is not None], ["password"])
        self.assertNotIn(self.secret, json.dumps(calls))
        # The harness also scanned every scratch file, including native history and step outputs.

    def test_enable_waits_are_single_bounded_commands(self):
        _, calls = self.run_role()
        for workflow, step in [
            ("metrics-containers", "prometheus_ready"),
            ("metrics-containers", "grafana_login"),
            ("metrics-publication", "answers"),
        ]:
            waits = self.named(calls, workflow, step)
            self.assertEqual(len(waits), 1, step)
            self.assertEqual(waits[0]["args"]["argv"][:4], ["timeout", "60", "sh", "-c"], step)
            self.assertEqual(waits[0]["args"]["timeoutSec"], 70, step)
        # The Gateway CA sits in the agent's root-only directory.
        self.assertIs(self.named(calls, "metrics-publication", "answers")[0]["args"]["sudo"], True)
        containers = self.named(calls, "metrics-containers")
        self.assertLess(
            max(i for i, c in enumerate(containers) if c["step"] == "started"),
            min(i for i, c in enumerate(containers) if c["step"] in ("prometheus_ready", "grafana_login")),
        )

    def test_failed_promtool_validate_stops_before_publish_and_removes_the_candidate(self):
        history, calls = self.run_role("metrics-configuration:validate", success=False)
        self.assertEqual(history["status"], "failed")
        steps = [c["step"] for c in self.named(calls, "metrics-configuration")]
        self.assertIn("validate", steps)
        self.assertNotIn("publish", steps)
        self.assertNotIn("password", steps)
        self.assertIn("remove_candidate", steps)
        self.assertEqual(self.chart(calls), ENABLE_STEPS[:2])
        for workflow in ("metrics-containers", "metrics-publication", "operation-route-caddy", "route-dns-publish", "node-role-certificate-publish"):
            self.assertEqual(self.named(calls, workflow), [], workflow)

    def disable(self, purge=False):
        self.intent = self.capture(enabled=False, purge=purge)

    def test_disable_withdraws_first_and_keeps_volumes_without_purge(self):
        self.disable()
        history, calls = self.run_role()
        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(self.chart(calls), DISABLE_STEPS)
        self.assertEqual(self.named(calls, "operation-metrics-disable", "Delete the volumes"), [])
        withdrawal = [c["workflow"] for c in calls if OWNER["operation-metrics-disable"].get(c["workflow"]) == "Withdraw metrics.orbit"]
        self.assertEqual(withdrawal, ["operation-route-caddy", "route-dns-publish", "metrics-withdrawal"])
        rules = self.named(calls, "operation-metrics-disable", "Remove the Grafana rules")
        self.assertEqual(rules[0]["globals"]["ensure"], "absent")
        config = self.named(calls, "operation-metrics-disable", "Remove the configuration")
        self.assertEqual((config[0]["globals"]["path"], config[0]["globals"]["ensure"]), ("/etc/orbit/metrics", "absent"))
        containers = self.named(calls, "metrics-containers-remove", "container")
        self.assertEqual(sorted(c["globals"]["name"] for c in containers), ["orbit-metrics-grafana", "orbit-metrics-prometheus"])
        self.assertTrue(all(c["globals"]["ensure"] == "absent" for c in containers))
        self.assertFalse(any(c["stdinSha256"] for c in calls))
        self.assertNotIn("metrics-configuration", [c["workflow"] for c in calls])

    def test_disable_with_purge_removes_each_volume_before_the_check(self):
        self.disable(purge=True)
        history, calls = self.run_role()
        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(
            self.chart(calls),
            DISABLE_STEPS[:-1] + ["Delete the volumes", "Check it is gone"],
        )
        volumes = self.named(calls, "operation-metrics-disable", "Delete the volumes")
        self.assertEqual(
            sorted(c["args"]["argv"] for c in volumes),
            [
                ["/usr/bin/docker", "volume", "rm", "orbit-metrics-grafana-data"],
                ["/usr/bin/docker", "volume", "rm", "orbit-metrics-prometheus-data"],
            ],
        )

    def test_disable_fails_when_the_end_check_finds_metrics_left(self):
        self.disable()
        history, calls = self.run_role("operation-metrics-disable:Check it is gone", success=False)
        self.assertEqual(history["status"], "failed")
        self.assertEqual(self.chart(calls), DISABLE_STEPS)

    def test_server_backed_resume_does_not_deliver_the_password_again(self):
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
                history, _ = self.run_role(
                    "metrics-containers:grafana_login", success=False, flags=flags
                )
                self.assertEqual(history["status"], "failed")
                self.state.write_text(json.dumps({"fail": []}))
                resumed = json.loads(
                    self.cli(
                        "workflow",
                        "resume",
                        self.workflow(),
                        "--run",
                        history["id"],
                        "--from",
                        "Start the containers",
                        *flags,
                    ).stdout
                )
                self.assertEqual(resumed["status"], "succeeded")
                calls = self.recorded()
                self.assertEqual(len(self.named(calls, "metrics-configuration", "password")), 1)
                self.assertEqual(len(self.named(calls, "metrics-containers", "grafana_login")), 2)
                self.assertEqual(len(self.named(calls, "metrics-publication", "answers")), 1)
            finally:
                server.terminate()
                try:
                    server.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait(timeout=5)
