"""Existing Process material via unmodified official SSH exec stdin on native e459."""

import json
import os
import shutil
import sys

import yaml
from native_harness import ROOT, NativeTestCase


class RoleProcessStdinNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.cli("extension", "pull", "@swamp/ssh@2026.10.23.1")
        scripts = self.repo / "process-scripts"
        shutil.copytree(ROOT / "extensions/behaviors/operations/process-commands/scripts", scripts)
        self.secret = "role-process-stdin-" + os.urandom(16).hex()
        self.secrets = (self.secret,)
        private = {"runtime": "docker", "spec": "a" * 64, "definition": {"environment": {"TOKEN": self.secret}}}
        self.cli("vault", "create", "local_encryption", "role-process-fixture")
        self.cli("vault", "put", "role-process-fixture", "installation", input=json.dumps(private))
        audit = self.repo / "public-argv.json"
        binary = self.repo / "fixture-ssh"
        binary.write_text("#!" + sys.executable + "\n" + '''
import json,os,shlex,sys
from pathlib import Path
Path(AUDIT).write_text(json.dumps(sys.argv[1:]))
remote=shlex.split(sys.argv[-1])
expected=remote[-1]
assert 'ORBIT_PROCESS_MATERIAL' not in os.environ
sys.path.insert(0,SCRIPTS)
from unit_files import material
from process_common import Refused
sys.argv=['create_container.py','--material-stdin']
try:
    value=material({'process_id':17},{'runtime':'docker','spec':expected})
    assert value['definition']['environment']['TOKEN']
    assert 'ORBIT_PROCESS_MATERIAL' not in os.environ
    print(json.dumps({'ready':True}))
except Refused as error:
    print(json.dumps({'error_code':error.code}))
    raise SystemExit(1)
'''.replace("AUDIT", repr(str(audit))).replace("SCRIPTS", repr(str(scripts))))
        binary.chmod(0o700)
        identity = self.repo / "identity"
        identity.write_text("synthetic unused identity")
        task = {
            "type": "behavior", "behaviorType": "@swamp/ssh", "behaviorName": "role-process-stdin",
            "methodName": "exec", "globalArgs": {
                "name": "role-process-stdin", "sshBinary": str(binary),
                "transport": {
                    "kind": "ssh", "user": "orbit", "identityFile": str(identity),
                    "hostKeys": ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"],
                },
                "hosts": [{"name": "target", "address": "dev.invalid"}],
            },
            "inputs": {
                "hosts": "name:target", "sudo": True,
                "argv": ["python3", "create_container.py", "--material-stdin", "${{ inputs.expected_spec }}"],
                "stdin": "${{ vault.get('role-process-fixture', 'installation') }}",
            },
        }
        # The test uses local placement; the production parent supplies fixed209 placement.
        workflow = {
            "id": "1e47d612-c905-4cf2-a732-5ab82ef0c280", "name": "role-process-stdin-fixture",
            "vaults": ["role-process-fixture"],
            "inputs": {"type": "object", "properties": {"expected_spec": {"type": "string"}}, "required": ["expected_spec"]},
            "jobs": [{"name": "material", "steps": [{"name": "consume", "task": task}]}],
        }
        (self.repo / "workflows/workflow-role-process-stdin-fixture.yaml").write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.cli("workflow", "validate", "role-process-stdin-fixture")

    def test_existing_material_resolves_to_stdin_and_stays_out_of_public_history_and_argv(self):
        result = json.loads(self.cli("workflow", "run", "role-process-stdin-fixture", "--input", "expected_spec=" + "a" * 64).stdout)
        history = json.loads(self.cli("workflow", "history", "get", result["id"]).stdout)

        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(json.loads(history["jobs"][0]["steps"][0]["outputs"]["stdout"]), {"ready": True})
        self.assert_private_absent((self.repo / "public-argv.json").read_text())

    def test_stdin_spec_mismatch_refuses_in_the_same_canonical_parser(self):
        result = json.loads(self.cli("workflow", "run", "role-process-stdin-fixture", "--input", "expected_spec=" + "b" * 64, success=False).stdout)

        self.assertEqual(result["status"], "failed")
        self.assert_private_absent((self.repo / "public-argv.json").read_text())
