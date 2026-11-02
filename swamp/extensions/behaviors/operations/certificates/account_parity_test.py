"""Legacy managed-home validation at the canonical native certificate boundary."""

import importlib.util
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

spec = importlib.util.spec_from_file_location(
    "certificate_account_policy", Path(__file__).parent / "scripts/local.py"
)
policy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(policy)


class ManagedHomeParity(unittest.TestCase):
    def resolve(self, home, supplied=None):
        user = SimpleNamespace(pw_uid=1000, pw_gid=1000, pw_dir=home)
        raw = {"user": "orbit", "scope": "route-7"}
        if supplied is not None:
            raw["home"] = supplied
        with patch.object(policy.pwd, "getpwnam", return_value=user) as lookup:
            result = policy.account(raw)
            lookup.assert_called_once_with("orbit")
            return result

    def test_admitted_account_supplies_the_home_when_no_home_is_captured(self):
        user, root, caddy = self.resolve("/srv/managed")
        self.assertEqual(1000, user.pw_uid)
        self.assertEqual(Path("/srv/managed/.orbit/certificates/route-7"), root)
        self.assertEqual(Path("/etc/caddy/orbit-certificates/route-7"), caddy)

    def test_supplied_home_must_match_the_account_before_paths_are_selected(self):
        with self.assertRaisesRegex(ValueError, "certificate.home_mismatch"):
            self.resolve("/srv/managed", "/srv/foreign")

    def test_legacy_invalid_account_homes_refuse_before_paths_are_selected(self):
        for home in (
            "relative/home",
            "/",
            "/srv//managed",
            "/srv/./managed",
            "/srv/../managed",
            "/srv/managed/",
            "/srv/managed\n",
            "/srv/managed\r",
        ):
            with (
                self.subTest(home=home),
                self.assertRaisesRegex(ValueError, "certificate.account_invalid"),
            ):
                self.resolve(home)


if __name__ == "__main__":
    unittest.main()
