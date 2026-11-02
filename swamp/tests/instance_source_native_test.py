"""Run the native source readers through official SSH in an isolated local fixture."""
import json
import subprocess
import sys

import yaml
from native_harness import ROOT, NativeTestCase


class InstanceSourceNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.cli("extension", "pull", "@swamp/ssh@2026.10.23.1")
        self.copy_behaviors("operations/instance-source-command", "operations/instance-lifecycle", "operations/tools")
        self.fake = self.repo / "ssh"
        self.fake.write_text("#!" + sys.executable + "\n" + '''import shlex,subprocess,sys
argv=shlex.split(sys.argv[-1])
result=subprocess.run(argv,input=sys.stdin.buffer.read(),capture_output=True)
sys.stdout.buffer.write(result.stdout)
sys.stderr.buffer.write(result.stderr)
raise SystemExit(result.returncode)
''')
        self.fake.chmod(0o755)
        self.checkout = self.repo / "checkout"
        self.checkout.mkdir()
        self.git("init", "-q")
        self.git("config", "user.email", "fixture@example.test")
        self.git("config", "user.name", "Fixture")
        self.git("remote", "add", "origin", "https://github.com/example/proof.git")
        (self.checkout / "file").write_text("source\n")
        self.git("add", "file")
        self.git("commit", "-qm", "fixture")
        self.git("branch", "-M", "main")
        (self.checkout / ".env").write_text("PRIVATE_SENTINEL=must-not-leave\n")
        (self.checkout / ".env").chmod(0o644)
        for kind in ("register", "rename"):
            workflow = yaml.safe_load((ROOT / f"workflows/workflow-operation-instance-{kind}.yaml").read_text())
            for job in workflow["jobs"]:
                self.assertEqual(job.pop("labels"), {"role": "swamp"})
                for step in job["steps"]:
                    self.assertEqual(step.pop("labels"), {"role": "swamp"})
                    if step["task"].get("behaviorType") == "@swamp/ssh":
                        step["task"]["globalArgs"]["sshBinary"] = str(self.fake)
            (self.repo / f"workflows/workflow-operation-instance-{kind}.yaml").write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.intent = {"instance_id": 7, "project_id": 8, "checkout": str(self.checkout), "application": str(self.checkout),
                       "repository_identity": "github.com/example/proof", "project_type": "monorepo", "php_versions": ["8.5"],
                       "branch": "main", "kind": "instance.register", "ssh_execution": {"identity_file": "/fixture/identity", "target": {
                           "host": "192.0.2.9", "user": "orbit", "port": 22, "host_keys": ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"]}}}

    def git(self, *argv):
        return subprocess.run(["git", "-C", str(self.checkout), *argv], capture_output=True, check=True).stdout.decode().strip()

    def run_source(self, kind, success=True):
        intent = {**self.intent, "kind": "instance." + kind}
        values = {"operation_id": 7002, "node_id": 9, "kind": "instance." + kind, "intent": intent, "execution_secrets": {}}
        argv = ["workflow", "run", "operation-instance-" + kind]
        for key, value in values.items():
            argv += ["--input", key + "=" + (value if isinstance(value, str) else json.dumps(value))]
        run = json.loads(self.cli(*argv, success=success).stdout)
        return run, json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)

    def stdout(self, history, name):
        return next(step["outputs"]["stdout"] for step in history["jobs"][0]["steps"] if step["name"] == name)

    def test_register_then_rename_reads_public_facts_and_protects_environment(self):
        run, history = self.run_source("register")
        self.assertEqual(run["status"], "succeeded")
        self.assertEqual(self.stdout(history, "Read the commit").strip(), self.git("rev-parse", "HEAD"))
        self.assertEqual(self.stdout(history, "Read the Git top level").strip(), str(self.checkout))
        self.assertEqual(self.stdout(history, "Read the branch").strip(), "main")
        self.assertNotIn("PRIVATE_SENTINEL", json.dumps(history))
        self.assertEqual((self.checkout / ".env").stat().st_mode & 0o777, 0o600)
        run, history = self.run_source("rename")
        self.assertEqual(run["status"], "succeeded")
        self.assertEqual(json.loads(history["jobs"][0]["steps"][-1]["outputs"]["stdout"]), {"branch": "main"})
        self.assertEqual(self.git("branch", "--show-current"), "main")

    def test_authenticated_origin_is_masked_before_it_enters_history(self):
        self.git("remote", "set-url", "origin", "https://PRIVATE_ORIGIN_SENTINEL@github.com/example/proof.git")
        run, history = self.run_source("register")
        self.assertEqual(run["status"], "succeeded")
        self.assertNotIn("PRIVATE_ORIGIN_SENTINEL", json.dumps(history))
        self.assertIn("REDACTED@github.com", self.stdout(history, "Read the origin"))

    def test_a_missing_checkout_fails_before_the_environment_step(self):
        self.intent["checkout"] = str(self.repo / "missing")
        run, history = self.run_source("register", success=False)
        self.assertEqual(run["status"], "failed")
        self.assertEqual(history["jobs"][0]["steps"][-1]["status"], "skipped")
        self.assertEqual((self.checkout / ".env").stat().st_mode & 0o777, 0o644)

    def test_register_reads_the_entry_point_and_composer_json(self):
        composer = json.dumps({"require": {"php": "^8.4", "laravel/framework": "^12.0"}})
        (self.checkout / "composer.json").write_text(composer)
        (self.checkout / "artisan").write_text("#!/usr/bin/env php\n")
        self.git("add", "composer.json", "artisan")
        self.git("commit", "-qm", "laravel")
        self.intent["project_type"] = "laravel-app"
        run, history = self.run_source("register")
        self.assertEqual(run["status"], "succeeded")
        digest, entry_point, *rest = self.stdout(history, "Read the checkout files").split("\n")
        self.assertRegex(digest, "^[a-f0-9]{64}$")
        self.assertEqual((entry_point, "\n".join(rest).strip()), ("regular", composer))
