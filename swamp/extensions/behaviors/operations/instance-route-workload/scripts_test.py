"""Workload access and link policy at the host-command boundary."""

import importlib.util
import pwd
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SCRIPTS = Path(__file__).with_name("scripts")


def module(name):
    spec = importlib.util.spec_from_file_location(name, SCRIPTS / (name + ".py"))
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


class WorkloadPolicy(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.user = pwd.getpwuid(self.root.stat().st_uid).pw_name
        self.checkout = self.root / "checkout"
        self.checkout.mkdir()
        self.public = self.checkout / "public"
        self.public.mkdir()
        self.input = {
            "user": self.user,
            "sites": [
                {
                    "checkout": str(self.checkout),
                    "relative_root": "public",
                    "application": str(self.checkout),
                }
            ],
        }
        self.access = module("source_access")

    def test_missing_root_refuses_before_permission_changes(self):
        self.public.rmdir()
        with (
            patch.object(self.access, "command") as command,
            self.assertRaises(ValueError),
        ):
            self.access.grant(self.input)
        command.assert_not_called()

    def test_foreign_symlink_refuses_before_permission_changes(self):
        (self.public / "private").symlink_to(self.checkout / ".env")
        with (
            patch.object(self.access, "command") as command,
            self.assertRaises(ValueError),
        ):
            self.access.grant(self.input)
        command.assert_not_called()

    def test_full_captured_union_is_granted_after_the_checkout_deny(self):
        docs = self.checkout / "docs/public"
        docs.mkdir(parents=True)
        (self.checkout / ".git").mkdir()
        self.input["sites"].append(
            {
                "checkout": str(self.checkout),
                "relative_root": "docs/public",
                "application": str(docs.parent),
            }
        )
        with (
            patch.object(self.access, "command") as command,
            patch.object(self.access, "passage"),
        ):
            self.assertTrue(self.access.grant(self.input)["ready"])
        calls = [call.args[0] for call in command.call_args_list]
        denies = [
            call
            for call in calls
            if "u:caddy:---" in call and call[-1] == str(self.checkout)
        ]
        self.assertEqual(len(denies), 1)
        for root in (self.public, docs):
            self.assertTrue(
                any("u:caddy:r-X" in call and call[-1] == str(root) for call in calls)
            )
        self.assertTrue(
            any(
                "u:caddy:---" in call and call[-1] == str(self.checkout / ".git")
                for call in calls
            )
        )

    def test_unlinked_storage_is_not_exposed(self):
        storage = self.checkout / "storage/app/public"
        storage.mkdir(parents=True)
        with (
            patch.object(self.access, "command") as command,
            patch.object(self.access, "passage"),
        ):
            self.access.grant(self.input)
        self.assertFalse(
            any(str(storage) == call.args[0][-1] for call in command.call_args_list)
        )

    def test_nested_storage_link_refuses_before_permission_changes(self):
        storage = self.checkout / "storage/app/public"
        storage.mkdir(parents=True)
        (self.public / "storage").symlink_to(storage)
        (storage / "private").symlink_to(self.checkout / ".env")
        with (
            patch.object(self.access, "command") as command,
            self.assertRaises(ValueError),
        ):
            self.access.grant(self.input)
        command.assert_not_called()

    def test_storage_exception_requires_the_application_public_root(self):
        storage = self.checkout / "storage/app/public"
        storage.mkdir(parents=True)
        docs = self.checkout / "docs"
        docs.mkdir()
        (docs / "storage").symlink_to(storage)
        self.input["sites"][0]["relative_root"] = "docs"
        with (
            patch.object(self.access, "command") as command,
            self.assertRaises(ValueError),
        ):
            self.access.grant(self.input)
        command.assert_not_called()

    def test_existing_development_current_is_resolved_without_changing_the_link(self):
        home = self.root / "default"
        release = home / "releases/123"
        (release / "public").mkdir(parents=True)
        (home / "current").symlink_to("releases/123")
        self.input["sites"] = [
            {
                "checkout": str(home / "current"),
                "relative_root": "public",
                "application": str(home / "current"),
            }
        ]
        with (
            patch.object(self.access, "command") as command,
            patch.object(self.access, "passage"),
        ):
            self.access.grant(self.input)
        self.assertTrue(
            any(
                str(release / "public") == call.args[0][-1]
                and "u:caddy:r-X" in call.args[0]
                for call in command.call_args_list
            )
        )
        self.assertEqual((home / "current").readlink().as_posix(), "releases/123")
        self.assertEqual(self.input["sites"][0]["checkout"], str(home / "current"))

    def test_passage_adds_only_execute_to_private_mask(self):
        import subprocess

        self.public.chmod(0o700)
        subprocess.run(
            ["/usr/bin/setfacl", "-m", "u:65534:---,m::---", str(self.public)],
            check=True,
        )
        acl = subprocess.check_output(
            ["/usr/bin/getfacl", "-cn", str(self.public)], text=True
        )
        grant = self.access.passage_grant(acl, "65534")
        subprocess.run(
            ["/usr/bin/setfacl", "-n", "-m", grant, str(self.public)], check=True
        )
        actual = subprocess.check_output(
            ["/usr/bin/getfacl", "-cn", str(self.public)], text=True
        )
        self.assertIn("user:65534:--x", actual)
        self.assertIn("mask::--x", actual)
        self.assertNotIn("#effective:---", actual)
        self.assertEqual(
            self.access.passage_grant(
                "user:caddy:r--\ngroup::r-x\nmask::r--\n", "caddy"
            ),
            "u:caddy:r-x,m::r-x",
        )

    def test_storage_parents_receive_effective_passage(self):
        storage = self.checkout / "storage/app/public"
        storage.mkdir(parents=True)
        (self.public / "storage").symlink_to(storage)
        with (
            patch.object(self.access, "command"),
            patch.object(self.access, "passage") as passage,
        ):
            self.access.grant(self.input)
        self.assertIn(
            storage.parent.parent, [call.args[0] for call in passage.call_args_list]
        )
        self.assertIn(storage.parent, [call.args[0] for call in passage.call_args_list])

    def production(self):
        home = self.root / "home"
        release = home / "releases/123"
        (release / "docs/public").mkdir(parents=True)
        (release / "public").mkdir()
        (home / "current").symlink_to("releases/123")
        return (
            home,
            release,
            {
                "user": self.user,
                "home": str(home),
                "default_web_root": "public",
                "default_directory": "",
                "served": [{"web_root": "docs/public", "directory": "docs"}],
            },
        )

    def test_served_root_links_keep_the_existing_relative_layout(self):
        home, release, value = self.production()
        served = module("served_roots")

        def ln(argv, **kwargs):
            Path(argv[-1]).symlink_to(argv[-2])

        with patch.object(served.subprocess, "run", side_effect=ln):
            result = served.prepare(value)
        self.assertEqual(
            (release / "docs/.env").readlink().as_posix(), "../../../env/docs/.env"
        )
        self.assertEqual(len(result["sites"]), 2)
        self.assertEqual(result["sites"][0]["relative_root"], "public")
        self.assertFalse((release / ".env").exists())
        self.assertEqual(home.joinpath("current").readlink().as_posix(), "releases/123")

    def test_served_root_refuses_a_foreign_existing_environment(self):
        _, release, value = self.production()
        (release / "docs/.env").write_text("operator-file")
        with self.assertRaises(ValueError):
            module("served_roots").prepare(value)
        self.assertEqual((release / "docs/.env").read_text(), "operator-file")

    def test_only_the_canonical_storage_link_is_allowed(self):
        storage = self.checkout / "storage/app/public"
        storage.mkdir(parents=True)
        (self.public / "storage").symlink_to(storage)
        with (
            patch.object(self.access, "command") as command,
            patch.object(self.access, "passage"),
        ):
            self.assertTrue(self.access.grant(self.input)["ready"])
        self.assertTrue(
            any(
                str(storage) == call.args[0][-1] and "u:caddy:r-X" in call.args[0]
                for call in command.call_args_list
            )
        )


if __name__ == "__main__":
    unittest.main()
