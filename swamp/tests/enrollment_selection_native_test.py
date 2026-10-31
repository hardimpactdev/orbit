"""Native optional enrollment selection defers absent intent and preserves inner fanout."""

import json
import os
import subprocess
import unittest

import yaml
from native_harness import ROOT, NativeTestCase


class EnrollmentSelection(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/node-bootstrap")
        program = """
import {selectEnrollmentSteps} from './extensions/behaviors/operations/node-bootstrap/composition.ts';
const e=(s)=>'${{ '+s+' }}';
const source='steps.admitted.outputs.intent.plans[0]';
const read=(name,root,dependency)=>({name,target:'orbit-node-209',...(dependency?{dependsOn:[{step:dependency,condition:{type:'succeeded'}}]}:{}),task:{type:'behavior',behaviorType:'@nckrtl/node-enrollment-settings',behaviorName:name,methodName:'read',inputs:{storage:{root,user:e(source+'.account.user'),create_missing:false,protected_roots:[],managed_checkouts:[]},account:e(source+'.account')}}});
const first=read('storage',e(source+'.root'));
const nested=read('nested',e('self.part.root'),'storage');nested.forEach={item:'part',in:e(source+'.children')};
const last=read('done',e('steps.storage.outputs.root'),'nested');
const selected=selectEnrollmentSteps([first,nested,last],'size(steps.admitted.outputs.intent.plans)>0');
console.log(JSON.stringify([{name:'admitted',target:'orbit-node-209',task:{type:'behavior',behaviorType:'@nckrtl/node-enrollment-intent',behaviorName:'enrollment-admitted',methodName:'read',inputs:{intent:e('inputs.intent')}}},...selected]));
"""
        generated = subprocess.check_output(
            [os.environ.get("DENO_BIN", "/tmp/lane4-deno-2.9.8/deno"), "eval", program],
            cwd=ROOT,
            text=True,
        )
        steps = json.loads(generated)
        for step in steps:
            self.assertEqual(step.pop("target"), "orbit-node-209")
            self.assertNotIn("labels", step)
        workflow = {
            "id": "1d7f76b5-adcf-4e10-a714-84edaf073b8c",
            "name": "enrollment-selection-fixture",
            "inputs": {
                "type": "object",
                "properties": {"intent": {"type": "object"}},
                "required": ["intent"],
            },
            "jobs": [
                {"name": "admission", "steps": steps[:1]},
                {
                    "name": "selection",
                    "dependsOn": [
                        {"job": "admission", "condition": {"type": "succeeded"}}
                    ],
                    "steps": steps[1:],
                },
            ],
        }
        (self.repo / "workflows/workflow-selection.yaml").write_text(
            yaml.safe_dump(workflow, sort_keys=False)
        )

    def run_selection(self, plans):
        self.cli("workflow", "validate", "enrollment-selection-fixture")
        return json.loads(
            self.cli(
                "workflow",
                "run",
                "enrollment-selection-fixture",
                "--input",
                json.dumps(
                    {
                        "intent": {
                            "schema_version": 1,
                            "node_id": 73,
                            "mode": "tld_only",
                            "candidate": {},
                            "bootstrap": {},
                            "ssh_identity": {
                                "control_node_id": 209,
                                "identity_file": "/approved",
                                "public_key": "fixture-public",
                            },
                            "tools": None,
                            "network": None,
                            "hub": None,
                            "plans": plans,
                        }
                    }
                ),
            ).stdout
        )

    def test_absent_optional_plan_never_evaluates_missing_input(self):
        run = self.run_selection([])
        self.assertEqual("succeeded", run["status"])
        self.assertTrue(
            all(
                step["status"] == "skipped"
                for step in run["jobs"][1]["steps"]
                if step["name"] != "admitted"
            )
        )

    def test_scalar_references_and_existing_inner_fanout_remain_in_the_parent(self):
        run = self.run_selection(
            [
                {
                    "root": "/srv/apps",
                    "account": {
                        "user": "orbit",
                        "group": "orbit",
                        "home": "/home/orbit",
                    },
                    "children": [{"root": "/srv/apps"}],
                }
            ]
        )
        states = {
            step["name"]: step["status"]
            for step in run["jobs"][1]["steps"]
            if step["name"] != "admitted"
        }
        self.assertEqual(
            {"storage-0": "succeeded", "nested-0": "succeeded", "done-0": "succeeded"},
            states,
        )


if __name__ == "__main__":
    unittest.main()
