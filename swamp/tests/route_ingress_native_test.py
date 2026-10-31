"""Prove official ingress ports and preserve source-specific LAN rules."""

import json
from pathlib import Path

import yaml

from tests import route_lan_command_native_test as fixture

ROOT = Path(__file__).resolve().parents[1]


class RouteIngressNative(fixture.RouteLanFixture):
    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/routes/update-command")
        fake = self.repo / "ssh-tools/ufw"
        source = fake.read_text().replace(
            "row={'port':int(args[args.index('port')+1]),'protocol':args[args.index('proto')+1],'source':args[args.index('from')+1]}",
            "port,protocol=args[-1].split('/');row={'port':int(port),'protocol':protocol,'source':'Anywhere'}",
        )
        fake.write_text(source)
        workflow = yaml.safe_load(
            (ROOT / "workflows/workflow-route-ingress-apply.yaml").read_text()
        )
        for step in workflow["jobs"][0]["steps"]:
            step.pop("target")
            step.pop("labels")
        (self.repo / "workflows/workflow-route-ingress-apply.yaml").write_text(
            yaml.safe_dump(workflow, sort_keys=False)
        )
        self.state.write_text(
            json.dumps({"backend": "active", "legacy": [], "tuples": [self.tuple]})
        )

    def ingress(self, ensure, success=True):
        value = {
            "operation_id": 7,
            "node_id": 9,
            "kind": "route.update",
            "execution_secrets": {},
            "intent": {
                "rule": {
                    "node_id": 9,
                    "rules": [{"port": p, "ensure": ensure} for p in (80, 443)],
                },
                "control_node_id": 209,
                "connection": self.connection,
                "host_keys": [fixture.PIN],
            },
        }
        self.cli("workflow", "validate", "route-ingress-apply")
        return self.cli(
            "workflow",
            "run",
            "route-ingress-apply",
            "--input",
            json.dumps(value),
            success=success,
        )

    def test_public_ports_add_and_withdraw_preserve_lan_source_rules(self):
        self.ingress("present")
        rules = json.loads(self.state.read_text())["tuples"]
        self.assertIn(self.tuple, rules)
        self.assertTrue(
            all(
                {"port": p, "protocol": "tcp", "source": "Anywhere"} in rules
                for p in (80, 443)
            )
        )
        self.ingress("absent")
        self.assertEqual(json.loads(self.state.read_text())["tuples"], [self.tuple])

    def test_zero_effect_success_cannot_prove_public_ingress(self):
        state = json.loads(self.state.read_text())
        state["ignore_apply"] = True
        self.state.write_text(json.dumps(state))
        self.ingress("present", success=False)
        self.assertEqual(json.loads(self.state.read_text())["tuples"], [self.tuple])
