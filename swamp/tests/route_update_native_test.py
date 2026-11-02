"""operation-route-update on real native Swamp: the chart's steps, their child runs, and failure stop.

The official cfgmgmt behaviors open their own SSH connections, so these runs keep those lists empty and exercise the
SSH exec steps through a fixture ssh binary; the topology receipt (swamp/proof/route.update.json) proves the rest.
"""

import json
import sys
import unittest

import yaml
from tests.native_harness import ROOT, NativeTestCase
from tests.route_create_native_test import PIN, SSH

SITE = ("acl", "links", "php_files", "php_tests", "php_reloads", "caddy", "url_lines", "env_files", "trust", "lan", "leaf_indexes", "leaves", "site_nodes")
WORKFLOWS = ("operation-route-update", "route-target-retire", "route-create-csr", "route-create-sign", "route-create-site", "route-create-ingress",
             "route-create-dns", "route-certificate-remove", "route-dns-grace")
STEPS = ["Make a CSR on the Node", "Sign the CSR", "Install cert, publish site", "Write the new APP_URL", "Probe the site over TLS",
         "Open or close the edge", "Move private DNS", "Wait the DNS grace", "Withdraw the old site"]


class RouteUpdateNative(NativeTestCase):
    native_timeout = 180

    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/certificates")
        self.cli("extension", "pull", "@swamp/ssh@2026.10.23.1")
        self.log = self.repo / "calls.jsonl"
        identity = self.repo / "identity"
        identity.write_text("synthetic identity")
        identity.chmod(0o600)
        nodes = {str(n): {"host": f"node-{n}.invalid", "user": "orbit", "port": 22, "identity_file": str(identity), "host_keys": [PIN], "account": "orbit"}
                 for n in (7, 8)}
        phase = lambda: {**{name: [] for name in SITE}, "root_certificate": "", "nodes": nodes}
        self.intent = {"leaves": [], "leaf_indexes": [], "probes": [], "dns": [], "grace": [], "ingress": [], "ingress_ports": [], "edge_nodes": [],
                       "cleanup": [], "lan_absent": [], "retire_nodes": [], "nodes": nodes, "publish": phase(), "app_url": phase(), "retire": phase()}

    def update(self, code=0, success=True):
        ssh = self.repo / "ssh"
        ssh.write_text("#!" + sys.executable + "\nLOG = " + repr(str(self.log)) + "\nCODE = " + str(code) + SSH)
        ssh.chmod(0o700)
        for name in WORKFLOWS:
            workflow = yaml.safe_load((ROOT / f"workflows/workflow-{name}.yaml").read_text())
            for job in workflow["jobs"]:
                job.pop("labels", None)
                for step in job["steps"]:
                    step.pop("labels", None)
                    if step["task"].get("behaviorType") == "@swamp/ssh":
                        step["task"]["globalArgs"].update(sshBinary=str(ssh), scpBinary=str(ssh))
            (self.repo / f"workflows/workflow-{name}.yaml").write_text(yaml.safe_dump(workflow, sort_keys=False))
        payload = {"operation_id": 73, "node_id": 7, "kind": "route.update", "execution_secrets": {}, "intent": self.intent}
        run = json.loads(self.cli("workflow", "run", "operation-route-update", "--stdin", input=json.dumps(payload), success=success).stdout)
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        self.assertNotIn("No such key", json.dumps(history))
        self.steps = {step["name"]: step for job in history["jobs"] for step in job["steps"]}
        calls = [json.loads(line) for line in self.log.read_text().splitlines()] if self.log.exists() else []
        return run, calls

    def pools(self, phase, node):
        self.intent[phase]["site_nodes"] = [node]
        self.intent[phase]["php_tests"] = [{"node_id": node, "argv": ["/usr/sbin/php-fpm8.5", "-t"]}]

    def test_the_workflow_is_the_chart(self):
        run, calls = self.update()
        self.assertEqual((run["status"], calls), ("succeeded", []))
        self.cli("workflow", "validate", "operation-route-update")
        flow = yaml.safe_load((ROOT / "workflows/workflow-operation-route-update.yaml").read_text())
        self.assertEqual([step["name"] for step in flow["jobs"][0]["steps"]], STEPS)

    def test_the_old_site_leaves_only_after_the_probe_and_the_dns_grace(self):
        self.pools("publish", 7)
        self.pools("retire", 7)
        self.intent["retire_nodes"] = [7]
        self.intent["grace"] = [{"seconds": 1}]
        run, calls = self.update()
        self.assertEqual(run["status"], "succeeded")
        self.assertEqual(len([call for call in calls if "php-fpm8.5" in " ".join(call)]), 2)
        self.assertLessEqual(self.steps["Install cert, publish site-0"]["completedAt"], self.steps["Wait the DNS grace-0"]["startedAt"])
        self.assertLessEqual(self.steps["Wait the DNS grace-0"]["completedAt"], self.steps["Withdraw the old site-0"]["startedAt"])

    def test_a_failed_probe_keeps_the_old_site(self):
        self.pools("retire", 7)
        self.intent["retire_nodes"] = [7]
        self.intent["probes"] = [{"node_id": 7, "address": "127.0.0.1", "domain": "shop.test"}]
        run, calls = self.update(success=False)
        self.assertEqual(run["status"], "failed")
        self.assertEqual(self.steps["Probe the site over TLS-0"]["status"], "failed")
        self.assertEqual(calls, [])
        self.assertEqual({step["status"] for name, step in self.steps.items() if name.startswith("Withdraw the old site")}, {"skipped"})

    def test_a_failed_publish_command_stops_every_later_step(self):
        self.pools("publish", 7)
        self.pools("app_url", 8)
        run, _ = self.update(code=1, success=False)
        self.assertEqual(run["status"], "failed")
        self.assertEqual(self.steps["Install cert, publish site-0"]["status"], "failed")
        self.assertEqual({step["status"] for name, step in self.steps.items() if name.startswith("Write the new APP_URL")}, {"skipped"})


if __name__ == "__main__":
    unittest.main()
