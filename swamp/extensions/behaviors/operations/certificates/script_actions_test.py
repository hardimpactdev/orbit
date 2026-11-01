"""Real OpenSSL contract tests with disposable paths and account metadata."""

import base64
import hashlib
import importlib
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

SCRIPTS = Path(__file__).parent / "scripts"
sys.path.insert(0, str(SCRIPTS))
local = importlib.import_module("local")
request = importlib.import_module("request")
publish = importlib.import_module("publish")
remove = importlib.import_module("remove")
spec = importlib.util.spec_from_file_location(
    "certificate_inspect", SCRIPTS / "inspect.py"
)
inspect_leaf = importlib.util.module_from_spec(spec)
spec.loader.exec_module(inspect_leaf)


def openssl(*args):
    return subprocess.run(
        ["openssl", *map(str, args)], check=True, capture_output=True
    ).stdout


class ScriptActions(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix="orbit-certificate-actions-")
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        home = self.root / "home"
        home.mkdir()
        user = SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid(), pw_dir=str(home))
        for target, value in (
            ("local.pwd.getpwnam", lambda name: user),
            ("local.CADDY", self.root / "caddy"),
            ("publish.CADDY", self.root / "caddy"),
            ("local.os.chown", lambda *args, **kwargs: None),
            ("publish.grp.getgrnam", lambda name: SimpleNamespace(gr_gid=os.getgid())),
        ):
            replacement = patch(target, value)
            replacement.start()
            self.addCleanup(replacement.stop)
        openssl(
            "req",
            "-x509",
            "-newkey",
            "rsa:2048",
            "-nodes",
            "-keyout",
            self.root / "ca.key",
            "-out",
            self.root / "ca.pem",
            "-days",
            "400",
            "-subj",
            "/CN=disposable CA",
        )
        self.leaf = {
            "node_id": 9,
            "user": "orbit",
            "scope": "route-7-router",
            "domain": "shop.test",
            "version": "0123456789abcdef",
            "root_hash": hashlib.sha256(
                (self.root / "ca.pem").read_bytes()
            ).hexdigest(),
            "agent_sha256": "a" * 64,
        }

    def signed(self):
        result = request.request(self.leaf)
        csr = self.root / "request.pem"
        csr.write_bytes(base64.b64decode(result["request_base64"]))
        extensions = self.root / "extensions"
        extensions.write_text(
            "subjectAltName=DNS:shop.test\nbasicConstraints=critical,CA:FALSE\n"
        )
        certificate = openssl(
            "x509",
            "-req",
            "-in",
            csr,
            "-CA",
            self.root / "ca.pem",
            "-CAkey",
            self.root / "ca.key",
            "-CAcreateserial",
            "-days",
            "398",
            "-extfile",
            extensions,
        )
        return {
            "certificate_base64": base64.b64encode(certificate).decode(),
            "root_certificate_base64": base64.b64encode(
                (self.root / "ca.pem").read_bytes()
            ).decode(),
        }

    def publish(self):
        signed = self.signed()
        with patch.dict(os.environ, ORBIT_CERTIFICATE_SIGNED=json.dumps(signed)):
            publish.publish(self.leaf)

    def test_request_is_a_valid_public_csr_and_resume_keeps_the_local_key(self):
        first = request.request(self.leaf)
        _, root, _ = local.account(self.leaf)
        key = root / "versions/0123456789abcdef.candidate/key.pem"
        before = key.read_bytes()
        second = request.request(self.leaf)
        csr = self.root / "request.pem"
        csr.write_bytes(base64.b64decode(second["request_base64"]))
        openssl("req", "-in", csr, "-verify", "-noout")
        self.assertEqual(before, key.read_bytes())
        self.assertEqual(0o600, key.stat().st_mode & 0o777)
        self.assertNotIn(
            "PRIVATE KEY", base64.b64decode(first["request_base64"]).decode()
        )

    def test_publish_current_inspect_and_remove_are_idempotent(self):
        self.publish()
        self.assertEqual({"state": "current"}, request.request(self.leaf))
        with patch.dict(os.environ, ORBIT_CERTIFICATE_EXPECT_PRESENT="true"):
            self.assertEqual({"present": True}, inspect_leaf.inspect(self.leaf))
        scope = {
            key: value
            for key, value in self.leaf.items()
            if key not in ("domain", "version", "root_hash")
        }
        scope["in_use"] = False
        with patch.object(
            remove.subprocess,
            "run",
            return_value=SimpleNamespace(stdout=b'{"apps":{}}'),
        ):
            remove.remove(scope)
            remove.remove(scope)
        with patch.dict(os.environ, ORBIT_CERTIFICATE_EXPECT_PRESENT="false"):
            self.assertEqual({"present": False}, inspect_leaf.inspect(scope))

    def test_publication_resumes_after_user_commit_without_replacing_private_key(self):
        signed = self.signed()
        _, root, _ = local.account(self.leaf)
        key = root / "versions/0123456789abcdef.candidate/key.pem"
        before = key.read_bytes()
        with patch.dict(os.environ, ORBIT_CERTIFICATE_SIGNED=json.dumps(signed)):
            with (
                patch.object(
                    publish.grp,
                    "getgrnam",
                    side_effect=KeyError("fixture interruption"),
                ),
                self.assertRaises(KeyError),
            ):
                publish.publish(self.leaf)
            publish.publish(self.leaf)
        self.assertEqual(before, (root / "current/key.pem").read_bytes())

    def test_home_mismatch_and_in_use_removal_refuse_before_effect(self):
        with self.assertRaisesRegex(ValueError, "certificate.home_mismatch"):
            request.request({**self.leaf, "home": "/different"})
        with self.assertRaisesRegex(ValueError, "certificate.in_use"):
            remove.remove({**self.leaf, "in_use": True})
        _, root, caddy = local.account(self.leaf)
        self.assertFalse(root.exists())
        self.assertFalse(caddy.exists())

    def test_fresh_caddy_references_and_unreadable_configuration_refuse_removal(self):
        self.publish()
        _, root, caddy = local.account(self.leaf)
        scope = {**self.leaf, "in_use": False}
        for certificates in (
            {
                "load_files": [
                    {
                        "certificate": str(caddy / "current/cert.pem"),
                        "key": str(caddy / "current/key.pem"),
                    }
                ]
            },
            {"load_folders": [str(caddy.parent)]},
        ):
            raw = json.dumps({"apps": {"tls": {"certificates": certificates}}}).encode()
            with (
                patch.object(
                    remove.subprocess, "run", return_value=SimpleNamespace(stdout=raw)
                ),
                self.assertRaisesRegex(ValueError, "certificate.in_use"),
            ):
                remove.remove(scope)
            self.assertTrue((root / "current/key.pem").exists())
        with (
            patch.object(
                remove.subprocess,
                "run",
                side_effect=subprocess.CalledProcessError(1, "caddy"),
            ),
            self.assertRaises(subprocess.CalledProcessError),
        ):
            remove.remove(scope)
        self.assertTrue((caddy / "current/key.pem").exists())

    def test_removal_refuses_invalid_account_or_home_before_reading_caddy(self):
        self.publish()
        _, root, caddy = local.account(self.leaf)
        key = (root / "current/key.pem").read_bytes()
        for data, code in (
            ({**self.leaf, "user": "orbit;id"}, "certificate.account_invalid"),
            ({**self.leaf, "home": "/different"}, "certificate.home_mismatch"),
        ):
            with (
                self.subTest(code=code),
                patch.object(remove.subprocess, "run") as command,
            ):
                with self.assertRaisesRegex(ValueError, code):
                    remove.remove({**data, "in_use": False})
                command.assert_not_called()
            self.assertEqual(key, (root / "current/key.pem").read_bytes())
            self.assertTrue((caddy / "current/cert.pem").is_file())

    def test_wrong_root_or_domain_never_publishes_a_pair(self):
        signed = self.signed()
        with patch.dict(os.environ, ORBIT_CERTIFICATE_SIGNED=json.dumps(signed)):
            with self.assertRaisesRegex(ValueError, "certificate.root_mismatch"):
                publish.publish({**self.leaf, "root_hash": "b" * 64})
            with self.assertRaises(subprocess.CalledProcessError):
                publish.publish({**self.leaf, "domain": "other.test"})
        _, root, caddy = local.account(self.leaf)
        self.assertFalse((root / "current").exists())
        self.assertFalse((caddy / "current").exists())


if __name__ == "__main__":
    unittest.main()
