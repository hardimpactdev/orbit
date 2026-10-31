"""Execute the shipped inline router actions against an actual local HTTP fixture."""
import copy
import http.server
import json
import os
import subprocess
import threading
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]


class Router:
    model = "1" * 64
    anchor = "2" * 64
    other = "3" * 64
    management = "synthetic-management-key"

    def __init__(self, keys=None):
        self.keys = list(keys if keys is not None else [self.anchor, self.other])
        self.applied = self.keys.copy()
        self.calls = []
        self.false_ack = False
        self.drop_other = False
        self.apply = True
        self.anonymous_status = 401
        self.requests = []
        self.on_request = lambda: None
        self.barrier = lambda: None

    def __enter__(self):
        owner = self

        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def do_GET(self):
                owner.requests.append(("GET", self.path))
                owner.on_request()
                if self.path == "/_test/held":
                    owner.barrier()
                    self.reply(200)
                elif self.path == "/v0/management/api-keys":
                    if self.headers.get("Authorization") != "Bearer " + owner.management:
                        self.reply(401)
                    else:
                        self.reply(200, {"api-keys": owner.keys})
                elif self.path == "/v1/models":
                    token = self.headers.get("Authorization", "").removeprefix("Bearer ")
                    status = owner.anonymous_status if not token else (200 if token in owner.applied else 401)
                    self.reply(status)
                else:
                    self.reply(404)

            def do_PATCH(self):
                self.change("PATCH")

            def do_PUT(self):
                self.change("PUT")

            def change(self, method):
                if self.path != "/v0/management/api-keys" or self.headers.get("Authorization") != "Bearer " + owner.management:
                    self.reply(403)
                    return
                body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
                owner.requests.append((method, self.path))
                owner.calls.append((method, copy.deepcopy(body)))
                if not owner.false_ack:
                    if method == "PUT":
                        owner.keys = body.copy()
                    elif body["old"] in owner.keys:
                        owner.keys[owner.keys.index(body["old"])] = body["new"]
                    else:
                        owner.keys.append(body["new"])
                    if owner.drop_other:
                        owner.keys = [key for key in owner.keys if key != owner.other]
                    if owner.apply:
                        owner.applied = owner.keys.copy()
                self.reply(200)

            def reply(self, status, body=None):
                data = json.dumps(body or {}).encode()
                self.send_response(status)
                self.send_header("Content-Length", str(len(data)))
                self.end_headers()
                self.wfile.write(data)

        self.server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.origin = "http://127.0.0.1:" + str(self.server.server_port)
        return self

    def __exit__(self, *_args):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join()

    def environment(self):
        return {"ORBIT_TASK_VM_ID": "42", "ORBIT_MODEL_ORIGIN": self.origin,
                "ORBIT_MODEL_KEY": self.model, "ORBIT_MODEL_ANCHOR": self.anchor,
                "ORBIT_MODEL_MANAGEMENT": self.management}


def workflow(action):
    return yaml.safe_load((ROOT / "workflows" / f"workflow-task-vm-model-{action}.yaml").read_text())


def execute(router, action, step):
    definition = next(value for value in workflow(action)["jobs"][0]["steps"] if value["name"] == step)
    return subprocess.run(["/bin/sh", "-c", definition["task"]["inputs"]["run"]],
                          env={**os.environ, **router.environment()}, text=True,
                          capture_output=True, timeout=15, check=False)


class ModelShellTest(unittest.TestCase):
    def assert_redacted(self, router, result):
        for secret in (router.model, router.anchor, router.management, router.other):
            self.assertNotIn(secret, result.stdout + result.stderr)

    def test_revoke_removes_duplicates_without_tombstones_and_is_idempotent(self):
        with Router([Router.anchor, Router.model, Router.other, Router.model]) as router:
            result = execute(router, "revoke", "key-revoke")
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(router.keys, [router.anchor, router.other])
            self.assertEqual(router.calls, [("PUT", [router.anchor, router.other])])
            self.assert_redacted(router, result)
            again = execute(router, "revoke", "key-revoke")
            self.assertEqual(again.returncode, 0, again.stderr)
            self.assertEqual(len(router.calls), 1)

    def test_issue_preserves_unrelated_keys_and_is_idempotent(self):
        with Router() as router:
            result = execute(router, "issue", "key-issue")
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(router.keys, [router.anchor, router.other, router.model])
            self.assertEqual(router.calls, [("PATCH", {"old": router.model, "new": router.model})])
            self.assert_redacted(router, result)
            self.assertEqual(execute(router, "issue", "key-issue").returncode, 0)
            self.assertEqual(len(router.calls), 1)

    def test_anchor_repair_only_adds_the_anchor(self):
        with Router([Router.other]) as router:
            result = execute(router, "revoke", "anchor-ensure")
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(router.keys, [router.other, router.anchor])
            self.assertEqual(router.calls, [("PATCH", {"old": router.anchor, "new": router.anchor})])

    def test_false_acknowledgement_and_unrelated_key_loss_fail_redacted(self):
        for fault in ("false_ack", "drop_other"):
            with self.subTest(fault=fault), Router([Router.anchor, Router.model, Router.other]) as router:
                setattr(router, fault, True)
                result = execute(router, "revoke", "key-revoke")
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(result.stderr.strip(), "task_vm.model_key_failed")
                self.assert_redacted(router, result)

    def test_anchor_can_never_be_revoked(self):
        with Router() as router:
            router.model = router.anchor
            result = execute(router, "revoke", "key-revoke")
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(router.calls, [])
            self.assertEqual(router.keys, [router.anchor, router.other])
            self.assert_redacted(router, result)

    def test_delayed_access_fails_one_probe_then_passes_without_repeating_write(self):
        with Router([Router.anchor, Router.model, Router.other]) as router:
            router.apply = False
            self.assertEqual(execute(router, "revoke", "key-revoke").returncode, 0)
            stale = execute(router, "revoke", "key-access")
            self.assertNotEqual(stale.returncode, 0)
            router.applied = router.keys.copy()
            self.assertEqual(execute(router, "revoke", "key-access").returncode, 0)
            self.assertEqual(len(router.calls), 1)
            self.assert_redacted(router, stale)

    def test_anonymous_check_never_accepts_open_access(self):
        with Router() as router:
            router.anonymous_status = 200
            result = execute(router, "issue", "anonymous-before")
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(router.calls, [])

    def test_full_list_refuses_issue_and_anchor_append_before_mutating(self):
        for action, step, keys in [("issue", "key-issue", [Router.anchor] + [Router.other] * 4095),
                                   ("revoke", "anchor-ensure", [Router.other] * 4096)]:
            with self.subTest(step=step), Router(keys) as router:
                result = execute(router, action, step)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(router.keys, keys)
                self.assertEqual(router.calls, [])
                self.assert_redacted(router, result)

    def test_issue_at_the_last_available_slot_remains_readable_and_revocable(self):
        with Router([Router.anchor] + [Router.other] * 4094) as router:
            self.assertEqual(execute(router, "issue", "key-issue").returncode, 0)
            self.assertEqual(len(router.keys), 4096)
            self.assertEqual(execute(router, "revoke", "key-revoke").returncode, 0)
            self.assertEqual(len(router.keys), 4095)

    def test_parent_result_contains_only_vm_identity_and_success(self):
        with Router() as router:
            for action, flag in [("issue", "issued"), ("revoke", "revoked")]:
                result = execute(router, action, "result")
                self.assertEqual(json.loads(result.stdout), {"task_vm_id": 42, flag: True})
                self.assert_redacted(router, result)

    def test_redirect_is_not_followed_and_origin_credentials_are_refused(self):
        with Router() as router:
            router.origin += "/forbidden"
            result = execute(router, "issue", "key-issue")
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(router.calls, [])


if __name__ == "__main__":
    unittest.main()
