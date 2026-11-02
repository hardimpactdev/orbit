"""operation-analytics-disable-direct on real native Swamp: the chart's steps and failure stop.

The official cfgmgmt behaviors open their own SSH connections, so these runs keep those lists empty and exercise the
SSH exec steps through a fixture ssh binary.
"""

import json
import sys
import unittest

import yaml
from tests.native_harness import ROOT, NativeTestCase
from tests.route_create_native_test import PIN, SSH

SITE = ("acl", "links", "php_files", "php_tests", "php_reloads", "caddy", "url_lines", "env_files", "trust", "lan", "leaf_indexes", "leaves", "site_nodes")
WORKFLOWS = ("operation-analytics-disable-direct", "route-target-retire", "route-create-site", "route-create-dns", "route-certificate-remove", "route-remove-check")
STEPS = ["Remove private DNS", "Withdraw sites, certs", "Check the hosts are gone"]


class AnalyticsDisableNative(NativeTestCase):
    native_timeout = 180

    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/certificates")
        self.cli("extension", "pull", "@swamp/ssh@2026.10.23.1")
        self.log = self.repo / "calls.jsonl"
        identity = self.repo / "identity"
        identity.write_text("synthetic identity")
        identity.chmod(0o600)
        nodes = {"9": {"host": "node-9.invalid", "user": "orbit", "port": 22, "identity_file": str(identity), "host_keys": [PIN], "account": "orbit"}}
        self.intent = {"leaves": [], "leaf_indexes": [], "root_certificate": "", "nodes": nodes, "dns": [], "cleanup": [], "lan_absent": [], "retire_nodes": [],
                       "retire": {**{name: [] for name in SITE}, "root_certificate": "", "nodes": nodes},
                       "check_nodes": [], "lan_remove": [], "ingress_close": []}

    def disable(self, code=0, success=True):
        ssh = self.repo / "ssh"
        ssh.write_text("#!" + sys.executable + "\nLOG = " + repr(str(self.log)) + "\nCODE = " + str(code) + SSH)
        ssh.chmod(0o700)
        for name in WORKFLOWS:
            flow = yaml.safe_load((ROOT / f"workflows/workflow-{name}.yaml").read_text())
            for job in flow["jobs"]:
                job.pop("labels", None)
                for step in job["steps"]:
                    step.pop("labels", None)
                    if step["task"].get("behaviorType") == "@swamp/ssh":
                        step["task"]["globalArgs"].update(sshBinary=str(ssh), scpBinary=str(ssh))
            (self.repo / f"workflows/workflow-{name}.yaml").write_text(yaml.safe_dump(flow, sort_keys=False))
        payload = {"operation_id": 76, "node_id": 9, "kind": "analytics.disable", "execution_secrets": {}, "intent": self.intent}
        run = json.loads(self.cli("workflow", "run", "operation-analytics-disable-direct", "--stdin", input=json.dumps(payload), success=success).stdout)
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        self.assertNotIn("No such key", json.dumps(history))
        self.steps = {step["name"]: step for job in history["jobs"] for step in job["steps"]}
        return run

    def test_the_workflow_is_the_chart(self):
        self.assertEqual(self.disable()["status"], "succeeded")
        self.cli("workflow", "validate", "operation-analytics-disable-direct")
        flow = yaml.safe_load((ROOT / "workflows/workflow-operation-analytics-disable-direct.yaml").read_text())
        self.assertEqual([step["name"] for step in flow["jobs"][0]["steps"]], STEPS)

    def test_a_failed_withdraw_stops_the_check(self):
        self.intent["retire"]["site_nodes"] = [9]
        self.intent["retire"]["php_tests"] = [{"node_id": 9, "argv": ["/usr/sbin/php-fpm8.5", "-t"]}]
        self.intent["retire_nodes"] = [9]
        self.intent["check_nodes"] = [9]
        run = self.disable(code=1, success=False)
        self.assertEqual(run["status"], "failed")
        self.assertEqual(self.steps["Withdraw sites, certs-0"]["status"], "failed")
        self.assertEqual({step["status"] for name, step in self.steps.items() if name.startswith("Check the hosts are gone")}, {"skipped"})


if __name__ == "__main__":
    unittest.main()
