"""Retained removal parity against the canonical native filesystem action."""

import getpass
import importlib.util
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

SCRIPTS = Path(__file__).parent / "scripts"
sys.path.insert(0, str(SCRIPTS))
import local

spec = importlib.util.spec_from_file_location("native_remove", SCRIPTS / "remove.py")
native_remove = importlib.util.module_from_spec(spec)
spec.loader.exec_module(native_remove)


class CertificateRemovalTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.home = self.root / "home"
        self.home.mkdir()
        self.user = getpass.getuser()
        self.identity = SimpleNamespace(
            pw_uid=os.getuid(), pw_gid=os.getgid(), pw_dir=str(self.home)
        )
        self.account_patch = patch.object(
            local.pwd, "getpwnam", return_value=self.identity
        )
        self.lookup = self.account_patch.start()
        self.addCleanup(self.account_patch.stop)
        self.addCleanup(patch.stopall)
        patch.object(local, "CADDY", self.root / "caddy").start()
        self.caddy = patch.object(
            native_remove.subprocess,
            "run",
            return_value=SimpleNamespace(stdout=json.dumps({"apps": {}}).encode()),
        ).start()
        self.leaf = {
            "scope": "route-42",
            "home": str(self.home),
            "user": self.user,
            "in_use": False,
        }
        self.paths = self.seed_scope("route-42")
        self.unrelated = self.seed_scope("route-43")
        self.lookup.reset_mock()

    def seed_scope(self, scope):
        _, root, caddy = local.account({**self.leaf, "scope": scope})
        for path in (root, caddy):
            path.mkdir(parents=True)
            (path / "leaf.pem").write_text("fixture")
        return root, caddy

    def remove(self, leaf=None):
        return native_remove.remove(self.leaf if leaf is None else leaf)

    def assert_preserved(self):
        for path in (*self.paths, *self.unrelated):
            self.assertEqual("fixture", (path / "leaf.pem").read_text())

    def test_remove_clears_only_the_admitted_scope_and_is_repeatable(self):
        for _ in range(2):
            self.assertEqual({"applied": True}, self.remove())
        self.assertTrue(all(not path.exists() for path in self.paths))
        for path in self.unrelated:
            self.assertEqual("fixture", (path / "leaf.pem").read_text())

    def test_absent_home_resolves_from_the_admitted_user(self):
        leaf = dict(self.leaf)
        del leaf["home"]
        self.assertEqual({"applied": True}, self.remove(leaf))
        self.lookup.assert_called_once_with(self.user)

    def test_mismatched_home_refuses_before_deletion(self):
        with self.assertRaisesRegex(ValueError, "certificate.home_mismatch"):
            self.remove({**self.leaf, "home": str(self.root / "foreign")})
        self.caddy.assert_not_called()
        self.assert_preserved()

    def test_wrong_home_owner_refuses_before_deletion(self):
        self.identity.pw_uid += 1
        with self.assertRaisesRegex(ValueError, "certificate.home_unsafe"):
            self.remove()
        self.caddy.assert_not_called()
        self.assert_preserved()

    def test_symlink_home_refuses_before_deletion(self):
        target = self.root / "moved-home"
        self.home.rename(target)
        self.home.symlink_to(target)
        with self.assertRaisesRegex(ValueError, "certificate.home_unsafe"):
            self.remove()
        self.caddy.assert_not_called()
        self.assert_preserved()

    def test_invalid_username_refuses_before_lookup_or_deletion(self):
        for user in ("orbit;id", "../orbit", "", None):
            with (
                self.subTest(user=user),
                self.assertRaisesRegex(ValueError, "certificate.account_invalid"),
            ):
                self.remove({**self.leaf, "user": user})
        self.lookup.assert_not_called()
        self.caddy.assert_not_called()
        self.assert_preserved()

    def test_invalid_scope_refuses_before_deletion(self):
        for scope in ("../route-42", "role-gateway", "route-0", "route-42/.."):
            with self.subTest(scope=scope), self.assertRaises(ValueError):
                self.remove({**self.leaf, "scope": scope})
        self.caddy.assert_not_called()
        self.assert_preserved()

    def test_missing_or_invalid_account_refuses_before_caddy_or_deletion(self):
        with (
            patch.object(local.pwd, "getpwnam", side_effect=KeyError(self.user)),
            self.assertRaisesRegex(ValueError, "certificate.account_invalid"),
        ):
            self.remove()
        self.identity.pw_uid = "invalid"
        with self.assertRaisesRegex(ValueError, "certificate.account_invalid"):
            self.remove()
        self.caddy.assert_not_called()
        self.assert_preserved()

    def test_native_removal_only_reads_caddy_and_never_signs_or_reloads(self):
        self.remove()
        self.assertEqual(1, self.caddy.call_count)
        self.assertEqual(
            [
                "caddy",
                "adapt",
                "--config",
                "/etc/caddy/Caddyfile",
                "--adapter",
                "caddyfile",
            ],
            self.caddy.call_args.args[0],
        )

    def test_fixed_account_lookup_reads_the_existing_account(self):
        self.account_patch.stop()
        user, root, _ = local.account({"scope": "route-42", "user": self.user})
        self.assertEqual(os.getuid(), user.pw_uid)
        self.assertEqual(Path(user.pw_dir) / ".orbit/certificates/route-42", root)


if __name__ == "__main__":
    unittest.main()
