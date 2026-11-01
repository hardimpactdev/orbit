"""New read-only child runs shipped shared policy through the official pinned SSH API."""

import json

import yaml
from tests.native_harness import NativeTestCase
from tests.route_lan_command_native_test import PIN, ROOT, RouteLanFixture
from tests.route_ssh_fixture import install_ssh


class RouteUpstreamFirewallNative(RouteLanFixture):
    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/routes/update-command")
        self.copy_behaviors("operations/firewall")
        fake = str(self.repo / "ssh-tools/ufw")
        for name in ["route-upstream-firewall", "operation-firewall-inspect"]:
            flow = yaml.safe_load(
                (ROOT / f"workflows/workflow-{name}.yaml").read_text()
            )
            flow.pop("target", None)
            for job in flow["jobs"]:
                job.pop("target", None)
                for step in job["steps"]:
                    step.pop("target", None)
            flow = json.loads(json.dumps(flow).replace("/usr/sbin/ufw", fake))
            (self.repo / f"workflows/workflow-{name}.yaml").write_text(
                yaml.safe_dump(flow, sort_keys=False)
            )
        self.value = {
            "operation_id": 1,
            "node_id": 9,
            "kind": "route.create",
            "execution_secrets": {},
            "intent": {
                "node_id": 9,
                "control_node_id": 209,
                "connection": self.connection,
                "host_keys": [PIN],
                "expected": [
                    {
                        "comment": "operator-http",
                        "action": "allow",
                        "direction": "in",
                        "source": "192.168.6.2",
                        "destination": "any",
                        "port": "4788",
                        "protocol": "tcp",
                        "in_interface": None,
                        "out_interface": None,
                        "family": "v4",
                    }
                ],
            },
        }

    def test_shipped_policy_proves_exact_existing_allowance_without_writes(self):
        self.initialize(legacy=["4788/tcp ALLOW IN 192.168.6.2 # operator-http"])
        self.cli("workflow", "validate", "route-upstream-firewall")
        self.cli(
            "workflow",
            "run",
            "route-upstream-firewall",
            "--input",
            json.dumps(self.value),
        )
        self.assertEqual(
            [json.loads(line) for line in self.audit.read_text().splitlines()],
            [["status", "numbered"]],
        )

    def test_missing_allowance_refuses_and_never_writes_firewall(self):
        self.initialize()
        self.cli(
            "workflow",
            "run",
            "route-upstream-firewall",
            "--input",
            json.dumps(self.value),
            success=False,
        )
        self.assertEqual(
            [json.loads(line) for line in self.audit.read_text().splitlines()],
            [["status", "numbered"]],
        )


class RouteHttpProbeNative(NativeTestCase):
    workflow_name = "cluster-router-probe"

    def test_head_accepts_unauthorized_and_bypasses_proxy(self):
        self.check_response(401)

    def test_head_accepts_not_found_and_bypasses_proxy(self):
        self.check_response(404)

    def check_response(self, status):
        import threading
        from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

        received = []

        class Handler(BaseHTTPRequestHandler):
            def do_HEAD(self):
                received.append((self.command, self.headers.get("Host")))
                self.send_response(status)
                self.end_headers()

            def log_message(self, *_args):
                pass

        server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        connection = install_ssh(self)
        self.native_env.update(
            http_proxy="http://127.0.0.1:1", HTTP_PROXY="http://127.0.0.1:1"
        )
        flow = yaml.safe_load(
            (ROOT / "workflows/workflow-cluster-router-probe.yaml").read_text()
        )
        for step in flow["jobs"][0]["steps"]:
            step.pop("target", None)
        (self.repo / "workflows/workflow-cluster-router-probe.yaml").write_text(
            yaml.safe_dump(flow, sort_keys=False)
        )
        if self.workflow_name != "cluster-router-probe":
            root = yaml.safe_load(
                (ROOT / f"workflows/workflow-{self.workflow_name}.yaml").read_text()
            )
            for job in root["jobs"]:
                for step in job["steps"]:
                    step.pop("target", None)
            (self.repo / f"workflows/workflow-{self.workflow_name}.yaml").write_text(
                yaml.safe_dump(root, sort_keys=False)
            )
        self.cli(
            "workflow",
            "run",
            self.workflow_name,
            "--input",
            json.dumps(
                {
                    "operation_id": 1,
                    "node_id": 9,
                    "kind": "route.public_verify"
                    if self.workflow_name != "cluster-router-probe"
                    else "route.create",
                    "execution_secrets": {},
                    "intent": {
                        "connection": connection,
                        "host_keys": [PIN],
                        "protocol": "http",
                        "probe": {
                            "address": "127.0.0.1",
                            "port": server.server_port,
                            "domain": "process.test",
                        },
                    },
                }
            ),
        )
        self.assertEqual(received, [("HEAD", "process.test")])


class RouteVerificationRootNative(RouteHttpProbeNative):
    workflow_name = "operation-route-verification"

    def test_failed_probe_is_a_failed_root_with_bounded_error(self):
        root = yaml.safe_load(
            (ROOT / "workflows/workflow-operation-route-verification.yaml").read_text()
        )
        for step in root["jobs"][0]["steps"]:
            step.pop("target", None)
        (self.repo / "workflows/workflow-operation-route-verification.yaml").write_text(
            yaml.safe_dump(root, sort_keys=False)
        )
        # Substitute only the host probe child. Parent dependency/result logic remains real.
        child = {
            "id": "5cbf1292-9922-444b-9225-fb2b682111f7",
            "name": "cluster-router-probe",
            "inputs": {"type": "object"},
            "jobs": [
                {
                    "name": "probe",
                    "steps": [
                        {
                            "name": "probe",
                            "task": {
                                "type": "behavior",
                                "behaviorType": "command/shell",
                                "behaviorName": "probe",
                                "methodName": "execute",
                                "inputs": {"run": "exit 1"},
                            },
                        }
                    ],
                }
            ],
        }
        (self.repo / "workflows/workflow-cluster-router-probe.yaml").write_text(
            yaml.safe_dump(child)
        )
        reply = json.loads(
            self.cli(
                "workflow",
                "run",
                self.workflow_name,
                "--input",
                json.dumps(
                    {
                        "operation_id": 7,
                        "node_id": 9,
                        "kind": "route.workload_verify",
                        "intent": {"protocol": "tls"},
                        "execution_secrets": {},
                    }
                ),
                success=False,
            ).stdout
        )
        history = json.loads(self.cli("workflow", "history", "get", reply["id"]).stdout)
        result = next(
            step for step in history["jobs"][0]["steps"] if step["name"] == "result"
        )
        self.assertEqual(reply["status"], "failed")
        self.assertEqual(
            json.loads(result["outputs"]["stdout"]),
            {"ready": False, "error_code": "route.certificate_invalid"},
        )
