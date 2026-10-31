"""Domain policy tests without host effects or a live Incus daemon."""

import copy
import grp
import importlib.util
import io
import json
import os
import pwd
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch


def load(name):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).parent / "scripts" / (name + ".py"))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


storage = load("storage")
ci = load("ci_resource")
directory = load("directory")
file = load("file")
account_directory = load("account_directory")
composer_manifest = load("composer_manifest")
observer_identity = load("observer_identity")
observer_checkout = load("observer_checkout")
file_snapshot = load("file_snapshot")
file_restore = load("file_restore")
file_snapshot_cleanup = load("file_snapshot_cleanup")


class RoleFileRecoveryTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.data = {"run_id": "46382a57-31e5-4b13-8938-c14a7951e05f", "path": str(self.root / "configuration")}
        self.snapshot_patch = patch.object(file_snapshot, "SNAPSHOTS", self.root / "snapshots")
        self.restore_patch = patch.object(file_restore, "SNAPSHOTS", self.root / "snapshots")
        self.cleanup_patch = patch.object(file_snapshot_cleanup, "SNAPSHOTS", self.root / "snapshots")
        self.cleanup_patch.start()
        self.addCleanup(self.cleanup_patch.stop)
        self.snapshot_patch.start()
        self.restore_patch.start()
        self.addCleanup(self.snapshot_patch.stop)
        self.addCleanup(self.restore_patch.stop)

    def test_snapshot_keeps_private_bytes_on_target_and_restores_original_mode_owner_and_content(self):
        path = Path(self.data["path"])
        path.write_bytes(b"previous-private-configuration")
        path.chmod(0o400)
        before = path.stat()
        self.assertEqual(file_snapshot.snapshot(self.data), {"ready": True, "changed": False})
        path.chmod(0o644)
        path.write_bytes(b"replacement-private-configuration")
        # Explicit native replay retains the original snapshot.
        file_snapshot.snapshot(self.data)
        self.assertEqual(file_restore.restore(self.data), {"ready": True, "changed": True})
        self.assertEqual(path.read_bytes(), b"previous-private-configuration")
        self.assertEqual((path.stat().st_mode, path.stat().st_uid, path.stat().st_gid),
                         (before.st_mode, before.st_uid, before.st_gid))
        self.assertEqual(list(self.root.glob(".orbit-role-restore-*")), [])

    def test_completed_snapshot_cleanup_removes_only_that_file_and_is_idempotent(self):
        path = Path(self.data["path"])
        path.write_text("private")
        file_snapshot.snapshot(self.data)
        other = {**self.data, "path": str(self.root / "other")}
        file_snapshot.snapshot(other)
        self.assertEqual(file_snapshot_cleanup.cleanup(self.data), {"ready": True, "changed": True})
        self.assertEqual(file_snapshot_cleanup.cleanup(self.data), {"ready": True, "changed": False})
        self.assertEqual(path.read_text(), "private")
        self.assertTrue(Path(str(file_snapshot.location(other)) + ".json").exists())

    def test_initial_absence_is_restored_without_recreating_a_file(self):
        file_snapshot.snapshot(self.data)
        path = Path(self.data["path"])
        path.write_text("new configuration")
        file_restore.restore(self.data)
        self.assertFalse(path.exists())

    def test_missing_snapshot_and_link_source_are_refused_before_mutation(self):
        path = Path(self.data["path"])
        path.write_text("kept")
        with self.assertRaises(FileNotFoundError):
            file_restore.restore(self.data)
        self.assertEqual(path.read_text(), "kept")
        link = self.root / "link"
        link.symlink_to(path)
        with self.assertRaises(ValueError):
            file_snapshot.snapshot({**self.data, "path": str(link)})


class ObserverFilesTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)

    def test_existing_observer_identity_is_verified_without_replacement_or_private_output(self):
        secret = self.root / "secret"
        secret.write_bytes(b"existing-private-identity")
        expected = __import__("hashlib").sha256(secret.read_bytes()).hexdigest()
        with patch.object(observer_identity, "SECRET", secret):
            self.assertEqual(observer_identity.verify(expected), {"ready": True, "changed": False})
            with self.assertRaises(ValueError):
                observer_identity.verify("a" * 64)
        self.assertEqual(secret.read_bytes(), b"existing-private-identity")

    def test_checkout_environment_loses_only_world_permissions(self):
        environment = self.root / ".env"
        environment.write_text("existing-private-environment")
        environment.chmod(0o664)
        before = self.root.stat()
        self.assertEqual(observer_checkout.apply({"checkout": str(self.root)}), {"ready": True, "changed": True})
        self.assertEqual(environment.stat().st_mode & 0o777, 0o660)
        self.assertEqual(environment.read_text(), "existing-private-environment")
        self.assertEqual(self.root.stat().st_mode, before.st_mode)
        self.assertEqual(observer_checkout.apply({"checkout": str(self.root)}), {"ready": True, "changed": False})

    def test_checkout_links_and_missing_environment_are_left_untouched(self):
        actual = self.root / "actual"
        actual.mkdir()
        environment = actual / ".env"
        environment.write_text("private")
        environment.chmod(0o644)
        link = self.root / "link"
        link.symlink_to(actual, target_is_directory=True)
        self.assertEqual(observer_checkout.apply({"checkout": str(link)}), {"ready": True, "changed": False})
        self.assertEqual(environment.stat().st_mode & 0o777, 0o644)
        environment.unlink()
        self.assertEqual(observer_checkout.apply({"checkout": str(actual)}), {"ready": True, "changed": False})


class PrerequisiteFilesTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.user = pwd.getpwuid(os.getuid()).pw_name
        self.group = grp.getgrgid(os.getgid()).gr_name
        self.account = {"user": self.user, "group": self.group}

    def test_application_directory_preserves_the_scratch_parent_and_repairs_only_its_own_mode(self):
        before = self.root.stat()
        data = {**self.account, "home": str(self.root), "scope": "apps"}
        self.assertEqual(account_directory.apply(data), {"ready": True, "changed": True})
        path = self.root / "apps"
        path.chmod(0o700)
        self.assertEqual(account_directory.apply(data), {"ready": True, "changed": True})
        self.assertEqual(account_directory.apply(data), {"ready": True, "changed": False})
        self.assertEqual(path.stat().st_mode & 0o777, 0o755)
        self.assertEqual(self.root.stat().st_mode, before.st_mode)
        self.assertEqual(self.root.stat().st_uid, before.st_uid)

    def test_symlinked_or_foreign_application_directory_is_refused_without_adoption(self):
        path = self.root / "apps"
        path.symlink_to(self.root)
        data = {**self.account, "home": str(self.root), "scope": "apps"}
        with self.assertRaises(ValueError):
            account_directory.apply(data)
        path.unlink()
        path.mkdir(mode=0o700)
        foreign = type("Account", (), {"pw_uid": os.getuid() + 1})()
        with patch.object(account_directory.pwd, "getpwnam", return_value=foreign), self.assertRaises(ValueError):
            account_directory.apply(data)
        self.assertEqual(path.stat().st_mode & 0o777, 0o700)

    def test_composer_directories_are_separate_actions_with_no_ancestor_permission_change(self):
        composer = self.root / "composer"
        with patch.object(account_directory, "COMPOSER", composer):
            for scope, path in (("composer_home", composer), ("composer_vendor", composer / "vendor"),
                                ("composer_bin", composer / "vendor/bin")):
                with self.subTest(scope=scope):
                    self.assertEqual(account_directory.apply({**self.account, "home": str(self.root), "scope": scope}),
                                     {"ready": True, "changed": True})
                    self.assertEqual(path.stat().st_mode & 0o777, 0o755)
        self.assertEqual(self.root.stat().st_mode & 0o777, 0o700)

    def test_composer_manifest_creates_only_missing_intent_and_preserves_existing_dependencies_and_mode(self):
        with patch.object(composer_manifest, "COMPOSER", self.root):
            self.assertEqual(composer_manifest.apply(self.account), {"ready": True, "changed": True})
            manifest = self.root / "composer.json"
            self.assertEqual(manifest.read_text(), '{"require":{}}\n')
            content = '{"require":{"vendor/kept":"^1.0"}}\n'
            manifest.write_text(content)
            manifest.chmod(0o400)
            self.assertEqual(composer_manifest.apply(self.account), {"ready": True, "changed": False})
            self.assertEqual(manifest.read_text(), content)
            self.assertEqual(manifest.stat().st_mode & 0o777, 0o400)

    def test_composer_manifest_refuses_a_foreign_link_without_touching_the_target(self):
        target = self.root / "operator.json"
        target.write_text("operator-owned")
        (self.root / "composer.json").symlink_to(target)
        with patch.object(composer_manifest, "COMPOSER", self.root), self.assertRaises(ValueError):
            composer_manifest.apply(self.account)
        self.assertEqual(target.read_text(), "operator-owned")


class StorageTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / "apps"
        self.intent = {
            "root": str(self.root),
            "user": pwd.getpwuid(os.getuid()).pw_name,
            "group": grp.getgrgid(os.getgid()).gr_name,
        }

    def test_missing_tree_is_created_and_a_second_run_preserves_it(self):
        self.assertEqual(storage.apply(self.intent), {"ready": True, "changed": True})
        self.assertEqual(storage.apply(self.intent), {"ready": True, "changed": False})
        self.assertEqual(self.root.stat().st_mode & 0o777, 0o755)

    def test_existing_writable_root_is_refused_without_repair(self):
        self.root.mkdir(mode=0o777)
        self.root.chmod(0o777)

        with self.assertRaises(ValueError):
            storage.apply(self.intent)

        self.assertEqual(self.root.stat().st_mode & 0o777, 0o777)

    def test_symlink_and_file_are_refused_without_adoption(self):
        self.root.symlink_to(self.temp.name)
        with self.assertRaises(ValueError):
            storage.apply(self.intent)
        self.assertTrue(self.root.is_symlink())


class Incus:
    def __init__(self):
        self.objects = {}
        self.writes = []
        self.refuse = False

    def invoke(self, args, data=None):
        endpoint = args[1]
        collection = endpoint.split("?")[0]
        if len(args) == 2:
            if collection.count("/") == 2:
                return [key for key in self.objects if key.split("?")[0].startswith(collection + "/")]
            return copy.deepcopy(self.objects[endpoint])
        if self.refuse:
            raise OSError("Incus unavailable")
        desired = copy.deepcopy(data)
        self.writes.append((args, desired))
        if "POST" in args:
            endpoint = collection + "/" + desired["name"] + "?" + endpoint.split("?", 1)[1]
        if collection == "/1.0/networks":
            desired["managed"] = True
            desired["config"]["ipv4.address"] = "10.100.0.1/24"
        self.objects[endpoint] = {**self.objects.get(endpoint, {}), **desired}
        if collection == "/1.0/projects" and "POST" in args:
            self.objects["/1.0/profiles/default?project=orbit-ci"] = {"config": {}, "devices": {}}


class DirectoryTest(unittest.TestCase):
    def test_one_directory_uses_its_required_mode_and_does_not_modify_protected_ancestors(self):
        with tempfile.TemporaryDirectory() as temporary:
            parent = Path(temporary)
            parent.chmod(0o700)
            path = parent / "caddy" / "hibernation"
            group = grp.getgrgid(os.getgid())
            with (patch.dict(directory.PATHS, {str(path): 0o2775}, clear=True),
                  patch.object(directory, "PROTECTED", {temporary}),
                  patch.object(directory.grp, "getgrnam", return_value=group),
                  patch.object(directory.os, "chown") as chown):
                self.assertEqual(directory.apply({"path": str(path)}), {"ready": True, "changed": True})
                chown.assert_called_once_with(str(path), 0, group.gr_gid)
            self.assertEqual(path.stat().st_mode & 0o7777, 0o2775)
            self.assertEqual(parent.stat().st_mode & 0o777, 0o700)

    def test_symlink_is_refused_before_permissions_change(self):
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / "hibernation"
            path.symlink_to(temporary)
            with (patch.dict(directory.PATHS, {str(path): 0o755}, clear=True),
                  patch.object(directory.grp, "getgrnam", return_value=grp.getgrgid(os.getgid())),
                  patch.object(directory.os, "chmod") as chmod):
                with self.assertRaises(ValueError):
                    directory.apply({"path": str(path)})
                chmod.assert_not_called()


class FileTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.path = Path(self.temp.name) / "role" / "configuration"
        self.input = {
            "path": str(self.path), "mode": "0640", "owner": f"{os.getuid()}:{os.getgid()}",
            "content": "# Managed by Orbit.\nvalue=new\n", "material_field": "text", "marker": "# Managed by Orbit.\n",
        }

    def test_one_atomic_public_file_is_idempotent_and_applies_its_mode(self):
        self.assertEqual(file.apply(self.input), {"ready": True, "changed": True})
        self.assertEqual(file.apply(self.input), {"ready": True, "changed": False})
        self.assertEqual(self.path.read_text(), self.input["content"])
        self.assertEqual(self.path.stat().st_mode & 0o777, 0o640)
        self.assertEqual(list(self.path.parent.glob(".orbit-role-*")), [])

    def test_failed_rename_keeps_prior_content_and_removes_the_candidate(self):
        file.apply(self.input)
        self.input["content"] = "# Managed by Orbit.\nvalue=replacement\n"
        with patch.object(file.os, "replace", side_effect=OSError("refused")), self.assertRaises(OSError):
            file.apply(self.input)
        self.assertEqual(self.path.read_text(), "# Managed by Orbit.\nvalue=new\n")
        self.assertEqual(list(self.path.parent.glob(".orbit-role-*")), [])

    def test_private_existing_material_selects_only_the_admitted_configuration_file(self):
        self.input.update(content=None, material_field="configuration", marker=None, mode="0400")
        private = {"configuration": {str(self.path): "private-file-fixture", "/unrelated": "not-selected"}}
        with patch.object(sys, "stdin", io.StringIO(json.dumps(private))):
            result = file.apply(self.input)
        self.assertEqual(result, {"ready": True, "changed": True})
        self.assertEqual(self.path.read_text(), "private-file-fixture")
        self.assertNotIn("private-file-fixture", json.dumps(result))

    def test_foreign_file_is_refused_without_overwriting_or_staging(self):
        self.path.parent.mkdir()
        self.path.write_text("operator content")
        with self.assertRaises(ValueError):
            file.apply(self.input)
        self.assertEqual(self.path.read_text(), "operator content")
        self.assertEqual(list(self.path.parent.glob(".orbit-role-*")), [])


class CiTest(unittest.TestCase):
    def test_each_action_creates_only_its_own_resource_and_replays_without_mutation(self):
        daemon = Incus()
        for resource in ("pool", "project", "acl", "network", "profile"):
            with self.subTest(resource=resource):
                before = len(daemon.writes)
                self.assertEqual(ci.apply({"resource": resource}, daemon.invoke), {"ready": True, "changed": True})
                self.assertEqual(len(daemon.writes), before + 1)
                self.assertEqual(ci.apply({"resource": resource}, daemon.invoke), {"ready": True, "changed": False})
                self.assertEqual(len(daemon.writes), before + 1)
        self.assertEqual(daemon.objects["/1.0/profiles/default?project=orbit-ci"]["devices"]["eth0"]["network"], "orbitci0")

    def test_existing_pool_and_unrelated_network_config_are_preserved(self):
        daemon = Incus()
        daemon.objects["/1.0/storage-pools/default?project=default"] = {"driver": "zfs"}
        self.assertEqual(ci.apply({"resource": "pool"}, daemon.invoke), {"ready": True, "changed": False})
        daemon.objects["/1.0/networks/orbitci0?project=default"] = {
            "type": "bridge", "managed": True,
            "config": {"ipv4.address": "10.50.0.1/24", "user.note": "retain"},
        }

        self.assertEqual(ci.apply({"resource": "network"}, daemon.invoke), {"ready": True, "changed": True})

        network = daemon.objects["/1.0/networks/orbitci0?project=default"]
        self.assertEqual(network["config"]["ipv4.address"], "10.50.0.1/24")
        self.assertEqual(network["config"]["user.note"], "retain")

    def test_foreign_network_and_project_are_refused_before_write(self):
        for resource, endpoint, state in (
            ("network", "/1.0/networks/orbitci0?project=default", {"type": "physical", "managed": True, "config": {}}),
            ("project", "/1.0/projects/orbit-ci?project=default", {"config": {"features.networks": "true"}}),
        ):
            with self.subTest(resource=resource):
                daemon = Incus()
                daemon.objects[endpoint] = state
                with self.assertRaises(ValueError):
                    ci.apply({"resource": resource}, daemon.invoke)
                self.assertEqual(daemon.writes, [])

    def test_failed_write_and_uncertain_verification_never_return_readiness(self):
        daemon = Incus()
        daemon.refuse = True
        with self.assertRaises(OSError):
            ci.apply({"resource": "acl"}, daemon.invoke)

        def stale(args, data=None):
            reply = daemon.invoke(args, data)
            return {"egress": [], "ingress": []} if args == ["query", "/1.0/network-acls/orbit-ci-egress?project=default"] else reply

        daemon.refuse = False
        with self.assertRaises(ValueError):
            ci.apply({"resource": "acl"}, stale)


if __name__ == "__main__":
    unittest.main()
