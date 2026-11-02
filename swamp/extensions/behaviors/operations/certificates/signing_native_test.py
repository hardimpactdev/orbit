"""The 209 signer against a disposable HTTPS Gateway: one call, the leaf and root as PEM, no retry."""

import base64
import http.server
import json
import os
import ssl
import subprocess
import tempfile
import threading
import unittest
from pathlib import Path

SIGNER = Path(__file__).resolve().parent / "scripts/sign.py"
LEAF = "-----BEGIN CERTIFICATE-----\nleaf\n-----END CERTIFICATE-----\n"
ROOT_PEM = "-----BEGIN CERTIFICATE-----\nroot\n-----END CERTIFICATE-----\n"
CSR = "-----BEGIN CERTIFICATE REQUEST-----\npublic\n-----END CERTIFICATE REQUEST-----\n"


class SignerTest(unittest.TestCase):
    def setUp(self):
        home = tempfile.TemporaryDirectory(prefix="fixture-gateway-")
        self.addCleanup(home.cleanup)
        self.home = Path(home.name)
        key, cert = self.home / "https.key", self.home / "https.pem"
        subprocess.run(
            [
                "openssl",
                "req",
                "-x509",
                "-newkey",
                "rsa:2048",
                "-nodes",
                "-keyout",
                str(key),
                "-out",
                str(cert),
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
        self.calls, self.status = [], 200
        owner = self

        class Handler(http.server.BaseHTTPRequestHandler):
            def do_POST(self):
                owner.calls.append(
                    (
                        self.path,
                        json.loads(
                            self.rfile.read(int(self.headers["Content-Length"]))
                        ),
                    )
                )
                self.send_response(owner.status)
                if owner.status == 302:
                    self.send_header("Location", "/forbidden-redirect")
                self.end_headers()
                signed = {
                    "node_id": 9,
                    "scope": "route-7",
                    "certificate_base64": base64.b64encode(LEAF.encode()).decode(),
                    "root_certificate_base64": base64.b64encode(
                        ROOT_PEM.encode()
                    ).decode(),
                }
                self.wfile.write(
                    json.dumps({"data": {"certificates": [signed]}}).encode()
                )

            def log_message(self, *_args):
                pass

        server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        tls.load_cert_chain(cert, key)
        server.socket = tls.wrap_socket(server.socket, server_side=True)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        self.script = self.home / "sign.py"
        self.script.write_text(
            SIGNER.read_text()
            .replace("https://gateway.orbit", f"https://127.0.0.1:{server.server_port}")
            .replace(
                "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt", str(cert)
            )
        )

    def sign(self, operation="19", **extra):
        env = {
            "PATH": os.environ["PATH"],
            "ORBIT_CERTIFICATE_OPERATION_ID": operation,
            "ORBIT_CERTIFICATE_RUN_ID": "run-1",
            "ORBIT_CERTIFICATE_NODE_ID": "9",
            "ORBIT_CERTIFICATE_SCOPE": "route-7",
            "ORBIT_CERTIFICATE_REQUEST": CSR,
            **extra,
        }
        return subprocess.run(
            ["python3", "-I", str(self.script)],
            env=env,
            text=True,
            capture_output=True,
            timeout=30,
            check=False,
        )

    def test_one_call_prints_the_leaf_then_the_root(self):
        result = self.sign(ORBIT_CERTIFICATE_RESTORING="true")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(LEAF + ROOT_PEM, result.stdout)
        self.assertEqual(1, len(self.calls))
        path, body = self.calls[0]
        self.assertEqual("/api/v1/direct-operations/19/certificates/sign", path)
        self.assertEqual(
            {
                "run_id": "run-1",
                "restoring": True,
                "requests": [
                    {
                        "node_id": 9,
                        "scope": "route-7",
                        "state": "request",
                        "request_base64": base64.b64encode(CSR.encode()).decode(),
                    }
                ],
            },
            body,
        )

    def test_invalid_operation_fails_before_any_call(self):
        result = self.sign(operation="19/../20")
        self.assertNotEqual(0, result.returncode)
        self.assertEqual([], self.calls)

    def test_refusal_and_redirect_fail_once_without_retry(self):
        for status in (403, 302):
            self.status = status
            result = self.sign()
            self.assertNotEqual(0, result.returncode)
            self.assertIn("app-dev.certificate_sign_failed", result.stderr)
            self.assertEqual("", result.stdout)
        self.assertEqual(2, len(self.calls))


if __name__ == "__main__":
    unittest.main()
