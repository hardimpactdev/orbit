"""The Swamp pair has one fixed path, account and admitted public trust identity."""

import hashlib
import os
import runpy
import tempfile
import types
import unittest
from pathlib import Path
from unittest.mock import patch


class SwampPaths(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.home = Path(self.tmp.name) / "home"
        self.root = Path(self.tmp.name) / "public-ca.pem"
        self.root.write_bytes(b"public fixture CA")
        self.current = self.home / ".config/swamp/serve-tls"
        namespace = runpy.run_path(
            str(Path(__file__).with_name("scripts") / "role_paths.py")
        )
        self.paths = namespace["role_paths"]
        self.globals = self.paths.__globals__
        self.user = types.SimpleNamespace(pw_dir=str(self.home), pw_uid=os.getuid())
        self.pwd = types.SimpleNamespace(getpwnam=lambda _: self.user)
        self.grp = types.SimpleNamespace(
            getgrnam=lambda _: types.SimpleNamespace(gr_gid=os.getgid())
        )
        context = patch.dict(
            self.globals,
            SWAMP_HOME=self.home,
            SWAMP_ROOT=self.root,
            pwd=self.pwd,
            grp=self.grp,
        )
        context.start()
        self.addCleanup(context.stop)
        self.leaf = {
            "scope": "role-swamp",
            "domain": "swamp.orbit",
            "version": "0123456789abcdef",
            "root_hash": hashlib.sha256(self.root.read_bytes()).hexdigest(),
        }

    def test_exact_paths_and_owner_use_no_caddy_pair_or_service_root_file(self):
        self.assertEqual(
            (self.current / "cert.pem", self.current / "key.pem", self.root),
            self.paths(self.leaf),
        )
        self.assertEqual(
            (os.getuid(), os.getgid(), 0o700, 0o600),
            self.globals["role_owner"](self.leaf),
        )
        for change in [{"scope": "swamp"}, {"domain": "foreign.orbit"}]:
            with (
                self.subTest(change=change),
                self.assertRaisesRegex(ValueError, "certificate.role_input_invalid"),
            ):
                self.paths({**self.leaf, **change})

    def test_wrong_or_absent_public_trust_refuses_before_the_pair_directory_exists(
        self,
    ):
        for present in [True, False]:
            if present:
                self.root.write_bytes(b"unrelated public CA")
            else:
                self.root.unlink()
            with (
                self.subTest(present=present),
                self.assertRaisesRegex(ValueError, "certificate.root_mismatch"),
            ):
                self.paths(self.leaf)
            self.assertFalse(self.current.exists())

    def test_nonregular_pair_and_symlink_directory_refuse(self):
        self.current.parent.mkdir(parents=True)
        target = Path(self.tmp.name) / "other"
        target.mkdir()
        self.current.symlink_to(target, target_is_directory=True)
        with self.assertRaisesRegex(ValueError, "certificate.role_path_invalid"):
            self.paths(self.leaf)
        self.current.unlink()
        self.current.mkdir()
        for name in ["cert.pem", "key.pem"]:
            path = self.current / name
            path.symlink_to(target / "untouched")
            with (
                self.subTest(name=name),
                self.assertRaisesRegex(ValueError, "certificate.role_path_invalid"),
            ):
                self.paths(self.leaf)
            path.unlink()
        os.mkfifo(self.current / "key.pem")
        with self.assertRaisesRegex(ValueError, "certificate.role_path_invalid"):
            self.paths(self.leaf)
        self.assertEqual([], list(target.iterdir()))

    def test_wrong_home_or_missing_service_account_refuses(self):
        self.user.pw_dir = "/another/home"
        with self.assertRaisesRegex(ValueError, "certificate.account_invalid"):
            self.paths(self.leaf)

        def missing(_):
            raise KeyError("orbit")

        self.pwd.getpwnam = missing
        with self.assertRaisesRegex(ValueError, "certificate.account_invalid"):
            self.paths(self.leaf)


if __name__ == "__main__":
    unittest.main()
