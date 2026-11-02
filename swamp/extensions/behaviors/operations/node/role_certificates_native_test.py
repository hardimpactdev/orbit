"""Actual same-root certificate jobs; only canonical local effect and vault boundaries injected."""

import importlib.util
import json
import os
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
spec = importlib.util.spec_from_file_location("native_harness", ROOT / "tests/native_harness.py")
harness = importlib.util.module_from_spec(spec)
spec.loader.exec_module(harness)
NativeTestCase = harness.NativeTestCase


class RoleCertificatesNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors(
            "automatic-deploy",
            "node-worker",
            "operations/_lib",
            "operations/node",
            "operations/routes",
            "operations/certificates",
            "operations/fleet",
            "operations/integrations",
            "operations/processes-schedules",
            "operations/tools",
        )
        module = self.repo / "extensions/behaviors/operations/node/role_certificates.ts"
        module.write_text(
            'import {fixtureCertificateHttp} from "./role_certificates_native_fixture.ts";\n'
            + module.read_text().replace(
                "export const behavior = roleCertificates();",
                "export const behavior = roleCertificates(() => fixtureCertificateHttp);",
            )
        )
        script = """import {roleCertificateJobs} from "./extensions/behaviors/operations/node/role_certificates_workflow.ts";
const generated=roleCertificateJobs("prepared");
console.log(JSON.stringify({id:"c466ef02-dae7-4309-bf18-a38c11a6777c",name:"role-certificate-fixture",inputs:{type:"object",properties:{operation_id:{type:"integer"},node_id:{type:"integer"},kind:{type:"string"}},required:["operation_id","node_id","kind"]},jobs:[{name:"prepared",steps:[{name:"prepared",task:{type:"assert",expr:"true",severity:"high",message:"prepared"}}]},...generated.jobs]}));"""
        result = subprocess.run(
            [os.environ.get("DENO_BIN", "/tmp/lane0d-deno/deno"), "eval", script],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=True,
        )
        self.source = json.loads(result.stdout)
        workflow = json.loads(result.stdout)
        for job in workflow["jobs"]:
            for step in job["steps"]:
                step.pop("target", None); step.pop("labels", None)
                step.pop("labels", None)
        (self.repo / "workflows/workflow-role-certificate-fixture.yaml").write_text(
            json.dumps(workflow)
        )
        self.state = {
            "operation": {
                "id": 17,
                "node_id": 13,
                "kind": "node.role_add",
                "status": "running",
                "cancel_requested": False,
                "input": {
                    "certificates": {
                        "leaves": [
                            {
                                "node_id": 13,
                                "scope": "role-metrics",
                                "domain": "metrics.orbit",
                                "version": "0123456789abcdef",
                                "root_hash": "a" * 64,
                            },
                            {
                                "node_id": 14,
                                "scope": "role-analytics",
                                "domain": "analytics.orbit",
                                "version": "fedcba9876543210",
                                "root_hash": "a" * 64,
                            },
                        ],
                        "withdrawals": [],
                    }
                },
            }
        }

        plan = self.state["operation"]["input"]["certificates"]
        plan["connections"] = [
            {
                "node_id": leaf["node_id"],
                "ssh": {
                    "control_node_id": 209,
                    "identity_file": "/etc/orbit/swamp-role-worker/ssh-identity",
                    "public_key": "ssh-ed25519 public",
                    "target": {
                        "name": "target",
                        "host": "10.44.0.14",
                        "user": "orbit",
                        "port": 22,
                        "host_keys": ["ssh-ed25519 pinned"],
                    },
                },
            }
            for leaf in plan["leaves"]
        ]
        import yaml

        child = yaml.safe_load(
            (ROOT / "workflows/workflow-node-role-certificate-publish.yaml").read_text()
        )
        # The actual child has dedicated official-SSH/OpenSSL tests. This boundary
        # isolates parent failure/phase ordering with two effect-Node contributions.
        (self.repo / "test-child.py").write_text("""import json,os,sys
from pathlib import Path
leaf={'node_id':int(os.environ['NODE']), 'scope':os.environ['SCOPE']}
with Path('role-certificates.ndjson').open('a') as stream:
    stream.write(json.dumps({'method':'child','leaf':leaf,'parent_run_id':os.environ['PARENT']})+'\\n')
if json.loads(Path('role-certificates.json').read_text()).get('fail')=='publish': sys.exit(1)
print(json.dumps({'ready':True,**leaf}))
""")
        child["jobs"] = [
            {
                "name": "fixture",
                "steps": [
                    {
                        "name": "result",
                        "task": {
                            "type": "behavior",
                            "behaviorType": "command/shell",
                            "behaviorName": '${{ "fixture-" + run.id }}',
                            "methodName": "execute",
                            "inputs": {
                                "run": "/usr/bin/python3 test-child.py",
                                "env": {
                                    "NODE": "${{ string(inputs.leaf.node_id) }}",
                                    "SCOPE": "${{ inputs.leaf.scope }}",
                                    "PARENT": "${{ inputs.parent_run_id }}",
                                },
                            },
                        },
                    }
                ],
            }
        ]
        (
            self.repo / "workflows/workflow-node-role-certificate-publish.yaml"
        ).write_text(yaml.safe_dump(child))

    def run_root(self, success=True):
        (self.repo / "role-certificates.json").write_text(json.dumps(self.state))
        self.cli("workflow", "validate", "role-certificate-fixture")
        result = self.cli(
            "workflow",
            "run",
            "role-certificate-fixture",
            "--input",
            "operation_id=17",
            "--input",
            "node_id=13",
            "--input",
            "kind=node.role_add",
            success=success,
        )
        decoder = json.JSONDecoder()
        remaining = result.stdout.strip()
        records = []
        while remaining:
            value, end = decoder.raw_decode(remaining)
            records.append(value)
            remaining = remaining[end:].strip()
        self.result = next(
            value
            for value in records
            if value.get("workflowName") == "role-certificate-fixture"
            and "jobs" in value
        )
        return [
            json.loads(line)
            for line in (self.repo / "role-certificates.ndjson")
            .read_text()
            .splitlines()
        ]

    def test_actual_native_child_results_precede_each_parent_publication_marker(self):
        calls = self.run_root()
        children = [call for call in calls if call["method"] == "child"]
        phases = [
            call["body"]["result"]["publication_phase"]
            for call in calls
            if call["method"] == "POST"
        ]
        self.assertEqual([13, 14], sorted(call["leaf"]["node_id"] for call in children))
        self.assertTrue(
            all(call["parent_run_id"] == self.result["id"] for call in children)
        )
        self.assertEqual([13, 14], sorted(phase["node_id"] for phase in phases))
        methods = [call["method"] for call in calls]
        self.assertGreater(
            methods.index("POST"), max(i for i, m in enumerate(methods) if m == "child")
        )

    def test_empty_plan_completes_without_children_or_phase_writes(self):
        plan = self.state["operation"]["input"]["certificates"]
        plan["leaves"] = []
        plan["connections"] = []
        calls = self.run_root()
        self.assertTrue(all(call["method"] == "GET" for call in calls))

    def test_failed_child_blocks_all_phase_recording(self):
        self.state["fail"] = "publish"
        calls = self.run_root(success=False)
        self.assertIn("child", [call["method"] for call in calls])
        self.assertNotIn("POST", [call["method"] for call in calls])


if __name__ == "__main__":
    unittest.main()
