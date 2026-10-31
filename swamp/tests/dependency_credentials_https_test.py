"""Real Git smart HTTPS authentication through the production GIT_ASKPASS helper."""
import base64
import http.server
import json
import os
from pathlib import Path
import shutil
import ssl
import subprocess
import tempfile
import threading
import unittest
from urllib.parse import urlsplit

REPO = Path(__file__).resolve().parents[1]


class CredentialHttpsTest(unittest.TestCase):
    def test_real_git_asks_for_password_and_keeps_token_out_of_config(self):
        with tempfile.TemporaryDirectory(prefix="dependency-https-") as tmp:
            root = Path(tmp)
            real_git = shutil.which("git")

            def git(*args):
                return subprocess.check_output([real_git, *args], stderr=subprocess.PIPE, text=True).strip()

            source = root / "source"
            source.mkdir()
            git("init", "-q", "-b", "main", str(source))
            (source / "readme").write_text("real Git transport fixture\n")
            git("-C", str(source), "add", ".")
            git("-C", str(source), "-c", "user.name=Fixture", "-c", "user.email=fixture@example.test", "commit", "-qm", "fixture")
            repositories = root / "repos"
            bare = repositories / "nckrtl/website.git"
            bare.parent.mkdir(parents=True)
            git("clone", "-q", "--bare", str(source), str(bare))
            cert, key = root / "cert.pem", root / "key.pem"
            subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
                            "-subj", "/CN=github.com", "-addext", "subjectAltName=DNS:github.com",
                            "-keyout", str(key), "-out", str(cert)], check=True, capture_output=True)
            challenged, authenticated = [], []
            expected = "Basic " + base64.b64encode(b"x-access-token:ephemeral_sentinel").decode()

            class SmartHttps(http.server.BaseHTTPRequestHandler):
                def log_message(self, *args):
                    pass

                def service(self):
                    if self.headers.get("Authorization") != expected:
                        challenged.append(True)
                        self.send_response(401)
                        self.send_header("WWW-Authenticate", 'Basic realm="fixture"')
                        self.send_header("Content-Length", "0")
                        self.end_headers()
                        return
                    authenticated.append(True)
                    url = urlsplit(self.path)
                    env = {"PATH": os.environ["PATH"], "GIT_PROJECT_ROOT": str(repositories), "GIT_HTTP_EXPORT_ALL": "1",
                           "PATH_INFO": url.path, "QUERY_STRING": url.query, "REQUEST_METHOD": self.command,
                           "CONTENT_TYPE": self.headers.get("Content-Type", ""), "REMOTE_USER": "x-access-token"}
                    body = self.rfile.read(int(self.headers.get("Content-Length", "0")))
                    response = subprocess.run([real_git, "http-backend"], input=body, env=env, capture_output=True, check=True).stdout
                    headers, payload = response.split(b"\r\n\r\n", 1)
                    self.send_response(200)
                    for header in headers.decode().splitlines():
                        name, value = header.split(":", 1)
                        self.send_header(name, value.strip())
                    self.send_header("Content-Length", str(len(payload)))
                    self.end_headers()
                    self.wfile.write(payload)

                do_GET = service
                do_POST = service

            server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), SmartHttps)
            context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
            context.load_cert_chain(cert, key)
            server.socket = context.wrap_socket(server.socket, server_side=True)
            threading.Thread(target=server.serve_forever, daemon=True).start()
            try:
                port = server.server_port
                bins = root / "bin"
                bins.mkdir()
                shim = bins / "git"
                # Only the trusted test executable supplies loopback DNS and the fixture CA.
                shim.write_text("#!/usr/bin/env python3\nimport os,sys\n" +
                                f"os.execv({real_git!r},['git','-c','http.proxy=','-c','http.sslCAInfo={cert}','-c','http.curloptResolve=github.com:{port}:127.0.0.1',*sys.argv[1:]])\n")
                shim.chmod(0o755)
                clone = root / "clone"
                script = f"""
import {{ credentialCommand }} from {json.dumps((REPO / 'extensions/behaviors/_lib/dependency_credentials.ts').as_uri())};
const credential={{project_id:20,repository:'github.com/nckrtl/website',clone_url:'https://github.com:{port}/nckrtl/website.git',token:'ephemeral_sentinel',expires_at:new Date(Date.now()+3600000).toISOString()}};
await credentialCommand({json.dumps(str(source))},'git',['clone','--',credential.clone_url,{json.dumps(str(clone))}],credential,AbortSignal.timeout(10000));
"""
                env = dict(os.environ, PATH=str(bins) + ":" + os.environ["PATH"])
                result = subprocess.run(["deno", "eval", script], cwd=root, env=env, capture_output=True, text=True, timeout=20)
                self.assertEqual(result.returncode, 0, result.stderr[-2000:])
                self.assertTrue(challenged, "real Git must receive an HTTP password challenge")
                self.assertTrue(authenticated, "production askpass must supply the expected username and password")
                self.assertEqual((clone / "readme").read_text(), "real Git transport fixture\n")
                self.assertNotIn("ephemeral_sentinel", (clone / ".git/config").read_text())
            finally:
                server.shutdown()
                server.server_close()
