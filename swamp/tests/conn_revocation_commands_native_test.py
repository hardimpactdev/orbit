"""Native parent/child DAG against a disposable T3 HTTP API fixture."""

import json
import sys
import threading
import unittest
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase


class ConnRevocationNative(NativeTestCase):
    native_timeout = 90

    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/conn-revocation")
        for filename in (
            "workflow-operation-conn-pairing-revoke.yaml",
            "workflow-conn-revoke-session.yaml",
        ):
            workflow = yaml.safe_load((ROOT / "workflows" / filename).read_text())
            for job in workflow["jobs"]:
                for step in job["steps"]:
                    self.assertEqual(step.pop("target"), "orbit-node-209")
                    self.assertNotIn("labels", step)
            (self.repo / "workflows" / filename).write_text(
                yaml.safe_dump(workflow, sort_keys=False)
            )
        self.secret = "private-admin-" + uuid.uuid4().hex
        self.secrets = (self.secret,)
        self.sessions = [
            {"sessionId": "owned", "client": {"label": "phone"}},
            {"sessionId": "self", "current": True, "client": {"label": "phone"}},
            {"sessionId": "foreign", "client": {"label": "other"}},
        ]
        self.calls = []
        self.revoked = []
        self.revoked_links = []
        self.fail_list = False
        self.fail_revoke = False
        self.fail_link = False
        self.change_owner = False
        self.disappear = False
        self.list_count = 0
        case = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def send(self, status, body):
                self.send_response(status)
                self.end_headers()
                self.wfile.write(
                    body if isinstance(body, bytes) else json.dumps(body).encode()
                )

            def do_GET(self):
                if self.headers.get("Authorization") != "Bearer " + case.secret:
                    return self.send(401, {"private": case.secret})
                case.calls.append(("GET", self.path))
                case.list_count += 1
                if case.fail_list:
                    return self.send(200, b"invalid-json")
                rows = json.loads(json.dumps(case.sessions))
                if case.list_count > 1:
                    if case.change_owner:
                        rows[0]["client"]["label"] = "other"
                    if case.disappear:
                        rows = [row for row in rows if row["sessionId"] != "owned"]
                for row in rows:
                    row["private"] = case.secret
                return self.send(200, rows)

            def do_POST(self):
                if self.headers.get("Authorization") != "Bearer " + case.secret:
                    return self.send(401, {"private": case.secret})
                case.calls.append(("POST", self.path))
                body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
                if self.path.endswith("clients/revoke"):
                    if case.fail_revoke:
                        return self.send(500, {"private": case.secret})
                    case.revoked.append(body["sessionId"])
                    case.sessions = [
                        row
                        for row in case.sessions
                        if row["sessionId"] != body["sessionId"]
                    ]
                else:
                    if case.fail_link:
                        return self.send(500, {"private": case.secret})
                    case.revoked_links.append(body["id"])
                return self.send(200, {"revoked": True, "private": case.secret})

        server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        self.cli(
            "vault",
            "put",
            "orbit-control-inputs",
            "conn-pairing-revoke-11111111-1111-4111-8111-111111111111",
            input=self.secret,
        )
        self.intent = {
            "url": "http://127.0.0.1:" + str(server.server_port),
            "labels": ["phone"],
            "links": ["owned-link"],
        }
        self.inputs = {
            "operation_id": 7,
            "node_id": 14,
            "kind": "conn.pairing_revoke",
            "intent": self.intent,
            "execution_secrets": {
                "admin_session": {
                    "vault": "orbit-control-inputs",
                    "key": "conn-pairing-revoke-11111111-1111-4111-8111-111111111111",
                }
            },
        }
        self.input_file = self.repo / "public-input.json"

    def run_revoke(self, success=True):
        self.input_file.write_text(json.dumps(self.inputs))
        result = self.cli(
            "workflow",
            "run",
            "operation-conn-pairing-revoke",
            "--input-file",
            str(self.input_file),
            success=success,
        )
        started = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]
        history = self.cli("workflow", "history", "get", started["id"])
        run = json.JSONDecoder().raw_decode(history.stdout.lstrip())[0]
        terminal = next(
            step
            for job in run["jobs"]
            for step in job["steps"]
            if step["name"] == "result"
        )
        self.assertEqual(run["status"], "succeeded" if success else "failed")
        self.assertIn("outputs", terminal, json.dumps(run["jobs"]))
        return run, json.loads(terminal["outputs"]["stdout"])

    def test_revokes_only_admitted_sessions_and_links(self):
        self.cli("workflow", "validate", "operation-conn-pairing-revoke")
        _, result = self.run_revoke()
        self.assertEqual(result, {"backend": "revoked", "revoked_sessions": 1})
        self.assertEqual(self.revoked, ["owned"])
        self.assertEqual(self.revoked_links, ["owned-link"])
        self.assertEqual(
            [row["sessionId"] for row in self.sessions], ["self", "foreign"]
        )
        self.assertEqual(self.list_count, 2)

    def test_multiple_sessions_fan_out_without_shared_result_conflicts(self):
        self.sessions.append({"sessionId": "owned-two", "client": {"label": "phone"}})
        _, result = self.run_revoke()
        self.assertEqual(result, {"backend": "revoked", "revoked_sessions": 2})
        self.assertEqual(sorted(self.revoked), ["owned", "owned-two"])
        self.assertEqual(self.revoked_links, ["owned-link"])

    def test_multiple_links_use_distinct_native_models_at_default_concurrency(self):
        self.intent["links"] = ["first-link", "second-link", "third-link"]
        run, result = self.run_revoke()
        self.assertEqual(result, {"backend": "revoked", "revoked_sessions": 1})
        self.assertEqual(sorted(self.revoked_links), sorted(self.intent["links"]))
        links = [
            step
            for job in run["jobs"]
            for step in job["steps"]
            if step["name"].startswith("link-")
        ]
        self.assertEqual(len(links), 3)
        resources = [
            {
                row["dataId"]
                for row in step["dataArtifacts"]
                if row.get("tags", {}).get("type") == "resource"
            }
            for step in links
        ]
        self.assertTrue(all(resources))
        self.assertEqual(len(set.union(*resources)), sum(map(len, resources)))

    def test_failed_list_is_not_successful_empty_cleanup(self):
        self.fail_list = True
        _, result = self.run_revoke(success=False)
        self.assertEqual(result, {"error_code": "conn.revocation_failed"})
        self.assertEqual(self.revoked, [])
        self.assertEqual(self.revoked_links, [])

    def test_changed_session_owner_refuses_before_effect(self):
        self.change_owner = True
        _, result = self.run_revoke(success=False)
        self.assertEqual(result, {"error_code": "conn.revocation_failed"})
        self.assertEqual(self.revoked, [])
        self.assertEqual(self.revoked_links, [])

    def test_disappeared_session_is_absent_without_another_mutation(self):
        self.disappear = True
        _, result = self.run_revoke()
        self.assertEqual(result, {"backend": "revoked", "revoked_sessions": 0})
        self.assertEqual(self.revoked, [])
        self.assertEqual(self.revoked_links, ["owned-link"])

    def test_http_refusal_cannot_leak_private_response_body(self):
        self.fail_revoke = True
        _, result = self.run_revoke(success=False)
        self.assertEqual(result, {"error_code": "conn.revocation_failed"})
        self.assertEqual(self.revoked_links, [])

    def test_empty_admitted_labels_and_links_make_no_mutation(self):
        self.intent["labels"] = []
        self.intent["links"] = []
        _, result = self.run_revoke()
        self.assertEqual(result, {"backend": "revoked", "revoked_sessions": 0})
        self.assertEqual(self.revoked, [])
        self.assertEqual(self.revoked_links, [])
        self.assertEqual(self.calls, [])

    def test_native_resume_reconciles_after_partial_effects(self):
        self.fail_link = True
        first, _ = self.run_revoke(success=False)
        self.assertEqual(self.revoked, ["owned"])
        self.fail_link = False
        resumed = self.cli(
            "workflow",
            "resume",
            "operation-conn-pairing-revoke",
            "--run",
            first["id"],
            "--from",
            "link",
        )
        run = json.JSONDecoder().raw_decode(resumed.stdout.lstrip())[0]
        self.assertEqual(run["status"], "succeeded")
        self.assertEqual(self.revoked, ["owned"])
        self.assertEqual(self.revoked_links, ["owned-link"])


if __name__ == "__main__":
    unittest.main()
