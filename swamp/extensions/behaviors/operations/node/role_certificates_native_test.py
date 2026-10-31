"""Actual same-root certificate jobs; only canonical local effect and vault boundaries injected."""
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase


class RoleCertificatesNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors("automatic-deploy", "node-worker", "operations/_lib", "operations/node",
                            "operations/routes", "operations/certificates", "operations/fleet",
                            "operations/integrations", "operations/processes-schedules", "operations/tools")
        module = self.repo / "extensions/behaviors/operations/node/role_certificates.ts"
        module.write_text('import {fixtureCertificateHttp} from "./role_certificates_native_fixture.ts";\n' +
                          module.read_text().replace("export const behavior = roleCertificates();",
                              "export const behavior = roleCertificates(() => fixtureCertificateHttp);"))
        module = self.repo / "extensions/behaviors/operations/certificates/mod.ts"
        module.write_text('import {fixtureSignBatch,fixtureRoleActions} from "../node/role_certificates_native_fixture.ts";\n' +
                          module.read_text().replace('export const behavior = { ...certificates(), type: "@nckrtl/certificates" };',
                              'export const behavior = certificates(undefined,fixtureSignBatch,fixtureRoleActions());'))
        script = '''import {roleCertificateJobs} from "./extensions/behaviors/operations/node/role_certificates_workflow.ts";
const generated=roleCertificateJobs("prepared");
console.log(JSON.stringify({id:"c466ef02-dae7-4309-bf18-a38c11a6777c",name:"role-certificate-fixture",inputs:{type:"object",properties:{operation_id:{type:"integer"},node_id:{type:"integer"},kind:{type:"string"}},required:["operation_id","node_id","kind"]},jobs:[{name:"prepared",steps:[{name:"prepared",task:{type:"assert",expr:"true",severity:"high",message:"prepared"}}]},...generated.jobs]}));'''
        result = subprocess.run([os.environ.get("DENO_BIN", "/tmp/lane0d-deno/deno"), "eval", script],
                                cwd=ROOT, text=True, capture_output=True, check=True)
        self.source = json.loads(result.stdout)
        workflow = json.loads(result.stdout)
        for job in workflow["jobs"]:
            for step in job["steps"]:
                step.pop("target", None)
                step.pop("labels", None)
                if step.get("task", {}).get("methodName") == "signBatch":
                    step["task"]["globalArgs"] = {"vaultName": "disposable-fixture", "secretKey": "ca"}
        (self.repo / "workflows/workflow-role-certificate-fixture.yaml").write_text(json.dumps(workflow))
        self.state = {"operation": {"id": 17, "node_id": 13, "kind": "node.role_add", "status": "running",
            "cancel_requested": False, "input": {"certificates": {"leaves": [
                {"node_id": 13, "scope": "role-metrics", "domain": "metrics.orbit", "version": "0123456789abcdef", "root_hash": "a"*64},
                {"node_id": 14, "scope": "role-analytics", "domain": "analytics.orbit", "version": "fedcba9876543210", "root_hash": "a"*64}], "withdrawals": []}}}}

    def run_root(self, success=True):
        (self.repo / "role-certificates.json").write_text(json.dumps(self.state))
        self.cli("workflow", "validate", "role-certificate-fixture")
        self.cli("workflow", "run", "role-certificate-fixture", "--input", "operation_id=17",
                 "--input", "node_id=13", "--input", "kind=node.role_add", success=success)
        return [json.loads(line) for line in (self.repo / "role-certificates.ndjson").read_text().splitlines()]

    def test_actual_native_associates_each_public_csr_and_signature_with_its_placed_leaf(self):
        calls = self.run_root()
        requests = [call for call in calls if call["method"] == "roleRequest"]
        published = [call for call in calls if call["method"] == "rolePublish"]
        phases = [call["body"]["result"]["publication_phase"] for call in calls if call["method"] == "POST"]
        self.assertEqual(len(requests), 2)
        self.assertEqual(len(published), 2)
        self.assertEqual(sum(call["method"] == "signBatch" for call in calls), 1)
        self.assertEqual(sorted(phase["node_id"] for phase in phases), [13, 14])
        self.assertTrue(all(phase["phase"] == "certificates_published" for phase in phases))
        for call in published:
            import base64
            self.assertEqual(base64.b64decode(call["request"]["request_base64"]).decode(),
                             "PUBLIC CSR " + str(call["node_id"]) + ":" + call["scope"])
            self.assertEqual(set(call["signed"]), {"certificate_base64", "root_certificate_base64"})
        methods = [call["method"] for call in calls]
        self.assertLess(methods.index("signBatch"), methods.index("rolePublish"))
        self.assertGreater(methods.index("POST"), max(i for i,m in enumerate(methods) if m == "rolePublish"))
        record = self.source["jobs"][5]["steps"][0]
        self.assertEqual(record["target"], '${{ "orbit-node-" + string(inputs.node_id) }}')
        self.assertEqual(record["labels"], {"node": '${{ string(inputs.node_id) }}'})

    def test_current_certificates_skip_vault_signing_but_still_publish_and_record(self):
        self.state["current"] = True
        calls = self.run_root()
        self.assertNotIn("signBatch", [call["method"] for call in calls])
        self.assertEqual([call["signed"] for call in calls if call["method"] == "rolePublish"],
                         [{"skipped": True}, {"skipped": True}])

    def test_empty_plan_completes_without_certificate_effects_or_phase_writes(self):
        self.state["operation"]["input"]["certificates"]["leaves"] = []
        calls = self.run_root()
        self.assertTrue(all(call["method"] == "GET" for call in calls))

    def test_failed_publication_blocks_all_phase_recording(self):
        self.state["fail"] = "publish"
        calls = self.run_root(success=False)
        self.assertIn("rolePublish", [call["method"] for call in calls])
        self.assertNotIn("POST", [call["method"] for call in calls])


if __name__ == "__main__":
    unittest.main()
