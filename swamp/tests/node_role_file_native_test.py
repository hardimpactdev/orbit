"""Canonical role file action through official SSH and a scratch encrypted vault."""

import json
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

import yaml
from native_harness import ROOT, NativeTestCase


class RoleFileNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/node-role-converge-command")
        self.cli("extension", "pull", "@swamp/ssh@2026.10.23.1")
        self.secret = "role-file-stdin-" + os.urandom(16).hex()
        self.secrets = (self.secret,)
        # The target filesystem is separate from the worker's source/history tree.
        target_root = tempfile.TemporaryDirectory(prefix="role-target-", dir=os.environ.get("TMPDIR"))
        self.addCleanup(target_root.cleanup)
        self.target = Path(target_root.name) / "role" / "configuration"
        self.cli("vault", "create", "local_encryption", "role-file-fixture")
        self.cli("vault", "put", "role-file-fixture", "material", input=json.dumps({
            "configuration": {str(self.target): self.secret, "/unrelated": "unused"},
        }))
        self.audit = self.repo / "argv.json"
        binary = self.repo / "fixture-ssh"
        binary.write_text("#!" + sys.executable + "\n" + '''
import json,shlex,subprocess,sys
from pathlib import Path
Path(AUDIT).write_text(json.dumps(sys.argv[1:]))
argv=shlex.split(sys.argv[-1])
index=argv.index('/usr/bin/python3')
result=subprocess.run(argv[index:],input=sys.stdin.buffer.read(),capture_output=True)
sys.stdout.buffer.write(result.stdout)
sys.stderr.buffer.write(result.stderr)
raise SystemExit(result.returncode)
'''.replace("AUDIT", repr(str(self.audit))))
        binary.chmod(0o700)
        identity = self.repo / "identity"
        identity.write_text("synthetic unused identity")
        globals_ = {
            "name": "role-file-fixture", "sshBinary": str(binary),
            "transport": {
                "kind": "ssh", "user": "orbit", "identityFile": str(identity),
                "hostKeys": ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"],
            },
            "hosts": [{"name": "target", "address": "dev.invalid"}],
        }
        source = (ROOT / "extensions/behaviors/operations/node-role-converge-command/steps.ts").as_uri()
        public = {
            "path": str(self.target), "mode": "0400", "owner": f"{os.getuid()}:{os.getgid()}",
            "content": None, "material_field": "configuration", "marker": "# Managed by Orbit.\n",
        }
        program = (
            "import {roleBaselineSteps} from " + json.dumps(source) + ";"
            "console.log(JSON.stringify(roleBaselineSteps('config','file',"
            + json.dumps(public) + "," + json.dumps(globals_)
            + ",undefined,\"vault.get('role-file-fixture', 'material')\")));"
        )
        deno = os.environ.get("DENO_BIN", shutil.which("deno") or "deno")
        steps = json.loads(subprocess.run([deno, "eval", program], check=True, capture_output=True, text=True).stdout)
        for step in steps:
            self.assertEqual(step.pop("target"), "orbit-node-209")
            self.assertEqual(step.pop("labels"), {"node": "209"})
        workflow = {
            "id": "799fdaf5-e5b6-4824-bf8f-f23c771b6926", "name": "role-file-fixture",
            "vaults": ["role-file-fixture"], "jobs": [{"name": "file", "steps": steps}],
        }
        (self.repo / "workflows/workflow-role-file-fixture.yaml").write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.cli("workflow", "validate", "role-file-fixture")

    def run_file(self, success=True):
        run = json.loads(self.cli("workflow", "run", "role-file-fixture", success=success).stdout)
        return json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)

    def test_whole_execution_copy_publishes_only_the_admitted_private_file(self):
        history = self.run_file()

        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(self.target.read_text(), self.secret)
        self.assertEqual(self.target.stat().st_mode & 0o777, 0o400)
        steps = {step["name"]: step for step in history["jobs"][0]["steps"]}
        self.assertEqual(steps["config"]["outputs"], {"ready": True, "changed": True})
        self.assert_private_absent(self.audit.read_text())
        self.assertEqual(list(self.target.parent.glob(".orbit-role-*")), [])

    def test_foreign_existing_file_fails_without_replacing_it_or_proving_readiness(self):
        self.target.parent.mkdir()
        self.target.write_text("operator content")

        history = self.run_file(success=False)

        self.assertEqual(history["status"], "failed")
        self.assertEqual(self.target.read_text(), "operator content")
        steps = {step["name"]: step for step in history["jobs"][0]["steps"]}
        self.assertEqual(steps["config"]["status"], "skipped")
        self.assert_private_absent(self.audit.read_text())
        self.assertEqual(list(self.target.parent.glob(".orbit-role-*")), [])
