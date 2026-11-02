"""Native flat DAG against a disposable T3 HTTP API fixture."""

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
        ):
            workflow = yaml.safe_load((ROOT / "workflows" / filename).read_text())
            for job in workflow["jobs"]:
                for step in job["steps"]:
                    self.assertEqual(step.pop("labels"), {"role": "swamp"})
                    self.assertNotIn("labels", step)
            (self.repo / "workflows" / filename).write_text(
                yaml.safe_dump(workflow, sort_keys=False)
            )
        self.secret = "private-admin-" + uuid.uuid4().hex
        self.private = "unrelated-private-response-" + uuid.uuid4().hex
        self.secrets = (self.secret, self.private)
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
        self.fail_link_id = None
        self.invalid_revoke = False
        self.already_absent = False
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
                    row["private"] = case.private
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
                    if case.fail_link or body["id"] == case.fail_link_id:
                        return self.send(500, {"private": case.secret})
                    case.revoked_links.append(body["id"])
                return self.send(200, b"invalid-json" if case.invalid_revoke else {"revoked": not case.already_absent, "private": case.private})

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
        steps = {step['name']: step for job in run['jobs'] for step in job['steps']}
        self.assertEqual(run["status"], "succeeded" if success else "failed", json.dumps(run))
        self.assertEqual(steps['Remove private replies']['status'], 'succeeded')
        self.assertFalse(Path(steps['Create private replies']['outputs']['stdout'].strip()).exists())
        value = steps.get('Read session revocations', {}).get('outputs', {})
        receipt = json.loads(value.get('stdout', '{}'))
        result = {"backend": "revoked", "revoked_sessions": receipt.get('revoked', 0)} if success else {"error_code": "conn.revocation_failed"}
        return run, result

    def test_revokes_only_admitted_sessions_and_links(self):
        self.cli("workflow", "validate", "operation-conn-pairing-revoke")
        _, result = self.run_revoke()
        self.assertEqual(result, {"backend": "revoked", "revoked_sessions": 1})
        self.assertEqual(self.revoked, ["owned"])
        self.assertEqual(self.revoked_links, ["owned-link"])
        self.assertEqual(
            [row["sessionId"] for row in self.sessions], ["self", "foreign"]
        )
        self.assertEqual(self.list_count, 1)

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
            if step["name"].startswith("Revoke unused links-")
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

    def test_no_duplicate_runtime_ownership_read(self):
        self.change_owner = True
        self.run_revoke()
        self.assertEqual(self.list_count, 1)
        self.assertEqual(self.revoked, ["owned"])

    def test_already_absent_reply_does_not_count_a_mutation(self):
        self.already_absent = True
        _, result = self.run_revoke()
        self.assertEqual(result, {"backend": "revoked", "revoked_sessions": 0})

    def test_invalid_success_reply_refuses_and_cleans_up(self):
        self.invalid_revoke = True
        self.run_revoke(success=False)
        self.assertEqual(self.revoked_links, [])

    def test_partial_link_failure_keeps_the_valid_public_completions(self):
        self.intent['links'] = ['first', 'second']
        self.fail_link_id = 'second'
        run, _ = self.run_revoke(success=False)
        steps = {step['name']: step for job in run['jobs'] for step in job['steps']}
        receipt = json.loads(steps['Read link revocations']['outputs']['stdout'])
        self.assertEqual(receipt, {'valid': False, 'completed': [0], 'revoked': 1})

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

    def test_new_run_reconciles_after_partial_effects(self):
        self.fail_link = True
        self.run_revoke(success=False)
        self.assertEqual(self.revoked, ["owned"])
        self.fail_link = False
        _, result = self.run_revoke()
        self.assertEqual(result, {"backend": "revoked", "revoked_sessions": 0})
        self.assertEqual(self.revoked, ["owned"])
        self.assertEqual(self.revoked_links, ["owned-link"])


if __name__ == "__main__":
    unittest.main()
