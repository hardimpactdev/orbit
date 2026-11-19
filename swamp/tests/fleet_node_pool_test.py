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
P = "orbit-releases"


class Incus:
    def __init__(self, pools, instances, volumes=(), images=()):
        self.pools = {name: {"driver": driver, "config": {}} for name, driver in pools.items()}
        self.instances = {i["name"]: i for i in instances}
        self.volumes = {v: {"pool": pool, "used_by": [], "backups": []} for v, pool in volumes}
        self.images = {i: pool for i, pool in images}
        self.profile = {"name": "default", "devices": {"root": {"type": "disk", "path": "/", "pool": P}}}
        self.calls = []
        self.fail_at = None

    def used_by(self, pool):
        out = [f"/1.0/instances/{n}?project={P}" for n, i in self.instances.items() if self.root(i)["pool"] == pool]
        out += [f"/1.0/storage-pools/{pool}/volumes/custom/{v}?project={P}" for v, r in self.volumes.items() if r["pool"] == pool]
        out += [f"/1.0/storage-pools/{pool}/volumes/image/{i}?project={P}" for i, p in self.images.items() if p == pool]
        if any(d.get("pool") == pool for d in self.profile["devices"].values()):
            out.append(f"/1.0/profiles/default?project={P}")
        return out

    def expanded(self, instance):
        devices = copy.deepcopy(self.profile["devices"])
        for name, device in instance["devices"].items():
            devices[name] = {**devices.get(name, {}), **device}
        return devices

    def root(self, instance):
        return self.expanded(instance)["root"]

    def record(self, instance):
        return {**copy.deepcopy(instance), "project": P, "expanded_devices": self.expanded(instance)}

    def __call__(self, *args, timeout=600):
        self.calls.append(args)
        if self.fail_at and self.fail_at(args):
            raise subprocess.CalledProcessError(1, args)
        a = list(args)
        if a[0] == "query":
            path = urllib.parse.urlparse(a[2]).path
            if path == "/1.0/storage-pools":
                return json.dumps([{"name": n, "driver": r["driver"], "config": r["config"], "used_by": self.used_by(n)} for n, r in self.pools.items()])
            if path == "/1.0/instances":
                return json.dumps([self.record(i) for i in self.instances.values()])
            if path.startswith("/1.0/instances/"):
                name = path.split("/")[3]
                return json.dumps(self.instances[name].get("backups", []) if path.endswith("/backups") else self.record(self.instances[name]))
            if "/volumes/custom/" in path:
                volume = self.volumes[path.split("/")[6]]
                return json.dumps(volume["backups"] if path.endswith("/backups") else {"used_by": volume["used_by"]})
            if path.startswith("/1.0/profiles/"):
                return json.dumps(self.profile)
        if a[:2] == ["storage", "create"]:
            assert a[3] == "btrfs" and a[2] not in self.pools
            self.pools[a[2]] = {"driver": "btrfs", "config": dict(item.split("=", 1) for item in a[5:])}
        elif a[:2] == ["storage", "delete"]:
            assert not self.used_by(a[2]), self.used_by(a[2])
            del self.pools[a[2]]
        elif a[:3] == ["storage", "volume", "move"]:
            assert all(i["status"] == "Stopped" for i in self.instances.values())
            self.volumes[a[3].split("/")[1]]["pool"] = a[4].split("/")[0]
        elif a[:3] == ["storage", "volume", "delete"]:
            del self.images[a[4].split("/")[1]]
        elif a[0] == "move":
            instance = self.instances[a[1]]
            assert instance["status"] == "Stopped"
            assert not (self.pools[a[-1]]["driver"] == "btrfs" and any(
                d.get("path") == "/" and "size" in d for d in self.expanded(instance).values())), "qgroup limit exits 255"
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
            self.instances[a[3]]["devices"][a[4]] = dict(self.profile["devices"][a[4]])
        elif a[:3] == ["config", "device", "unset"]:
            del self.instances[a[3]]["devices"][a[4]][a[5]]
        elif a[:3] == ["profile", "device", "unset"]:
            del self.profile["devices"][a[4]][a[5]]
        elif a[:3] == ["profile", "device", "set"]:
            self.profile["devices"][a[4]]["pool"] = a[5].split("=")[1]
        return ""


def instance(name, status, devices=None, **extra):
    return {"name": name, "status": status, "config": {}, "devices": devices or {}, **extra}


def writes(fake):
    return [c for c in fake.calls if c[0] != "query"]


class FleetNodePoolTest(unittest.TestCase):
    def converge(self, fake, pool=P):
        script = runpy.run_path(str(ROOT / "ansible/files/fleet-node-pool.py"))
        script["converge"].__globals__["incus"] = fake
        return script["converge"](pool)

    def seed(self):
        """An older nested app-prod: a dir release pool with a live, a stopped old and a frozen preparer, a volume and an image."""
        return Incus({P: "dir"}, [instance("live", "Running"), instance("old", "Stopped"), instance("prep", "Frozen")],
                     volumes=[("shared", P)], images=[("abc", P)])

    def assert_moved(self, fake):
        self.assertEqual({n: r["driver"] for n, r in fake.pools.items()}, {P: "btrfs"})
        self.assertEqual({n: (i["status"], fake.root(i)["pool"]) for n, i in fake.instances.items()},
                         {"live": ("Running", P), "old": ("Stopped", P), "prep": ("Frozen", P)})
        self.assertFalse(any(MARK in i["config"] for i in fake.instances.values()))
        self.assertEqual(fake.volumes["shared"]["pool"], P)
        self.assertEqual(fake.profile["devices"]["root"]["pool"], P)

    def test_a_dir_pool_moves_to_btrfs_with_every_instance_in_its_state_and_a_rerun_changes_nothing(self):
        fake = self.seed()
        self.assertEqual(self.converge(fake), {"changed": True})
        self.assert_moved(fake)
        fake.calls.clear()
        self.assertEqual(self.converge(fake), {"changed": False})
        self.assertEqual(writes(fake), [])

    def test_the_spare_pool_names_its_owner(self):
        fake = self.seed()
        fake.fail_at = lambda args: args[:3] == ("storage", "delete", P)
        with self.assertRaises(subprocess.CalledProcessError):
            self.converge(fake)
        self.assertEqual(fake.pools[P + "-move"]["config"], {MARK: P})
        self.assertEqual(fake.instances["prep"]["config"][MARK], P + ":frozen")

    def test_a_converge_stopped_part_way_finishes_on_the_next_run(self):
        for step in (("move", "old"), ("storage", "delete", P), ("storage", "create", P),
                     ("move", "live", "--project", P, "--storage", P), ("start",), ("pause",)):
            with self.subTest(step=step):
                fake = self.seed()
                fake.fail_at = lambda args, step=step: args[:len(step)] == step
                with self.assertRaises(subprocess.CalledProcessError):
                    self.converge(fake)
                fake.fail_at = None
                self.assertEqual(self.converge(fake), {"changed": True})
                self.assert_moved(fake)

    def test_an_instance_already_in_its_recorded_state_only_loses_its_marker(self):
        fake = Incus({P: "btrfs"}, [instance("prep", "Frozen", {"root": {"pool": P}}), instance("live", "Running")])
        fake.instances["prep"]["config"][MARK] = P + ":frozen"
        fake.instances["live"]["config"][MARK] = P + ":running"
        self.assertEqual(self.converge(fake), {"changed": True})
        self.assertEqual(writes(fake), [("config", "unset", "prep", MARK, "--project", P), ("config", "unset", "live", MARK, "--project", P)])

    def test_markers_and_spare_pools_of_another_owner_are_left_alone(self):
        fake = Incus({P: "btrfs", "other": "btrfs"}, [instance("foreign", "Stopped", {"root": {"pool": "other"}}),
                                                     instance("mine", "Stopped", {"root": {"pool": "other"}})])
        fake.instances["foreign"]["config"][MARK] = "elsewhere:running"
        fake.instances["mine"]["config"][MARK] = P + ":running"
        self.assertEqual(self.converge(fake), {"changed": False})
        self.assertEqual(writes(fake), [])
        fake = self.seed()
        fake.pools[P + "-move"] = {"driver": "btrfs", "config": {}}
        with self.assertRaisesRegex(SystemExit, f"the pool {P}-move was not made by the move of {P}"):
            self.converge(fake)
        self.assertEqual(writes(fake), [])

    def test_every_root_size_goes_before_btrfs_on_instances_and_profiles(self):
        fake = Incus({P: "dir"}, [instance("i1", "Stopped", {"rootfs": {"type": "disk", "path": "/", "pool": P, "size": "20GiB"}}),
                                  instance("i2", "Stopped")])
        fake.profile["devices"] = {"root": {"type": "disk", "path": "/", "pool": P, "size": "10GiB"}}
        self.converge(fake)
        self.assertNotIn("size", fake.profile["devices"]["root"])
        for name in ("i1", "i2"):
            self.assertFalse([d for d in fake.expanded(fake.instances[name]).values() if "size" in d], name)

    def test_what_a_move_would_lose_refuses_before_anything_stops(self):
        cases = {
            "ephemeral": lambda f: f.instances["live"].update(ephemeral=True),
            "has Incus backups that a move drops": lambda f: f.instances["old"].update(backups=["/1.0/instances/old/backups/b0"]),
            "volume orbit-releases/shared on the dir pool orbit-releases has Incus backups":
                lambda f: f.volumes["shared"].update(backups=["b0"]),
            "is used by /1.0/instances/elsewhere": lambda f: f.volumes["shared"].update(used_by=[f"/1.0/instances/elsewhere?project={P}"]),
            "is used by /1.0/profiles/data": lambda f: f.volumes["shared"].update(used_by=[f"/1.0/profiles/data?project={P}"]),
        }
        for message, change in cases.items():
            with self.subTest(message=message):
                fake = self.seed()
                change(fake)
                with self.assertRaisesRegex(SystemExit, "instance.host_unavailable: .*" + message):
                    self.converge(fake)
                self.assertEqual(writes(fake), [])

    def test_a_missing_pool_is_created_and_another_driver_refuses_untouched(self):
        fake = Incus({}, [])
        self.assertEqual(self.converge(fake), {"changed": True})
        self.assertEqual(fake.pools[P]["driver"], "btrfs")
        fake = Incus({P: "zfs"}, [instance("live", "Running")])
        with self.assertRaisesRegex(SystemExit, "instance.host_unavailable: the Incus pool orbit-releases uses the zfs driver"):
            self.converge(fake)
        self.assertEqual(writes(fake), [])

    def test_a_create_that_hangs_is_killed_and_the_next_run_finishes(self):
        fake = self.seed()
        real = fake.__call__
        hung = []

        def hang(*args, timeout=600):
            if args[:3] == ("storage", "create", P) and not hung:
                hung.append(timeout)
                raise subprocess.TimeoutExpired(args, timeout)
            return real(*args, timeout=timeout)
        script = runpy.run_path(str(ROOT / "ansible/files/fleet-node-pool.py"))
        script["converge"].__globals__["incus"] = hang
        with self.assertRaises(subprocess.TimeoutExpired):
            script["converge"](P)
        self.assertEqual(script["converge"](P), {"changed": True})
        self.assertEqual(hung, [120])
        self.assert_moved(fake)


if __name__ == "__main__":
    unittest.main()
