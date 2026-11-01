"""A prefixed direct contribution must prove completed publication before firewall."""

import json
import os
import subprocess

import yaml
from native_harness import ROOT, NativeTestCase
from node_database_role_native_test import DatabaseRoleNative


class RolePrefixNative(NativeTestCase):
    native_timeout = 120
    run_role = DatabaseRoleNative.run_role
    ingress = DatabaseRoleNative.ingress

    def setUp(self):
        DatabaseRoleNative.setUp(self)
        self.ingress()
        source = ROOT / "extensions/behaviors/operations/node-role-converge-command"
        program = (
            "import {roleWorkflow} from "
            + json.dumps((source / "workflows.ts").as_uri())
            + ";"
        )
        program += (
            "import {roleConvergeSteps} from "
            + json.dumps((source / "root_steps.ts").as_uri())
            + ";"
        )
        program += """const f=roleWorkflow();
const globals=f.jobs[0].steps.find(s=>s.task.behaviorType==="@swamp/ssh").task.globalArgs;
f.jobs[0].steps=roleConvergeSteps("target","inputs.intent",globals);
f.jobs[1].steps[0].task.inputs.result="${{ steps.target_role_result.outputs }}";
console.log(JSON.stringify(f));"""
        result = subprocess.run(
            [
                os.environ.get("DENO_BIN", "/tmp/lane4-deno-2.9.8/deno"),
                "eval",
                "--no-check",
                program,
            ],
            check=True,
            capture_output=True,
            text=True,
        )
        flow = json.loads(result.stdout)
        flow.pop("target")
        for job in flow["jobs"]:
            for step in job["steps"]:
                self.assertEqual(step.pop("target"), "orbit-node-209")
                if step["task"].get("behaviorType") == "@swamp/ssh":
                    step["task"]["globalArgs"]["sshBinary"] = str(
                        self.repo / "fixture-ssh"
                    )
                    step["task"]["globalArgs"]["scpBinary"] = str(
                        self.repo / "fixture-scp"
                    )
        (self.repo / "workflows/workflow-node-role-converge.yaml").write_text(
            yaml.safe_dump(flow, sort_keys=False)
        )

    def test_prefixed_ingress_publication_allows_firewall_and_role_proof(self):
        history, result = self.run_role()
        self.assertTrue(result["role_proven"])
        self.assertTrue(result["publication_proven"])
        steps = {s["name"]: s for j in history["jobs"] for s in j["steps"]}
        # The child contributes its typed result; its detailed history is separate.
        self.assertEqual(steps["native-0"]["status"], "succeeded")
        calls = [
            json.loads(row)["action"] for row in self.audit.read_text().splitlines()
        ]
        self.assertLess(calls.index("caddy_write"), calls.index("firewall"))
