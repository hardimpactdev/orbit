"""Retained residue removal against isolated real filesystem trees."""

import getpass
import os
import pathlib
import re
import tempfile
import unittest

PROGRAM = re.search(
    r"String.raw`(.*?)`;",
    pathlib.Path(__file__).with_name("system.ts").read_text(),
    re.DOTALL,
)[1]


class CertificateRemovalTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = pathlib.Path(self.temp.name)
        self.ns = {"__name__": "fixture"}
        exec(PROGRAM, self.ns)  # noqa: S102 - test the retained production program.
        self.ns["CADDY"] = self.root / "caddy"
        self.home = self.root / "home"
        self.home.mkdir()
        self.uid = os.getuid()
        self.user = getpass.getuser()
        self.leaf = {"scope": "route-42", "home": str(self.home), "user": self.user}
        self.lookups = []
        self.real_run = self.ns["run"]

        def account_lookup(*args):
            self.lookups.append(args)
            self.assertEqual(("/usr/bin/getent", "passwd", self.user), args)
            return f"{self.user}:x:{self.uid}:{os.getgid()}:fixture:{self.home}:/bin/sh\n"

        self.ns["run"] = account_lookup
        self.paths = self.seed_scope("route-42")
        self.unrelated = self.seed_scope("route-43")

    def seed_scope(self, scope):
        paths = self.ns["roots"]({**self.leaf, "scope": scope})
        for path in paths:
            path.mkdir(parents=True)
            (path / "leaf.pem").write_text("fixture")
        return paths

    def remove(self, leaf=None):
        return self.ns["execute"]("certificate.remove-leaf", self.leaf if leaf is None else leaf)

    def assert_preserved(self):
        for path in (*self.paths, *self.unrelated):
            self.assertEqual("fixture", (path / "leaf.pem").read_text())

    def test_remove_clears_only_the_admitted_scope_and_is_repeatable(self):
        for _ in range(2):
            self.assertEqual({}, self.remove())
        self.assertTrue(all(not path.exists() for path in self.paths))
        for path in self.unrelated:
            self.assertEqual("fixture", (path / "leaf.pem").read_text())

    def test_absent_home_resolves_from_the_admitted_user(self):
        self.assertEqual({}, self.remove({"scope": "route-42", "user": self.user}))
        self.assertEqual([("/usr/bin/getent", "passwd", self.user)], self.lookups)
        self.assertTrue(all(not path.exists() for path in self.paths))

    def test_mismatched_home_refuses_before_deletion(self):
        with self.assertRaisesRegex(ValueError, "certificate.home_mismatch"):
            self.remove({**self.leaf, "home": str(self.root / "foreign")})
        self.assert_preserved()

    def test_wrong_home_owner_refuses_before_deletion(self):
        self.uid += 1
        with self.assertRaisesRegex(ValueError, "certificate.home_unsafe"):
            self.remove()
        self.assert_preserved()

    def test_symlink_home_refuses_before_deletion(self):
        target = self.root / "moved-home"
        self.home.rename(target)
        self.home.symlink_to(target)
        with self.assertRaisesRegex(ValueError, "certificate.home_unsafe"):
            self.remove()
        self.assert_preserved()

    def test_invalid_username_refuses_before_lookup_or_deletion(self):
        for user in ("orbit;id", "../orbit", "", None):
            with self.subTest(user=user), self.assertRaisesRegex(ValueError, "certificate.account_invalid"):
                self.remove({**self.leaf, "user": user})
        self.assertEqual([], self.lookups)
        self.assert_preserved()

    def test_invalid_scope_refuses_before_deletion(self):
        for scope in ("../route-42", "role-gateway", "route-0", "route-42/.."):
            with self.subTest(scope=scope), self.assertRaises(ValueError):
                self.remove({**self.leaf, "scope": scope})
        self.assert_preserved()

    def test_removed_actions_refuse_before_lookup_or_effects(self):
        for action in ("certificate.request-leaf", "certificate.publish-leaf", "certificate.inspect-leaf"):
            with self.subTest(action=action), self.assertRaises(ValueError):
                self.ns["execute"](action, self.leaf)
        self.assertEqual([], self.lookups)
        self.assert_preserved()

    def test_retained_program_exposes_no_issuance_trust_or_reload_helpers(self):
        self.assertEqual(
            {"run", "local_account", "roots", "execute"},
            {key for key, value in self.ns.items() if callable(value) and key != "P"},
        )
        for command in ("openssl", "systemctl", "update-ca-certificates"):
            self.assertNotIn(command, PROGRAM)

    def test_fixed_getent_reads_the_existing_account(self):
        self.ns["run"] = self.real_run
        uid, gid, home = self.ns["local_account"]({"user": self.user})
        self.assertEqual(os.getuid(), uid)
        self.assertIsInstance(gid, int)
        self.assertTrue(pathlib.Path(home).is_dir())


if __name__ == "__main__":
    unittest.main()
