"""Disposable native delivery/ordering fixture; OS effects are the SSH binary seam."""

import json
import os
import subprocess
import sys

import yaml
from native_harness import ROOT, NativeTestCase


class SwampRoleNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors(
            "operations/swamp-role", "operations/node-worker-assets", "node-worker"
        )
        self.cli("extension", "pull", "@swamp/ssh@2026.10.23.1")
        self.cli("extension", "pull", "@swamp/cfgmgmt@2026.10.27.1")
        self.audit = self.repo / "phases.jsonl"
        self.failure = self.repo / "failure"
        binary = self.repo / "fixture-ssh"
        binary.write_text(
            "#!"
            + sys.executable
            + "\n"
            + """
import json,shlex,sys
from pathlib import Path
if '-M' in sys.argv or '-O' in sys.argv:raise SystemExit(0)
argv=shlex.split(sys.argv[-1])
if '/usr/bin/mkdir' in argv:
 Path(argv[-1]).mkdir(parents=True,exist_ok=True);raise SystemExit(0)
if '/usr/bin/python3' not in argv:raise SystemExit('unexpected fixture command')
phase=Path(argv[-2]).stem
stage=Path(argv[-1]);plan=json.loads((stage/'plan.json').read_text())
assert (stage/'common.py').is_file() and (stage/(phase+'.py')).is_file()
assert plan['intent']['role']=='swamp'
with Path(AUDIT).open('a') as stream:stream.write(json.dumps(phase)+'\\n')
if Path(FAILURE).exists() and Path(FAILURE).read_text()==phase:
 print('fixture phase refusal: '+phase,file=sys.stderr);raise SystemExit(3)
print('{"ready":true}')
""".replace("AUDIT", repr(str(self.audit))).replace("FAILURE", repr(str(self.failure)))
        )
        binary.chmod(0o700)
        scp = self.repo / "fixture-scp"
        scp.write_text(
            "#!"
            + sys.executable
            + "\nimport shutil,sys\nshutil.copytree(sys.argv[-2],sys.argv[-1].split(':',1)[1],dirs_exist_ok=True)\n"
        )
        scp.chmod(0o700)
        module = (
            ROOT / "extensions/behaviors/operations/swamp-role/workflow.ts"
        ).as_uri()
        source = (
            "import {swampRoleWorkflow} from "
            + json.dumps(module)
            + ";console.log(JSON.stringify(swampRoleWorkflow()));"
        )
        generated = subprocess.run(
            [os.environ["DENO_BIN"], "eval", source],
            check=True,
            capture_output=True,
            text=True,
        )
        workflow = json.loads(
            generated.stdout.replace(
                "/tmp/orbit-swamp-role-", str(self.repo / "remote-stage-")
            )
        )
        for step in workflow["jobs"][0]["steps"]:
            self.assertEqual(step.pop("target"), "orbit-node-209")
            task = step["task"]
            if task.get("behaviorType") == "@swamp/ssh":
                task["globalArgs"].update(sshBinary=str(binary), scpBinary=str(scp))
        (self.repo / "workflows/workflow-node-role-swamp.yaml").write_text(
            yaml.safe_dump(workflow, sort_keys=False)
        )
        identity = self.repo / "identity"
        identity.write_text("synthetic fixture only")
        self.input = {
            "intent": {
                "schema_version": 1,
                "role": "swamp",
                "node_id": 22,
                "assignment_id": 13,
                "created": True,
                "user": "orbit",
                "address": "10.44.0.19",
                "source_sha": "a" * 40,
                "services_proxy_source": "10.44.0.3",
                "activate": False,
            },
            "ssh": {
                "transport": {
                    "kind": "ssh",
                    "user": "orbit",
                    "port": 22,
                    "identityFile": str(identity),
                },
                "hosts": [
                    {
                        "name": "target",
                        "address": "fixture.invalid",
                        "transport": {
                            "hostKeys": [
                                "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"
                            ]
                        },
                    }
                ],
            },
        }

    def run_role(self, success=True):
        self.cli("workflow", "validate", "node-role-swamp")
        result = json.loads(
            self.cli(
                "workflow",
                "run",
                "node-role-swamp",
                "--input",
                json.dumps(self.input),
                success=success,
            ).stdout
        )
        history = json.loads(
            self.cli("workflow", "history", "get", result["id"]).stdout
        )
        steps = {step["name"]: step for job in history["jobs"] for step in job["steps"]}
        import shutil

        shutil.rmtree(steps["assets"]["outputs"]["directory"], ignore_errors=True)
        return history, steps, steps["role_result"]["outputs"]

    def test_image_preparation_delivers_assets_and_skips_all_activation_effects(self):
        history, steps, result = self.run_role()
        self.assertEqual("succeeded", history["status"])
        self.assertTrue(result["prepared"])
        self.assertFalse(result["role_proven"])
        self.assertFalse(result["publication_proven"])
        self.assertEqual(
            [
                "account",
                "install",
                "checkout",
                "configuration",
                "release_load",
                "units",
            ],
            [json.loads(line) for line in self.audit.read_text().splitlines()],
        )
        for name in ("firewall", "activation", "verify"):
            self.assertEqual("skipped", steps[name]["status"])
        # Remote stage paths are native run scoped and removed with this fixture.
        for step in steps.values():
            inputs = step.get("inputs", {})
            self.assertNotIn("server_token", json.dumps(inputs))

    def test_phase_failure_stops_later_effects_and_preserves_named_terminal_facts(self):
        self.failure.write_text("configuration")
        history, steps, result = self.run_role(False)
        self.assertEqual("failed", history["status"])
        self.assertFalse(result["prepared"])
        self.assertEqual("configuration", result["failed_step"])
        self.assertEqual("skipped", steps["release_load"]["status"])
        self.assertEqual("failed", steps["accepted"]["status"])


if __name__ == "__main__":
    import unittest

    unittest.main()
