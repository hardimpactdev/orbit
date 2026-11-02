"""operation-route-create on real native Swamp: its seven chart steps, their child runs, and failure stop.

The official cfgmgmt behaviors open their own SSH connections, so these runs keep their lists empty and exercise the
SSH exec steps through a fixture ssh binary; the topology receipt (swamp/proof/route.create.json) proves the rest.
"""

import json
import sys
import unittest

import yaml
from tests.native_harness import ROOT, NativeTestCase

SSH = r"""
import json, shlex, sys
args = sys.argv[1:]
if '-M' in args or '-O' in args:
    raise SystemExit(0)
with open(LOG, 'a') as log:
    log.write(json.dumps(shlex.split(args[-1])) + '\n')
raise SystemExit(CODE)
"""
PIN = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"
LISTS = ("lan", "ingress_ports", "acl", "links", "php_files", "php_tests", "php_reloads", "leaf_indexes", "leaves", "trust",
         "caddy", "url_lines", "env_files", "probes", "ingress", "dns", "site_nodes")
WORKFLOWS = ("operation-route-create", "route-create-csr", "route-create-sign", "route-create-site", "route-create-ingress", "route-create-dns")


class RouteCreateNative(NativeTestCase):
    native_timeout = 180

    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/certificates")
        self.cli("extension", "pull", "@swamp/ssh@2026.10.23.1")
        self.log = self.repo / "calls.jsonl"
        identity = self.repo / "identity"
        identity.write_text("synthetic identity")
        identity.chmod(0o600)
        self.intent = {"route_id": 14, "domain": "shop.test", "root_certificate": "", "nodes": {
            "7": {"host": "node-7.invalid", "user": "orbit", "port": 22, "identity_file": str(identity), "host_keys": [PIN], "account": "orbit"}}}
        self.intent.update({name: [] for name in LISTS})

    def create(self, code=0, success=True):
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
        payload = {"operation_id": 71, "node_id": 7, "kind": "route.create", "execution_secrets": {}, "intent": self.intent}
        run = json.loads(self.cli("workflow", "run", "operation-route-create", "--stdin", input=json.dumps(payload), success=success).stdout)
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        self.assertNotIn("No such key", json.dumps(history))
        self.steps = {step["name"]: step for job in history["jobs"] for step in job["steps"]}
        calls = [json.loads(line) for line in self.log.read_text().splitlines()] if self.log.exists() else []
        return run, calls

    def grants(self, *paths):
        self.intent["site_nodes"] = [7]
        self.intent["acl"] = [{"node_id": 7, "argv": ["/usr/bin/setfacl", "-m", "u:caddy:--x", "--", path]} for path in paths]

    def test_a_state_only_create_touches_no_node(self):
        run, calls = self.create()
        self.cli("workflow", "validate", "operation-route-create")
        self.assertEqual((run["status"], calls), ("succeeded", []))
        self.assertEqual(set(self.steps), {"Make a CSR on the Node", "Sign the CSR", "Allow the Router on LAN", "Install cert, publish site",
                                           "Probe the site over TLS", "Open the Ingress edge", "Publish private DNS"} & set(self.steps))

    def test_grants_run_one_at_a_time_in_list_order_inside_the_site_step(self):
        paths = ["/home", "/home/orbit", "/home/orbit/apps"]
        self.grants(*paths)
        run, calls = self.create()
        self.assertEqual(run["status"], "succeeded")
        self.assertEqual(calls[0][-3:], ["getent", "passwd", "orbit"])
        grants = [call for call in calls if "/usr/bin/setfacl" in call]
        self.assertEqual([call[-1] for call in grants], paths)
        self.assertEqual(grants[0][:5], ["sudo", "-n", "--", "timeout", "120"])
        self.assertEqual(self.steps["Install cert, publish site-7"]["status"], "succeeded")

    def test_a_failed_node_command_fails_the_site_step_and_stops_later_steps(self):
        self.grants("/home")
        self.intent["php_tests"] = [{"node_id": 7, "argv": ["/usr/sbin/php-fpm8.5", "-t"]}]
        self.intent["probes"] = [{"node_id": 7, "address": "127.0.0.1", "domain": "shop.test"}]
        run, calls = self.create(code=1, success=False)
        self.assertEqual(run["status"], "failed")
        self.assertFalse(any("php-fpm8.5" in " ".join(call) for call in calls))
        self.assertEqual(self.steps["Install cert, publish site-7"]["status"], "failed")
        self.assertEqual({step["status"] for name, step in self.steps.items() if name.startswith("Probe the site over TLS")}, {"skipped"})

    def test_a_failed_probe_fails_the_run(self):
        self.intent["probes"] = [{"node_id": 7, "address": "127.0.0.1", "domain": "shop.test"}]
        run, _ = self.create(success=False)
        self.assertEqual(run["status"], "failed")
        self.assertTrue(any(name.startswith("Probe the site over TLS") and step["status"] == "failed" for name, step in self.steps.items()))


if __name__ == "__main__":
    unittest.main()
