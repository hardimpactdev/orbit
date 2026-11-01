"""The no-effect Route target-unset contract executes in the pinned native runtime."""

import json

import yaml
from tests.native_harness import ROOT, NativeTestCase


class RouteUnsetNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        flow = yaml.safe_load(
            (ROOT / "workflows/workflow-operation-route-target-unset.yaml").read_text()
        )
        for step in flow["jobs"][0]["steps"]:
            step.pop("target", None)
        (self.repo / "workflows/workflow-operation-route-target-unset.yaml").write_text(
            yaml.safe_dump(flow, sort_keys=False)
        )
        self.value = {
            "operation_id": 7,
            "node_id": 9,
            "kind": "route.target_unset",
            "intent": {"route_id": 3, "target_ids": []},
            "execution_secrets": {},
        }

    def test_confirms_only_the_admitted_empty_target_set(self):
        reply = json.loads(
            self.cli(
                "workflow",
                "run",
                "operation-route-target-unset",
                "--input",
                json.dumps(self.value),
            ).stdout
        )
        history = json.loads(self.cli("workflow", "history", "get", reply["id"]).stdout)
        result = history["jobs"][0]["steps"][0]
        self.assertEqual(reply["status"], "succeeded")
        self.assertEqual(json.loads(result["outputs"]["stdout"]), {"ready": True})

    def test_refuses_nonempty_targets_before_the_confirmation_runs(self):
        self.value["intent"]["target_ids"] = [4]
        self.cli(
            "workflow",
            "run",
            "operation-route-target-unset",
            "--input",
            json.dumps(self.value),
            success=False,
        )
