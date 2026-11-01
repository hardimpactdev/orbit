"""Public DNS deltas use the same pinned official SSH target as publication."""

import json
import shutil
import sys
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from tests.native_harness import NativeTestCase
from tests.route_ssh_fixture import PIN, install_ssh

ROOT = Path(__file__).resolve().parents[1]


class RouteDnsNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.connection = install_ssh(self)
        self.copy_behaviors("operations/routes/mutation-command")
        dependency = (
            self.repo
            / "extensions/behaviors/operations/routes/publication-command/sections.ts"
        )
        dependency.parent.mkdir(parents=True)
        shutil.copy(
            ROOT
            / "extensions/behaviors/operations/routes/publication-command/sections.ts",
            dependency,
        )
        self.host = self.repo / "host"
        self.host.mkdir()
        self.records = self.host / "records"
        self.catalog = self.host / "catalog"
        self.audit = self.repo / "audit"
        tools = self.repo / "dns-tools"
        tools.mkdir()
        for name in ("dnsmasq", "systemctl"):
            path = tools / name
            path.write_text(
                "#!"
                + sys.executable
                + "\n"
                + """import json,os,sys
from pathlib import Path
with Path(os.environ['FIXTURE_AUDIT']).open('a') as out:out.write(json.dumps([Path(sys.argv[0]).name,*sys.argv[1:]])+'\\n')
if Path(sys.argv[0]).name=='dnsmasq' and os.environ.get('FIXTURE_FAIL'):raise SystemExit(1)
"""
            )
            path.chmod(0o700)
        self.native_env["FIXTURE_AUDIT"] = str(self.audit)
        workflow = yaml.safe_load(
            (ROOT / "workflows/workflow-route-dns-publish.yaml").read_text()
        )

        def patch(value):
            if isinstance(value, str):
                return (
                    value.replace(
                        "/etc/dnsmasq.d/orbit-records.conf", str(self.records)
                    )
                    .replace(
                        "/var/lib/orbit/private-dns/catalog.json", str(self.catalog)
                    )
                    .replace("/var/lib/orbit/private-dns", str(self.host))
                    .replace("/usr/sbin/dnsmasq", str(tools / "dnsmasq"))
                    .replace("/usr/bin/systemctl", str(tools / "systemctl"))
                )
            if isinstance(value, list):
                return [patch(v) for v in value]
            if isinstance(value, dict):
                return {k: patch(v) for k, v in value.items()}
            return value

        workflow = patch(workflow)
        for step in workflow["jobs"][0]["steps"]:
            self.assertEqual(step.pop("target"), "orbit-node-209")
            self.assertNotIn("labels", step)
            args = step["task"].get("inputs", {}).get("argv", [])
            if args[:1] == ["sudo"]:
                args.pop(0)
        (self.repo / "workflows/workflow-route-dns-publish.yaml").write_text(
            yaml.safe_dump(workflow, sort_keys=False)
        )
        self.before = {
            "records": "host-record=moving.test,10.44.0.9\n",
            "catalog": json.dumps({"routes": {"moving.test": "10.44.0.9"}}),
        }
        self.after = {
            "records": "host-record=moving.test,10.44.0.10\n",
            "catalog": json.dumps({"routes": {"moving.test": "10.44.0.10"}}),
        }
        self.records.write_text(
            self.before["records"] + "host-record=survivor.test,10.44.0.11\n"
        )
        self.catalog.write_text(
            json.dumps(
                {"routes": {"moving.test": "10.44.0.9", "survivor.test": "10.44.0.11"}}
            )
        )

    def run_dns(self, success=True):
        intent = {
            "node_id": 1,
            "control_node_id": 209,
            "connection": self.connection,
            "host_keys": [PIN],
            "before": self.before,
            "after": self.after,
            "alternatives": [],
        }
        return json.loads(
            self.cli(
                "workflow",
                "run",
                "route-dns-publish",
                "--input",
                json.dumps(
                    {
                        "operation_id": 7,
                        "node_id": 9,
                        "kind": "node.add",
                        "execution_secrets": {},
                        "intent": intent,
                    }
                ),
                success=success,
            ).stdout
        )

    def test_publication_preserves_survivors_and_validates_before_activation(self):
        self.assertEqual(self.run_dns()["status"], "succeeded")
        self.assertIn("host-record=moving.test,10.44.0.10", self.records.read_text())
        self.assertIn("host-record=survivor.test,10.44.0.11", self.records.read_text())
        self.assertEqual(
            json.loads(self.catalog.read_text())["routes"]["survivor.test"],
            "10.44.0.11",
        )
        self.assertEqual(
            [json.loads(row) for row in self.audit.read_text().splitlines()],
            [
                ["dnsmasq", "--test"],
                ["systemctl", "restart", "dnsmasq"],
                ["systemctl", "enable", "--now", "orbit-private-dns.socket"],
            ],
        )

    def test_validation_failure_skips_backend_and_listener_activation(self):
        self.native_env["FIXTURE_FAIL"] = "validate"
        self.run_dns(success=False)
        self.assertEqual(
            [json.loads(row) for row in self.audit.read_text().splitlines()],
            [["dnsmasq", "--test"]],
        )
