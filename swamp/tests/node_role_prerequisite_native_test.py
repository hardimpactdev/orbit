"""Complete public prerequisite selection and failure ordering through official SSH."""

import json
import os
import shutil
import subprocess
import sys

import yaml
from native_harness import ROOT, NativeTestCase


class RolePrerequisiteNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/node-bootstrap", "operations/node-role-command", "operations/node-role-converge-command")
        self.cli("extension", "pull", "@swamp/ssh@2026.10.23.1")
        self.audit = self.repo / "calls.jsonl"
        self.failure = self.repo / "refuse-vendor"
        binary = self.repo / "fixture-ssh"
        binary.write_text("#!" + sys.executable + "\n" + '''
import ast,json,sys
from pathlib import Path
if '/usr/bin/composer' in sys.argv[-1]:
    data={};action='composer_verify'
else:
    tree=ast.parse(sys.stdin.read())
    value=next(node.value.args[0] for node in tree.body if isinstance(node,ast.Assign)
               and isinstance(node.targets[0],ast.Name) and node.targets[0].id in ('ROLE_INPUT','BOOTSTRAP_INPUT'))
    data=json.loads(ast.literal_eval(value))
    action=(data['scope'] if 'scope' in data else 'storage' if 'root' in data else 'hibernation' if 'path' in data
            else data['resource'] if 'resource' in data else 'caddy' if 'amd64_sha512' in data
            else 'packages' if 'packages' in data else 'account' if set(data)=={'user'} else 'composer_manifest')
with Path(AUDIT).open('a') as stream: stream.write(json.dumps({'action':action,'data':data})+'\\n')
if action=='composer_vendor' and Path(FAILURE).exists():raise SystemExit(1)
result=({'user':data['user'],'group':'orbit','home':'/home/orbit'} if action=='account' else
        {'caddy_installed':True} if action=='caddy' else {'ready':True,'changed':False})
print(json.dumps(result))
'''.replace("AUDIT", repr(str(self.audit))).replace("FAILURE", repr(str(self.failure))))
        binary.chmod(0o700)
        identity = self.repo / "identity"
        identity.write_text("synthetic unused identity")
        globals_ = {
            "name": "role-prerequisite-fixture", "sshBinary": str(binary),
            "transport": {"kind": "ssh", "user": "orbit", "identityFile": str(identity),
                          "hostKeys": ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"]},
            "hosts": [{"name": "target", "address": "dev.invalid"}],
        }
        self.globals = globals_
        source = (ROOT / "extensions/behaviors/operations/node-role-converge-command/prerequisite_steps.ts").as_uri()
        deno = os.environ.get("DENO_BIN", shutil.which("deno") or "deno")
        code = ("import {rolePrerequisiteSteps} from " + json.dumps(source) + ";"
                "console.log(JSON.stringify(rolePrerequisiteSteps('baseline','inputs.intent'," + json.dumps(globals_) + ")));" )
        steps = json.loads(subprocess.run([deno, "eval", code], check=True, capture_output=True, text=True).stdout)
        for step in steps:
            self.assertEqual(step.pop("target"), "orbit-node-209")
            self.assertEqual(step.pop("labels"), {"node": "209"})
        workflow = {
            "id": "001fc37c-50e5-45bf-a92e-204bf57c12ac", "name": "role-prerequisite-fixture",
            "inputs": {"type": "object", "properties": {"intent": {"type": "object"}}, "required": ["intent"]},
            "jobs": [{"name": "prerequisites", "steps": steps}],
        }
        (self.repo / "workflows/workflow-role-prerequisite-fixture.yaml").write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.cli("workflow", "validate", "role-prerequisite-fixture")

    def run_prerequisites(self, role="app-dev", success=True):
        application = role in ("app-dev", "app-prod")
        intent = {"node_id": 7, "role": role, "user": "orbit", "storage_root": None,
                  "caddy_package": {"release": "2.10.0", "minimum_version": "2.9.0", "amd64_sha512": "a" * 128} if application else None,
                  "prerequisites": {"releases": ["noble"], "packages": ["composer"] if application else ["postgresql-client"],
                                    "apps_directory": role == "app-dev", "composer_home": application}}
        path = self.repo / "inputs.json"
        path.write_text(json.dumps({"intent": intent, **({"tools": self.tools} if hasattr(self, "tools") else {})}))
        run = json.loads(self.cli("workflow", "run", "role-prerequisite-fixture", "--input-file", str(path), success=success).stdout)
        return json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)

    def calls(self):
        return [json.loads(line) for line in self.audit.read_text().splitlines()] if self.audit.exists() else []

    def test_application_prerequisites_keep_directory_manifest_and_verification_actions_visible(self):
        history = self.run_prerequisites()

        self.assertEqual(history["status"], "succeeded")
        self.assertEqual([call["action"] for call in self.calls()], [
            "account", "caddy", "storage", "packages", "apps", "composer_parent", "composer_home",
            "composer_vendor", "composer_bin", "composer_manifest", "composer_verify", "hibernation", "hibernation",
        ])
        self.assertEqual(self.calls()[2]["data"]["root"], "/home/orbit/apps")

    def test_database_prerequisites_continue_past_inapplicable_caddy_and_application_actions(self):
        history = self.run_prerequisites("database")

        self.assertEqual(history["status"], "succeeded")
        self.assertEqual([call["action"] for call in self.calls()], ["account", "packages"])

    def test_failed_directory_blocks_later_actions_and_native_resume_continues_the_same_root(self):
        self.failure.touch()
        history = self.run_prerequisites(success=False)
        first = [call["action"] for call in self.calls()]
        self.assertEqual(first[-1], "composer_vendor")
        self.assertNotIn("composer_manifest", first)
        self.assertNotIn("hibernation", first)
        self.failure.unlink()

        result = json.loads(self.cli("workflow", "resume", "role-prerequisite-fixture", "--run", history["id"]).stdout)

        self.assertEqual(result["id"], history["id"])
        self.assertEqual(result["status"], "succeeded")
        calls = [call["action"] for call in self.calls()]
        self.assertEqual(calls.count("account"), 1)
        self.assertEqual(calls.count("composer_vendor"), 2)
        self.assertIn("composer_verify", calls)

    def prepared(self, receipt):
        self.tools = receipt
        deno = os.environ.get("DENO_BIN", shutil.which("deno") or "deno")
        source = (ROOT / "extensions/behaviors/operations/node-role-converge-command/prerequisite_steps.ts").as_uri()
        code = "import {rolePrerequisiteSteps} from " + json.dumps(source) + ";console.log(JSON.stringify(rolePrerequisiteSteps('baseline','inputs.intent'," + json.dumps(self.globals) + ",undefined,'inputs.tools')));"
        steps = json.loads(subprocess.run([deno, "eval", code], check=True, capture_output=True, text=True).stdout)
        for step in steps:
            self.assertEqual(step.pop("target"), "orbit-node-209")
            self.assertEqual(step.pop("labels"), {"node": "209"})
        path = self.repo / "workflows/workflow-role-prerequisite-fixture.yaml"
        workflow = yaml.safe_load(path.read_text())
        workflow["inputs"]["properties"]["tools"] = {"type": "object"}
        workflow["jobs"][0]["steps"] = steps
        path.write_text(yaml.safe_dump(workflow, sort_keys=False))

    def test_verified_parent_tools_skip_generic_apt_and_preserve_caddy_and_account_directory_duties(self):
        self.prepared({"ready": True, "node_id": 7, "packages": ["composer", "caddy"]})
        history = self.run_prerequisites()
        self.assertEqual(history["status"], "succeeded")
        actions = [call["action"] for call in self.calls()]
        self.assertNotIn("packages", actions)
        self.assertIn("caddy", actions)
        self.assertIn("account", actions)
        self.assertIn("storage", actions)
        self.assertIn("composer_verify", actions)

    def test_wrong_node_tool_contribution_refuses_before_any_target_action(self):
        self.prepared({"ready": True, "node_id": 8, "packages": ["composer", "caddy"]})
        history = self.run_prerequisites(success=False)
        self.assertEqual(history["status"], "failed")
        self.assertEqual(self.calls(), [])

    def test_incomplete_tool_coverage_refuses_before_any_target_action(self):
        self.prepared({"ready": True, "node_id": 7, "packages": ["caddy"]})
        history = self.run_prerequisites(success=False)
        self.assertEqual(history["status"], "failed")
        self.assertEqual(self.calls(), [])
