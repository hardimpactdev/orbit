"""Execute native workflow commands against Git and a Pi HTTP fixture."""

import ast
import json
import os
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

import yaml

SCRIPTS = Path(__file__).with_name("scripts")
TURN = Path(__file__).resolve().parent.parent / "tasks/turn.ts"
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
        if getattr(self.server, "malformed", None):
            self.wfile.write((self.server.malformed + "\n").encode())
        if not self.server.disconnect:
            self.wfile.write(
                (
                    json.dumps(
                        {
                            "state": "done",
                            "turnId": "operation-15",
                            "usage": getattr(self.server, "usage", {"total": 4}),
                        }
                    )
                    + "\n"
                ).encode()
            )

    def respond(self, value):
        if getattr(self.server, "redirect", False):
            self.send_response(302)
            self.send_header("Location", "/credential-destination")
            self.end_headers()
            return
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

    def worker_path(self):
        """The task VM image's tools for 209 commands: sh, curl and awk, and no jq."""
        tools = Path(self.temp.name) / "worker-tools"
        if not tools.exists():
            tools.mkdir()
            for name, choices in {
                "sh": ["sh"],
                "curl": ["curl"],
                "awk": ["mawk", "awk"],
            }.items():
                found = next(filter(None, map(shutil.which, choices)))
                (tools / name).symlink_to(found)
        return str(tools)

    def run_step(self, name, *, ok=True, terminal_ok=True, **extra):
        if name in {"receipt_read", "difference"}:
            return self.reader(
                "receipt" if name == "receipt_read" else "difference",
                {"path": str(self.repo / ".git/orbit/receipt.json")}
                if name == "receipt_read"
                else {
                    "counts": str(self.repo / ".git/orbit/counts"),
                    "patch": str(self.repo / ".git/orbit/patch"),
                },
                ok,
            )
        remote = name == "receipt_prepare"
        args = [sys.executable, "-B", str(SCRIPTS / (name + ".py"))]
        env = {**os.environ, "ORBIT_TURN_INPUT": json.dumps(self.value)}
        terminal = Path(self.temp.name) / "local/terminal.json"
        if name in {"session_create", "message_send", "interrupt", "observe"}:
            flow = yaml.safe_load(
                (
                    Path(__file__).resolve().parents[4]
                    / "workflows/workflow-operation-task-turn.yaml"
                ).read_text()
            )
            step = next(
                s for j in flow["jobs"] for s in j["steps"] if s["name"] == name
            )
            # command/shell runs `sh -c`, without errexit or pipefail.
            args = ["sh", "-c", step["task"]["inputs"]["run"]]
            terminal.parent.mkdir(exist_ok=True)
            env = {
                "PATH": self.worker_path(),
                "ORBIT_PI_PATH": {
                    "session_create": "/sessions",
                    "message_send": "/sessions/session-14/messages",
                    "interrupt": "/sessions/session-14/interrupt",
                }.get(name, ""),
                "ORBIT_PI_SESSION": self.value["session_id"],
                "ORBIT_PI_TURN": "operation-" + str(self.value["operation_id"]),
                "ORBIT_PI_BODY": "{}",
                "ORBIT_TERMINAL": str(terminal),
            }
        result = subprocess.run(
            args,
            input=json.dumps(self.value) if remote else None,
            env={**env, **extra},
            text=True,
            capture_output=True,
            check=False,
            timeout=10,
        )
        self.assertNotIn(TOKEN, result.stdout + result.stderr)
        self.assertEqual(result.returncode == 0, ok, result.stderr)
        if name == "observe" and result.returncode == 0:
            self.assertEqual(result.stdout, "")
            return self.reader(
                "terminal",
                {
                    "path": str(terminal),
                    "turn": "operation-" + str(self.value["operation_id"]),
                },
                terminal_ok,
            )
        return (
            json.loads(result.stdout)
            if result.returncode == 0 and result.stdout.strip()
            else result
        )

    def reader(self, method, args, ok=True):
        """Run one private-file reader of tasks/turn.ts as the worker does."""
        result = subprocess.run(
            [
                "deno",
                "eval",
                (
                    "const [file, method, args] = Deno.args;"
                    "try { console.log(await (await import(file))[method](JSON.parse(args))); }"
                    "catch (error) { console.error(error.message); Deno.exit(1); }"
                ),
                TURN.as_uri(),
                method,
                json.dumps(args),
            ],
            text=True,
            capture_output=True,
            check=False,
            timeout=120,
        )
        self.assertNotIn(TOKEN, result.stdout + result.stderr)
        self.assertEqual(result.returncode == 0, ok, result.stderr)
        return json.loads(result.stdout) if result.returncode == 0 else result

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

    def test_pi_actions_are_separate_and_stream_ignores_another_turn(self):
        server, env = self.pi_server()
        server.usage = {"total": 4, "provider_key": TOKEN}
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
        self.assertEqual(terminal["usage"]["total"], 4)
        self.assertNotIn(TOKEN, json.dumps(terminal))
        self.assertNotIn("provider_key", terminal["usage"])
        self.assertEqual(len(server.calls), 3)
        self.run_step("interrupt", **env)
        self.assertEqual(server.calls[-1][0], "/sessions/session-14/interrupt")

    def test_disconnect_without_matching_terminal_event_fails_without_retry(self):
        server, env = self.pi_server()
        server.disconnect = True
        result = self.run_step("observe", ok=False, **env)
        self.assertIn("tasks.pi_completion_unconfirmed", result.stderr)
        self.assertEqual(len(server.calls), 1)

    def test_pi_redirects_fail_without_forwarding_the_credential(self):
        server, env = self.pi_server()
        server.redirect = True
        self.run_step("session_create", ok=False, **env)
        self.assertEqual([path for path, _ in server.calls], ["/sessions"])

    def test_public_receipt_redacts_private_formats_recursively(self):
        self.run_step("receipt_prepare")
        private = [
            "a1" * 32,
            "user:pass",
            "ghp_" + "a" * 30,
            "github_pat_" + "b" * 30,
            "private-value",
            "pem-private",
        ]
        text = (
            private[0]
            + " https://"
            + private[1]
            + "@example.invalid "
            + private[2]
            + " "
            + private[3]
            + "\nAuthorization: Bearer private-value\n"
            + "MODEL_KEY=private-value\n-----BEGIN PRIVATE KEY-----\npem-private\n-----END PRIVATE KEY-----"
        )
        (self.repo / ".git/orbit/receipt.json").write_text(
            json.dumps({"summary": text, "deliverables": {"key": text}})
        )
        receipt = self.run_step("receipt_read")
        for value in private:
            self.assertNotIn(value, json.dumps(receipt))
        self.assertIn("[REDACTED]", receipt["summary"])
        self.assertIn("key", receipt["deliverables"])

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
        before = {"head": self.head, "tree": self.git("write-tree")}
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
        self.git("add", "--all")
        after = {"head": self.head, "tree": self.git("write-tree")}
        (self.repo / ".git/orbit/counts").write_text(
            self.git("diff", "--numstat", "-z", before["tree"], after["tree"], "--")
        )
        (self.repo / ".git/orbit/patch").write_text(
            self.git("diff", before["tree"], after["tree"], "--")
        )
        diff = self.run_step("difference")
        self.assertNotIn(credential, json.dumps(diff))
        self.assertIn("[REDACTED]", diff["diff"])

    def test_pi_credential_reaches_curl_only_through_its_environment(self):
        server, env = self.pi_server()
        tools = Path(self.temp.name) / "tools"
        tools.mkdir()
        record = Path(self.temp.name) / "curl-argv"
        real = shutil.which("curl")
        (tools / "curl").write_text(
            '#!/bin/sh\nprintf "%s\\n" "$@" >> "' + str(record) + '"\n'
            'exec "' + real + '" "$@"\n'
        )
        (tools / "curl").chmod(0o755)
        path = str(tools) + ":" + self.worker_path()
        for name in ["session_create", "message_send", "observe", "interrupt"]:
            self.run_step(name, **env, PATH=path)
        self.assertEqual(len(server.calls), 4)
        self.assertIn("%ORBIT_PI_TOKEN", record.read_text())
        self.assertNotIn(TOKEN, record.read_text())

    def test_malformed_pi_event_reports_one_fixed_code(self):
        server, env = self.pi_server()
        server.disconnect = True
        server.malformed = '{"state": "done", "turnId": "operation-15", private-pi-text'
        result = self.run_step("observe", terminal_ok=False, **env)
        self.assertEqual(result.stderr, "tasks.pi_event_invalid\n")
        self.assertNotIn("private-pi-text", result.stdout)

    def test_observe_runs_on_the_task_vm_image_tools_against_the_proof_fixture(self):
        # The proof's Swamp worker is a task VM: sh, curl and awk, no jq.
        source = (
            Path(__file__).resolve().parents[4] / "proof/tasks.turn/pi_fixture.py"
        ).read_text()
        listen = 'HTTPServer(("0.0.0.0", 3774), Handler)'
        self.assertIn(listen, source)
        with socket.socket() as probe:
            probe.bind(("127.0.0.1", 0))
            port = probe.getsockname()[1]
        fixture = Path(self.temp.name) / "pi_fixture.py"
        fixture.write_text(
            source.replace(
                listen,
                'turn = "operation-15"\nHTTPServer(("127.0.0.1", '
                + str(port)
                + "), Handler)",
            )
        )
        server = subprocess.Popen([sys.executable, "-I", str(fixture)])
        self.addCleanup(server.wait)
        self.addCleanup(server.kill)
        for _ in range(100):
            with socket.socket() as client:
                if client.connect_ex(("127.0.0.1", port)) == 0:
                    break
            time.sleep(0.05)
        terminal = self.run_step(
            "observe",
            ORBIT_PI_TOKEN=TOKEN,
            ORBIT_PI_URL="http://127.0.0.1:" + str(port),
        )
        self.assertEqual(terminal["state"], "done")
        self.assertEqual(terminal["usage"]["total"], 4)
        self.assertEqual(shutil.which("jq", path=self.worker_path()), None)

    def test_malformed_receipt_and_counts_report_one_fixed_code(self):
        root = self.repo / ".git/orbit"
        root.mkdir()
        (root / "receipt.json").write_text('{"summary": private-receipt-text}')
        result = self.run_step("receipt_read", ok=False)
        self.assertEqual(result.stderr, "tasks.receipt_invalid\n")
        self.assertNotIn("private-receipt-text", result.stdout)
        (root / "counts").write_text("private-count\t1\tfile\0")
        (root / "patch").write_text("")
        result = self.run_step("difference", ok=False)
        self.assertEqual(result.stderr, "tasks.difference_invalid\n")
        self.assertNotIn("private-count", result.stdout)

    def test_every_shipped_action_compiles(self):
        for path in SCRIPTS.glob("*.py"):
            ast.parse(path.read_text(), filename=str(path))


if __name__ == "__main__":
    unittest.main()
