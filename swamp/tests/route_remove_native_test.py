"""operation-route-removal on real native Swamp: the chart's steps, the --offline skip, and failure stop.

The official cfgmgmt behaviors open their own SSH connections, so these runs keep those lists empty and exercise the
SSH exec steps through a fixture ssh binary; the topology receipt (swamp/proof/route.destroy.json) proves the rest.
"""

import json
import sys
import unittest

import yaml
from tests.native_harness import ROOT, NativeTestCase
from tests.route_create_native_test import PIN

SITE = ("acl", "links", "php_files", "php_tests", "php_reloads", "caddy", "url_lines", "env_files", "trust", "lan", "leaf_indexes", "leaves", "site_nodes")
WORKFLOWS = ("operation-route-removal", "route-target-retire", "route-create-site", "route-create-dns", "route-certificate-remove", "route-dns-grace",
             "route-remove-firewall", "route-remove-check")
STEPS = ["Check each Node answers", "Remove private DNS", "Wait the DNS grace", "Withdraw site, remove cert", "Converge PHP-FPM pools",
         "Remove unused LAN rules", "Check nothing is left"]
# The fixture ssh fails every command on one host, as a Node that does not answer.
SSH = r"""
import json, shlex, sys
args = sys.argv[1:]
if '-M' in args or '-O' in args:
    raise SystemExit(0)
with open(LOG, 'a') as log:
    log.write(json.dumps(shlex.split(args[-1])) + '\n')
raise SystemExit(255 if DOWN and any(DOWN in arg for arg in args) else CODE)
"""


class RouteRemoveNative(NativeTestCase):
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
        self.intent = {"offline": False, "nodes": nodes, "answer_nodes": [], "answer_index": {}, "dns": [], "grace": [], "retire": phase(), "cleanup": [],
                       "lan_absent": [], "retire_nodes": [], "pools": phase(), "lan_remove": [], "ingress_close": [], "firewall_nodes": [], "check_nodes": []}

    def remove(self, code=0, down="", success=True):
        ssh = self.repo / "ssh"
        ssh.write_text("#!" + sys.executable + "\nLOG = " + repr(str(self.log)) + "\nCODE = " + str(code) + "\nDOWN = " + repr(down) + SSH)
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
        payload = {"operation_id": 74, "node_id": 7, "kind": "route.destroy", "execution_secrets": {}, "intent": self.intent}
        run = json.loads(self.cli("workflow", "run", "operation-route-removal", "--stdin", input=json.dumps(payload), success=success).stdout)
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        self.assertNotIn("No such key", json.dumps(history))
        self.steps = {step["name"]: step for job in history["jobs"] for step in job["steps"]}
        calls = [json.loads(line) for line in self.log.read_text().splitlines()] if self.log.exists() else []
        return run, calls

    def pools(self, *nodes):
        self.intent["pools"]["site_nodes"] = list(nodes)
        self.intent["pools"]["php_tests"] = [{"node_id": node, "argv": ["/usr/sbin/php-fpm8.5", "-t"]} for node in nodes]

    def test_the_workflow_is_the_chart(self):
        run, calls = self.remove()
        self.assertEqual((run["status"], calls), ("succeeded", []))
        self.cli("workflow", "validate", "operation-route-removal")
        flow = yaml.safe_load((ROOT / "workflows/workflow-operation-route-removal.yaml").read_text())
        self.assertEqual([step["name"] for step in flow["jobs"][0]["steps"]], STEPS)

    def test_offline_skips_a_node_that_does_not_answer(self):
        self.pools(7, 8)
        self.intent.update(offline=True, answer_nodes=[7, 8], answer_index={"7": 0, "8": 1})
        run, calls = self.remove(down="node-8.invalid")
        self.assertEqual(run["status"], "succeeded")
        self.assertEqual(self.steps["Check each Node answers-1"]["status"], "failed")
        tests = [call for call in calls if "php-fpm8.5" in " ".join(call)]
        # Only the Node that answered converges its pools; the other keeps residue.
        self.assertEqual(len(tests), 1)
        self.assertEqual(self.steps["Converge PHP-FPM pools-0"]["status"], "succeeded")
        self.assertEqual(self.steps["Converge PHP-FPM pools-1"]["status"], "skipped")

    def test_without_offline_a_failed_node_command_stops_every_later_step(self):
        self.pools(7)
        self.intent["check_nodes"] = [7]
        run, _ = self.remove(code=1, success=False)
        self.assertEqual(run["status"], "failed")
        self.assertEqual(self.steps["Converge PHP-FPM pools-0"]["status"], "failed")
        self.assertEqual({step["status"] for name, step in self.steps.items() if name.startswith("Check nothing is left")}, {"skipped"})


if __name__ == "__main__":
    unittest.main()
