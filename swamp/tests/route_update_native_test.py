"""Exercise the real update parent; replace only the child host effects."""

import json
from pathlib import Path

import yaml
from tests import route_mutation_command_native_test as fixture

ROOT = Path(__file__).resolve().parents[1]


class RouteUpdateNative(fixture.RouteMutationFixture):
    root_workflow = "operation-route-update"
    root_kind = "route.update"
    failure_code = "route.update_failed"

    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/routes/update-command")
        self.copy_behaviors("operations/firewall")
        for name in (
            self.root_workflow,
            "route-mutation-forward",
            "route-mutation-restore",
        ):
            workflow = yaml.safe_load(
                (ROOT / "workflows" / ("workflow-" + name + ".yaml")).read_text()
            )
            workflow.pop("target", None); workflow.pop("labels", None)
            for job in workflow["jobs"]:
                for step in job["steps"]:
                    step.pop("target", None); step.pop("labels", None)
                    step.pop("labels", None)
            (self.repo / "workflows" / ("workflow-" + name + ".yaml")).write_text(
                yaml.safe_dump(workflow, sort_keys=False)
            )
        self.input["kind"] = self.root_kind
        self.input["intent"].update(
            effectful=True, route_id=31, result_route_id=32, workloads=[]
        )
        self.plan["lan"] = []
        self.plan["url_writes"] = []

    def workload(self, fail_phase=""):
        self.native_env["FIXTURE_FAIL_PHASE"] = fail_phase
        self.input["intent"]["workloads"] = [
            {
                "node_id": 9,
                "instance_id": 5,
                "connection": {},
                "host_keys": [],
                **{
                    phase: {"phase": phase}
                    for phase in ("prepare", "selected", "restore")
                },
            }
        ]
        workflow = {
            "id": "e0784b8d-cf13-4d2d-a5b5-bc0d0046b9d3",
            "name": "instance-route-workload",
            "inputs": {"type": "object"},
            "jobs": [
                {
                    "name": "fixture",
                    "steps": [
                        {
                            "name": "apply",
                            "task": {
                                "type": "behavior",
                                "behaviorType": "command/shell",
                                "behaviorName": "fixture-workload",
                                "methodName": "execute",
                                "inputs": {
                                    "run": "python3 - <<'PYTHON'\nimport os\nif os.environ.get('FIXTURE_FAIL_PHASE') == os.environ['PHASE']: raise SystemExit(1)\nprint('{\"ready\":true}')\nPYTHON",
                                    "env": {"PHASE": "${{ inputs.intent.plan.phase }}"},
                                },
                            },
                        }
                    ],
                }
            ],
        }
        (self.repo / "workflows/workflow-instance-route-workload.yaml").write_text(
            yaml.safe_dump(workflow, sort_keys=False)
        )

    def test_failed_workload_preparation_blocks_forward_and_restores_the_prior_plan(
        self,
    ):
        self.workload("prepare")
        history, result = self.run_update(success=False)
        steps = {s["name"]: s for s in history["jobs"][0]["steps"]}
        self.assertEqual(steps["forward"]["status"], "skipped")
        self.assertEqual(steps["restore-workload-0"]["status"], "succeeded")
        self.assertTrue(result["restored"])
        self.assertFalse(result["ready"])

    def test_retirement_failure_restores_a_completed_forward(self):
        self.workload("selected")
        history, result = self.run_update(success=False)
        steps = {s["name"]: s for s in history["jobs"][0]["steps"]}
        self.assertTrue(steps["forward"]["outputs"]["result"]["ready"])
        self.assertEqual(steps["retire-0"]["status"], "failed")
        self.assertTrue(result["restored"])
        self.assertFalse(result["ready"])

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
        result = next(
            step for step in history["jobs"][0]["steps"] if step["name"] == "result"
        )
        return history, json.loads(result["outputs"]["stdout"])

    def test_empty_optional_fanouts_still_publish_and_project(self):
        history, result = self.run_update()
        self.assertEqual(history["status"], "succeeded")
        self.assertTrue(result["ready"])
        self.assertEqual(result["selected"], "after")
        self.assertFalse(result["restored"])
        actions = [
            json.loads(line)["action"] for line in self.audit.read_text().splitlines()
        ]
        self.assertIn("route-dns-publish", actions)
        self.assertIn("operation-route-caddy-delta", actions)

    def test_unchanged_update_has_no_effects_or_missing_input_reads(self):
        self.input["intent"] = {
            "effectful": False,
            "route_id": 31,
            "result_route_id": 31,
            "workloads": [],
        }
        _, result = self.run_update()
        self.assertTrue(result["ready"])
        self.assertFalse(self.audit.exists())

    def test_failed_forward_restores_before_and_keeps_native_failure(self):
        self.native_env["FIXTURE_FAIL"] = "route-dns-publish"
        history, result = self.run_update(success=False)
        self.assertEqual(history["status"], "failed")
        self.assertFalse(result["ready"])
        self.assertTrue(result["restored"])
        self.assertEqual(result["selected"], "before")
        self.assertEqual(result["error_code"], self.failure_code)

    def test_failed_restoration_never_claims_the_candidate_or_old_publication(self):
        self.native_env["FIXTURE_FAIL"] = "route-dns-publish"
        for path in (self.repo / "workflows").glob("*.yaml"):
            path.write_text(path.read_text().replace("not marker.exists()", "True"))
        history, result = self.run_update(success=False)
        self.assertEqual(history["status"], "failed")
        self.assertFalse(result["ready"])
        self.assertFalse(result["restored"])
        self.assertEqual(result["selected"], "unknown")


class RouteTargetNative(RouteUpdateNative):
    root_workflow = "operation-route-target-set"
    root_kind = "route.target_set"
    failure_code = "route.target_set_failed"

    def setUp(self):
        super().setUp()
        self.input["intent"]["result_route_id"] = self.input["intent"]["route_id"]
        self.input["intent"]["preparation"] = {
            "certificates": self.plan["prepare_certificates"],
            "lan": [{"node_id": 9}],
            "publications": [
                {**row, "before": row["before"]["caddy"], "after": row["prepared"]["caddy"], "direction": "forward", "alternatives": [0]}
                for row in self.plan["publications"]
            ],
            "probes": [{"node_id": 9, "probe": {"address": "10.0.0.10", "port": 443, "domain": "app.test"}}],
        }
        self.plan["prepare_certificates"] = []
        probe = yaml.safe_load((self.repo / "workflows/workflow-route-dns-publish.yaml").read_text())
        probe.update(id="cdf8ebbe-89c2-4935-a41f-4ebcfb80321b", name="cluster-router-probe")
        probe["jobs"][0]["steps"][0]["task"]["inputs"]["env"]["FIXTURE_ACTION"] = "cluster-router-probe"
        (self.repo / "workflows/workflow-cluster-router-probe.yaml").write_text(yaml.safe_dump(probe, sort_keys=False))

    def test_workload_tls_is_proved_before_the_router_can_select_new_targets(self):
        history, result = self.run_update()
        steps = {step["name"]: step for step in history["jobs"][0]["steps"]}
        self.assertTrue(result["ready"])
        self.assertLessEqual(steps["prepare-caddy-1"]["completedAt"], steps["prepare-probe-0"]["startedAt"])
        self.assertLessEqual(steps["prepare-probe-0"]["completedAt"], steps["forward"]["startedAt"])

    def test_a_failed_tls_probe_blocks_forward_and_restores_the_admitted_before(self):
        self.native_env["FIXTURE_FAIL"] = "cluster-router-probe"
        history, result = self.run_update(success=False)
        steps = {step["name"]: step for step in history["jobs"][0]["steps"]}
        self.assertEqual(steps["forward"]["status"], "skipped")
        self.assertTrue(result["restored"])
        self.assertFalse(result["ready"])
