"""The btrfs pool of an Instance fleet Node (ansible/files/fleet-node-pool.py) against a fake Incus that keeps pools, instances,
volumes and profiles."""
import copy
import json
import runpy
import subprocess
import unittest
import urllib.parse
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MARK = "user.orbit.pool-move"


class Incus:
    def __init__(self, pools, instances, volumes=(), images=()):
        self.pools = dict(pools)
        self.instances = {i["name"]: i for i in instances}
        self.volumes = {v: pool for v, pool in volumes}
        self.images = {i: pool for i, pool in images}
        self.profile = {"name": "default", "devices": {"root": {"type": "disk", "path": "/", "pool": "orbit-releases"}}}
        self.calls = []
        self.fail_at = None

    def used_by(self, pool):
        out = [f"/1.0/instances/{n}?project=orbit-releases" for n, i in self.instances.items() if self.root(i)["pool"] == pool]
        out += [f"/1.0/storage-pools/{pool}/volumes/custom/{v}?project=orbit-releases" for v, p in self.volumes.items() if p == pool]
        out += [f"/1.0/storage-pools/{pool}/volumes/image/{i}?project=orbit-releases" for i, p in self.images.items() if p == pool]
        if self.profile["devices"]["root"]["pool"] == pool:
            out.append("/1.0/profiles/default?project=orbit-releases")
        return out

    def root(self, instance):
        return {**self.profile["devices"]["root"], **instance["devices"].get("root", {})}

    def record(self, instance):
        return {**copy.deepcopy(instance), "project": "orbit-releases", "expanded_devices": {"root": self.root(instance)}}

    def __call__(self, *args, timeout=600):
        self.calls.append(args)
        if self.fail_at and self.fail_at(args):
            raise subprocess.CalledProcessError(1, args)
        a = list(args)
        if a[0] == "query":
            url = urllib.parse.urlparse(a[2])
            if url.path == "/1.0/storage-pools":
                return json.dumps([{"name": n, "driver": d, "used_by": self.used_by(n)} for n, d in self.pools.items()])
            if url.path == "/1.0/instances":
                return json.dumps([self.record(i) for i in self.instances.values()])
            if url.path.startswith("/1.0/instances/"):
                return json.dumps(self.record(self.instances[url.path.split("/")[-1]]))
            if url.path.startswith("/1.0/profiles/"):
                return json.dumps(self.profile)
        if a[:2] == ["storage", "create"]:
            assert a[3] == "btrfs" and a[2] not in self.pools
            self.pools[a[2]] = "btrfs"
        elif a[:2] == ["storage", "delete"]:
            assert not self.used_by(a[2]), self.used_by(a[2])
            del self.pools[a[2]]
        elif a[:3] == ["storage", "volume", "move"]:
            name = a[3].split("/")[1]
            assert all(i["status"] == "Stopped" for i in self.instances.values())
            self.volumes[name] = a[4].split("/")[0]
        elif a[:3] == ["storage", "volume", "delete"]:
            del self.images[a[4].split("/")[1]]
        elif a[0] == "move":
            instance = self.instances[a[1]]
            assert instance["status"] == "Stopped"
            assert not (self.pools[a[-1]] == "btrfs" and "size" in self.root(instance)), "qgroup limit exits 255"
            instance["devices"]["root"] = {**self.root(instance), "pool": a[-1]}
        elif a[0] == "stop":
            self.instances[a[1]]["status"] = "Stopped"
        elif a[0] == "start":
            self.instances[a[1]]["status"] = "Running"
        elif a[0] == "pause":
            self.instances[a[1]]["status"] = "Frozen"
        elif a[:2] == ["config", "set"]:
            key, _, value = a[5].partition("=")
            self.instances[a[2]]["config"][key] = value
        elif a[:2] == ["config", "unset"]:
            del self.instances[a[2]]["config"][a[3]]
        elif a[:3] == ["config", "device", "override"]:
            self.instances[a[3]]["devices"]["root"] = dict(self.profile["devices"]["root"])
        elif a[:3] == ["config", "device", "unset"]:
            del self.instances[a[3]]["devices"]["root"][a[5]]
        elif a[:3] == ["profile", "device", "set"]:
            self.profile["devices"][a[4]]["pool"] = a[5].split("=")[1]
        return ""


def instance(name, status, devices=None):
    return {"name": name, "status": status, "config": {}, "devices": devices or {}}


class FleetNodePoolTest(unittest.TestCase):
    def converge(self, fake, pool="orbit-releases"):
        script = runpy.run_path(str(ROOT / "ansible/files/fleet-node-pool.py"))
        script["converge"].__globals__["incus"] = fake
        return script["converge"](pool)

    def seed(self):
        """An older nested app-prod: a dir release pool with a live, a stopped old and a frozen preparer, a volume and an image."""
        return Incus({"orbit-releases": "dir"}, [instance("live", "Running"), instance("old", "Stopped"), instance("prep", "Frozen")],
                     volumes=[("shared", "orbit-releases")], images=[("abc", "orbit-releases")])

    def assert_moved(self, fake):
        self.assertEqual(fake.pools, {"orbit-releases": "btrfs"})
        self.assertEqual({n: (i["status"], fake.root(i)["pool"]) for n, i in fake.instances.items()},
                         {"live": ("Running", "orbit-releases"), "old": ("Stopped", "orbit-releases"), "prep": ("Frozen", "orbit-releases")})
        self.assertFalse(any(MARK in i["config"] for i in fake.instances.values()))
        self.assertEqual(fake.volumes, {"shared": "orbit-releases"})
        self.assertEqual(fake.profile["devices"]["root"]["pool"], "orbit-releases")

    def test_a_dir_pool_moves_to_btrfs_with_every_instance_in_its_state_and_a_rerun_changes_nothing(self):
        fake = self.seed()
        self.assertEqual(self.converge(fake), {"changed": True})
        self.assert_moved(fake)
        fake.calls.clear()
        self.assertEqual(self.converge(fake), {"changed": False})
        self.assertEqual([c for c in fake.calls if c[0] != "query"], [])

    def test_a_converge_stopped_part_way_finishes_on_the_next_run(self):
        for step in (("move", "old"), ("storage", "delete", "orbit-releases"), ("storage", "create", "orbit-releases"),
                     ("move", "live", "--project", "orbit-releases", "--storage", "orbit-releases"), ("start",)):
            with self.subTest(step=step):
                fake = self.seed()
                fake.fail_at = lambda args, step=step: args[:len(step)] == step
                with self.assertRaises(subprocess.CalledProcessError):
                    self.converge(fake)
                fake.fail_at = None
                self.assertEqual(self.converge(fake), {"changed": True})
                self.assert_moved(fake)

    def test_a_sized_root_loses_its_size_before_it_reaches_btrfs(self):
        fake = Incus({"orbit-instances": "dir"}, [instance("i1", "Stopped", {"root": {"type": "disk", "path": "/", "pool": "orbit-instances", "size": "20GiB"}})])
        fake.profile["devices"]["root"]["pool"] = "orbit-instances"
        self.converge(fake, "orbit-instances")
        self.assertEqual(fake.root(fake.instances["i1"]), {"type": "disk", "path": "/", "pool": "orbit-instances"})

    def test_a_missing_pool_is_created_and_another_driver_refuses_untouched(self):
        fake = Incus({}, [])
        self.assertEqual(self.converge(fake), {"changed": True})
        self.assertEqual(fake.pools, {"orbit-releases": "btrfs"})
        fake = Incus({"orbit-releases": "zfs"}, [instance("live", "Running")])
        with self.assertRaisesRegex(SystemExit, "instance.host_unavailable: the Incus pool orbit-releases uses the zfs driver"):
            self.converge(fake)
        self.assertEqual([c for c in fake.calls if c[0] != "query"], [])

    def test_a_create_that_hangs_is_killed_and_the_next_run_finishes(self):
        fake = self.seed()
        real = fake.__call__
        hung = []

        def hang(*args, timeout=600):
            if args[:3] == ("storage", "create", "orbit-releases") and not hung:
                hung.append(timeout)
                raise subprocess.TimeoutExpired(args, timeout)
            return real(*args, timeout=timeout)
        script = runpy.run_path(str(ROOT / "ansible/files/fleet-node-pool.py"))
        script["converge"].__globals__["incus"] = hang
        with self.assertRaises(subprocess.TimeoutExpired):
            script["converge"]("orbit-releases")
        self.assertEqual(script["converge"]("orbit-releases"), {"changed": True})
        self.assertEqual(hung, [120])
        self.assert_moved(fake)

if __name__ == "__main__":
    unittest.main()
