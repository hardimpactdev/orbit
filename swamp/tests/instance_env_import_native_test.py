"""Run the env:import DAG natively: official SSH stages and copies .env, the store behavior puts it in the vault."""

import json
import os
import pwd
import shutil
import sys
import tempfile
import uuid
from pathlib import Path

import yaml
from native_harness import ROOT, NativeTestCase

WORKFLOW = "operation-instance-env-import"


class EnvImportNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        inventory = json.loads((ROOT / "extensions/models/upstream_extensions.json").read_text())
        self.cli("extension", "pull", f"@swamp/ssh@{inventory['@swamp/ssh']['version']}")
        self.copy_behaviors("operations/instance-env-import")
        self.base = Path(tempfile.mkdtemp(prefix="orbit-env-import-node-"))
        self.addCleanup(shutil.rmtree, self.base)
        tools = self.repo / "tools"
        tools.mkdir()
        (tools / "ssh").write_text("#!" + sys.executable + """
import shlex,subprocess,sys
if '-M' in sys.argv or '-O' in sys.argv: sys.exit(0)
argv=shlex.split(sys.argv[-1])
if argv[0] in ('sudo','/usr/bin/sudo'): argv=argv[argv.index('--')+1:]
sys.exit(subprocess.call(argv))
""")
        (tools / "scp").write_text("#!" + sys.executable + """
import shutil,sys
paths=[value.split(':',1)[1] if '@' in value and ':' in value else value for value in sys.argv[-2:]]
shutil.copyfile(*paths)
""")
        for tool in ("ssh", "scp"):
            (tools / tool).chmod(0o755)
        workflow = yaml.safe_load((ROOT / f"workflows/workflow-{WORKFLOW}.yaml").read_text())
        for job in workflow["jobs"]:
            self.assertEqual(job.pop("labels"), {"role": "swamp"})
            for step in job["steps"]:
                self.assertEqual(step.pop("labels"), {"role": "swamp"})
                if step["task"].get("behaviorType") == "@swamp/ssh":
                    step["task"]["globalArgs"].update(sshBinary=str(tools / "ssh"), scpBinary=str(tools / "scp"))
        (self.repo / f"workflows/workflow-{WORKFLOW}.yaml").write_text(yaml.safe_dump(workflow, sort_keys=False))
        (self.repo / "existing-key").write_text("fixture identity not consumed")
        self.secret = f"private-import-{uuid.uuid4().hex}"
        self.secrets = (self.secret,)
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        user = pwd.getpwuid(os.getuid()).pw_name
        self.inputs = {"operation_id": 41, "node_id": 9, "kind": "instance.env_import", "execution_secrets": {},
                       "intent": {"base": str(self.base), "user": user, "ssh_execution": {
                           "identity_file": str(self.repo / "existing-key"),
                           "target": {"host": "198.51.100.9", "user": user, "port": 22,
                                      "host_keys": ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ111111111111111111111111111111111111111111"]}}}}

    def run_import(self, success=True):
        reply = self.cli("workflow", "run", WORKFLOW, "--stdin", input=json.dumps(self.inputs), success=success)
        decoder = json.JSONDecoder()
        for n, char in enumerate(reply.stdout):
            if char == "{":
                try:
                    self.run = decoder.raw_decode(reply.stdout[n:])[0]
                    break
                except ValueError:
                    pass
        history = json.loads(self.cli("workflow", "history", "get", self.run["id"]).stdout)
        return {s["name"]: s for job in history["jobs"] for s in job["steps"]}

    def test_the_file_reaches_the_vault_and_only_its_size_and_hash_come_back(self):
        content = f'APP_KEY="{self.secret}"\n'
        (self.base / ".env").write_text(content)
        steps = self.run_import()
        self.assertEqual(self.run["status"], "succeeded")
        self.assertEqual(steps["Read .env into the vault"]["outputs"]["size"], len(content))
        keys = json.loads(self.cli("vault", "list-keys", "orbit-control-inputs").stdout)["secretKeys"]
        self.assertIn("instance-env-import.content.41", keys)
        self.assertEqual(steps["Remove the staged copy"]["status"], "succeeded")
        self.assertEqual(list(Path("/tmp").glob("orbit-env-import-" + self.run["id"] + "*")), [])
        self.assertEqual((self.base / ".env").read_text(), content)

    def test_a_missing_or_linked_file_is_refused_before_any_copy(self):
        self.run_import(success=False)
        (self.base / "elsewhere").write_text("A=1\n")
        (self.base / ".env").symlink_to(self.base / "elsewhere")
        steps = self.run_import(success=False)
        self.assertEqual(steps["Stage .env for the copy"]["status"], "failed")
        self.assertIn("env.import_source_missing", json.dumps(steps["Stage .env for the copy"]))


if __name__ == "__main__":
    import unittest

    unittest.main()
