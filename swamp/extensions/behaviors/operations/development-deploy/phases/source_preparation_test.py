"""F9 source parity against real disposable git repositories; no fleet access."""

import contextlib
import http.server
import io
import json
import os
import pwd
import subprocess
import sys
import tempfile
import threading
import unittest
import urllib.error
from functools import partial
from pathlib import Path
from runpy import run_path
from unittest.mock import patch

SCRIPT = Path(__file__).with_name("source_preparation.py")


class QuietFiles(http.server.SimpleHTTPRequestHandler):
    def log_message(self, *_):
        pass


class SourceParity(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="deploy-f9-", dir=Path.home())
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.repo = self.root / "checkout"
        self.repo.mkdir()
        self.git("init", "--initial-branch=main")
        self.git("config", "user.name", "Proof")
        self.git("config", "user.email", "proof@example.test")
        (self.repo / "app").write_text("first")
        (self.repo / "removed").mkdir()
        (self.repo / "removed/tracked").write_text("old")
        self.git("add", ".")
        self.git("commit", "-m", "first")
        self.first = self.git("rev-parse", "HEAD")
        (self.repo / "app").write_text("second")
        self.git("rm", "removed/tracked")
        self.git("commit", "-am", "second")
        self.second = self.git("rev-parse", "HEAD")
        subprocess.run(["git", "clone", "--bare", str(self.repo), str(self.root / "fixture.git")],
                       check=True, capture_output=True)
        subprocess.run(["git", "--git-dir", str(self.root / "fixture.git"), "update-server-info"], check=True)
        server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), partial(QuietFiles, directory=str(self.root)))
        worker = threading.Thread(target=server.serve_forever, daemon=True)
        worker.start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        self.url = f"http://127.0.0.1:{server.server_port}/fixture.git"
        self.git("remote", "add", "origin", self.url)
        self.git("reset", "--hard", self.first)
        self.intent = {"commit": None, "github_mint": False, "deployment": {
            "variant": "development", "node_user": pwd.getpwuid(os.getuid()).pw_name,
            "home": str(self.repo), "repository": self.url, "branch": "main", "protocols": ["https"]}}

    def git(self, *args):
        return subprocess.check_output(["git", "-C", str(self.repo), *args], stderr=subprocess.DEVNULL, text=True).strip()

    def run_phase(self):
        process = subprocess.run([sys.executable, str(SCRIPT), json.dumps({"intent": self.intent,
                                  "operation_id": 1, "run_id": "81ad824c-8fbc-4ff1-ac13-b4c1a8cc1b48"})],
                                 capture_output=True, text=True, timeout=10, check=False)
        self.assertIn(process.returncode, [0, 1], process.stderr)
        result = json.loads(process.stdout)
        self.assertEqual(process.returncode == 0, result["ready"])
        return result

    def test_one_phase_fetches_checks_and_checks_out_the_default_branch(self):
        result = self.run_phase()
        self.assertEqual(result, {"ready": True, "commit": self.second})
        self.assertEqual(self.git("rev-parse", "HEAD"), self.second)
        self.assertEqual(self.git("branch", "--show-current"), "main")

    def test_pinned_ancestor_keeps_exact_pin_not_latest_head(self):
        self.intent["commit"] = self.first
        self.assertEqual(self.run_phase()["commit"], self.first)
        self.assertEqual(self.git("rev-parse", "HEAD"), self.first)

    def test_pin_not_on_fetched_branch_refuses_without_checkout(self):
        self.intent["commit"] = "f" * 40
        result = self.run_phase()
        self.assertEqual(result["error_code"], "deployment.commit_not_on_branch")
        self.assertEqual(result["commit"], "f" * 40)
        self.assertEqual(self.git("rev-parse", "HEAD"), self.first)

    def test_dirty_checkout_refuses_without_discarding_edits(self):
        (self.repo / "app").write_text("user change")
        result = self.run_phase()
        self.assertEqual(result["error_code"], "deployment.checkout_dirty")
        self.assertEqual(result["commit"], self.second)
        self.assertEqual((self.repo / "app").read_text(), "user change")
        self.assertEqual(self.git("rev-parse", "HEAD"), self.first)

    def test_diverged_branch_refuses_without_discarding_commits(self):
        (self.repo / "app").write_text("local")
        self.git("commit", "-am", "local")
        local = self.git("rev-parse", "HEAD")
        self.assertEqual(self.run_phase()["error_code"], "deployment.branch_diverged")
        self.assertEqual(self.git("rev-parse", "HEAD"), local)

    def test_foreign_origin_refuses_before_fetch(self):
        self.git("remote", "set-url", "origin", "https://foreign.invalid/repo.git")
        self.assertEqual(self.run_phase()["error_code"], "deployment.repository_mismatch")
        self.assertEqual(self.git("rev-parse", "HEAD"), self.first)

    def test_cleanup_removes_only_nested_files_in_removed_directories(self):
        (self.repo / "removed/cache").mkdir()
        (self.repo / "removed/cache/file").write_text("remove")
        (self.repo / "node_modules").mkdir()
        (self.repo / "node_modules/keep").write_text("keep")
        (self.repo / "top-level").write_text("keep")
        self.assertTrue(self.run_phase()["ready"])
        self.assertFalse((self.repo / "removed/cache").exists())
        self.assertTrue((self.repo / "node_modules/keep").exists())
        self.assertTrue((self.repo / "top-level").exists())

    def test_minted_token_fetch_normalizes_both_github_ssh_origin_forms(self):
        self.intent.update(github_mint=True, github_repository="acme/private")
        grant = {"data": {"github": {"repository": "acme/private", "permissions": {"contents": "read"},
                                     "token": "ghs_fixture-sentinel"}}}
        missing = urllib.error.HTTPError("https://api.github.com/repos/acme/private", 404, "Not Found", {}, None)
        with patch("urllib.request.OpenerDirector.open", side_effect=[missing, io.BytesIO(json.dumps(grant).encode())]):
            env = run_path(str(SCRIPT))["github_environment"]({"intent": self.intent, "operation_id": 1, "run_id": "fixture"})
        env.update(GIT_CONFIG_NOSYSTEM="1", GIT_CONFIG_GLOBAL="/dev/null")
        for origin in ("git@github.com:acme/private.git", "ssh://git@github.com/acme/private.git"):
            result = subprocess.run(["git", "ls-remote", "--get-url", origin], env=env,
                                    capture_output=True, text=True, check=True)
            self.assertEqual(result.stdout.strip(), "https://github.com/acme/private.git")
            self.assertNotIn("ghs_fixture-sentinel", result.stdout + result.stderr)


if __name__ == "__main__":
    with contextlib.redirect_stderr(sys.stderr):
        unittest.main()
