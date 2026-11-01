"""Native sensitive outputs keep issued credentials out of public history."""

import json
import os
import socket
import subprocess
import sys
import threading
import time
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from tests.native_harness import NativeTestCase

ROOT = Path(__file__).resolve().parents[1]


class ConnPairingNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/conn-pairing")
        self.admin = "native-admin-secret-924318"
        self.credential = "native-issued-secret-482619"
        self.secrets = (self.admin, self.credential)
        self.calls = 0
        self.mode = ""
        owner = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_):
                pass

            def do_POST(self):
                owner.calls += 1
                body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
                if (
                    self.path != "/api/auth/pairing-token"
                    or self.headers.get("Authorization") != "Bearer " + owner.admin
                    or body["label"] != "fixture phone"
                ):
                    self.send_response(401)
                    self.end_headers()
                    return
                self.send_response(503 if owner.mode == "refusal" else 200)
                self.end_headers()
                self.wfile.write(
                    json.dumps(
                        {
                            "id": owner.credential
                            if owner.mode == "echo"
                            else "public-link",
                            "credential": owner.credential,
                            "expiresAt": "2026-11-01T02:00:00Z",
                        }
                    ).encode()
                )

        self.server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.addCleanup(self.close_server)
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        self.key = "conn-pairing-create-00000000-0000-4000-8000-000000000001"
        self.cli("vault", "put", "orbit-control-inputs", self.key, input=self.admin)
        workflow = yaml.safe_load(
            (ROOT / "workflows/workflow-operation-conn-pairing-create.yaml").read_text()
        )
        self.assertEqual(
            workflow["jobs"][0]["steps"][0].pop("target"), "orbit-node-209"
        )
        self.assertNotIn("labels", workflow["jobs"][0]["steps"][0])
        (self.repo / "workflows/workflow-conn-pairing.yaml").write_text(
            yaml.safe_dump(workflow, sort_keys=False)
        )
        self.inputs = {
            "operation_id": 7,
            "node_id": 14,
            "kind": "conn.pairing_create",
            "intent": {
                "url": "http://127.0.0.1:" + str(self.server.server_port),
                "pairing_id": 17,
                "label": "fixture phone",
            },
            "execution_secrets": {
                "admin_session": {"vault": "orbit-control-inputs", "key": self.key}
            },
        }

    def close_server(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join()

    def run_create(self, success=True):
        reply = json.loads(
            self.cli(
                "workflow",
                "run",
                "operation-conn-pairing-create",
                "--input",
                json.dumps(self.inputs),
                success=success,
            ).stdout
        )
        return json.loads(self.cli("workflow", "history", "get", reply["id"]).stdout)

    def test_native_sensitive_output_has_only_reference_and_its_vault_value_matches(
        self,
    ):
        self.cli("workflow", "validate", "operation-conn-pairing-create")
        run = self.run_create()
        self.assertEqual(run["status"], "succeeded")
        self.assertEqual(self.calls, 1)
        output = run["jobs"][0]["steps"][0]["outputs"]
        result = json.loads(output["stdout"])
        self.assertEqual(result["pairing_id"], 17)
        self.assertEqual(result["pairing_link_id"], "public-link")
        self.assertRegex(
            result["credential_ref"]["key"],
            r"^nckrtl-conn-pairing-[a-f0-9-]+-issue-issued-issued-credential$",
        )
        self.assertEqual(
            output["credential"],
            "${{ vault.get('orbit-control-inputs', '"
            + result["credential_ref"]["key"]
            + "') }}",
        )
        # Intentional private test read: verify bytes in memory, never pass them to
        # argv, a fixture file, an assertion diagnostic or normal native outputs.
        env = dict(os.environ, SWAMP_NO_UPDATE_CHECK="1", SWAMP_NO_TELEMETRY="1")
        for key in (
            "SWAMP_SERVER_URL",
            "SWAMP_SERVE_URL",
            "SWAMP_SERVER_TOKEN",
            "SWAMP_SERVER_TOKEN_FILE",
            "SWAMP_REPO_DIR",
            "SWAMP_CA_CERT",
            "SWAMP_EXTENSIONS_DIR",
        ):
            env.pop(key, None)
        read = subprocess.run(
            [
                os.environ.get("SWAMP_BIN", "swamp"),
                "vault",
                "read-secret",
                "orbit-control-inputs",
                result["credential_ref"]["key"],
                "--json",
            ],
            cwd=self.repo,
            env=env,
            capture_output=True,
            text=True,
            check=True,
        )
        self.assertTrue(
            json.loads(read.stdout)["value"] == self.credential,
            "Native sensitive vault value did not match",
        )
        self.assert_private_absent(json.dumps(run))

    def test_refused_issue_never_reports_a_credential_or_retries(self):
        self.mode = "refusal"
        run = self.run_create(False)
        self.assertEqual(run["status"], "failed")
        self.assertEqual(self.calls, 1)
        self.assertNotIn("stdout", run["jobs"][0]["steps"][0].get("outputs", {}))

    def test_secret_echo_in_public_metadata_refuses_before_native_persistence(self):
        self.mode = "echo"
        run = self.run_create(False)
        self.assertEqual(run["status"], "failed")
        self.assertEqual(self.calls, 1)
        self.assertFalse(
            [
                row
                for row in run["jobs"][0]["steps"][0].get("dataArtifacts", [])
                if row.get("tags", {}).get("type") == "resource"
            ]
        )

    def test_remote_worker_protects_generated_output_before_public_result(self):
        self.native_timeout = 120
        path = self.repo / "workflows/workflow-conn-pairing.yaml"
        workflow = yaml.safe_load(path.read_text())
        workflow["jobs"][0]["steps"][0]["target"] = "orbit-node-209"
        path.write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.cli(
            "access", "token", "mint", "fixture-admin", "--principal", "user:fixture"
        )
        admin = self.repo / "admin"
        admin.write_text(
            json.loads(
                self.cli("access", "token", "reveal", "fixture-admin", "--yes").stdout
            )["token"]
        )
        admin.chmod(0o600)
        with socket.socket() as listener:
            listener.bind(("127.0.0.1", 0))
            port = listener.getsockname()[1]
        url = f"ws://127.0.0.1:{port}"
        binary = os.environ.get("SWAMP_BIN", "swamp")
        env = dict(os.environ, SWAMP_NO_UPDATE_CHECK="1", SWAMP_NO_TELEMETRY="1")
        for key in (
            "SWAMP_SERVER_URL",
            "SWAMP_SERVE_URL",
            "SWAMP_SERVER_TOKEN",
            "SWAMP_SERVER_TOKEN_FILE",
            "SWAMP_REPO_DIR",
            "SWAMP_CA_CERT",
            "SWAMP_EXTENSIONS_DIR",
        ):
            env.pop(key, None)
        serve = worker = None
        with (
            (self.repo / "serve.log").open("w") as log,
            (self.repo / "worker.log").open("w") as worker_log,
        ):
            try:
                serve = subprocess.Popen(
                    [
                        binary,
                        "serve",
                        "--host",
                        "127.0.0.1",
                        "--port",
                        str(port),
                        "--auth-mode",
                        "token",
                        "--admins",
                        "user:fixture",
                        "--remote-only",
                    ],
                    cwd=self.repo,
                    env=env,
                    stdout=log,
                    stderr=log,
                )
                for _ in range(100):
                    try:
                        urllib.request.urlopen(
                            f"http://127.0.0.1:{port}/ready", timeout=1
                        ).close()
                        break
                    except OSError:
                        self.assertIsNone(
                            serve.poll(), (self.repo / "serve.log").read_text()[-2000:]
                        )
                        time.sleep(0.1)
                flags = ("--server", url, "--token-file", str(admin))
                enrollment = self.repo / "enrollment"
                enrollment.write_text(
                    json.loads(
                        self.cli(
                            "worker",
                            "token",
                            "create",
                            "orbit-node-209",
                            "--duration",
                            "10m",
                            *flags,
                        ).stdout
                    )["token"]
                )
                enrollment.chmod(0o600)
                self.cli(
                    "access",
                    "token",
                    "mint",
                    "fixture-worker",
                    "--principal",
                    "worker:orbit-node-209",
                    *flags,
                )
                token = self.repo / "worker-server"
                token.write_text(
                    json.loads(
                        self.cli(
                            "access", "token", "reveal", "fixture-worker", "--yes"
                        ).stdout
                    )["token"]
                )
                token.chmod(0o600)
                worker = subprocess.Popen(
                    [
                        binary,
                        "worker",
                        "connect",
                        url,
                        "--token-file",
                        str(enrollment),
                        "--server-token-file",
                        str(token),
                        "--cache-dir",
                        str(self.repo / "worker-cache"),
                    ],
                    cwd=self.repo,
                    env=env,
                    stdout=worker_log,
                    stderr=worker_log,
                )
                result = json.loads(
                    self.cli(
                        "workflow",
                        "run",
                        "operation-conn-pairing-create",
                        "--input",
                        json.dumps(self.inputs),
                        *flags,
                    ).stdout
                )
                history = json.loads(
                    self.cli("workflow", "history", "get", result["id"], *flags).stdout
                )
                self.assertEqual(history["status"], "succeeded")
                output = json.loads(history["jobs"][0]["steps"][0]["outputs"]["stdout"])
                self.assertEqual(output["pairing_id"], 17)
                self.assertTrue(
                    output["credential_ref"]["key"].startswith("nckrtl-conn-pairing-")
                )
                self.assertEqual(
                    history["jobs"][0]["steps"][0]["outputs"]["credential"],
                    "${{ vault.get('orbit-control-inputs', '"
                    + output["credential_ref"]["key"]
                    + "') }}",
                )
                self.assertEqual(self.calls, 1)
                self.assert_private_absent(json.dumps(history))
            finally:
                for process in (worker, serve):
                    if process is not None:
                        process.terminate()
                        try:
                            process.wait(timeout=10)
                        except subprocess.TimeoutExpired:
                            process.kill()
                            process.wait(timeout=5)


if __name__ == "__main__":
    import unittest

    unittest.main()
