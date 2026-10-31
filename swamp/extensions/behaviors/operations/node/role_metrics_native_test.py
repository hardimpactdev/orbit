"""Native same-root Metrics fleet ordering and rollback, injected host command boundary."""
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase


class RoleMetricsNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors("automatic-deploy", "operations/_lib", "operations/node", "operations/routes",
                            "operations/certificates", "operations/fleet", "operations/integrations", "operations/php",
                            "operations/processes-schedules", "operations/tools", "node-worker")
        module = self.repo / "extensions/behaviors/operations/node/role_metrics.ts"
        module.write_text('import { fixtureHttp, fixtureLocal, fixtureRuntime } from "./role_metrics_native_fixture.ts";\n' + module.read_text().replace(
            "export const behavior = roleMetrics();", "export const behavior = roleMetrics(() => fixtureHttp, fixtureLocal, fixtureRuntime);"))
        script = '''import {roleMetricsJobs} from "./extensions/behaviors/operations/node/role_metrics_workflow.ts";
const generated=roleMetricsJobs("prepared");
const workflow={id:"a791bcc2-5894-4bbe-97f3-d42de801ed49",name:"node-role-metrics-fixture",inputs:{type:"object",properties:{operation_id:{type:"integer"},node_id:{type:"integer"},kind:{type:"string"}},required:["operation_id","node_id","kind"]},jobs:[{name:"prepared",steps:[{name:"prepared",task:{type:"assert",expr:"true",severity:"high",message:"prepared"}}]},...generated.jobs]};
console.log(JSON.stringify(workflow));'''
        response = subprocess.run([os.environ.get("DENO_BIN", "/tmp/lane0d-deno/deno"), "eval", script], cwd=ROOT,
                                  text=True, capture_output=True, check=True)
        self.source = json.loads(response.stdout)
        workflow = json.loads(response.stdout)
        for job in workflow["jobs"]:
            for step in job["steps"]:
                step.pop("target", None)
                step.pop("labels", None)
        (self.repo / "workflows/workflow-role-metrics-fixture.yaml").write_text(json.dumps(workflow))
        fleet = [{"node_id": node_id, "address": f"10.44.0.{node_id}", "agent_sha256": "a" * 64,
                  "worker": True, "selected": True, "caddy_managed": False, "fpm": False,
                  "service": {"config": None}, "pools": []} for node_id in [9, 13]]
        self.state = {"operation": {"id": 17, "node_id": 13, "kind": "node.role_add", "status": "running",
            "cancel_requested": False, "input": {"role": "database", "metrics_fleet": {
                "metrics_node_id": 9, "metrics_address": "10.44.0.9", "fleet": fleet}}}}

    def run_root(self, success=True):
        (self.repo / "role-metrics.json").write_text(json.dumps(self.state))
        self.cli("workflow", "validate", "node-role-metrics-fixture")
        self.cli("workflow", "run", "node-role-metrics-fixture", "--input", "operation_id=17",
                 "--input", "node_id=" + str(self.state["operation"]["node_id"]),
                 "--input", "kind=" + self.state["operation"]["kind"], success=success)
        log = self.repo / "role-metrics.ndjson"
        return [json.loads(line) for line in log.read_text().splitlines()] if log.exists() else []

    def test_fleet_verifies_every_member_before_one_runtime_commit(self):
        calls = self.run_root()
        phases = [call["phase"] for call in calls]
        self.assertEqual(phases, ["snapshot"] * 2 + ["apply"] * 2 + ["service"] * 2 + ["verify"] * 2 + ["credentials", "runtime"])
        for phase in ["snapshot", "apply", "service", "verify"]:
            self.assertEqual(sorted(call["node_id"] for call in calls if call["phase"] == phase), [9, 13])
        for job in self.source["jobs"]:
            for step in job["steps"]:
                if "forEach" in step:
                    self.assertEqual(step["target"], '${{ "orbit-node-" + string(self.id) }}')
                    self.assertEqual(step["labels"], {"node": "${{ string(self.id) }}"})

    def test_confirmed_service_failure_restores_members_before_commit(self):
        self.state["failure"] = "service"
        calls = self.run_root(success=False)
        self.assertNotIn("runtime", [call["phase"] for call in calls])
        self.assertEqual(sorted(call["node_id"] for call in calls if call["phase"] == "restore"), [9, 13])

    def test_bad_credentials_restore_both_members_but_uncertain_commit_does_not(self):
        self.state["failure"] = "credentials"
        calls = self.run_root()
        self.assertEqual(sorted(call["node_id"] for call in calls if call["phase"] == "restore"), [9, 13])

    def test_unknown_runtime_commit_never_restores(self):
        self.state["failure"] = "runtime_unknown"
        calls = self.run_root(success=False)
        self.assertNotIn("restore", [call["phase"] for call in calls])

    def test_workerless_full_inventory_blocks_before_snapshot_or_effect(self):
        self.state["operation"]["input"]["metrics_fleet"]["fleet"][0]["worker"] = False
        self.assertEqual(self.run_root(success=False), [])

    def test_removed_metrics_role_disables_fleet_without_restarting_its_runtime(self):
        self.state["operation"]["kind"] = "node.role_remove"
        self.state["operation"]["input"]["role"] = "metrics"
        calls = self.run_root()
        self.assertEqual([call["phase"] for call in calls], ["snapshot"] * 2 + ["apply"] * 2 + ["service"] * 2 + ["verify"] * 2)

    def test_metrics_role_initializes_once_then_verifies_without_baseline_runtime(self):
        self.state["operation"]["input"]["role"] = "metrics"
        self.state["operation"]["input"]["metrics_fleet"].update(metrics_node_id=13, metrics_address="10.44.0.13")
        calls = self.run_root()
        phases = [call["phase"] for call in calls]
        self.assertEqual(phases[-2:], ["runtime", "credentials"])
        self.assertEqual(phases.count("runtime"), 1)
        self.assertNotIn("restore", phases)

    def test_enrollment_initializes_guest_metrics_from_gateway_root(self):
        operation = self.state["operation"]
        operation.update(kind="node.add", node_id=14)
        operation["input"].pop("role")
        operation["input"].update(schema_version=1, node_id=13, executor_node_id=14,
            candidate={"user": "orbit", "wireguard_ip": "10.44.0.13"}, role_snapshots=[{
                "schema_version": 1, "node_id": 13, "assignment_id": 27, "role": "metrics",
                "agent_sha256": "a" * 64, "user": "orbit"}])
        operation["input"]["metrics_fleet"].update(metrics_node_id=13, metrics_address="10.44.0.13")
        calls = self.run_root()
        phases = [call["phase"] for call in calls]
        self.assertEqual(phases[-2:], ["runtime", "credentials"])
        self.assertEqual(phases.count("runtime"), 1)
        self.assertNotIn("restore", phases)

    def test_metrics_postcommit_bad_credentials_never_rollback_fleet(self):
        self.state["operation"]["input"]["role"] = "metrics"
        self.state["operation"]["input"]["metrics_fleet"].update(metrics_node_id=13, metrics_address="10.44.0.13")
        self.state["failure"] = "credentials"
        calls = self.run_root()
        phases = [call["phase"] for call in calls]
        self.assertEqual(phases[-2:], ["runtime", "credentials"])
        self.assertNotIn("restore", phases)

    def test_no_metrics_contribution_completes_without_effects(self):
        self.state["operation"]["input"]["metrics_fleet"] = None
        self.assertEqual(self.run_root(), [])


if __name__ == "__main__":
    unittest.main()
