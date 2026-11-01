"""Shared disposable TLS/SSH fixture for the canonical certificate children."""

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
from pathlib import Path

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
                "node_batch.ts",
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
        signer = (
            self.repo / "extensions/behaviors/operations/certificates/scripts/sign.py"
        )
        signer.write_text(
            signer.read_text()
            .replace("https://gateway.orbit", f"https://127.0.0.1:{server.server_port}")
            .replace(
                "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt",
                str(self.ca),
            )
        )
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
