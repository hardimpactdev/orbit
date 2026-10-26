"""Exercise bundle deployments with real git repositories and a local SSH transport."""
import importlib.machinery
import importlib.util
import contextlib
import io
import json
import os
from pathlib import Path
import shlex
import subprocess
import tempfile
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / "bin/deploy-services"
loader = importlib.machinery.SourceFileLoader("deploy_services", str(SCRIPT))
spec = importlib.util.spec_from_loader(loader.name, loader)
deploy = importlib.util.module_from_spec(spec)
loader.exec_module(deploy)


class DeployTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.origin = self.root / "origin"
        self.source = self.root / "source"
        self.target = self.root / "services"
        self.calls = []
        self.active = []
        self.active_responses = []
        self.events = []
        self.corrupt_bundle = False
        self.wrong_sha = False
        self.fail_update = False
        self.git("init", "-q", "-b", "main", str(self.origin))
        self.git("-C", str(self.origin), "config", "user.name", "Test")
        self.git("-C", str(self.origin), "config", "user.email", "test@example.test")
        (self.origin / "version").write_text("one")
        self.git("-C", str(self.origin), "add", ".")
        self.git("-C", str(self.origin), "commit", "-qm", "First")
        self.git("clone", "-q", str(self.origin), str(self.source))
        (self.source / "bin").mkdir()
        self.target.mkdir()
        (self.target / ".swamp").mkdir()
        (self.target / ".swamp/history").write_text("retained")
        self.process = {"id": 300, "name": "swamp-serve", "runtime": "systemd",
                        "user": "orbit", "working_directory": str(self.target)}
        self.original_run = deploy.run

    def git(self, *args):
        return subprocess.check_output(["git", *args], text=True, stderr=subprocess.PIPE).strip()

    def orbit(self, *args):
        self.calls.append(args[0])
        if args[0].startswith("process:") and args[0] != "process:list":
            self.events.append(args[0])
        if args[0] == "node:show":
            return {"status": "active", "user": "orbit", "wireguard_ip": "10.44.0.3"}
        if args[0] == "process:list":
            return {"processes": [self.process]}
        return {"status": "active"}

    def transport(self, argv, *, source=None):
        if argv[0] == "ssh":
            if source == deploy.CHECK_HOST:
                return ""
            if source == deploy.ACTIVE:
                self.events.append("active")
                runs = self.active_responses.pop(0) if self.active_responses else self.active
                return json.dumps({"runs": runs})
            if source == deploy.PREFLIGHT:
                self.events.append("preflight")
            if source == deploy.UPDATE:
                self.events.append("update")
                if self.fail_update:
                    raise RuntimeError("Checkout update failed")
            if source == deploy.HEALTH:
                return '{"authenticated":true}'
            return self.original_run(shlex.split(argv[-1]), source=source)
        if argv[0] == "scp":
            self.events.append("scp")
            data = b"bad bundle" if self.corrupt_bundle else Path(argv[-2]).read_bytes()
            Path(argv[-1].split(":", 1)[1]).write_bytes(data)
            return ""
        if self.wrong_sha and argv[-2:] == ["rev-parse", "origin/main"]:
            return "0" * 40
        return self.original_run(argv, source=source)

    def execute(self):
        with patch.object(deploy, "APP", str(self.target)), \
                patch.object(deploy, "__file__", str(self.source / "bin/deploy-services")), \
                patch.object(deploy, "orbit", self.orbit), \
                patch.object(deploy, "run", self.transport):
            return deploy.deploy()

    def test_deploys_fetched_main_preserves_state_and_repeats(self):
        # A stale source checkout must still ship the newest origin/main.
        (self.origin / "version").write_text("two")
        self.git("-C", str(self.origin), "commit", "-qam", "Second")
        expected = self.git("-C", str(self.origin), "rev-parse", "HEAD")
        for changed in (True, False):
            self.calls.clear()
            self.events.clear()
            result = self.execute()
            self.assertEqual(result["sha"], expected)
            self.assertEqual(result["changed"], changed)
            self.assertEqual((self.target / "version").read_text(), "two")
            self.assertEqual((self.target / ".swamp/history").read_text(), "retained")
            if changed:
                self.assertEqual(self.calls[-2:], ["process:stop", "process:restart"])
                self.assertLess(self.events.index("scp"), self.events.index("preflight"))
                self.assertEqual(self.events[self.events.index("process:stop") - 1], "active")
            else:
                self.assertNotIn("process:stop", self.calls)
                self.assertNotIn("process:restart", self.calls)
        self.assertEqual(self.git("-C", str(self.target), "ls-files"), "version")

    def test_fast_forward_preserves_unrelated_untracked_model(self):
        self.execute()
        model = self.target / "models/@swamp/git/git-wt-bump-apps-e2e.yaml"
        model.parent.mkdir(parents=True)
        model.write_text("historical model")
        (self.origin / "version").write_text("two")
        self.git("-C", str(self.origin), "commit", "-qam", "Second")
        self.calls.clear()
        result = self.execute()
        self.assertTrue(result["changed"])
        self.assertEqual(self.calls[-2:], ["process:stop", "process:restart"])
        self.assertEqual((self.target / "version").read_text(), "two")
        self.assertEqual(model.read_text(), "historical model")

    def test_readiness_authenticates_through_swamp_without_reading_secret_values(self):
        # This executable stands in for Swamp, which owns token-file handling.
        cli = self.root / "swamp"
        cli.write_text("#!/usr/bin/env python3\nimport json, sys\n"
                       "assert '--server' in sys.argv\n"
                       "assert sys.argv[sys.argv.index('--token-file')+1] == '/private/token'\n"
                       "print(json.dumps({'runs': []}))\n")
        cli.chmod(0o755)
        result = self.original_run(["bash", "-s", "--", str(cli), "/private/token"],
                                   source=deploy.HEALTH)
        self.assertEqual(json.loads(result), {"authenticated": True})
        result = self.original_run(["bash", "-s", "--", str(cli), "/private/token"],
                                   source=deploy.ACTIVE)
        self.assertEqual(json.loads(result), {"runs": []})

    def test_readiness_retries_timeouts_invalid_json_and_failed_commands(self):
        program = deploy.HEALTH.split("<<'HEALTH_PY'\n")[1].split("\nHEALTH_PY")[0]
        output = io.StringIO()
        with patch("sys.argv", ["-", "/swamp", "/private/token"]), \
                patch("subprocess.run", side_effect=[
                    subprocess.TimeoutExpired("swamp", 10),
                    subprocess.CompletedProcess([], 0, "not JSON"),
                    subprocess.CompletedProcess([], 1, ""),
                    subprocess.CompletedProcess([], 0, '{"runs": []}'),
                ]) as command, patch("time.sleep") as sleep, contextlib.redirect_stdout(output):
            exec(compile(program, "readiness", "exec"), {})
        self.assertEqual(json.loads(output.getvalue()), {"authenticated": True})
        self.assertEqual(command.call_count, 4)
        self.assertEqual(sleep.call_count, 3)

    def test_active_run_prevents_stop_or_update(self):
        self.active = [{"id": "busy"}]
        with self.assertRaisesRegex(RuntimeError, "active runs"):
            self.execute()
        self.assertNotIn("process:stop", self.calls)
        self.assertFalse((self.target / ".git").exists())

    def test_wrong_process_account_prevents_remote_work(self):
        self.process["user"] = "root"
        with self.assertRaisesRegex(RuntimeError, "user orbit"):
            self.execute()
        self.assertNotIn("process:stop", self.calls)

    def test_run_starting_during_preflight_prevents_stop(self):
        self.active_responses = [[], [{"id": "just-started"}]]
        with self.assertRaisesRegex(RuntimeError, "active runs"):
            self.execute()
        self.assertNotIn("process:stop", self.calls)
        self.assertFalse((self.target / "version").exists())

    def test_divergent_checkout_keeps_serve_running(self):
        self.execute()
        self.git("-C", str(self.target), "config", "user.name", "Test")
        self.git("-C", str(self.target), "config", "user.email", "test@example.test")
        (self.target / "local").write_text("local commit")
        self.git("-C", str(self.target), "add", "local")
        self.git("-C", str(self.target), "commit", "-qm", "Local")
        self.calls.clear()
        with self.assertRaises(RuntimeError):
            self.execute()
        self.assertNotIn("process:stop", self.calls)
        self.assertNotIn("process:restart", self.calls)
        self.assertEqual((self.target / ".swamp/history").read_text(), "retained")

    def test_bad_bundle_or_wrong_commit_keeps_serve_running(self):
        self.execute()
        for fault in ("corrupt_bundle", "wrong_sha"):
            with self.subTest(fault=fault):
                self.calls.clear()
                setattr(self, fault, True)
                with self.assertRaises(RuntimeError):
                    self.execute()
                setattr(self, fault, False)
                self.assertNotIn("process:stop", self.calls)
                self.assertEqual((self.target / "version").read_text(), "one")

    def test_untracked_collisions_keep_serve_running(self):
        self.execute()
        # Include spaces, newlines, a file blocking a new directory, and a
        # directory whose contents would be replaced by a new file.
        for path, local in (("new model\nname.yaml", "new model\nname.yaml"),
                            ("models/new.yaml", "models"),
                            ("replaced", "replaced/local.yaml")):
            (self.origin / path).parent.mkdir(parents=True, exist_ok=True)
            (self.origin / path).write_text("from main")
        self.git("-C", str(self.origin), "add", ".")
        self.git("-C", str(self.origin), "commit", "-qm", "Add models")
        for local in ("new model\nname.yaml", "models", "replaced/local.yaml"):
            with self.subTest(local=local):
                (self.target / local).parent.mkdir(parents=True, exist_ok=True)
                (self.target / local).write_text("keep local")
                self.calls.clear()
                with self.assertRaisesRegex(RuntimeError, "Untracked path conflicts"):
                    self.execute()
                self.assertNotIn("process:stop", self.calls)
                self.assertEqual((self.target / "version").read_text(), "one")
                self.assertEqual((self.target / local).read_text(), "keep local")
                (self.target / local).unlink()

    def test_first_deploy_preserves_untracked_file_that_main_adds(self):
        (self.target / "version").write_text("keep local")
        with self.assertRaisesRegex(RuntimeError, "Untracked path conflicts"):
            self.execute()
        self.assertNotIn("process:stop", self.calls)
        self.assertEqual((self.target / "version").read_text(), "keep local")

    def test_failure_after_stop_leaves_serve_stopped(self):
        self.fail_update = True
        with self.assertRaisesRegex(RuntimeError, "Checkout update failed"):
            self.execute()
        self.assertIn("process:stop", self.calls)
        self.assertNotIn("process:restart", self.calls)


if __name__ == "__main__":
    unittest.main()
