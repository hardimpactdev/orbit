"""Exercise the real update parent; replace only the child host effects."""

import json
from pathlib import Path

import yaml

from tests import route_mutation_command_native_test as fixture

ROOT = Path(__file__).resolve().parents[1]


class RouteUpdateNative(fixture.RouteMutationFixture):
    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/routes/update-command")
        for name in (
            "operation-route-update",
            "route-mutation-forward",
            "route-mutation-restore",
        ):
            workflow = yaml.safe_load(
                (ROOT / "workflows" / ("workflow-" + name + ".yaml")).read_text()
            )
            workflow.pop("target", None)
            for job in workflow["jobs"]:
                for step in job["steps"]:
                    step.pop("target", None)
                    step.pop("labels", None)
            (self.repo / "workflows" / ("workflow-" + name + ".yaml")).write_text(
                yaml.safe_dump(workflow, sort_keys=False)
            )
        self.input["kind"] = "route.update"
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
        reply = json.loads(
            self.cli(
                "workflow",
                "run",
                "operation-route-update",
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
        self.assertEqual(result["error_code"], "route.update_failed")

    def test_failed_restoration_never_claims_the_candidate_or_old_publication(self):
        self.native_env["FIXTURE_FAIL"] = "route-dns-publish"
        for path in (self.repo / "workflows").glob("*.yaml"):
            path.write_text(path.read_text().replace("not marker.exists()", "True"))
        history, result = self.run_update(success=False)
        self.assertEqual(history["status"], "failed")
        self.assertFalse(result["ready"])
        self.assertFalse(result["restored"])
        self.assertEqual(result["selected"], "unknown")
