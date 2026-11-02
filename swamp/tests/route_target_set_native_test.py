"""operation-route-target-set on real native Swamp: the chart's steps, their child runs, and failure stop.

The official cfgmgmt behaviors open their own SSH connections, so these runs keep those lists empty and exercise the
SSH exec steps through a fixture ssh binary; the topology receipt (swamp/proof/route.target_set.json) proves the rest.
"""

import json
import sys
import unittest

import yaml
from tests.native_harness import ROOT, NativeTestCase
from tests.route_create_native_test import PIN, SSH

SITE = ("acl", "links", "php_files", "php_tests", "php_reloads", "caddy", "url_lines", "env_files", "trust", "lan", "leaf_indexes", "leaves", "site_nodes")
WORKFLOWS = ("operation-route-target-set", "route-target-stage", "route-target-retire", "route-create-csr", "route-create-sign",
             "route-create-site", "route-create-dns", "route-certificate-remove")
STEPS = ["Make CSRs on new Nodes", "Sign the CSRs", "Install cert, publish site", "Stage the new targets", "Probe each new target",
         "Switch the Router pool", "Retire detached targets", "Move private DNS"]


class RouteTargetSetNative(NativeTestCase):
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
                 for n in (7, 8, 9)}
        phase = lambda: {**{name: [] for name in SITE}, "root_certificate": "", "nodes": nodes}
        self.intent = {"leaves": [], "leaf_indexes": [], "probes": [], "dns": [], "cleanup": [], "lan_absent": [], "retire_nodes": [], "nodes": nodes,
                       "publish": phase(), "stage": phase(), "router": phase(), "retire": phase()}

    def run_set(self, code=0, success=True):
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
        payload = {"operation_id": 72, "node_id": 7, "kind": "route.target_set", "execution_secrets": {}, "intent": self.intent}
        run = json.loads(self.cli("workflow", "run", "operation-route-target-set", "--stdin", input=json.dumps(payload), success=success).stdout)
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        self.assertNotIn("No such key", json.dumps(history))
        self.steps = {step["name"]: step for job in history["jobs"] for step in job["steps"]}
        calls = [json.loads(line) for line in self.log.read_text().splitlines()] if self.log.exists() else []
        return run, calls

    def pools(self, phase, node):
        self.intent[phase]["site_nodes"] = [node]
        self.intent[phase]["php_tests"] = [{"node_id": node, "argv": ["/usr/sbin/php-fpm8.5", "-t"]}]

    def test_the_workflow_is_the_chart(self):
        run, _ = self.run_set()
        self.assertEqual(run["status"], "succeeded")
        self.cli("workflow", "validate", "operation-route-target-set")
        flow = yaml.safe_load((ROOT / "workflows/workflow-operation-route-target-set.yaml").read_text())
        self.assertEqual([step["name"] for step in flow["jobs"][0]["steps"]], STEPS)

    def test_a_development_target_runs_only_the_publish_step(self):
        self.pools("publish", 8)
        run, calls = self.run_set()
        self.assertEqual(run["status"], "succeeded")
        self.assertEqual(calls[0][-3:], ["getent", "passwd", "orbit"])
        self.assertTrue(any("php-fpm8.5" in " ".join(call) for call in calls))
        # The fixed end check of a development target: its first publish iteration.
        self.assertEqual(self.steps["Install cert, publish site-0"]["status"], "succeeded")
        self.assertFalse(any(name.startswith(("Stage the new targets-", "Switch the Router pool-")) for name in self.steps))

    def test_a_production_set_switches_the_router_only_after_staging_and_probing(self):
        self.pools("stage", 8)
        self.pools("router", 9)
        self.pools("retire", 7)
        self.intent["retire_nodes"] = [7]
        run, calls = self.run_set()
        self.assertEqual(run["status"], "succeeded")
        hosts = [call for call in calls if "php-fpm8.5" in " ".join(call)]
        self.assertEqual(len(hosts), 3)
        self.assertLessEqual(self.steps["Stage the new targets-0"]["completedAt"], self.steps["Switch the Router pool-0"]["startedAt"])
        self.assertLessEqual(self.steps["Switch the Router pool-0"]["completedAt"], self.steps["Retire detached targets-0"]["startedAt"])
        self.assertEqual(self.steps["Switch the Router pool-0"]["status"], "succeeded")

    def test_a_failed_probe_keeps_the_router_pool(self):
        self.pools("stage", 8)
        self.pools("router", 9)
        self.intent["probes"] = [{"node_id": 8, "address": "127.0.0.1", "domain": "shop.test"}]
        run, _ = self.run_set(success=False)
        self.assertEqual(run["status"], "failed")
        self.assertEqual(self.steps["Probe each new target-0"]["status"], "failed")
        self.assertEqual({step["status"] for name, step in self.steps.items() if name.startswith("Switch the Router pool")}, {"skipped"})

    def test_a_failed_stage_command_stops_every_later_step(self):
        self.pools("stage", 8)
        self.pools("router", 9)
        run, _ = self.run_set(code=1, success=False)
        self.assertEqual(run["status"], "failed")
        self.assertEqual(self.steps["Stage the new targets-0"]["status"], "failed")
        self.assertEqual({step["status"] for name, step in self.steps.items() if name.startswith("Switch the Router pool")}, {"skipped"})


if __name__ == "__main__":
    unittest.main()
