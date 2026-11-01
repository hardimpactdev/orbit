"""Real lifecycle roots and canonical phases, with only host children substituted."""

import json

import yaml
from tests.route_update_native_test import ROOT, RouteTargetNative, RouteUpdateNative


class RouteCreateNative(RouteTargetNative):
    root_workflow = "operation-route-create"
    root_kind = "route.create"
    failure_code = "route.create_failed"

    def setUp(self):
        super().setUp()
        self.input["intent"]["preparation"]["upstream_firewalls"] = []
        self.input["intent"]["verifications"] = []

    def test_failed_public_verification_restores_a_completed_publication(self):
        self.input["intent"]["preparation"]["probes"] = []
        self.input["intent"]["verifications"] = [{"node_id": 9}]
        self.native_env["FIXTURE_FAIL"] = "cluster-router-probe"
        history, result = self.run_update(success=False)
        steps = {step["name"]: step for step in history["jobs"][0]["steps"]}
        self.assertEqual(steps["forward"]["status"], "succeeded")
        self.assertEqual(steps["verify-0"]["status"], "failed")
        self.assertFalse(result["ready"])
        self.assertTrue(result["restored"])

    def test_missing_process_allowance_blocks_publication(self):
        self.input["intent"]["preparation"]["upstream_firewalls"] = [{"node_id": 9}]
        source = self.repo / "workflows/workflow-cluster-router-probe.yaml"
        child = yaml.safe_load(source.read_text())
        child.update(
            id="d7eec9b6-54b9-47db-b509-6234b552fb35", name="route-upstream-firewall"
        )
        child["jobs"][0]["steps"][0]["task"]["inputs"]["env"]["FIXTURE_ACTION"] = (
            "route-upstream-firewall"
        )
        (self.repo / "workflows/workflow-route-upstream-firewall.yaml").write_text(
            yaml.safe_dump(child, sort_keys=False)
        )
        self.native_env["FIXTURE_FAIL"] = "route-upstream-firewall"
        history, result = self.run_update(success=False)
        steps = {step["name"]: step for step in history["jobs"][0]["steps"]}
        self.assertEqual(steps["prepare-upstream-0"]["status"], "failed")
        self.assertEqual(steps["forward"]["status"], "skipped")
        self.assertFalse(result["ready"])
        self.assertTrue(result["restored"])


class RouteRemoveNative(RouteUpdateNative):
    root_workflow = "operation-route-removal"
    root_kind = "route.destroy"
    failure_code = "route.withdrawal_failed"

    def setUp(self):
        super().setUp()
        self.input["intent"]["offline"] = False
        for name in ["route-mutation-withdraw", self.root_workflow]:
            path = self.repo / "workflows" / ("workflow-" + name + ".yaml")
            flow = yaml.safe_load((ROOT / "workflows" / path.name).read_text())
            flow.pop("target", None)
            for job in flow["jobs"]:
                for step in job["steps"]:
                    step.pop("target", None)
                    if step["name"] == "reachable":
                        step["task"] = {
                            "type": "behavior",
                            "behaviorType": "command/shell",
                            "behaviorName": "reachability",
                            "methodName": "execute",
                            "inputs": {
                                "run": 'test "$NODE" != "${UNREACHABLE_NODE:-}"',
                                "env": {"NODE": "${{ string(self.part.node_id) }}"},
                            },
                        }
            path.write_text(yaml.safe_dump(flow, sort_keys=False))

    def run_update(self, success=True):
        self.cli("workflow", "validate", self.root_workflow)
        reply = json.loads(
            self.cli(
                "workflow",
                "run",
                self.root_workflow,
                "--input",
                json.dumps(self.input),
                success=success,
            ).stdout
        )
        history = json.loads(self.cli("workflow", "history", "get", reply["id"]).stdout)
        # Expose the effect job to inherited ordering/failure assertions; retain both real jobs.
        history["jobs"] = sorted(
            history["jobs"], key=lambda job: job["name"] != "update"
        )
        result = next(
            step for step in history["jobs"][0]["steps"] if step["name"] == "result"
        )
        return history, json.loads(result["outputs"]["stdout"])

    def test_unchanged_update_has_no_effects_or_missing_input_reads(self):
        self.input["intent"]["effectful"] = False
        _, result = self.run_update()
        self.assertTrue(result["ready"])
        self.assertFalse(self.audit.exists())

    def test_unreachable_required_node_refuses_before_effects(self):
        self.native_env["UNREACHABLE_NODE"] = "9"
        _, result = self.run_update(success=False)
        self.assertFalse(result["ready"])
        self.assertFalse(self.audit.exists())

    def test_offline_retains_unreachable_effect_node(self):
        self.native_env["UNREACHABLE_NODE"] = "9"
        self.input["intent"]["offline"] = True
        for side in ["forward", "restore"]:
            for part in self.input["intent"]["route_mutations"][side]["publications"]:
                if part["node_id"] == 9:
                    part["before"]["dns"] = None
        _, result = self.run_update()
        self.assertTrue(result["ready"])
        self.assertEqual(result["retained_on_nodes"][0]["node_id"], 9)

    def test_dns_grace_precedes_site_and_php_retirement(self):
        _, result = self.run_update()
        self.assertTrue(result["ready"])
        actions = [
            json.loads(line)["action"] for line in self.audit.read_text().splitlines()
        ]
        self.assertLess(
            actions.index("route-dns-publish"),
            actions.index("operation-route-caddy-delta"),
        )
        self.assertLess(
            actions.index("operation-route-caddy-delta"),
            actions.index("operation-route-php-delta"),
        )
