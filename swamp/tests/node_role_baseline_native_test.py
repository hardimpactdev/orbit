"""Actual official SSH delivery and native ordering of the standalone role baseline."""

import json
import os
import shutil
import subprocess
import sys

import yaml
from native_harness import ROOT, NativeTestCase


class RoleBaselineNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/node-role-converge-command")
        self.cli("extension", "pull", "@swamp/ssh@2026.10.23.1")
        self.audit = self.repo / "calls.jsonl"
        self.failure = self.repo / "refuse-network"
        binary = self.repo / "fixture-ssh"
        binary.write_text("#!" + sys.executable + "\n" + '''
import ast,json,sys
from pathlib import Path
tree=ast.parse(sys.stdin.read())
node=next(node for node in tree.body if isinstance(node,ast.Assign)
          and isinstance(node.targets[0],ast.Name) and node.targets[0].id=='ROLE_INPUT')
data=json.loads(ast.literal_eval(node.value.args[0]))
with Path(AUDIT).open('a') as stream: stream.write(json.dumps(data)+'\\n')
if data['resource']=='network' and Path(FAILURE).exists(): raise SystemExit(1)
print(json.dumps({'ready':True,'changed':True}))
'''.replace("AUDIT", repr(str(self.audit))).replace("FAILURE", repr(str(self.failure))))
        binary.chmod(0o700)
        identity = self.repo / "identity"
        identity.write_text("synthetic unused identity")
        globals_ = {
            "name": "role-baseline-fixture", "sshBinary": str(binary),
            "transport": {
                "kind": "ssh", "user": "orbit", "identityFile": str(identity),
                "hostKeys": ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"],
            },
            "hosts": [{"name": "target", "address": "dev.invalid"}],
        }
        source = (ROOT / "extensions/behaviors/operations/node-role-converge-command/steps.ts").as_uri()
        program = (
            "import {roleBaselineSteps} from " + json.dumps(source) + ";"
            "const globals=" + json.dumps(globals_) + ";const steps=[];let prior;"
            "for(const resource of ['pool','project','acl','network','profile']){"
            "const name='ci_'+resource;steps.push(...roleBaselineSteps(name,'ci_resource',{resource},globals,prior));prior=name;}"
            "console.log(JSON.stringify(steps));"
        )
        deno = os.environ.get("DENO_BIN", shutil.which("deno") or "deno")
        steps = json.loads(subprocess.run([deno, "eval", program], check=True, capture_output=True, text=True).stdout)
        for step in steps:
            self.assertEqual(step.pop("target"), "orbit-node-209")
            self.assertEqual(step.pop("labels"), {"node": "209"})
        workflow = {
            "id": "1f9acbde-9701-47fa-8ad9-93193239e8ac", "name": "role-baseline-fixture",
            "jobs": [{"name": "baseline", "steps": steps}],
        }
        (self.repo / "workflows/workflow-role-baseline-fixture.yaml").write_text(yaml.safe_dump(workflow, sort_keys=False))

    def run_baseline(self, succeeds=True):
        self.cli("workflow", "validate", "role-baseline-fixture")
        run = json.loads(self.cli("workflow", "run", "role-baseline-fixture", success=succeeds).stdout)
        return json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)

    def test_five_distinct_resources_use_five_official_ssh_actions(self):
        history = self.run_baseline()

        self.assertEqual(history["status"], "succeeded")
        calls = [json.loads(row) for row in self.audit.read_text().splitlines()]
        self.assertEqual(calls, [{"resource": name} for name in ("pool", "project", "acl", "network", "profile")])
        steps = {step["name"]: step for step in history["jobs"][0]["steps"]}
        self.assertEqual(steps["ci_profile"]["outputs"], {"ready": True, "changed": True})

    def test_failed_network_prevents_profile_and_never_decodes_readiness(self):
        self.failure.touch()

        history = self.run_baseline(succeeds=False)

        calls = [json.loads(row) for row in self.audit.read_text().splitlines()]
        self.assertEqual([item["resource"] for item in calls], ["pool", "project", "acl", "network"])
        steps = {step["name"]: step for step in history["jobs"][0]["steps"]}
        self.assertEqual(steps["ci_profile_command"]["status"], "skipped")
        self.assertEqual(steps["ci_network"]["status"], "skipped")
