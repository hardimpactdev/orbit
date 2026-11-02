"""operation-cluster-router and operation-cluster-router-unset on real native Swamp: the charts' steps, the restore
that runs only when preparing or publishing failed, and the unset steps.

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
WORKFLOWS = ("operation-cluster-router", "operation-cluster-router-unset", "cluster-router-select", "cluster-router-revert", "route-target-retire",
             "route-create-csr", "route-create-sign", "route-create-site", "route-create-dns", "route-certificate-remove", "route-dns-grace",
             "route-remove-firewall", "route-remove-check")
SET = ["Prepare the Router", "Request the certificates", "Sign the CSRs", "Open LAN paths", "Install certs, publish", "Probe the workloads",
       "Select the Router in DNS", "Retire the old Router", "Restore the before state"]
UNSET = ["Withdraw the Router sites", "Remove the LAN rules", "Check the rules are gone"]


class ClusterRouterNative(NativeTestCase):
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
                 for n in (5, 7, 8)}
        phase = lambda: {**{name: [] for name in SITE}, "root_certificate": "", "nodes": nodes}
        self.intent = {"leaves": [], "leaf_indexes": [], "root_certificate": "", "nodes": nodes, "candidate": [], "lan_present": [], "publish": phase(),
                       "probes": [], "dns": [], "grace": [], "select": [], "retire": phase(), "cleanup": [], "lan_absent": [], "retire_nodes": [],
                       "restore": phase(), "restore_lan": [], "restore_dns_plan": {"dns": [], "nodes": nodes},
                       "lan_remove": [], "ingress_close": [], "firewall_nodes": [], "check_nodes": []}

    def run_kind(self, workflow, kind, code=0, success=True):
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
        payload = {"operation_id": 75, "node_id": 7, "kind": kind, "execution_secrets": {}, "intent": self.intent}
        run = json.loads(self.cli("workflow", "run", workflow, "--stdin", input=json.dumps(payload), success=success).stdout)
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        self.assertNotIn("No such key", json.dumps(history))
        self.steps = {step["name"]: step for job in history["jobs"] for step in job["steps"]}
        calls = [json.loads(line) for line in self.log.read_text().splitlines()] if self.log.exists() else []
        return run, calls

    def pools(self, phase, node):
        self.intent[phase]["site_nodes"] = [node]
        self.intent[phase]["php_tests"] = [{"node_id": node, "argv": ["/usr/sbin/php-fpm8.5", "-t"]}]

    def test_the_workflows_are_the_charts(self):
        for workflow, kind, names in (("operation-cluster-router", "cluster.router_set", SET), ("operation-cluster-router-unset", "cluster.router_unset", UNSET)):
            run, calls = self.run_kind(workflow, kind)
            self.assertEqual((run["status"], calls), ("succeeded", []))
            self.cli("workflow", "validate", workflow)
            flow = yaml.safe_load((ROOT / f"workflows/workflow-{workflow}.yaml").read_text())
            self.assertEqual([step["name"] for step in flow["jobs"][0]["steps"]], names)

    def test_the_restore_runs_only_when_publishing_failed(self):
        self.pools("publish", 7)
        self.pools("restore", 7)
        run, _ = self.run_kind("operation-cluster-router", "cluster.router_set", code=1, success=False)
        self.assertEqual(run["status"], "failed")
        self.assertEqual(self.steps["Install certs, publish-0"]["status"], "failed")
        self.assertEqual(self.steps["Restore the before state"]["status"], "failed")

    def test_a_passing_change_restores_nothing(self):
        self.pools("publish", 7)
        self.pools("retire", 5)
        self.intent["retire_nodes"] = [5]
        run, _ = self.run_kind("operation-cluster-router", "cluster.router_set")
        self.assertEqual(run["status"], "succeeded")
        self.assertEqual(self.steps["Retire the old Router-0"]["status"], "succeeded")
        self.assertEqual(self.steps["Restore the before state"]["status"], "skipped")

    def test_a_failed_retire_never_restores_the_old_router(self):
        self.pools("retire", 5)
        self.intent["retire_nodes"] = [5]
        run, _ = self.run_kind("operation-cluster-router", "cluster.router_set", code=1, success=False)
        self.assertEqual(self.steps["Retire the old Router-0"]["status"], "failed")
        self.assertEqual(self.steps["Restore the before state"]["status"], "skipped")


if __name__ == "__main__":
    unittest.main()
