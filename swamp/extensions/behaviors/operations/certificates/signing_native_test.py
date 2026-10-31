"""Real native shell -> disposable HTTPS Gateway boundary -> public typed result."""

import base64
import http.server
import importlib.util
import json
import shutil
import ssl
import subprocess
import tempfile
import threading
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
spec = importlib.util.spec_from_file_location(
    "native_harness", ROOT / "tests/native_harness.py"
)
harness = importlib.util.module_from_spec(spec)
spec.loader.exec_module(harness)
NativeTestCase = harness.NativeTestCase


class GatewaySigningNativeTest(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.real_certificate_signing = True
        self.copy_behaviors(
            "automatic-deploy", "operations/_lib", "operations/certificates"
        )
        local = self.repo / "extensions/behaviors/operations/routes"
        local.mkdir(parents=True)
        for name in ("local-program.ts", "caddy-program.ts"):
            shutil.copy(ROOT / "extensions/behaviors/operations/routes" / name, local)
        self.gateway_home = tempfile.TemporaryDirectory(prefix="fixture-gateway-ca-")
        self.addCleanup(self.gateway_home.cleanup)
        custody = Path(self.gateway_home.name)
        self.key = custody / "https.key"
        self.cert = custody / "https.pem"
        subprocess.run(
            [
                "openssl",
                "req",
                "-x509",
                "-newkey",
                "rsa:2048",
                "-nodes",
                "-keyout",
                str(self.key),
                "-out",
                str(self.cert),
                "-days",
                "1",
                "-subj",
                "/CN=localhost",
                "-addext",
                "subjectAltName=IP:127.0.0.1",
            ],
            capture_output=True,
            check=True,
        )
        self.secrets = (self.key.read_text(),)
        self.calls = []
        self.status = 200
        pem = base64.b64encode(
            b"-----BEGIN CERTIFICATE-----\npublic\n-----END CERTIFICATE-----\n"
        ).decode()
        self.signed = {
            "node_id": 9,
            "scope": "route-7-router",
            "certificate_base64": pem,
            "root_certificate_base64": pem,
        }
        owner = self

        class Handler(http.server.BaseHTTPRequestHandler):
            def do_POST(self):
                body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
                owner.calls.append((self.path, body))
                self.send_response(owner.status)
                self.send_header("Content-Type", "application/json")
                if owner.status == 302:
                    self.send_header("Location", "/forbidden-redirect")
                self.end_headers()
                self.wfile.write(
                    json.dumps({"data": {"certificates": [owner.signed]}}).encode()
                )

            def log_message(self, *_args):
                pass

        self.server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        tls.load_cert_chain(self.cert, self.key)
        self.server.socket = tls.wrap_socket(self.server.socket, server_side=True)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.addCleanup(self.stop_server)
        model = yaml.safe_load(
            (
                ROOT / "models/@nckrtl/certificates/orbit-certificate-signing-http.yaml"
            ).read_text()
        )
        script = model["globalArguments"]["run"]
        self.production_script = script
        self.script = script.replace(
            "https://gateway.orbit", f"https://127.0.0.1:{self.server.server_port}"
        )
        self.script = self.script.replace(
            "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt", str(self.cert)
        )
        model["globalArguments"]["run"] = self.script
        (self.repo / "models/@nckrtl/certificates/fixture-http.yaml").write_text(
            yaml.safe_dump(model)
        )
        self.leaf = {
            "node_id": 9,
            "scope": "route-7-router",
            "user": "orbit",
            "domain": "private.test",
            "version": "b" * 16,
            "root_hash": "c" * 64,
            "agent_sha256": "a" * 64,
        }
        self.request = {
            "node_id": 9,
            "scope": self.leaf["scope"],
            "state": "request",
            "request_base64": base64.b64encode(b"public CSR").decode(),
        }

    def stop_server(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=5)

    def shell(self, operation="19", requests=None, script=None):
        env = {
            "PATH": "/usr/bin:/bin",
            "ORBIT_CERTIFICATE_OPERATION_ID": operation,
            "ORBIT_CERTIFICATE_RUN_ID": "a075eb23-7ac4-4487-80d1-57a038a92676",
            "ORBIT_CERTIFICATE_MODE": "batch",
            "ORBIT_CERTIFICATE_REQUESTS": json.dumps(
                [requests if requests is not None else [self.request]]
            ),
        }
        return subprocess.run(
            ["/bin/sh"],
            input=script or self.script,
            env=env,
            text=True,
            capture_output=True,
            timeout=10,
        )

    def test_native_shell_calls_signer_once_then_pure_decoder_records_public_output(
        self,
    ):
        args = {
            "operation_id": 19,
            "kind": "certificate.converge",
            "node_id": 9,
            "run_id": "${{ run.id }}",
            "leaves": [self.leaf],
            "requests": [[self.request]],
        }
        steps = [
            {
                "name": "input",
                "task": {
                    "type": "behavior",
                    "behaviorType": "@nckrtl/certificates",
                    "behaviorName": "orbit-certificate-signing",
                    "methodName": "prepareSignBatch",
                    "inputs": args,
                },
            },
            {
                "name": "http",
                "dependsOn": [{"step": "input", "condition": {"type": "succeeded"}}],
                "task": {
                    "type": "behavior",
                    "behaviorType": "command/shell",
                    "behaviorName": "orbit-certificate-signing-http",
                    "methodName": "execute",
                    "inputs": {
                        "run": (
                            '${{ model["orbit-certificate-signing-http"]'
                            ".input.globalArguments.run }}"
                        ),
                        "env": {
                            "ORBIT_CERTIFICATE_OPERATION_ID": "19",
                            "ORBIT_CERTIFICATE_MODE": "batch",
                            "ORBIT_CERTIFICATE_RUN_ID": "${{ run.id }}",
                            "ORBIT_CERTIFICATE_REQUESTS": (
                                "${{ steps.input.outputs.requests_json }}"
                            ),
                        },
                    },
                },
            },
            {
                "name": "decode",
                "dependsOn": [{"step": "http", "condition": {"type": "succeeded"}}],
                "task": {
                    "type": "behavior",
                    "behaviorType": "@nckrtl/certificates",
                    "behaviorName": "orbit-certificate-signing",
                    "methodName": "signBatch",
                    "inputs": {
                        **args,
                        "native_stdout": "${{ steps.http.outputs.stdout }}",
                    },
                },
            },
        ]
        workflow = {
            "id": "ac93c20d-b49b-48f9-8b10-d3ce8b99a775",
            "name": "fixture-gateway-signing",
            "jobs": [{"name": "sign", "steps": steps}],
        }
        (self.repo / "workflows/workflow-fixture-gateway-signing.yaml").write_text(
            yaml.safe_dump(workflow, sort_keys=False)
        )
        result = json.loads(
            self.cli("workflow", "run", "fixture-gateway-signing").stdout
        )
        self.assertEqual("succeeded", result["status"])
        self.assertEqual(1, len(self.calls))
        path, body = self.calls[0]
        self.assertEqual("/api/v1/direct-operations/19/certificates/sign", path)
        self.assertEqual([self.request], body["requests"])
        self.assertEqual(result["id"], body["run_id"])
        self.assert_private_absent(json.dumps(result))

    def test_invalid_operation_fails_before_http_and_current_leaf_needs_no_gateway(
        self,
    ):
        invalid = self.shell(operation="19/../20")
        self.assertNotEqual(0, invalid.returncode)
        current = self.shell(
            requests=[{"node_id": 9, "scope": self.leaf["scope"], "state": "current"}],
            script=self.production_script,
        )
        self.assertEqual(0, current.returncode)
        self.assertEqual({"certificates": []}, json.loads(current.stdout))
        self.assertEqual([], self.calls)

    def test_http_refusal_and_redirect_have_no_retry_or_diagnostic_body(self):
        for status in (403, 302):
            self.status = status
            result = self.shell()
            self.assertNotEqual(0, result.returncode)
            self.assertEqual(
                {"error_code": "app-dev.certificate_sign_failed"},
                json.loads(result.stdout),
            )
            self.assertEqual("", result.stderr)
        self.assertEqual(2, len(self.calls))
        self.assert_private_absent()


if __name__ == "__main__":
    unittest.main()
