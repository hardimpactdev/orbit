"""Pure bootstrap-pin output binding runs before any candidate publication effect."""

import json
import os
import shutil
import subprocess

import yaml
from native_harness import ROOT, NativeTestCase


class RolePublicationBindingNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/node-role-converge-command")
        fixture = self.repo / "extensions/behaviors/pin_fixture.ts"
        fixture.write_text('''import {z} from "npm:zod@4.4.3";
const Pins=z.object({host_keys:z.array(z.string())});
export const behavior={type:"@fixture/bootstrap-pin",version:"2026.10.31.1",resources:{pins:{schema:Pins,lifetime:"1d",garbageCollection:10}},methods:{decode:{description:"Return admitted public fixture pins",arguments:Pins,execute:async(args:z.infer<typeof Pins>,ctx:{writeResource(spec:string,name:string,value:unknown):Promise<unknown>})=>{await ctx.writeResource("pins","pins",args);return args;}}}};
''')
        module = (ROOT / "extensions/behaviors/operations/node-role-converge-command/publication_binding.ts").as_uri()
        globals_ = {
            "name": '${{ "enrollment-target-"+run.id }}',
            "transport": {"kind": "ssh", "user": "${{ inputs.candidate.user }}", "port": 22,
                          "identityFile": "/approved/identity"},
            "hosts": [{"name": "target", "address": "${{ inputs.candidate.wireguard_ip }}",
                       "transport": {"hostKeys": "${{ steps.bootstrap_pin.outputs.host_keys }}"}}],
        }
        code = "import {rolePublicationBindingSteps} from " + json.dumps(module) + ";console.log(JSON.stringify(rolePublicationBindingSteps('binding','inputs.publications','inputs.candidate'," + json.dumps(globals_) + ",'bootstrap_pin')));"
        deno = os.environ.get("DENO_BIN", shutil.which("deno") or "deno")
        steps = json.loads(subprocess.run([deno, "eval", code], check=True, capture_output=True, text=True).stdout)
        for step in steps:
            self.assertEqual(step.pop("target"), "orbit-node-209")
            self.assertEqual(step.pop("labels"), {"node": "209"})
        steps.insert(0, {"name": "bootstrap_pin", "task": {"type": "behavior", "behaviorType": "@fixture/bootstrap-pin",
                        "behaviorName": "pin", "methodName": "decode", "inputs": {"host_keys": "${{ inputs.pins }}"}}})
        steps.append({"name": "effect", "dependsOn": [{"step": "binding", "condition": {"type": "succeeded"}}],
                      "task": {"type": "behavior", "behaviorType": "command/shell", "behaviorName": "effect",
                               "methodName": "execute", "inputs": {"run": "printf reached"}}})
        workflow = {"id": "d137b701-0533-430d-94f9-8234d3d75201", "name": "publication-binding-native-fixture",
                    "inputs": {"type": "object", "properties": {"publications": {"type": "array"}, "candidate": {"type": "object"}, "pins": {"type": "array"}}},
                    "jobs": [{"name": "binding", "steps": steps}]}
        (self.repo / "workflows/workflow-publication-binding-native-fixture.yaml").write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.cli("workflow", "validate", "publication-binding-native-fixture")

    def run_binding(self, node_id=31, pins=None):
        row = {"node_id": node_id, "control_node_id": 209, "host_keys": None,
               "connection": {"nodeHost": "10.44.0.31", "nodeUser": "orbit", "nodePort": 22, "nodeIdentityFile": "/approved/identity"},
               "before": {"caddy": None, "php": None, "dns": None}, "after": {"caddy": None, "php": None, "dns": None}}
        stored = {**row, "node_id": 14, "host_keys": ["ssh-ed25519 U1RPUkVE"],
                  "connection": {**row["connection"], "nodeHost": "10.44.0.14"}}
        run = json.loads(self.cli("workflow", "run", "publication-binding-native-fixture", "--input", json.dumps({
            "publications": [row, stored], "candidate": {"node_id": 31, "wireguard_ip": "10.44.0.31", "user": "orbit"},
            "pins": pins if pins is not None else ["ssh-ed25519 VkVSSUZJRUQ="]}), success=node_id == 31 and pins != []).stdout)
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        return history, {step["name"]: step for step in history["jobs"][0]["steps"]}, stored

    def test_native_binding_uses_predecessor_full_keys_and_preserves_other_participants(self):
        history, steps, stored = self.run_binding()
        self.assertEqual(history["status"], "succeeded")
        rows = steps["binding"]["outputs"]["publications"]
        self.assertEqual(rows[0]["host_keys"], ["ssh-ed25519 VkVSSUZJRUQ="])
        self.assertEqual(rows[1], stored)
        self.assertEqual(steps["effect"]["outputs"]["stdout"], "reached")

    def test_other_pending_node_is_refused_before_effect(self):
        history, steps, _ = self.run_binding(node_id=32)
        self.assertEqual(history["status"], "failed")
        self.assertEqual(steps["binding"]["status"], "failed")
        self.assertEqual(steps["effect"]["status"], "skipped")

    def test_missing_pins_are_refused_before_effect(self):
        history, steps, _ = self.run_binding(pins=[])
        self.assertEqual(history["status"], "failed")
        self.assertEqual(steps["binding"]["status"], "failed")
        self.assertEqual(steps["effect"]["status"], "skipped")
