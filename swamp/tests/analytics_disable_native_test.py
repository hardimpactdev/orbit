"""Exercise the Analytics parent against child-workflow effect fixtures."""

import hashlib
import json
import shlex
import shutil
import sys
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from tests.native_harness import NativeTestCase

ROOT = Path(__file__).resolve().parents[1]


class AnalyticsDisableNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors(
            "operations/routes/publication-command", "operations/certificates"
        )
        shutil.copy(
            ROOT / "extensions/behaviors/operations/routes/caddy-input.ts",
            self.repo / "extensions/behaviors/operations/routes/caddy-input.ts",
        )
        for path in (self.repo / "extensions/behaviors/operations/certificates").glob(
            "*.ts"
        ):
            if path.name not in (
                "script_assets.ts",
                "input.ts",
                "signing.ts",
                "role-input.ts",
            ):
                path.unlink()
        self.audit = self.repo / "audit"
        self.native_env["ANALYTICS_AUDIT"] = str(self.audit)
        self.native_env["ANALYTICS_FAIL"] = ""
        effect = self.repo / "child_effect.py"
        effect.write_text("""import json,os
from pathlib import Path
action=os.environ["ANALYTICS_ACTION"]
with Path(os.environ["ANALYTICS_AUDIT"]).open("a") as out:
    out.write(json.dumps({"action":action,"node_id":int(os.environ["ANALYTICS_TARGET"]),
        "operation_id":int(os.environ["ANALYTICS_OPERATION"]),"kind":os.environ["ANALYTICS_KIND"]})+"\\n")
if os.environ["ANALYTICS_FAIL"]==action:
    raise SystemExit(1)
print('{"ready":true}')
""")
        parent = yaml.safe_load(
            (
                ROOT / "workflows/workflow-operation-analytics-disable-direct.yaml"
            ).read_text()
        )
        for job in parent["jobs"]:
            for step in job["steps"]:
                self.assertEqual(step.pop("target"), "orbit-node-209")
                self.assertNotIn("labels", step)
        (self.repo / "workflows/workflow-analytics-disable.yaml").write_text(
            yaml.safe_dump(parent, sort_keys=False)
        )
        for index, name in enumerate(
            (
                "operation-route-caddy-delta",
                "route-dns-publish",
                "route-dns-grace",
                "route-certificate-remove",
            )
        ):
            workflow = {
                "id": f"bb5f8a7a-2222-4222-8222-{index:012d}",
                "name": name,
                "inputs": {"type": "object"},
                "jobs": [
                    {
                        "name": "effect",
                        "steps": [
                            {
                                "name": "apply",
                                "task": {
                                    "type": "behavior",
                                    "behaviorType": "command/shell",
                                    "behaviorName": "fixture-" + name,
                                    "methodName": "execute",
                                    "inputs": {
                                        "run": "python3 " + shlex.quote(str(effect)),
                                        "env": {
                                            "ANALYTICS_ACTION": name,
                                            "ANALYTICS_TARGET": "0"
                                            if name == "route-dns-grace"
                                            else (
                                                "${{ string(inputs.intent.leaf.node_id) }}"
                                                if name == "route-certificate-remove"
                                                else "${{ string(inputs.intent.node_id) }}"
                                            ),
                                            "ANALYTICS_OPERATION": '${{ "operation_id" in inputs ? string(inputs.operation_id) : "0" }}',
                                            "ANALYTICS_KIND": '${{ "kind" in inputs ? inputs.kind : "grace" }}',
                                        },
                                    },
                                },
                            }
                        ],
                    }
                ],
            }
            checks = []
            if name == "operation-route-caddy-delta":
                checks = [
                    {
                        "name": "canonical-input",
                        "task": {
                            "type": "behavior",
                            "behaviorType": "@nckrtl/route-publication-command",
                            "behaviorName": "canonical-caddy",
                            "methodName": "caddy",
                            "inputs": {
                                key: "${{ inputs.intent." + key + " }}"
                                for key in (
                                    "node_id",
                                    "user",
                                    "control_node_id",
                                    "host_keys",
                                    "connection",
                                    "before",
                                    "after",
                                    "direction",
                                )
                            },
                        },
                    }
                ]
                checks[0]["task"]["inputs"]["current"] = (
                    "${{ inputs.intent.before.content }}"
                )
            elif name == "route-certificate-remove":
                checks = [
                    {
                        "name": "canonical-input",
                        "task": {
                            "type": "behavior",
                            "behaviorType": "@nckrtl/certificate-script-assets",
                            "behaviorName": "canonical-removal",
                            "methodName": "read",
                            "inputs": {
                                "node_id": "${{ inputs.intent.leaf.node_id }}",
                                "intent": "${{ inputs.intent.leaf }}",
                            },
                        },
                    }
                ]
            if checks:
                workflow["jobs"][0]["steps"][0]["dependsOn"] = [
                    {"step": "canonical-input", "condition": {"type": "succeeded"}}
                ]
                workflow["jobs"][0]["steps"] = checks + workflow["jobs"][0]["steps"]
            (self.repo / f"workflows/workflow-{name}.yaml").write_text(
                yaml.safe_dump(workflow, sort_keys=False)
            )
        self.result = {
            "ready": True,
            "enabled": False,
            "detached": True,
            "domain": "shop.test",
            "timezone": "UTC",
        }
        header = "# Managed by Orbit: Node Caddy build\n{ admin off }\n"
        content = (
            header
            + "\n# orbit: routes analytics\nhttps://analytics.shop.test { respond 200 }\n"
        )

        def caddy(text):
            return {
                "content": text,
                "version": hashlib.sha256(text.encode()).hexdigest()[:32],
                "listen_addresses": ["10.44.0.9"],
                "unserved": text == header,
            }

        connection = {
            "control_node_id": 209,
            "host_keys": ["fixture-public-pin"],
            "connection": {
                "nodeHost": "fixture",
                "nodeUser": "orbit",
                "nodePort": 22,
                "nodeIdentityFile": "/fixture/key",
                "become": True,
            },
        }
        self.inputs = {
            "operation_id": 7,
            "node_id": 14,
            "kind": "analytics.disable",
            "execution_secrets": {},
            "intent": {
                "caddy": [
                    {
                        "node_id": node_id,
                        "user": "orbit",
                        **connection,
                        "direction": "forward",
                        "before": caddy(content),
                        "after": caddy(header),
                    }
                    for node_id in (9, 10)
                ],
                "dns": [{"node_id": 1}],
                "cleanup": [
                    {
                        "leaf": {
                            "node_id": 9,
                            "scope": "route-31-router",
                            "user": "orbit",
                            "agent_sha256": "a" * 64,
                            "in_use": False,
                        },
                        **connection,
                    }
                ],
                "result_json": json.dumps(self.result),
            },
        }

    def run_disable(self, success=True):
        reply = json.loads(
            self.cli(
                "workflow",
                "run",
                "operation-analytics-disable-direct",
                "--input",
                json.dumps(self.inputs),
                success=success,
            ).stdout
        )
        return json.loads(self.cli("workflow", "history", "get", reply["id"]).stdout)

    def actions(self):
        return [json.loads(row) for row in self.audit.read_text().splitlines()]

    def test_withdraws_every_host_before_dns_and_removes_leaves_after_grace(self):
        self.cli("workflow", "validate", "operation-analytics-disable-direct")
        run = self.run_disable()
        calls = self.actions()
        self.assertEqual(
            [row["action"] for row in calls],
            [
                "operation-route-caddy-delta",
                "operation-route-caddy-delta",
                "route-dns-publish",
                "route-dns-grace",
                "route-certificate-remove",
            ],
        )
        self.assertCountEqual([row["node_id"] for row in calls[:2]], [9, 10])
        self.assertTrue(
            all(
                row["operation_id"] == 7 and row["kind"] == "analytics.disable"
                for row in calls
                if row["action"] != "route-dns-grace"
            )
        )
        result = run["jobs"][-1]["steps"][0]["outputs"]["stdout"]
        self.assertEqual(json.loads(result), self.result)

    def test_caddy_failure_prevents_dns_and_certificate_removal(self):
        self.native_env["ANALYTICS_FAIL"] = "operation-route-caddy-delta"
        run = self.run_disable(success=False)
        self.assertTrue(
            all(
                row["action"] == "operation-route-caddy-delta" for row in self.actions()
            )
        )
        self.assertEqual(run["jobs"][-1]["status"], "skipped")

    def test_dns_failure_prevents_certificate_removal_and_detachment(self):
        self.native_env["ANALYTICS_FAIL"] = "route-dns-publish"
        run = self.run_disable(success=False)
        self.assertNotIn(
            "route-certificate-remove", [row["action"] for row in self.actions()]
        )
        self.assertEqual(run["jobs"][-1]["status"], "skipped")

    def test_empty_tracking_plan_detaches_without_host_effects_or_wait(self):
        for key in ("caddy", "dns", "cleanup"):
            self.inputs["intent"][key] = []
        run = self.run_disable()
        self.assertFalse(self.audit.exists())
        self.assertEqual(run["status"], "succeeded")
        self.assertEqual(
            json.loads(run["jobs"][-1]["steps"][0]["outputs"]["stdout"]), self.result
        )

    def test_nested_caddy_snapshot_is_refused_by_the_canonical_child(self):
        self.inputs["intent"]["caddy"][0]["before"] = {
            "caddy": self.inputs["intent"]["caddy"][0]["before"]
        }
        run = self.run_disable(success=False)
        self.assertEqual(run["jobs"][-1]["status"], "skipped")
        self.assertNotIn(
            "route-certificate-remove", [row["action"] for row in self.actions()]
        )

    def test_analytics_only_leaf_fields_are_refused_by_the_canonical_child(self):
        self.inputs["intent"]["cleanup"][0]["leaf"].update(
            route_id=31, domain="analytics.shop.test"
        )
        run = self.run_disable(success=False)
        self.assertEqual(run["jobs"][-1]["status"], "skipped")
        self.assertNotIn(
            "route-certificate-remove", [row["action"] for row in self.actions()]
        )

    def test_resume_repairs_failed_cleanup_without_republishing_hosts(self):
        self.native_env["ANALYTICS_FAIL"] = "route-certificate-remove"
        run = self.run_disable(success=False)
        before = [row["action"] for row in self.actions()]
        self.native_env["ANALYTICS_FAIL"] = ""
        self.cli(
            "workflow",
            "resume",
            "operation-analytics-disable-direct",
            "--run",
            run["id"],
        )
        after = [row["action"] for row in self.actions()]
        self.assertEqual(after, before + ["route-certificate-remove"])
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        self.assertEqual(history["status"], "succeeded")


if __name__ == "__main__":
    import unittest

    unittest.main()
