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
        fake = str(self.repo / "ssh-tools/ufw")
        script = (
            self.repo
            / "extensions/behaviors/operations/routes/mutation-command/scripts/firewall_snapshot.py"
        )
        script.write_text(script.read_text().replace('"/usr/sbin/ufw"', repr(fake)))
        flow = yaml.safe_load(
            (ROOT / "workflows/workflow-route-upstream-firewall.yaml").read_text()
        )
        for step in flow["jobs"][0]["steps"]:
            step.pop("target", None)
            text = json.dumps(step)
            step.clear()
            step.update(
                json.loads(
                    text.replace("/tmp/orbit-route-", str(self.repo / "remote-"))
                )
            )
        (self.repo / "workflows/workflow-route-upstream-firewall.yaml").write_text(
            yaml.safe_dump(flow, sort_keys=False)
        )
        self.value = {
            "operation_id": 1,
            "node_id": 9,
            "kind": "route.create",
            "execution_secrets": {},
            "intent": {
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
        self.cli(
            "workflow",
            "run",
            "cluster-router-probe",
            "--input",
            json.dumps(
                {
                    "operation_id": 1,
                    "node_id": 9,
                    "kind": "route.create",
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
