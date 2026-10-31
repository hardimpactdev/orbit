"""Real provider HTTP actions against a disposable API; no live provider or credentials."""

import contextlib
import importlib.util
import json
import os
import sys
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = ROOT / "extensions/behaviors/operations/task-vms/upcloud-provider/scripts"
sys.path.insert(0, str(SCRIPTS))
import upcloud_common as common

SERVER_ID = "22222222-2222-4222-8222-222222222222"
IMAGE_ID = "11111111-1111-4111-8111-111111111111"
PUBLIC_KEY = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4 tvm-184"


def public_intent():
    return {
        "task_vm_id": 184,
        "node_id": 14,
        "gateway_node_id": 14,
        "provider": "upcloud",
        "provider_id": None,
        "name": "tvm-184",
        "image_fingerprint": IMAGE_ID,
        "recipe_identity": "recipe-278",
        "provider_host_public_key": PUBLIC_KEY,
        "upcloud": {
            "zone": "nl-ams1",
            "plan": "2xCPU-4GB",
            "disk_gb": 20,
            "max_vms": 4,
            "source_template": IMAGE_ID,
            "gateway_public_key": PUBLIC_KEY,
        },
    }


class ProviderApi(contextlib.AbstractContextManager):
    def __init__(self):
        self.calls = []
        self.rows = []
        self.server_record = None
        self.created = 0
        self.started = 0
        self.lose_create_reply = False
        self.pending = False
        self.hide_inventory_after_create = False
        self.inventory_reads_to_hide = 0
        self.http_status = None
        self.received_private = False
        self.create_entered = None
        self.create_release = None
        self.control_entered = None
        self.token = "ucat_synthetic_" + os.urandom(12).hex()
        self.private = (
            "-----BEGIN OPENSSH PRIVATE KEY-----\n"
            + os.urandom(24).hex()
            + "\n-----END OPENSSH PRIVATE KEY-----\n"
        )

    def __enter__(self):
        api = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def do_GET(self):
                self.answer("GET")

            def do_POST(self):
                self.answer("POST")

            def answer(self, method):
                if self.headers.get("Authorization") != "Bearer " + api.token:
                    self.send_response(401)
                    self.end_headers()
                    return
                api.calls.append((method, self.path))
                body = (
                    json.loads(
                        self.rfile.read(int(self.headers.get("Content-Length", "0")))
                    )
                    if method == "POST"
                    else None
                )
                if api.http_status is not None:
                    self.send_response(api.http_status)
                    if api.http_status == 302:
                        self.send_header("Location", "/redirected")
                    self.end_headers()
                    return
                if self.path == "/_test/control":
                    if api.control_entered is not None:
                        api.control_entered.set()
                    value = {"ready": True}
                elif self.path == "/server?label=orbit-task-vm":
                    value = {
                        "servers": {
                            "server": [] if api.inventory_reads_to_hide else api.rows
                        }
                    }
                    api.inventory_reads_to_hide = max(
                        0, api.inventory_reads_to_hide - 1
                    )
                elif self.path == "/plan":
                    value = {
                        "plans": {"plan": [{"name": "2xCPU-4GB", "storage_tier": None}]}
                    }
                elif method == "POST" and self.path == "/server":
                    if api.create_entered is not None and api.created == 0:
                        api.create_entered.set()
                        if not api.create_release.wait(35):
                            raise AssertionError("Create barrier was not released")
                    api.created += 1
                    server = body["server"]
                    api.received_private = (
                        json.loads(server["user_data"].split("\n", 1)[1])["ssh_keys"][
                            "ed25519_private"
                        ]
                        == api.private
                    )
                    api.rows = [{"uuid": SERVER_ID, "title": "tvm-184"}]
                    api.server_record = {
                        "uuid": SERVER_ID,
                        "title": server["title"],
                        "hostname": server["hostname"],
                        "state": "maintenance" if api.pending else "started",
                        "labels": server["labels"],
                        "ip_addresses": {
                            "ip_address": [
                                {
                                    "access": "public",
                                    "family": "IPv4",
                                    "address": "8.8.4.4",
                                }
                            ]
                        },
                    }
                    if api.hide_inventory_after_create:
                        api.inventory_reads_to_hide = 1
                    if api.lose_create_reply:
                        self.connection.shutdown(2)
                        self.connection.close()
                        return
                    value = {"server": {"uuid": SERVER_ID}}
                elif (
                    self.path == "/server/" + SERVER_ID + "/start" and method == "POST"
                ):
                    api.started += 1
                    api.server_record["state"] = "started"
                    value = {}
                elif self.path == "/server/" + SERVER_ID:
                    if api.server_record is None:
                        self.send_response(404)
                        self.end_headers()
                        return
                    value = {"server": api.server_record}
                else:
                    self.send_response(404)
                    self.end_headers()
                    return
                raw = json.dumps(value).encode()
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.end_headers()
                self.wfile.write(raw)

        self.server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self.server.daemon_threads = True
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.origin = f"http://127.0.0.1:{self.server.server_port}/"
        return self

    def __exit__(self, *_args):
        self.server.shutdown()
        self.thread.join(timeout=2)
        self.server.server_close()


def load_action(name):
    spec = importlib.util.spec_from_file_location(
        "upcloud_test_" + name, SCRIPTS / (name + ".py")
    )
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class ProviderScriptsTest(unittest.TestCase):
    def setUp(self):
        self.api = ProviderApi().__enter__()
        self.addCleanup(self.api.__exit__)
        self.values = public_intent()
        self.environ = {
            "ORBIT_UPCLOUD_INTENT": json.dumps(self.values),
            "ORBIT_UPCLOUD_TOKEN": self.api.token,
            "ORBIT_PROVIDER_BOOTSTRAP": json.dumps(
                {"private": self.api.private, "public": PUBLIC_KEY}
            ),
            "ORBIT_UPCLOUD_PLAN": '{"tier":null}',
            "ORBIT_SERVER_ID": SERVER_ID,
        }
        self.environment = patch.dict(os.environ, self.environ)
        self.environment.start()
        self.addCleanup(self.environment.stop)
        self.origin = patch.object(common, "ORIGIN", self.api.origin)
        self.origin.start()
        self.addCleanup(self.origin.stop)

    def create(self):
        os.environ["ORBIT_PROVIDER_BOOTSTRAP"] = self.environ[
            "ORBIT_PROVIDER_BOOTSTRAP"
        ]
        return load_action("create").action()

    def test_create_uses_private_cloud_init_and_returns_only_public_id(self):
        self.assertEqual(self.create(), {"provider_id": SERVER_ID})
        self.assertTrue(self.api.received_private)
        self.assertEqual(self.api.created, 1)
        self.assertEqual(
            self.api.calls,
            [("GET", "/server?label=orbit-task-vm"), ("POST", "/server")],
        )

    def test_lost_create_reply_is_rediscovered_on_resume_without_a_second_write(self):
        self.api.lose_create_reply = True
        self.assertEqual(self.create(), {"request_uncertain": True})
        self.api.lose_create_reply = False
        self.assertEqual(self.create(), {"provider_id": SERVER_ID})
        self.assertEqual(self.api.created, 1)

    def test_budget_and_foreign_same_name_server_refuse_without_mutation(self):
        self.values["upcloud"]["max_vms"] = 1
        os.environ["ORBIT_UPCLOUD_INTENT"] = json.dumps(self.values)
        self.api.rows = [{"uuid": SERVER_ID, "title": "another-vm"}]
        with self.assertRaises(common.Refused):
            self.create()
        self.assertEqual(self.api.created, 0)
        self.api.rows = []
        self.values["upcloud"]["max_vms"] = 4
        os.environ["ORBIT_UPCLOUD_INTENT"] = json.dumps(self.values)
        self.create()
        self.api.server_record["labels"]["label"][0]["value"] = "185"
        with self.assertRaises(common.Refused):
            self.create()
        self.assertEqual(self.api.created, 1)

    def test_existing_owned_server_does_not_need_a_plan_or_bootstrap_value(self):
        self.create()
        self.api.calls.clear()
        os.environ.pop("ORBIT_PROVIDER_BOOTSTRAP", None)
        os.environ.pop("ORBIT_UPCLOUD_PLAN", None)
        self.assertEqual(load_action("create").action(), {"provider_id": SERVER_ID})
        self.assertEqual(
            self.api.calls,
            [("GET", "/server?label=orbit-task-vm"), ("GET", "/server/" + SERVER_ID)],
        )

    def test_start_is_idempotent_and_observation_is_one_read(self):
        self.create()
        self.api.server_record["state"] = "stopped"
        load_action("start").action()
        load_action("start").action()
        self.assertEqual(self.api.started, 1)
        self.api.calls.clear()
        result = load_action("observe").action()
        self.assertTrue(result["running"])
        self.assertTrue(result["settled"])
        self.assertEqual(result["ssh_host_key"], PUBLIC_KEY)
        self.assertEqual(self.api.calls, [("GET", "/server/" + SERVER_ID)])

    def test_pending_or_private_address_fails_observation_without_polling(self):
        self.create()
        self.api.server_record["state"] = "maintenance"
        self.api.calls.clear()
        with self.assertRaises(common.Refused):
            load_action("observe").action()
        self.assertEqual(len(self.api.calls), 1)
        self.api.server_record["state"] = "started"
        self.api.server_record["ip_addresses"]["ip_address"][0]["address"] = "10.0.0.5"
        with self.assertRaises(common.Refused):
            load_action("observe").action()

    def test_redirect_auth_and_server_failure_make_exactly_one_request(self):
        for status in (302, 401, 500):
            self.api.calls.clear()
            self.api.http_status = status
            with self.assertRaises(common.Refused):
                common.inventory(self.values)
            self.assertEqual(self.api.calls, [("GET", "/server?label=orbit-task-vm")])


if __name__ == "__main__":
    unittest.main()
