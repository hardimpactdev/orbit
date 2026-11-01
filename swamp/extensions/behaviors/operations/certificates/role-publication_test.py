"""Retained withdrawal preserves ownership checks and never publishes or reloads."""

import os
import pathlib
import tempfile
import types
import unittest

HERE = pathlib.Path(__file__).parent


class RoleWithdrawal(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = pathlib.Path(self.tmp.name) / "caddy"
        self.root.mkdir(mode=0o755)
        self.ns = {"__name__": "fixture"}
        exec((HERE / "retained_role_policy.py").read_text(), self.ns)
        self.ns["ROOT"] = self.root
        self.ns["OWNER_UID"] = os.getuid()
        self.ns["grp"] = types.SimpleNamespace(
            getgrnam=lambda _: types.SimpleNamespace(gr_gid=os.getgid())
        )
        self.current = self.root / "orbit-metrics-cert-current"
        self.current.mkdir(mode=0o750)
        for name in ("metrics.pem", "metrics.key", "root-ca.pem"):
            path = self.current / name
            path.write_text("existing local bytes")
            path.chmod(0o640)
        self.payload = {"directory": "/etc/caddy/orbit-metrics-cert-current"}

    def test_owned_withdrawal_is_repeatable_and_has_no_publication_executor(self):
        self.assertEqual({}, self.ns["role_remove"](self.payload))
        self.assertEqual({}, self.ns["role_remove"](self.payload))
        self.assertFalse(self.current.exists())
        for name in (
            "role_publish",
            "role_request",
            "certificate_reload",
            "exchange_pair",
        ):
            self.assertNotIn(name, self.ns)

    def test_foreign_file_or_link_refuses_deletion(self):
        foreign = self.current / "foreign"
        foreign.write_text("keep")
        with self.assertRaises(Exception):
            self.ns["role_remove"](self.payload)
        self.assertEqual("keep", foreign.read_text())
        foreign.unlink()
        key = self.current / "metrics.key"
        key.unlink()
        key.symlink_to(self.root / "outside")
        with self.assertRaises(Exception):
            self.ns["role_remove"](self.payload)
        self.assertTrue(key.is_symlink())

    def test_path_traversal_refuses_before_deletion(self):
        with self.assertRaises(Exception):
            self.ns["role_remove"]({"directory": "/etc/caddy/../foreign-cert-current"})
        self.assertTrue(self.current.is_dir())


if __name__ == "__main__":
    unittest.main()
