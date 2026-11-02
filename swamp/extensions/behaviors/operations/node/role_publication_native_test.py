"""Actual native same-root participant fanout; host command boundary injected."""
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase


class RolePublicationNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors("automatic-deploy", "operations/_lib", "operations/node", "operations/routes",
                            "operations/certificates", "operations/fleet", "operations/integrations",
                            "operations/processes-schedules", "operations/tools")
        module = self.repo / "extensions/behaviors/operations/node/role_publication.ts"
        module.write_text('import { fixtureHttp } from "./role_publication_native_fixture.ts";\n' + module.read_text().replace(
            "export const behavior = rolePublication();", "export const behavior = rolePublication(() => fixtureHttp);"))
        module = self.repo / "extensions/behaviors/operations/routes/publication.ts"
        module.write_text('import { fixturePlacedPublication } from "../node/role_publication_native_fixture.ts";\n' + module.read_text().replace(
            "export const behavior = publication();", "export const behavior = fixturePlacedPublication();"))
        self.workflow = self.repo / "workflows/workflow-node-role-publication-fixture.yaml"
        script = '''import {rolePublicationJobs} from "./extensions/behaviors/operations/node/role_publication_workflow.ts";
const generated=rolePublicationJobs("prepared");
const workflow={id:"87abdf58-fc05-4258-a5fb-b5b2c230ae3a",name:"node-role-publication-fixture",inputs:{type:"object",properties:{operation_id:{type:"integer"},node_id:{type:"integer"},kind:{type:"string"}},required:["operation_id","node_id","kind"]},jobs:[{name:"prepared",steps:[{name:"prepared",task:{type:"assert",expr:"true",severity:"high",message:"fixture preparation"}}]},...generated.jobs]};
console.log(JSON.stringify(workflow));'''
        response = subprocess.run([os.environ.get("DENO_BIN", "/tmp/lane0d-deno/deno"), "eval", script],
                                  cwd=ROOT, text=True, capture_output=True, check=True)
        self.source = response.stdout
        # This local harness has no fleet workers; source assertions retain exact placement.
        workflow = json.loads(self.source)
        for job in workflow["jobs"]:
            for step in job["steps"]:
                step.pop("target", None); step.pop("labels", None)
                step.pop("labels", None)
        self.workflow.write_text(json.dumps(workflow))
        self.state = {"operation": {"id": 17, "node_id": 13, "kind": "node.role_add", "status": "running",
            "cancel_requested": False, "input": {"publication": {"caddy_node_ids": [13, 14], "php_node_ids": [13], "dns_node_ids": [1, 18]}}}}

    def run_root(self, success=True):
        (self.repo / "role-publication.json").write_text(json.dumps(self.state))
        self.cli("workflow", "validate", "node-role-publication-fixture")
        self.cli("workflow", "run", "node-role-publication-fixture", "--input", "operation_id=17",
                 "--input", "node_id=13", "--input", "kind=node.role_add", success=success)
        return [json.loads(line) for line in (self.repo / "role-publication.ndjson").read_text().splitlines()]

    def test_native_fanout_publishes_every_participant_once_in_stage_order(self):
        calls = self.run_root()
        effects = [call for call in calls if call["method"] != "GET"]
        self.assertEqual([call["method"] for call in effects],
                         ["publishPhp", "publishCaddy", "publishCaddy", "publishFirewall", "publishFirewall", "publishDns", "publishDns"])
        for method, nodes in [("publishPhp", [13]), ("publishCaddy", [13, 14]), ("publishFirewall", [13, 14]), ("publishDns", [1, 18])]:
            self.assertEqual(sorted(call["node_id"] for call in effects if call["method"] == method), nodes)
        self.assertTrue(all(call["operation_id"] == 17 for call in effects))
        self.assertEqual(sum(call["method"] == "GET" for call in calls), 3)
        for job in json.loads(self.source)["jobs"]:
            for step in job["steps"]:
                if "forEach" in step:
                    self.assertEqual(step["labels"], {'node': '${{ string(self.participant) }}'})
                    self.assertEqual(step["labels"], {"node": "${{ string(self.participant) }}"})

    def test_empty_publication_plan_still_completes_without_effects(self):
        self.state["operation"]["input"]["publication"] = {"caddy_node_ids": [], "php_node_ids": [], "dns_node_ids": []}
        calls = self.run_root()
        self.assertEqual([call["method"] for call in calls], ["GET", "GET", "GET"])

    def test_relocation_target_publication_excludes_source_in_actual_native_fanout(self):
        workflow = json.loads(self.workflow.read_text())
        for job in workflow["jobs"]:
            for step in job["steps"]:
                if "forEach" in step:
                    source = step["forEach"]["in"]
                    step["forEach"]["in"] = source.replace(" }}", ".filter(node, node != 13) }}")
        self.workflow.write_text(json.dumps(workflow))
        calls = self.run_root()
        effects = [call for call in calls if call["method"] != "GET"]
        self.assertEqual([call["node_id"] for call in effects if call["method"] == "publishCaddy"], [14])
        self.assertNotIn(13, [call["node_id"] for call in effects])
        self.assertEqual(sorted(call["node_id"] for call in effects if call["method"] == "publishDns"), [1, 18])

    def test_failed_participant_blocks_dns_and_completion(self):
        self.state["fail"] = "publishCaddy"
        calls = self.run_root(success=False)
        self.assertIn("publishCaddy", [call["method"] for call in calls])
        self.assertNotIn("publishDns", [call["method"] for call in calls])
        self.assertNotIn("publishFirewall", [call["method"] for call in calls])
        self.assertEqual(sum(call["method"] == "GET" for call in calls), 1)


if __name__ == "__main__":
    unittest.main()
