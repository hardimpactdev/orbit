"""Execute the real inline capability probe against disposable HTTP servers."""

import json
import os
import subprocess
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
WORKFLOW = ROOT / "workflows/workflow-task-vm-runtime-command-confirm.yaml"


class CapabilityProbe(unittest.TestCase):
    def setUp(self):
        self.token = "c" * 64
        self.calls = []
        self.status = 200
        self.authorization = None
        case = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def do_GET(self):
                case.calls.append(self.path)
                case.authorization = self.headers.get("Authorization")
                self.send_response(case.status)
                if case.status == 302:
                    self.send_header("Location", "/redirected")
                self.end_headers()
                self.wfile.write(b"public capabilities")

        self.server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self.server.daemon_threads = True
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        workflow = yaml.safe_load(WORKFLOW.read_text())
        self.step = next(
            s for s in workflow["jobs"][0]["steps"] if s["name"] == "capabilities"
        )
        self.run = self.step["task"]["inputs"]["run"]

    def tearDown(self):
        self.server.shutdown()
        self.thread.join(timeout=2)
        self.server.server_close()

    def probe(self, **extra):
        env = {
            **os.environ,
            "ORBIT_PI_TOKEN": self.token,
            "ORBIT_PI_URL": f"http://127.0.0.1:{self.server.server_port}",
            "ORBIT_GUEST_ADDRESS": "127.0.0.1",
            **extra,
        }
        result = subprocess.run(
            ["/bin/sh", "-c", self.run],
            env=env,
            capture_output=True,
            text=True,
            timeout=10,
            check=False,
        )
        self.assertNotIn(self.token, result.stdout + result.stderr)
        return result

    def test_real_authenticated_success_returns_only_public_readiness(self):
        result = self.probe()
        self.assertEqual(result.returncode, 0)
        self.assertEqual(json.loads(result.stdout), {"capabilities_ready": True})
        self.assertEqual(self.authorization, "Bearer " + self.token)
        self.assertEqual(self.calls, ["/capabilities"])

    def test_trailing_slash_keeps_the_exact_capabilities_path(self):
        result = self.probe(ORBIT_PI_URL=f"http://127.0.0.1:{self.server.server_port}/")
        self.assertEqual(result.returncode, 0)
        self.assertEqual(self.calls, ["/capabilities"])

    def test_auth_refusal_and_server_failure_never_retry_or_fallback(self):
        for status in (401, 403, 500):
            with self.subTest(status=status):
                self.status = status
                self.calls.clear()
                result = self.probe()
                self.assertEqual(result.returncode, 1)
                self.assertEqual(
                    json.loads(result.stdout),
                    {"error_code": "task_vm.runtime_not_ready"},
                )
                self.assertEqual(self.calls, ["/capabilities"])

    def test_redirect_is_refused_without_forwarding_the_credential(self):
        self.status = 302
        result = self.probe()
        self.assertEqual(result.returncode, 1)
        self.assertEqual(self.calls, ["/capabilities"])

    def test_wrong_guest_address_and_invalid_token_refuse_before_network(self):
        for extra in [
            {"ORBIT_GUEST_ADDRESS": "192.0.2.212"},
            {"ORBIT_PI_TOKEN": "bad"},
            {"ORBIT_PI_URL": f"http://user:pass@127.0.0.1:{self.server.server_port}"},
        ]:
            with self.subTest(extra=extra):
                self.assertEqual(self.probe(**extra).returncode, 1)
        self.assertEqual(self.calls, [])


if __name__ == "__main__":
    unittest.main()
