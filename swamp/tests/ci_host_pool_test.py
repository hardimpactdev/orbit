"""The CI role's pool in the role playbook (ansible/files/ci-host-pool.py, ansible/tasks/ci.yml) against a fake Incus, and its
agreement with the agent's ci-runner.host (apps/agent/src/commands/ci_runner.rs)."""
import json
import re
import runpy
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SPEC = (ROOT / "extensions/behaviors/operations/node/ci_host_spec.ts").read_text()
ARGS = ("orbit-ci", "orbit-ci", "orbit-instances", "user.orbit.ci-runner")


def spec(name):
    return re.search(rf'export const {name} = "([^"]+)";', SPEC).group(1)


class Incus:
    """Pools and the orbit-ci project's runners as Incus API objects; each command changes them as Incus would."""

    def __init__(self, pools, runners=()):
        self.pools = pools
        self.runners = {name: {"devices": {}, "expanded_devices": {"root": {"pool": pool}}} for name, pool in runners}
        self.calls = []

    def __call__(self, *args):
        if args[0] == "query":
            path = args[1].split("?")[0]
            if path == "/1.0/storage-pools":
                return json.dumps([f"/1.0/storage-pools/{name}" for name in self.pools])
            if path.startswith("/1.0/storage-pools/"):
                return json.dumps(self.pools[path.rsplit("/", 1)[-1]])
            if path == "/1.0/instances":
                return json.dumps([f"/1.0/instances/{name}?project=orbit-ci" for name in self.runners])
            return json.dumps(self.runners[path.removeprefix("/1.0/instances/")])
        self.calls.append(args)
        if args[:4] == ("--project", "default", "storage", "create"):
            name, driver, source, owner = args[4:]
            self.pools[name] = {"driver": driver, "config": dict(item.split("=", 1) for item in (source, owner))}
        elif args[:3] == ("config", "device", "add"):
            root = {"pool": args[7].removeprefix("pool=")}
            self.runners[args[3]]["devices"]["root"] = root
            self.runners[args[3]]["expanded_devices"]["root"] = root
        else:
            raise AssertionError(args)
        return ""


class CiHostPoolTest(unittest.TestCase):
    def run_helper(self, incus, mode):
        module = runpy.run_path(str(ROOT / "ansible/files/ci-host-pool.py"))
        module["main"].__globals__["incus"] = incus
        return module["main"](mode, *ARGS)

    def test_creates_the_marked_pool_beside_the_instance_pool_and_repeats_without_change(self):
        for driver, source, created in (("zfs", "data/orbit-instances", "data/orbit-ci"), ("zfs", "fast", "fast/orbit-ci"),
                                        ("btrfs", "/var/lib/incus/storage-pools/orbit-instances",
                                         "/var/lib/incus/storage-pools/orbit-ci")):
            with self.subTest(source=source):
                incus = Incus({"default": {"driver": "dir", "config": {}},
                               "orbit-instances": {"driver": driver, "config": {"source": source}}})
                self.assertTrue(self.run_helper(incus, "pool"))
                self.assertEqual(incus.calls, [("--project", "default", "storage", "create", "orbit-ci", driver,
                                                f"source={created}", "user.orbit.ci-runner=true")])
                self.assertFalse(self.run_helper(incus, "pool"))
                self.assertFalse(self.run_helper(incus, "check"))
                self.assertEqual(len(incus.calls), 1)
                self.assertEqual(incus.pools["default"], {"driver": "dir", "config": {}})

    def test_refuses_a_node_without_a_zfs_or_btrfs_instance_pool_and_never_creates_a_dir_pool(self):
        for pools in ({}, {"orbit-instances": {"driver": "dir", "config": {}}}, {"orbit-instances": {"driver": "lvm", "config": {}}},
                      {"orbit-instances": {"driver": "zfs", "config": {"source": "-o/x"}}}):
            with self.subTest(pools=pools):
                incus = Incus(dict(pools))
                with self.assertRaisesRegex(ValueError, "needs the Node's orbit-instances pool on zfs or btrfs"):
                    self.run_helper(incus, "pool")
                self.assertEqual(incus.calls, [])

    def test_refuses_a_dir_unmarked_or_missing_ci_pool_before_any_change(self):
        source = {"orbit-instances": {"driver": "zfs", "config": {"source": "data/orbit-instances"}}}
        for mode, pool, message in (
                ("pool", {"driver": "dir", "config": {"user.orbit.ci-runner": "true"}}, "pool is dir; CI runners need a zfs or btrfs"),
                ("check", {"driver": "dir", "config": {"user.orbit.ci-runner": "true"}}, "pool is dir"),
                ("pool", {"driver": "zfs", "config": {"source": "data/orbit-ci"}}, "not owned by the CI role"),
                ("check", None, "the orbit-ci pool is missing")):
            with self.subTest(mode=mode, pool=pool):
                incus = Incus({**source, **({"orbit-ci": pool} if pool else {})})
                with self.assertRaisesRegex(ValueError, message):
                    self.run_helper(incus, mode)
                self.assertEqual(incus.calls, [])

    def test_pins_each_runner_on_another_pool_once(self):
        incus = Incus({}, [("ci-shark-1", "default"), ("ci-shark-10", "default"), ("ci-shark-2", "orbit-ci")])
        self.assertTrue(self.run_helper(incus, "pin"))
        self.assertEqual(incus.calls, [("config", "device", "add", name, "root", "disk", "path=/", "pool=default", "--project", "orbit-ci")
                                       for name in ("ci-shark-1", "ci-shark-10")])
        self.assertEqual(incus.runners["ci-shark-2"]["devices"], {})
        self.assertFalse(self.run_helper(incus, "pin"))
        self.assertEqual(len(incus.calls), 2)

    def test_the_cli_refuses_unknown_modes(self):
        result = subprocess.run(["python3", str(ROOT / "ansible/files/ci-host-pool.py"), "create", *ARGS], capture_output=True)
        self.assertEqual(result.returncode, 64)

    def test_the_playbook_and_the_agent_share_one_pool_rule(self):
        agent = (ROOT.parent / "apps/agent/src/commands/ci_runner.rs").read_text()
        rust = dict(re.findall(r'const (POOL|SOURCE_POOL|OWNER): &str = "([^"]+)";', agent))
        self.assertEqual((spec("pool"), spec("sourcePool"), spec("poolOwner")), (rust["POOL"], rust["SOURCE_POOL"], rust["OWNER"]))
        self.assertEqual(spec("pool"), "orbit-ci")
        self.assertIn("root: { path: \"/\", pool, type: \"disk\" }", SPEC)
        helper = (ROOT / "ansible/files/ci-host-pool.py").read_text()
        for message in ("the {name} pool is {driver}; CI runners need a zfs or btrfs pool", "the {name} pool is not owned by the CI role",
                        "the CI pool needs the Node's {source_name} pool on zfs or btrfs"):
            self.assertIn(message, helper)
        tasks = (ROOT / "ansible/tasks/ci.yml").read_text()
        self.assertNotIn("- dir\n", tasks)
        self.assertNotIn("storage-pools", tasks)
        steps = [line.removeprefix("- name: ") for line in tasks.splitlines() if line.startswith("- name: ")]
        self.assertLess(steps.index("Keep the CI pool on ZFS or btrfs"), steps.index("Create missing CI resources"))
        self.assertLess(steps.index("Pin each runner on another pool before the CI profile root moves"), steps.index("Apply the CI profile"))


if __name__ == "__main__":
    unittest.main()
