"""Real Git and disposable GitHub HTTP checks for the shipped publication actions."""

import base64
import importlib.util
import json
import os
import subprocess
import sys
import tempfile
import threading
import unittest
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from unittest.mock import patch

SCRIPTS = Path(__file__).with_name("scripts")
TOKEN = "synthetic-publication-token-no-real-credential"
sys.path.insert(0, str(SCRIPTS))


def module(name):
    spec = importlib.util.spec_from_file_location(name, SCRIPTS / (name + ".py"))
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


class GitHub(BaseHTTPRequestHandler):
    def log_message(self, *_args):
        pass

    def do_GET(self):
        self.respond("GET", self.server.pulls)

    def do_DELETE(self):
        self.respond("DELETE", None)

    def do_POST(self):
        self.server.pulls = [self.server.pull]
        self.respond("POST", {"number": 12})

    def do_PATCH(self):
        self.respond("PATCH", {"number": 12})

    def respond(self, method, value):
        if self.headers.get("Authorization") != "Bearer " + TOKEN:
            self.send_response(401)
            self.end_headers()
            return
        raw = self.rfile.read(int(self.headers.get("Content-Length", 0)))
        self.server.calls.append((method, self.path, json.loads(raw) if raw else None))
        if method == "POST" and self.server.lose_reply:
            self.close_connection = True
            return
        self.send_response(204 if method == "DELETE" else 200)
        self.end_headers()
        if value is not None:
            self.wfile.write(json.dumps(value).encode())


class PublicationActions(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="task-publication-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.work = self.root / "work"
        self.work.mkdir()
        self.git(self.work, "init", "-qb", "task-1")
        self.git(self.work, "config", "user.name", "Test")
        self.git(self.work, "config", "user.email", "test@example.test")
        self.work.joinpath("code").write_text("one\n")
        self.git(self.work, "add", ".")
        self.git(self.work, "commit", "-qm", "fixture")
        self.remote = self.root / "remote.git"
        self.git(self.root, "init", "--bare", "-q", str(self.remote))
        self.head = self.git(self.work, "rev-parse", "HEAD")
        self.tree = self.git(self.work, "rev-parse", "HEAD^{tree}")
        self.value = {
            "checkout_path": str(self.work),
            "commit": self.head,
            "tree": self.tree,
            "repository": "acme/shop",
            "branch": "task-1",
            "base": "main",
            "pull_request": {"title": "Export", "body": "User prose $(touch inert)."},
        }
        self.git(
            self.work, "bundle", "create", str(self.root / "candidate.bundle"), "HEAD"
        )
        self.environ = {
            "ORBIT_PUBLICATION_ROOT": str(self.root),
            "ORBIT_PUBLICATION_INPUT": json.dumps(self.value),
            "ORBIT_GITHUB_TOKEN": TOKEN,
        }
        self.server = ThreadingHTTPServer(("127.0.0.1", 0), GitHub)
        self.server.calls, self.server.pulls, self.server.lose_reply = [], [], False
        self.server.pull = {
            "number": 12,
            "state": "open",
            "head": {
                "ref": "task-1",
                "sha": self.head,
                "repo": {"full_name": "acme/shop"},
            },
            "base": {"ref": "main"},
        }
        threading.Thread(target=self.server.serve_forever, daemon=True).start()
        self.addCleanup(self.server.server_close)
        self.addCleanup(self.server.shutdown)
        self.patches = []
        original_opener = urllib.request.build_opener
        opener = original_opener()
        original_open = opener.open

        def open_local(request, *args, **kwargs):
            request.full_url = request.full_url.replace(
                "https://api.github.com",
                "http://127.0.0.1:" + str(self.server.server_port),
            )
            return original_open(request, *args, **kwargs)

        opener.open = open_local
        self.patches.append(patch("urllib.request.build_opener", return_value=opener))
        original_run = subprocess.run

        def run_git(args, **kwargs):
            env = kwargs.get("env", {})
            self.assertNotIn(TOKEN, json.dumps(args))
            if "https://github.com/acme/shop.git" in args:
                args = [
                    str(self.remote) if v == "https://github.com/acme/shop.git" else v
                    for v in args
                ]
                self.assertNotIn(str(self.work), args)
                if "ls-remote" in args:
                    self.assertEqual(kwargs.get("timeout"), 60)
                self.assertEqual(
                    env["GIT_CONFIG_VALUE_0"],
                    "Authorization: Basic "
                    + base64.b64encode(("x-access-token:" + TOKEN).encode()).decode(),
                )
                kwargs["env"] = {**env, "GIT_ALLOW_PROTOCOL": "file"}
            return original_run(args, **kwargs)

        self.patches.append(patch("subprocess.run", side_effect=run_git))
        for item in self.patches:
            item.start()
            self.addCleanup(item.stop)
        self.assertEqual(self.run_action("publish_import", "load"), {"imported": True})

    def git(self, root, *args):
        return subprocess.check_output(
            ["git", "-C", str(root), *args], stderr=subprocess.DEVNULL, text=True
        ).strip()

    def run_action(self, name, action, **extra):
        with patch.dict(os.environ, {**self.environ, **extra}):
            value = getattr(module(name), action)()
        self.assertNotIn(TOKEN, json.dumps(value))
        return value

    def test_exact_candidate_push_and_identical_tip(self):
        self.assertEqual(self.run_action("publish_tip", "observe"), {"commit": None})
        self.assertEqual(self.run_action("publish_push", "push"), {"pushed": True})
        self.assertEqual(
            self.run_action("publish_tip", "observe"), {"commit": self.head}
        )
        self.assertNotIn(TOKEN, (self.root / "repository.git/config").read_text())

    def test_divergence_is_not_forced(self):
        self.run_action("publish_push", "push")
        self.git(self.work, "checkout", "--orphan", "unrelated")
        self.git(self.work, "commit", "--allow-empty", "-qm", "other root")
        self.value["commit"] = self.git(self.work, "rev-parse", "HEAD")
        self.git(
            self.work, "bundle", "create", str(self.root / "candidate.bundle"), "HEAD"
        )
        self.environ["ORBIT_PUBLICATION_INPUT"] = json.dumps(self.value)
        import shutil

        shutil.rmtree(self.root / "repository.git")
        self.run_action("publish_import", "load")
        from repository_common import Refusal

        with self.assertRaisesRegex(Refusal, "tasks.not_fast_forward"):
            self.run_action("publish_push", "push")
        self.assertEqual(
            self.git(self.remote, "rev-parse", "refs/heads/task-1"), self.head
        )

    def test_create_and_update_keep_caller_prose_and_make_one_mutation(self):
        found = self.run_action("publish_pull_read", "read")
        self.assertEqual(
            self.run_action(
                "publish_pull_write", "write", ORBIT_EXISTING_PULL=json.dumps(found)
            ),
            {"number": 12},
        )
        self.assertEqual(
            self.server.calls[-1][2],
            {**self.value["pull_request"], "head": "task-1", "base": "main"},
        )
        found = self.run_action("publish_pull_read", "read")
        self.run_action(
            "publish_pull_write", "write", ORBIT_EXISTING_PULL=json.dumps(found)
        )
        self.assertEqual(
            [row[0] for row in self.server.calls], ["GET", "POST", "GET", "PATCH"]
        )
        self.assertEqual(self.server.calls[-1][2], self.value["pull_request"])

    def test_lost_create_reply_is_reconciled_by_fresh_lookup(self):
        from repository_common import Refusal

        self.server.lose_reply = True
        found = self.run_action("publish_pull_read", "read")
        with self.assertRaisesRegex(Refusal, "tasks.publish_failed"):
            self.run_action(
                "publish_pull_write", "write", ORBIT_EXISTING_PULL=json.dumps(found)
            )
        self.server.lose_reply = False
        found = self.run_action("publish_pull_read", "read")
        self.run_action(
            "publish_pull_write", "write", ORBIT_EXISTING_PULL=json.dumps(found)
        )
        self.assertEqual(sum(row[0] == "POST" for row in self.server.calls), 1)

    def test_wrong_pr_candidate_refuses_before_update(self):
        from repository_common import Refusal

        self.server.pull["head"]["sha"] = "f" * 40
        self.server.pulls = [self.server.pull]
        with self.assertRaisesRegex(Refusal, "tasks.head_moved"):
            self.run_action("publish_pull_read", "read")
        self.assertEqual([row[0] for row in self.server.calls], ["GET"])

    def test_token_revocation_is_a_distinct_action(self):
        self.assertEqual(self.run_action("publish_revoke", "revoke"), {"revoked": True})
        self.assertEqual(self.server.calls, [("DELETE", "/installation/token", None)])

    def test_workspace_git_config_and_hooks_cannot_run_with_the_token(self):
        marker = self.root / "untrusted-ran"
        hooks = self.root / "hooks"
        hooks.mkdir()
        hook = hooks / "pre-push"
        hook.write_text("#!/bin/sh\ntouch '" + str(marker) + "'\nexit 1\n")
        hook.chmod(0o755)
        self.git(self.work, "config", "core.hooksPath", str(hooks))
        self.git(self.work, "config", "url.ext::false.insteadOf", "https://github.com/")
        self.git(self.work, "config", "credential.helper", "!false")
        self.assertEqual(self.run_action("publish_push", "push"), {"pushed": True})
        self.assertFalse(marker.exists())

    def test_workflow_permission_refusal_retains_its_typed_code(self):
        from repository_common import Refusal

        hook = self.remote / "hooks/pre-receive"
        hook.write_text(
            "#!/bin/sh\necho 'refusing to allow a GitHub App to create workflow .github/workflows/ci.yml' >&2\nexit 1\n"
        )
        hook.chmod(0o755)
        with self.assertRaisesRegex(Refusal, "tasks.workflows_permission"):
            self.run_action("publish_push", "push")

    def test_bundle_candidate_is_verified_before_authenticated_actions(self):
        from repository_common import Refusal

        self.value["commit"] = "f" * 40
        self.environ["ORBIT_PUBLICATION_INPUT"] = json.dumps(self.value)
        with self.assertRaisesRegex(Refusal, "tasks.head_moved"):
            self.run_action("publish_import", "load")
        self.assertEqual(self.server.calls, [])


if __name__ == "__main__":
    unittest.main()
