"""Native certificate roots using unmodified official SSH and real OpenSSL."""

import base64
import http.server
import importlib.util
import json
import os
import shutil
import ssl
import subprocess
import sys
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


class Certificates(harness.NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.real_certificate_signing = True
        self.copy_behaviors("operations/certificates")
        official = self.repo / ".swamp/pulled-extensions/@swamp/ssh"
        official.parent.mkdir(parents=True)
        shutil.copytree(ROOT / ".swamp/pulled-extensions/@swamp/ssh", official)
        inventory = json.loads(
            (ROOT / "extensions/models/upstream_extensions.json").read_text()
        )
        path = self.repo / "extensions/models/upstream_extensions.json"
        path.parent.mkdir(parents=True)
        path.write_text(json.dumps({"@swamp/ssh": inventory["@swamp/ssh"]}))
        # The metadata behavior imports only public contracts and its pure decoder.
        for path in (self.repo / "extensions/behaviors/operations/certificates").glob(
            "*.ts"
        ):
            if path.name not in (
                "script_assets.ts",
                "input.ts",
                "signing.ts",
                "role-input.ts",
            ):
                path.unlink()
        self.fixture = self.repo / "fixture"
        self.fixture.mkdir()
        self.home = self.fixture / "home"
        self.home.mkdir()
        scripts = self.repo / "extensions/behaviors/operations/certificates/scripts"
        shutil.rmtree(scripts / "__pycache__", ignore_errors=True)
        common = scripts / "local.py"
        body = (
            common.read_text()
            .replace(
                'pathlib.Path("/etc/caddy/orbit-certificates")',
                f"pathlib.Path({str(self.fixture / 'caddy')!r})",
            )
            .replace(
                'pathlib.Path("/usr/local/share/ca-certificates/orbit-managed-root-ca.crt")',
                f"pathlib.Path({str(self.fixture / 'anchor.pem')!r})",
            )
        )
        body = body.replace(
            '    user = pwd.getpwnam(raw["user"])',
            '    user = __import__("types").SimpleNamespace('
            f"pw_uid=os.getuid(), pw_gid=os.getgid(), pw_dir={str(self.home)!r})",
        )
        body += "\nos.chown = lambda *args, **kwargs: None\n"
        common.write_text(body)
        publication = scripts / "publish.py"
        publication.write_text(
            publication.read_text().replace(
                'grp.getgrnam("caddy").gr_gid', "os.getgid()"
            )
        )
        self.tools = self.fixture / "tools"
        self.tools.mkdir()
        self.native_env["PATH"] = str(self.tools) + ":" + os.environ["PATH"]
        for name, code in {
            "systemctl": "#!/bin/sh\nexit 0\n",
            "update-ca-certificates": "#!/bin/sh\nexit 0\n",
            "caddy": "#!/bin/sh\nprintf '%s\\n' '{\"apps\":{}}'\n",
        }.items():
            path = self.tools / name
            path.write_text(code)
            path.chmod(0o755)
        fake = self.tools / "ssh"
        fake.write_text(
            "#!"
            + sys.executable
            + "\n"
            + """import os,shlex,subprocess,sys
from pathlib import Path
args=sys.argv[1:]
if '-M' in args or '-O' in args: raise SystemExit(0)
argv=shlex.split(args[-1])
if argv[:3]==['sudo','-n','--']: argv=argv[3:]
if argv[:2]==['rm','-rf'] and list(Path(argv[-1]).rglob('*.pyc')):
    sys.stderr.write('fixture: root Python left bytecode in the shared assets')
    raise SystemExit(1)
if any(str(a).endswith('/inspect.py') for a in argv) and Path(FAIL).exists():
    raise SystemExit(1)
result=subprocess.run(argv,input=sys.stdin.buffer.read(),capture_output=True)
sys.stdout.buffer.write(result.stdout);sys.stderr.buffer.write(result.stderr)
raise SystemExit(result.returncode)
""".replace("FAIL", repr(str(self.fixture / "fail-inspect")))
        )
        fake.chmod(0o755)
        scp = self.tools / "scp"
        scp.write_text(
            "#!"
            + sys.executable
            + "\n"
            + """import shutil,sys
from pathlib import Path
src=Path(sys.argv[-2]);dst=Path(sys.argv[-1].split(':',1)[1])
shutil.copytree(src,dst/src.name,dirs_exist_ok=True)
"""
        )
        scp.chmod(0o755)
        custody = tempfile.TemporaryDirectory(prefix="certificate-fixture-gateway-")
        self.addCleanup(custody.cleanup)
        self.key = Path(custody.name) / "ca.key"
        self.ca = self.fixture / "ca.pem"
        self.openssl(
            "req",
            "-x509",
            "-newkey",
            "rsa:2048",
            "-nodes",
            "-keyout",
            self.key,
            "-out",
            self.ca,
            "-days",
            "400",
            "-subj",
            "/CN=fixture CA",
            "-addext",
            "subjectAltName=IP:127.0.0.1",
        )
        self.signs = []
        self.refuse = False
        owner = self

        class Signer(http.server.BaseHTTPRequestHandler):
            def do_POST(self):
                raw = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
                owner.signs.append((self.path, raw))
                if owner.refuse:
                    self.send_response(403)
                    self.end_headers()
                    return
                signed = []
                for request in raw["requests"]:
                    csr = owner.fixture / "request.pem"
                    csr.write_bytes(base64.b64decode(request["request_base64"]))
                    extensions = owner.fixture / "extensions"
                    extensions.write_text(
                        "subjectAltName=DNS:shop.test\nbasicConstraints=critical,CA:FALSE\n"
                    )
                    certificate = owner.openssl(
                        "x509",
                        "-req",
                        "-in",
                        csr,
                        "-CA",
                        owner.ca,
                        "-CAkey",
                        owner.key,
                        "-CAcreateserial",
                        "-days",
                        "398",
                        "-extfile",
                        extensions,
                    )
                    signed.append(
                        dict(
                            node_id=request["node_id"],
                            scope=request["scope"],
                            certificate_base64=base64.b64encode(certificate).decode(),
                            root_certificate_base64=base64.b64encode(
                                owner.ca.read_bytes()
                            ).decode(),
                        )
                    )
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.end_headers()
                self.wfile.write(
                    json.dumps({"data": {"certificates": signed}}).encode()
                )

            def log_message(self, *_):
                pass

        server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Signer)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(self.ca, self.key)
        server.socket = context.wrap_socket(server.socket, server_side=True)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        self.secrets = (self.key.read_text(),)
        for kind in ("converge", "remove"):
            production = yaml.safe_load(
                (
                    ROOT / f"workflows/workflow-operation-certificate-{kind}.yaml"
                ).read_text()
            )
            for step in production["jobs"][0]["steps"]:
                self.assertEqual("orbit-node-209", step["target"])
                self.assertEqual({"node": "209"}, step["labels"])
                step.pop("target", None)
                step.pop("labels", None)
                task = step["task"]
                if task["behaviorType"] == "@swamp/ssh":
                    task["globalArgs"].update(sshBinary=str(fake), scpBinary=str(scp))
                if task["behaviorType"] == "command/shell":
                    task["inputs"]["run"] = (
                        task["inputs"]["run"]
                        .replace(
                            "https://gateway.orbit",
                            f"https://127.0.0.1:{server.server_port}",
                        )
                        .replace(
                            "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt",
                            str(self.ca),
                        )
                    )
            # Only disposable paths, placement and documented executable seams differ.
            raw = yaml.safe_dump(production, sort_keys=False).replace(
                "/tmp/orbit-certificate-", str(self.fixture / "staging-")
            )
            (
                self.repo / f"workflows/workflow-operation-certificate-{kind}.yaml"
            ).write_text(raw)
        self.leaf = dict(
            node_id=9,
            user="orbit",
            scope="route-7-router",
            domain="shop.test",
            version="0123456789abcdef",
            root_hash=__import__("hashlib").sha256(self.ca.read_bytes()).hexdigest(),
            agent_sha256="a" * 64,
        )

    def openssl(self, *args):
        return subprocess.run(
            ["openssl", *map(str, args)], check=True, capture_output=True
        ).stdout

    def run_workflow(self, kind="converge", success=True, raw=False, **intent_changes):
        leaf = (
            self.leaf
            if kind == "converge"
            else {
                **{
                    k: self.leaf[k]
                    for k in ("node_id", "scope", "user", "agent_sha256")
                },
                "in_use": False,
            }
        )
        intent = dict(
            leaf=leaf,
            control_node_id=209,
            host_keys=[
                "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIEV4aXN0aW5nUGlubmVkSG9zdElkZW50aXR5UHVibGlj"
            ],
            connection=dict(
                nodeHost="fixture",
                nodePort=22,
                nodeUser="orbit",
                nodeIdentityFile=str(self.fixture / "existing-identity"),
            ),
        )
        intent.update(intent_changes)
        (self.fixture / "existing-identity").write_text("synthetic identity never used")
        name = "operation-certificate-" + kind
        self.cli("workflow", "validate", name)
        result = self.cli(
            "workflow",
            "run",
            name,
            "--input",
            "operation_id=19",
            "--input",
            "node_id=9",
            "--input",
            "kind=certificate." + kind,
            "--input",
            "intent=" + json.dumps(intent),
            "--input",
            "execution_secrets={}",
            success=success,
        )
        return result if raw else json.loads(result.stdout)

    def test_fresh_current_and_removal_use_official_ssh_and_one_parent_signer(self):
        first = self.run_workflow()
        self.assertEqual("succeeded", first["status"])
        self.assertEqual(1, len(self.signs))
        self.assertEqual(first["id"], self.signs[0][1]["run_id"])
        second = self.run_workflow()
        states = {s["name"]: s["status"] for s in second["jobs"][0]["steps"]}
        self.assertEqual("skipped", states["sign-http"])
        self.assertEqual("skipped", states["publish"])
        self.assertEqual(1, len(self.signs))
        self.assertEqual("succeeded", self.run_workflow("remove")["status"])
        self.assertFalse((self.home / ".orbit/certificates/route-7-router").exists())
        self.assertEqual([], list(self.fixture.glob("staging-*")))

    def test_missing_pins_refuse_before_any_remote_action(self):
        self.run_workflow(success=False, raw=True, host_keys=[])
        self.assertEqual([], self.signs)
        self.assertEqual([], list(self.fixture.glob("staging-*")))

    def test_signer_refusal_makes_no_publication_or_hidden_callback(self):
        self.refuse = True
        failed = self.run_workflow(success=False)
        self.assertEqual("failed", failed["status"])
        self.assertEqual(1, len(self.signs))
        self.assertFalse(
            (self.home / ".orbit/certificates/route-7-router/current").exists()
        )
        self.assertEqual(
            "/api/v1/direct-operations/19/certificates/sign", self.signs[0][0]
        )

    def test_native_inspection_resume_preserves_the_key_without_signing_again(self):
        (self.fixture / "fail-inspect").touch()
        failed = self.run_workflow(success=False)
        key = self.home / ".orbit/certificates/route-7-router/current/key.pem"
        before = key.read_bytes()
        (self.fixture / "fail-inspect").unlink()
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
        self.assertEqual(before, key.read_bytes())
        self.assertEqual(1, len(self.signs))


if __name__ == "__main__":
    unittest.main()
