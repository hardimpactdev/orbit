"""Actual native asset delivery, OpenSSL leaf actions and a disposable HTTPS signer."""

import base64
import hashlib
import http.server
import importlib.util
import json
import os
import re
import shutil
import ssl
import subprocess
import tempfile
import threading
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
SPEC = importlib.util.spec_from_file_location(
    "native_harness", ROOT / "tests/native_harness.py"
)
harness = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(harness)


class CertificateScriptNativeTest(harness.NativeTestCase):
    def setUp(self):
        super().setUp()
        self.real_certificate_signing = True
        self.host = tempfile.TemporaryDirectory(prefix="certificate-node-")
        self.addCleanup(self.host.cleanup)
        self.home = Path(self.host.name)
        self.custody = tempfile.TemporaryDirectory(prefix="certificate-gateway-")
        self.addCleanup(self.custody.cleanup)
        self.ca = Path(self.custody.name) / "root.pem"
        self.ca_key = Path(self.custody.name) / "root.key"
        self.command(
            "openssl",
            "req",
            "-x509",
            "-newkey",
            "rsa:2048",
            "-nodes",
            "-keyout",
            str(self.ca_key),
            "-out",
            str(self.ca),
            "-days",
            "400",
            "-subj",
            "/CN=localhost",
            "-addext",
            "subjectAltName=IP:127.0.0.1",
        )
        self.secrets = (self.ca_key.read_text(),)
        self.calls = []
        self.http_status = 200
        owner = self

        class Handler(http.server.BaseHTTPRequestHandler):
            def do_POST(self):
                body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
                owner.calls.append((self.path, body))
                self.send_response(owner.http_status)
                self.send_header("Content-Type", "application/json")
                self.end_headers()
                if owner.http_status != 200:
                    self.wfile.write(b'{"error":"refused"}')
                    return
                request = body["requests"][0]
                csr = Path(owner.custody.name) / "leaf.csr"
                csr.write_bytes(base64.b64decode(request["request_base64"]))
                extensions = Path(owner.custody.name) / "extensions"
                extensions.write_text(
                    "basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:private.test\n"
                )
                cert = owner.command(
                    "openssl",
                    "x509",
                    "-req",
                    "-in",
                    str(csr),
                    "-CA",
                    str(owner.ca),
                    "-CAkey",
                    str(owner.ca_key),
                    "-CAcreateserial",
                    "-days",
                    "397",
                    "-extfile",
                    str(extensions),
                )
                value = {
                    "node_id": 9,
                    "scope": "route-7-router",
                    "certificate_base64": base64.b64encode(cert).decode(),
                    "root_certificate_base64": base64.b64encode(
                        owner.ca.read_bytes()
                    ).decode(),
                }
                self.wfile.write(
                    json.dumps({"data": {"certificates": [value]}}).encode()
                )

            def log_message(self, *_args):
                pass

        self.server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        tls.load_cert_chain(self.ca, self.ca_key)
        self.server.socket = tls.wrap_socket(self.server.socket, server_side=True)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.addCleanup(self.stop_server)
        self.leaf = dict(
            node_id=9,
            scope="route-7-router",
            user="orbit",
            domain="private.test",
            version="0123456789abcdef",
            root_hash=hashlib.sha256(self.ca.read_bytes()).hexdigest(),
            agent_sha256="a" * 64,
        )
        family = self.repo / "extensions/behaviors/operations/certificates"
        family.mkdir(parents=True)
        for name in (
            "manifest.yaml",
            "script_assets.ts",
            "input.ts",
            "signing.ts",
            "role-input.ts",
        ):
            shutil.copy(
                ROOT / "extensions/behaviors/operations/certificates" / name, family
            )
        shutil.copytree(
            ROOT / "extensions/behaviors/operations/certificates/scripts",
            family / "scripts",
        )
        module = family / "scripts/leaf.py"
        source = module.read_text()
        source = source.replace(
            'P("/etc/caddy/orbit-certificates")',
            repr(self.home / "caddy").replace("PosixPath", "P"),
        )
        source = source.replace(
            'P("/usr/local/share/ca-certificates/orbit-managed-root-ca.crt")',
            "P(" + repr(str(self.ca)) + ")",
        )
        source = source.replace(
            'P("/etc/ssl/certs/ca-certificates.crt")', "P(" + repr(str(self.ca)) + ")"
        )
        source = re.sub(
            r"def local_account\(data\):.*?\n\ndef digest",
            "def local_account(data):\n    return "
            + repr((os.getuid(), os.getgid(), str(self.home)))
            + "\n\ndef digest",
            source,
            flags=re.S,
        )
        source = source.replace("os.chown(", "fixture_chown(").replace(
            'grp.getgrnam("caddy").gr_gid', str(os.getgid())
        )
        source += "\n_real_run = subprocess.run\ndef fixture_chown(path, *ids, **kwargs):\n    os.chown(path, os.getuid(), os.getgid(), **kwargs)\ndef fixture_run(argv, **kwargs):\n    if argv[0] == 'systemctl':\n        return subprocess.CompletedProcess(argv, 3)\n    return _real_run(argv, **kwargs)\nsubprocess.run = fixture_run\n"
        module.write_text(source)
        self.inspect_refusal = self.home / "refuse-inspection"
        inspector = family / "scripts/inspect.py"
        source = inspector.read_text().replace(
            "from leaf import admitted, roots",
            "from pathlib import Path\nimport json\nimport sys\n\nfrom leaf import admitted, roots",
        )
        source = source.replace(
            "    emit(main)",
            f"    if Path({str(self.inspect_refusal)!r}).exists():\n"
            '        print(json.dumps({"error_code": "fixture.inspection_failed"}))\n'
            "        sys.exit(1)\n"
            "    emit(main)",
        )
        inspector.write_text(source)
        for kind in ("converge", "remove"):
            workflow = yaml.safe_load(
                (
                    ROOT / f"workflows/workflow-operation-certificate-{kind}.yaml"
                ).read_text()
            )
            for job in workflow["jobs"]:
                for step in job["steps"]:
                    step.pop("target", None)
                    step.pop("labels", None)
                    args = step["task"]["inputs"]
                    if "run" in args:
                        args["run"] = re.sub(
                            r"^sudo -n --preserve-env=[^ ]+ ", "", args["run"]
                        )
                        args["run"] = (
                            args["run"]
                            .replace(
                                "https://gateway.orbit",
                                f"https://127.0.0.1:{self.server.server_port}",
                            )
                            .replace(
                                "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt",
                                str(self.ca),
                            )
                        )
            (
                self.repo / f"workflows/workflow-operation-certificate-{kind}.yaml"
            ).write_text(yaml.safe_dump(workflow, sort_keys=False))

    def command(self, *args):
        return subprocess.run(args, check=True, capture_output=True).stdout

    def stop_server(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=5)

    def run_kind(self, kind="converge", success=True):
        intent = (
            self.leaf
            if kind == "converge"
            else {
                key: self.leaf[key]
                for key in ("node_id", "scope", "user", "agent_sha256")
            }
        )
        if kind == "remove":
            intent["in_use"] = False
        inputs = dict(
            operation_id=19,
            node_id=9,
            kind="certificate." + kind,
            intent=intent,
            execution_secrets={},
        )
        raw = self.cli(
            "workflow",
            "run",
            "operation-certificate-" + kind,
            "--input",
            json.dumps(inputs),
            success=success,
        )
        return json.loads(raw.stdout)

    def step(self, run, name):
        return next(step for step in run["jobs"][0]["steps"] if step["name"] == name)

    def public_presence(self, run):
        query = f'workflowRunId == "{run["id"]}" && stepName == "inspect" && name == "result"'
        output = json.loads(
            self.cli("data", "query", query, "--single", "--select", "content").stdout
        )
        return json.loads(output["stdout"])

    def test_native_convergence_delivers_scripts_signs_and_keeps_private_material_local(
        self,
    ):
        result = self.run_kind()
        self.assertEqual("succeeded", result["status"])
        self.assertEqual(
            {"present": True},
            self.public_presence(result),
        )
        self.assertEqual(1, len(self.calls))
        path, body = self.calls[0]
        self.assertEqual("/api/v1/direct-operations/19/certificates/sign", path)
        self.assertEqual(result["id"], body["run_id"])
        self.assertEqual(9, body["requests"][0]["node_id"])
        self.assertTrue(
            (self.home / ".orbit/certificates/route-7-router/current/key.pem").exists()
        )
        self.assert_private_absent()

    def test_current_leaf_skips_signing_and_publication_via_native_guards(self):
        self.run_kind()
        self.calls.clear()
        result = self.run_kind()
        self.assertEqual("succeeded", result["status"])
        self.assertEqual("skipped", self.step(result, "sign-http")["status"])
        self.assertEqual("skipped", self.step(result, "publish")["status"])
        self.assertEqual([], self.calls)

    def test_removal_and_absence_are_distinct_command_steps(self):
        self.run_kind()
        self.calls.clear()
        result = self.run_kind("remove")
        self.assertEqual("succeeded", result["status"])
        self.assertEqual(
            {"present": False},
            self.public_presence(result),
        )
        self.assertFalse((self.home / ".orbit/certificates/route-7-router").exists())
        self.assertFalse((self.home / "caddy/route-7-router").exists())
        self.assertEqual([], self.calls)

    def test_signer_refusal_skips_publication_without_retry_or_status_callback(self):
        self.http_status = 403
        result = self.run_kind(success=False)
        self.assertEqual("failed", result["status"])
        self.assertEqual("skipped", self.step(result, "publish")["status"])
        self.assertEqual(1, len(self.calls))
        self.assertFalse(
            (self.home / ".orbit/certificates/route-7-router/current").exists()
        )

    def test_native_resume_repeats_only_inspection_after_publication_completed(self):
        self.inspect_refusal.touch()
        failed = self.run_kind(success=False)
        key = self.home / ".orbit/certificates/route-7-router/current/key.pem"
        before = key.read_bytes()
        self.assertEqual(1, len(self.calls))
        self.inspect_refusal.unlink()

        resumed = json.loads(
            self.cli(
                "workflow",
                "resume",
                "operation-certificate-converge",
                "--run",
                failed["id"],
                "--from",
                "inspect",
            ).stdout
        )

        self.assertEqual("succeeded", resumed["status"])
        self.assertEqual({"present": True}, self.public_presence(resumed))
        self.assertEqual(before, key.read_bytes())
        self.assertEqual(1, len(self.calls))


if __name__ == "__main__":
    unittest.main()
