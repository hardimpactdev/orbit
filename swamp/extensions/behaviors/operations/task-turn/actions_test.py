"""Execute the workflow's shipped action scripts against Git and a Pi HTTP fixture."""

import ast
import hashlib
import json
import os
import subprocess
import sys
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

SCRIPTS = Path(__file__).with_name("scripts")
TOKEN = "synthetic-pi-test-token-with-at-least-32-characters"


class PiHandler(BaseHTTPRequestHandler):
    def log_message(self, *_args):
        pass

    def do_POST(self):
        self.server.calls.append(
            (
                self.path,
                json.loads(self.rfile.read(int(self.headers["Content-Length"]))),
            )
        )
        self.respond({"accepted": True})

    def do_GET(self):
        if self.headers.get("Authorization") != "Bearer " + TOKEN:
            self.send_response(401)
            self.end_headers()
            return
        self.server.calls.append((self.path, None))
        self.send_response(200)
        self.end_headers()
        self.wfile.write(
            (json.dumps({"state": "working", "turnId": "older"}) + "\n").encode()
        )
        if not self.server.disconnect:
            self.wfile.write(
                (
                    json.dumps(
                        {
                            "state": "done",
                            "turnId": "operation-15",
                            "usage": {"total": 4},
                        }
                    )
                    + "\n"
                ).encode()
            )

    def respond(self, value):
        if self.headers.get("Authorization") != "Bearer " + TOKEN:
            self.send_response(401)
            self.end_headers()
            return
        self.send_response(200)
        self.end_headers()
        self.wfile.write(json.dumps(value).encode())


class TurnActions(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="task-turn-native-")
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        self.git("init", "-q")
        self.git("config", "user.email", "test@example.invalid")
        self.git("config", "user.name", "Task fixture")
        (self.repo / "file").write_text("original\n")
        self.git("add", ".")
        self.git("commit", "-qm", "Initial")
        self.head = self.git("rev-parse", "HEAD")
        self.value = {
            "operation_id": 15,
            "new_session": True,
            "group_id": 10,
            "instance_id": 12,
            "node_id": 30,
            "subtask": 11,
            "thread_id": 14,
            "checkout_path": str(self.repo),
            "session_id": "session-14",
            "role": "implementer",
            "candidate": None,
            "deliverables": [],
            "analysis_evidence": None,
        }

    def git(self, *args):
        return subprocess.check_output(
            ["git", *args], cwd=self.repo, text=True, stderr=subprocess.DEVNULL
        ).strip()

    def run_step(self, name, *, ok=True, **extra):
        remote = name in {
            "snapshot_before",
            "snapshot_after",
            "candidate",
            "receipt_prepare",
            "receipt_read",
            "difference",
            "restore",
        }
        script = "snapshot" if name in ("snapshot_before", "snapshot_after") else name
        args = [sys.executable, "-B", str(SCRIPTS / (script + ".py"))]
        if remote and "ORBIT_BEFORE" in extra:
            args.append(extra.pop("ORBIT_BEFORE"))
        result = subprocess.run(
            args,
            input=json.dumps(self.value) if remote else None,
            env={
                **os.environ,
                "ORBIT_TURN_INPUT": json.dumps(self.value),
                **extra,
            },
            text=True,
            capture_output=True,
            check=False,
            timeout=10,
        )
        self.assertNotIn(TOKEN, result.stdout + result.stderr)
        self.assertEqual(result.returncode == 0, ok, result.stderr)
        return (
            json.loads(result.stdout)
            if result.returncode == 0 and result.stdout.strip()
            else result
        )

    def pi_server(self):
        server = ThreadingHTTPServer(("127.0.0.1", 0), PiHandler)
        server.calls, server.disconnect = [], False
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        return server, {
            "ORBIT_PI_TOKEN": TOKEN,
            "ORBIT_PI_URL": "http://127.0.0.1:" + str(server.server_port),
        }

    def test_receipt_helper_collects_real_repository_agent_receipt(self):
        before = self.run_step("snapshot_before")
        self.run_step("candidate", ORBIT_BEFORE=json.dumps(before))
        self.run_step("receipt_prepare")
        subprocess.run(
            [
                str(self.repo / ".git/orbit/turn"),
                "--thread=14",
                "--outcome=ready_for_review",
                "--summary=Implemented",
                "--deliverable=done=evidence",
            ],
            check=True,
        )
        self.assertEqual(
            self.run_step("receipt_read"),
            {
                "thread": 14,
                "outcome": "ready_for_review",
                "summary": "Implemented",
                "deliverables": {"done": "evidence"},
            },
        )

    def test_moved_review_candidate_fails_before_any_pi_call(self):
        before = self.run_step("snapshot_before")
        self.value.update(role="reviewer", candidate="f" * 40)
        self.assertEqual(
            self.run_step("candidate", ORBIT_BEFORE=json.dumps(before)),
            {"admitted": False, "error_code": "tasks.head_moved"},
        )

    def test_reviewer_diff_is_collected_then_exact_candidate_restored(self):
        self.value.update(role="reviewer", candidate=self.head)
        before = self.run_step("snapshot_before")
        (self.repo / "file").write_text("review mutation\n")
        (self.repo / "untracked").write_text("review extra\n")
        self.run_step("snapshot_after")
        difference = self.run_step("difference", ORBIT_BEFORE=json.dumps(before))
        self.assertEqual(difference["summary"]["files"], 2)
        restored = self.run_step("restore")
        self.assertEqual(restored["head"], self.head)
        self.assertEqual((self.repo / "file").read_text(), "original\n")
        self.assertFalse((self.repo / "untracked").exists())

    def test_pi_actions_are_separate_and_stream_ignores_another_turn(self):
        server, env = self.pi_server()
        self.run_step(
            "session_create", **env, ORBIT_PI_BODY=json.dumps({"id": "session-14"})
        )
        self.run_step(
            "message_send",
            **env,
            ORBIT_PI_BODY=json.dumps({"key": "operation-15", "text": "Do the task"}),
        )
        terminal = self.run_step("observe", **env)
        self.assertEqual(terminal["turnId"], "operation-15")
        self.assertEqual(len(server.calls), 3)
        self.run_step("interrupt", **env)
        self.assertEqual(server.calls[-1][0], "/sessions/session-14/interrupt")

    def test_disconnect_without_matching_terminal_event_fails_without_retry(self):
        server, env = self.pi_server()
        server.disconnect = True
        result = self.run_step("observe", ok=False, **env)
        self.assertIn("tasks.pi_completion_unconfirmed", result.stderr)
        self.assertEqual(len(server.calls), 1)

    def test_analysis_hash_and_entrypoint_are_bound_before_pi(self):
        entry = self.repo / "bin/task-analyze"
        entry.parent.mkdir()
        entry.write_text("#!/bin/sh\nprintf report\n")
        entry.chmod(0o755)
        self.git("add", ".")
        self.git("commit", "-qm", "Analyzer")
        self.git("remote", "add", "origin", "https://github.com/acme/test.git")
        before = self.run_step("snapshot_before")
        content = '{"report":"safe"}'
        source = {
            "base": self.head,
            "repository": "https://github.com/acme/test.git",
            "plan_sha256": "d" * 64,
        }
        provenance = {
            **source,
            **before,
            "instance_id": 12,
            "node_id": 30,
            "branch": self.git("symbolic-ref", "--short", "HEAD"),
            "entrypoint_sha256": hashlib.sha256(entry.read_bytes()).hexdigest(),
        }
        self.value["analysis_evidence"] = {
            "input": source,
            "result": {
                "artifact": {
                    "content": content,
                    "bytes": len(content),
                    "sha256": hashlib.sha256(content.encode()).hexdigest(),
                },
                "provenance": provenance,
            },
        }
        self.run_step("candidate", ORBIT_BEFORE=json.dumps(before))
        self.value["analysis_evidence"]["result"]["artifact"]["content"] += "tampered"
        self.assertEqual(
            self.run_step("candidate", ORBIT_BEFORE=json.dumps(before)),
            {"admitted": False, "error_code": "tasks.analysis_stale"},
        )

    def test_receipt_symlink_cannot_escape_into_host_file(self):
        root = self.repo / ".git/orbit"
        root.mkdir()
        destination = self.repo / "protected"
        destination.write_text("keep")
        (root / "turn").symlink_to(destination)
        self.run_step("receipt_prepare", ok=False)
        self.assertEqual(destination.read_text(), "keep")

    def test_receipt_and_difference_sanitize_vm_credential_formats(self):
        credential = "a1" * 32
        before = self.run_step("snapshot_before")
        self.run_step("receipt_prepare")
        (self.repo / ".git/orbit/receipt.json").write_text(
            json.dumps(
                {
                    "thread": 14,
                    "outcome": "ready_for_review",
                    "summary": "key " + credential,
                    "deliverables": {},
                }
            )
        )
        receipt = self.run_step("receipt_read")
        self.assertNotIn(credential, json.dumps(receipt))
        self.assertIn("[REDACTED]", receipt["summary"])
        (self.repo / "file").write_text("MODEL_KEY=" + credential + "\n")
        self.value["role"] = "consult"
        diff = self.run_step("difference", ORBIT_BEFORE=json.dumps(before))
        self.assertNotIn(credential, json.dumps(diff))
        self.assertIn("[REDACTED]", diff["diff"])

    def test_every_shipped_action_compiles(self):
        for path in SCRIPTS.glob("*.py"):
            ast.parse(path.read_text(), filename=str(path))


if __name__ == "__main__":
    unittest.main()
