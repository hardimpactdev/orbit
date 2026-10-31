"""Domain policy tests without host effects or a live Incus daemon."""

import copy
import grp
import importlib.util
import os
import pwd
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
