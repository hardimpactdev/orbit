"""Run the Instance environment DAG natively with official SSH and a local transport fixture."""

import hashlib
import json
import os
import pwd
import shutil
import sys
import tempfile
import uuid
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase

WORKFLOW = "operation-instance-environment"


class EnvironmentNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/instance-environment")
        official = self.repo / ".swamp/pulled-extensions/@swamp/ssh"
        official.parent.mkdir(parents=True)
        shutil.copytree(ROOT / ".swamp/pulled-extensions/@swamp/ssh", official)
        inventory = json.loads((ROOT / "extensions/models/upstream_extensions.json").read_text())
        path = self.repo / "extensions/models/upstream_extensions.json"
        path.parent.mkdir(parents=True)
        path.write_text(json.dumps({"@swamp/ssh": inventory["@swamp/ssh"]}))
        self.calls = self.repo / "calls.jsonl"
        self.ssh = self.repo / "ssh-fixture"
        self.ssh.write_text(
            "#!" + sys.executable + "\n"
            + """import json,shlex,subprocess,sys
from pathlib import Path
args=sys.argv[1:]
if '-M' in args or '-O' in args:raise SystemExit(0)
argv=shlex.split(args[-1])
with Path(CALLS).open('a') as f:f.write(json.dumps(argv)+'\\n')
if argv[0] in ('sudo','/usr/bin/sudo'):argv=argv[argv.index('--')+1:]
r=subprocess.run(argv,input=sys.stdin.buffer.read(),capture_output=True)
sys.stdout.buffer.write(r.stdout);sys.stderr.buffer.write(r.stderr)
raise SystemExit(r.returncode)
""".replace("CALLS", repr(str(self.calls)))
        )
        self.ssh.chmod(0o755)
        (self.repo / "existing-key").write_text("fixture identity not consumed")
        workflow = yaml.safe_load((ROOT / f"workflows/workflow-{WORKFLOW}.yaml").read_text())
        for job in workflow["jobs"]:
            self.assertEqual(job.pop("labels"), {"role": "swamp"})
            for step in job["steps"]:
                self.assertEqual(step.pop("labels"), {"role": "swamp"})
                if step["task"].get("behaviorType") == "@swamp/ssh" and "globalArgs" in step["task"]:
                    step["task"]["globalArgs"]["sshBinary"] = str(self.ssh)
        (self.repo / f"workflows/workflow-{WORKFLOW}.yaml").write_text(yaml.safe_dump(workflow, sort_keys=False))
        # The Node's directory lives outside the native repository, whose files must hold no private bytes.
        self.base = Path(tempfile.mkdtemp(prefix="orbit-environment-node-"))
        self.addCleanup(shutil.rmtree, self.base)
        self.secret = f"private-environment-{uuid.uuid4().hex}"
        self.secrets = (self.secret,)
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        self.inputs = {
            "operation_id": 31, "node_id": 9, "kind": "instance.sync_environment",
            "intent": {
                "environment": {
                    "instance_id": 42, "environment": "development", "base": str(self.base),
                    "user": pwd.getpwuid(os.getuid()).pw_name, "maximum": 1048576, "required_capacity": 1048576,
                    "require_home": False, "preserve_workload_keys": True, "keys": [], "owned_keys": ["OLD"],
                    "sha256": "", "testing": None,
                },
                "runtime": None,
                "ssh_execution": {
                    "control_node_id": 209, "identity_file": str(self.repo / "existing-key"),
                    "public_key": "ssh-ed25519 public-fixture",
                    "target": {"name": "target", "host": "198.51.100.9", "user": "orbit", "port": 22,
                               "host_keys": ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ111111111111111111111111111111111111111111"]},
                },
            },
            "execution_secrets": {"environment": {"vault": "orbit-control-inputs", "key": "instance-environment-31"}},
        }
        self.render({"APP_KEY": self.secret, "APP_ENV": "local"})

    def render(self, values, testing=None):
        content = "".join(f'{key}="{value}"\n' for key, value in sorted(values.items()))
        plan = self.inputs["intent"]["environment"]
        plan.update(keys=sorted(values), sha256=hashlib.sha256(content.encode()).hexdigest(), testing=None)
        material = {"content": content, "testing": None}
        if testing is not None:
            text = "".join(f'{key}="{value}"\n' for key, value in sorted(testing.items()))
            lines = "".join(sorted(f'{key}="{value}"\n' for key, value in testing.items()))
            plan["testing"] = {"managed_keys": sorted(testing), "sha256": hashlib.sha256(lines.encode()).hexdigest(), "test_database": "app_test"}
            material["testing"] = {"content": text}
        self.cli("vault", "put", "orbit-control-inputs", "instance-environment-31", input=json.dumps(material))
        return content

    def sync(self, success=True):
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
        self.assert_private_absent(json.dumps(history), self.calls.read_text())
        self.steps = {s["name"]: s for s in history["jobs"][0]["steps"]}
        return json.loads(self.steps["Report the result"]["outputs"]["stdout"])

    def test_development_sync_writes_a_private_file_and_reports_only_public_facts(self):
        self.cli("workflow", "validate", WORKFLOW)
        content = self.render({"APP_KEY": self.secret, "APP_ENV": "local"})
        result = self.sync()
        self.assertEqual(self.run["status"], "succeeded")
        self.assertEqual(result, {"instance_id": 42, "changed": True, "keys": ["APP_ENV", "APP_KEY"], "key_count": 2,
                                  "test_database": None, "environment_changed": True, "testing_status": None,
                                  "runtime_refresh": "skipped_development", "restarted_process_ids": []})
        self.assertEqual((self.base / ".env").read_text(), content)
        self.assertEqual((self.base / ".env").stat().st_mode & 0o777, 0o600)
        self.assertEqual(self.steps["Refresh the runtime"]["status"], "skipped")
        self.assertFalse(self.sync()["changed"])

    def test_a_foreign_key_refuses_before_the_write(self):
        (self.base / ".env").write_text("APP_ENV=old\nOLD=1\nFOREIGN=kept\n")
        result = self.sync(success=False)
        self.assertEqual(self.run["status"], "failed")
        self.assertEqual(result, {"error_code": "env.sync_would_drop_keys", "failed_step": "checked", "foreign_keys": ["FOREIGN"]})
        self.assertEqual((self.base / ".env").read_text(), "APP_ENV=old\nOLD=1\nFOREIGN=kept\n")
        self.assertEqual(self.steps["Write .env, .env.testing"]["status"], "skipped")

    def test_testing_merges_managed_keys_and_keeps_other_lines(self):
        (self.base / ".env.testing").write_text("KEEP=1\nDB_DATABASE=old\n")
        os.chmod(self.base / ".env.testing", 0o600)
        self.render({"APP_KEY": self.secret}, testing={"DB_CONNECTION": "pgsql", "DB_DATABASE": "app_test"})
        result = self.sync()
        self.assertEqual(result["testing_status"], "written")
        self.assertEqual(result["test_database"], "app_test")
        self.assertEqual((self.base / ".env.testing").read_text(), 'KEEP=1\nDB_DATABASE="app_test"\nDB_CONNECTION="pgsql"\n')

    def test_a_hash_that_differs_fails_the_check_step(self):
        self.render({"APP_KEY": self.secret})
        self.inputs["intent"]["environment"]["sha256"] = "0" * 64
        result = self.sync(success=False)
        self.assertEqual(result, {"error_code": "env.sync_unconfirmed", "failed_step": "verified"})
