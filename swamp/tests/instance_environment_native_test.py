"""Run the Instance environment DAG natively on unmodified official SSH and cfgmgmt, with a local host fixture."""

import hashlib
import json
import os
import pwd
import shutil
import subprocess
import sys
import tempfile
import uuid
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase

WORKFLOW = "operation-instance-environment"


class EnvironmentNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        inventory = json.loads((ROOT / "extensions/models/upstream_extensions.json").read_text())
        for package in ["@swamp/ssh", "@swamp/cfgmgmt"]:
            self.cli("extension", "pull", f"{package}@{inventory[package]['version']}")
        # The Node's directory lives outside the native repository, whose files must hold no private bytes.
        self.base = Path(tempfile.mkdtemp(prefix="orbit-environment-node-"))
        self.addCleanup(shutil.rmtree, self.base)
        self.calls = self.repo / "calls.jsonl"
        tools = self.repo / "tools"
        tools.mkdir()
        self.native_env["PATH"] = str(tools) + ":" + os.environ["PATH"]
        scripts = {
            # Runs the remote command locally; sudo, absolute or not, goes to the fixture below.
            "ssh": """import re,subprocess,sys
if '-M' in sys.argv or '-O' in sys.argv: sys.exit(0)
sys.exit(subprocess.call(['/bin/sh','-c',re.sub(r"(?<![\\w/])'?(/usr/bin/)?sudo'?(?= )",SUDO,sys.argv[-1])]))
""".replace("SUDO", repr(str(tools / "sudo"))),
            "scp": """import shutil,sys
shutil.copyfile(sys.argv[-2],sys.argv[-1].split(':',1)[1])
""",
            # Drops sudo's own options and a leading /usr/bin/timeout, then runs a fixture tool by name when one exists.
            "sudo": """import json,os,subprocess,sys
a=sys.argv[1:]
while a and a[0].startswith('-') and a[0]!='--':
    a=a[2:] if a[0] in ('-u','--user','-g') else a[1:]
if a and a[0]=='--':a=a[1:]
if a and a[0]=='/usr/bin/timeout':a=a[2:]
tool=os.path.join(TOOLS,os.path.basename(a[0])) if a else ''
if os.path.basename(a[0]) in ('systemctl','docker'):
    with open(CALLS,'a') as f:f.write(json.dumps([os.path.basename(a[0]),*a[1:]])+'\\n')
    sys.exit(0)
sys.exit(subprocess.call(a))
""".replace("TOOLS", repr(str(tools))).replace("CALLS", repr(str(self.calls))),
            "chown": "pass\n",
            "chgrp": "pass\n",
        }
        for name, source in scripts.items():
            tool = tools / name
            tool.write_text("#!" + sys.executable + "\n" + source)
            tool.chmod(0o700)
        workflow = yaml.safe_load((ROOT / f"workflows/workflow-{WORKFLOW}.yaml").read_text())
        for job in workflow["jobs"]:
            self.assertEqual(job.pop("labels"), {"role": "swamp"})
            for step in job["steps"]:
                self.assertEqual(step.pop("labels"), {"role": "swamp"})
                if step["task"].get("behaviorType") == "@swamp/ssh":
                    step["task"]["globalArgs"].update(sshBinary=str(tools / "ssh"), scpBinary=str(tools / "scp"))
        (self.repo / f"workflows/workflow-{WORKFLOW}.yaml").write_text(yaml.safe_dump(workflow, sort_keys=False))
        (self.repo / "existing-key").write_text("fixture identity not consumed")
        self.secret = f"private-environment-{uuid.uuid4().hex}"
        self.secrets = (self.secret,)
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        self.inputs = {
            "operation_id": 31, "node_id": 9, "kind": "instance.sync_environment",
            "intent": {
                "environment": {
                    "instance_id": 42, "environment": "development", "base": str(self.base),
                    "user": pwd.getpwuid(os.getuid()).pw_name, "keys": [], "owned_keys": ["OLD"],
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

    def render(self, values, testing=None):
        content = "".join(f'{key}="{value}"\n' for key, value in sorted(values.items()))
        plan = self.inputs["intent"]["environment"]
        plan.update(keys=sorted(values), sha256=hashlib.sha256(content.encode()).hexdigest(), testing=None)
        self.inputs["execution_secrets"].pop("testing", None)
        # Positional: the CLI's stdin form trims a trailing newline; the Gateway pushes exact bytes.
        self.cli("vault", "put", "orbit-control-inputs", "instance-environment-31", content)
        if testing is not None:
            text = "".join(f'{key}="{value}"\n' for key, value in sorted(testing.items()))
            plan["testing"] = {"sha256": hashlib.sha256(text.encode()).hexdigest(), "test_database": "app_test"}
            self.inputs["execution_secrets"]["testing"] = {"vault": "orbit-control-inputs", "key": "instance-environment-testing-31"}
            self.cli("vault", "put", "orbit-control-inputs", "instance-environment-testing-31", text)
            return content, text
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
        self.assert_private_absent(json.dumps(history))
        self.steps = {s["name"]: s for job in history["jobs"] for s in job["steps"]}
        return self.run["status"]

    def test_development_sync_writes_a_private_file_through_the_official_file_behavior(self):
        self.cli("workflow", "validate", WORKFLOW)
        content = self.render({"APP_KEY": self.secret, "APP_ENV": "local"})
        self.assertEqual(self.sync(), "succeeded")
        self.assertEqual((self.base / ".env").read_text(), content)
        self.assertEqual((self.base / ".env").stat().st_mode & 0o777, 0o600)
        self.assertEqual(self.steps["Check the written file"]["status"], "succeeded")
        self.assertFalse(self.calls.exists())
        self.assertEqual(self.sync(), "succeeded")
        self.assertEqual([l for l in self.steps["Read the key names"]["outputs"]["stdout"].split("\n") if l and not l.startswith("#")], ["APP_ENV", "APP_KEY"])

    def test_a_foreign_key_refuses_before_the_write(self):
        (self.base / ".env").write_text("APP_ENV=old\nOLD=1\nexport FOREIGN=kept\n")
        self.render({"APP_KEY": self.secret, "APP_ENV": "local"})
        self.assertEqual(self.sync(success=False), "failed")
        self.assertEqual(self.steps["Check for foreign keys"]["status"], "failed")
        self.assertIn("FOREIGN", self.steps["Read the key names"]["outputs"]["stdout"])
        self.assertNotIn("Write .env, .env.testing", " ".join(k for k, s in self.steps.items() if s["status"] == "succeeded"))
        self.assertEqual((self.base / ".env").read_text(), "APP_ENV=old\nOLD=1\nexport FOREIGN=kept\n")

    def test_testing_writes_the_whole_file_unless_git_tracks_it(self):
        (self.base / ".env.testing").write_text("KEEP=1\n")
        _, text = self.render({"APP_KEY": self.secret}, testing={"DB_CONNECTION": "pgsql", "DB_DATABASE": "app_test", "APP_ENV": "testing"})
        self.assertEqual(self.sync(), "succeeded")
        self.assertEqual((self.base / ".env.testing").read_text(), text)
        self.assertEqual((self.base / ".env.testing").stat().st_mode & 0o777, 0o600)

        (self.base / ".env.testing").write_text("TRACKED=1\n")
        git = ["git", "-c", "user.email=t@t", "-c", "user.name=t", "-C", str(self.base)]
        for args in (["init", "-q"], ["add", ".env.testing"], ["commit", "-qm", "t"]):
            subprocess.run([*git, *args], check=True)
        self.assertEqual(self.sync(), "succeeded")
        self.assertIn("#tracked", self.steps["Read the key names"]["outputs"]["stdout"])
        self.assertEqual((self.base / ".env.testing").read_text(), "TRACKED=1\n")

    def test_a_hash_that_differs_fails_the_end_check(self):
        self.render({"APP_KEY": self.secret})
        self.inputs["intent"]["environment"]["sha256"] = "0" * 64
        self.assertEqual(self.sync(success=False), "failed")
        self.assertEqual(self.steps["Check the written file"]["status"], "failed")

    def test_production_refreshes_the_config_cache_fpm_and_processes_in_order(self):
        application = self.base / "current"
        (application / "bootstrap/cache").mkdir(parents=True)
        (application / "bootstrap/cache/config.php").write_text("<?php return [];")
        (application / "artisan").write_text("")
        php = self.repo / "tools/php8.4"
        php.write_text("#!/bin/sh\necho \"$@\" > " + str(self.base / "artisan-call") + "\n")
        php.chmod(0o700)
        self.inputs["intent"]["environment"]["environment"] = "production"
        self.inputs["intent"]["runtime"] = {
            "config": {"user": pwd.getpwuid(os.getuid()).pw_name, "home": str(self.base), "version": "8.4", "application": str(application)},
            "fpm_service": "php8.4-fpm-orbit-app-7.service",
            "processes": [{"id": 5, "runtime": "systemd", "name": "orbit-process-5-worker.service"}, {"id": 6, "runtime": "docker", "name": "orbit-process-6"}],
        }
        workflow = self.repo / f"workflows/workflow-{WORKFLOW}.yaml"
        self.assertIn("/usr/bin/php", workflow.read_text())
        workflow.write_text(workflow.read_text().replace("/usr/bin/php", str(self.repo / "tools/php")))
        self.render({"APP_KEY": self.secret})
        self.assertEqual(self.sync(), "succeeded")
        self.assertEqual((self.base / "artisan-call").read_text().strip(), "artisan config:cache --no-interaction")
        calls = [json.loads(line) for line in self.calls.read_text().splitlines()]
        self.assertEqual(calls, [["systemctl", "reload", "php8.4-fpm-orbit-app-7.service"],
                                 ["systemctl", "restart", "orbit-process-5-worker.service"], ["docker", "restart", "orbit-process-6"]])


if __name__ == "__main__":
    import unittest

    unittest.main()
