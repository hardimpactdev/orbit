"""Exercise the identity checks on a release container and its immutable snapshot."""

import importlib.util
import json
import subprocess
import sys
import unittest
from pathlib import Path
from unittest.mock import call, patch


def load(name, relative):
    location = Path(__file__).resolve().parents[1] / relative
    spec = importlib.util.spec_from_file_location(name, location)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


release = load("release_container", "scripts/release-container.py")
power = load("instance_power", "ansible/files/instance-container-power.py")
SHA = "a" * 40
DIGEST = "sha256:" + "f" * 64


def record(name, role, operation="13", status="Running", **tags):
    identity = {"role": role, "target": "instance-8", "operation": operation, **tags}
    return {"name": name, "status": status, "config": {release.PREFIX + k: v for k, v in identity.items()}}


def addressed_candidate(**interfaces):
    return {
        **record("instance-8-" + SHA[:12], "candidate", commit=SHA),
        "expanded_devices": {"eth0": {"type": "nic", "network": "orbitgw0", "ipv4.address": "10.2.3.4"}},
        "state": {"network": {
            interface: {"addresses": [
                {"family": "inet", "scope": "global", "address": ip} for ip in addresses
            ]} for interface, addresses in interfaces.items()
        }},
    }


class ReleaseTest(unittest.TestCase):
    def test_capacity_refuses_each_shortage_before_mutating_a_release(self):
        gib = 1024**3
        prep = {"name": "prepare-instance-8", "status": "Frozen", "expanded_config": {},
                "state": {"memory": {"usage": 0, "swap_usage": gib}},
                "expanded_devices": {"root": {"pool": "selected-pool"}}}
        live = {"name": "instance-8-" + SHA[:12], "status": "Running", "expanded_config": {"limits.memory": "2GiB"},
                "state": {"memory": {"usage": gib, "swap_usage": 0, "total": 2 * gib}}}
        args = ["capacity", "instance-8", "--live", SHA[:12]]
        for ram, swap, disk, shortage in [(6, 1, 10, None), (5, 1, 10, "ram"), (6, 0, 10, "swap"), (6, 1, 9, "pool")]:
            meminfo = f"MemAvailable: {ram * gib // 1024} kB\nSwapFree: {swap * gib // 1024} kB"
            pool = json.dumps({"space": {"total": disk * gib, "used": 0}})
            with self.subTest(shortage=shortage), patch.object(release, "preparer", return_value=prep), \
                    patch.object(release, "release", return_value=live), patch.object(Path, "read_text", return_value=meminfo), \
                    patch("subprocess.check_output", return_value=pool) as query, patch.object(release, "power") as power:
                if shortage:
                    with self.assertRaisesRegex(ValueError, f"deployment.node_capacity: {shortage} needs"):
                        release.main(args)
                else:
                    self.assertEqual(release.main(args)["required_bytes"], {"ram": 6 * gib, "swap": gib, "pool": 10 * gib})
                self.assertEqual(query.call_args.args[0][-1], "/1.0/storage-pools/selected-pool/resources")
                power.assert_not_called()

    def test_first_gateway_build_counts_host_fpm_and_gateway_processes(self):
        services = [
            ("php8.5-fpm.service", "", "active", "6000"),
            ("orbit-process-4.service", "/home/orbit/orbit/apps/gateway", "active", "1234"),
            ("orbit-process-5.service", "/other/app", "active", "15000"),
            ("orbit-process-6.service", "/home/orbit/orbit/apps/gateway", "inactive", "[not set]"),
        ]
        output = "\n\n".join(f"Id={name}\nWorkingDirectory={cwd}\nActiveState={active}\nMemoryCurrent={memory}"
                               for name, cwd, active, memory in services)
        with patch("subprocess.check_output", return_value=output):
            self.assertEqual(release.host_gateway_memory(), (7234, 7234, 0))

    def test_address_is_reserved_once_and_cannot_silently_drift_after_boot(self):
        ip = "10.123.45.67"
        nic = {"type": "nic", "network": "orbitgw0"}
        item = {"name": "gateway-" + SHA[:12], "state": {"network": {"eth0": {
            "addresses": [{"family": "inet", "scope": "global", "address": ip}]}}},
            "devices": {}, "expanded_devices": {"eth0": nic}}
        with patch.object(release, "incus") as incus:
            self.assertEqual(release.address(item), ip)
            incus.assert_called_once_with("config", "device", "override", item["name"], "eth0", "ipv4.address=" + ip)
            incus.reset_mock()
            nic["ipv4.address"] = ip
            self.assertEqual(release.address(item), ip)
            nic["ipv4.address"] = "10.123.45.68"
            with self.assertRaisesRegex(ValueError, "address_changed"):
                release.address(item)
            incus.assert_not_called()

    def test_cold_resume_waits_for_eth0_when_another_interface_gets_ipv4_first(self):
        waiting = addressed_candidate(docker0=["172.17.0.1"])
        stopped = {**waiting, "status": "Stopped", "state": None}
        ready = addressed_candidate(docker0=["172.17.0.1"], eth0=["10.2.3.4"])
        name = stopped["name"]
        with patch.object(release, "instance", side_effect=[stopped, waiting, ready]) as lookup, \
                patch.object(release, "wake", return_value=name), \
                patch("time.monotonic", return_value=0), patch.object(release, "incus") as incus:
            self.assertEqual(release.main(["resume", "instance-8", SHA[:12], "14"]), name)
        self.assertEqual(lookup.call_args_list, [call(name), call(name, timeout=15), call(name, timeout=15)])
        incus.assert_called_once_with("exec", name, "--", "/usr/lib/systemd/systemd-networkd-wait-online",
                                     "--interface=eth0:routable", "--ipv4", "--timeout=15", timeout=15)

    def test_missing_eth0_address_uses_one_deadline_for_wait_and_reads(self):
        waiting = addressed_candidate(docker0=["172.17.0.1"])
        elapsed = 0

        def advance(seconds):
            nonlocal elapsed
            elapsed += seconds

        def read_instance(*args, **kwargs):
            advance(0.5)
            return waiting

        def wait_online(*args, **kwargs):
            advance(1)

        with patch.object(release, "ADDRESS_TIMEOUT", 2.75), \
                patch.object(release, "instance", side_effect=read_instance) as lookup, \
                patch("time.monotonic", side_effect=lambda: elapsed), \
                patch.object(release, "incus", side_effect=wait_online) as incus, \
                self.assertRaisesRegex(ValueError, r"^release\.container_address_unavailable$"):
            release.wait_address(waiting, refresh=True)
        self.assertEqual(lookup.call_args_list, [
            call(waiting["name"], timeout=2.75),
            call(waiting["name"], timeout=1.25),
        ])
        incus.assert_called_once_with("exec", waiting["name"], "--", "/usr/lib/systemd/systemd-networkd-wait-online",
                                     "--interface=eth0:routable", "--ipv4", "--timeout=3", timeout=2.25)
        self.assertEqual(elapsed, 2.0)

    def test_incus_read_timeout_reports_address_unavailable(self):
        waiting = addressed_candidate()
        run = subprocess.run

        def stalled_command(_command, **kwargs):
            return run([sys.executable, "-c", "import time; time.sleep(30)"], **kwargs)

        with patch.object(release, "ADDRESS_TIMEOUT", 0.1), \
                patch.object(release.subprocess, "run", side_effect=stalled_command) as command, \
                self.assertRaisesRegex(ValueError, "^release\\.container_address_unavailable$"):
            release.wait_address(waiting, refresh=True)
        command.assert_called_once()

    def test_non_timeout_incus_failure_remains_a_command_failure(self):
        waiting = addressed_candidate()
        error = subprocess.CalledProcessError(7, ["incus", "list"])
        with patch.object(release.subprocess, "run", side_effect=error) as command, \
                self.assertRaises(subprocess.CalledProcessError) as raised:
            release.wait_address(waiting, refresh=True)
        self.assertIs(raised.exception, error)
        command.assert_called_once()

    def test_wrong_or_ambiguous_eth0_addresses_refuse_immediately(self):
        for addresses, error in ((["10.2.3.5"], "address_changed"),
                                 (["10.2.3.4", "10.2.3.5"], "address_unavailable")):
            with self.subTest(addresses=addresses), patch.object(release, "incus") as incus, \
                    patch.object(release, "instance") as lookup, \
                    self.assertRaisesRegex(ValueError, "^release\\.container_" + error + "$"):
                release.wait_address(addressed_candidate(eth0=addresses))
            incus.assert_not_called()
            lookup.assert_not_called()

    def test_ready_eth0_returns_the_reserved_address_without_waiting(self):
        ready = addressed_candidate(docker0=["172.17.0.1"], eth0=["10.2.3.4"])
        with patch.object(release, "instance") as lookup, patch.object(release, "incus") as incus:
            self.assertEqual(release.wait_address(ready), "10.2.3.4")
        lookup.assert_not_called()
        incus.assert_not_called()

    def test_one_operation_uses_the_same_candidate_without_a_second_copy(self):
        owner = "instance-8"
        name = owner + "-" + SHA[:12]
        prep = record("prepare-" + owner, "prepare")
        built = record(name, "candidate", commit=SHA, artifact=DIGEST)
        with patch.object(release, "instance", side_effect=[prep, built]), patch.object(release, "incus", return_value="[]") as incus:
            self.assertEqual(release.candidate(owner, "13", SHA, DIGEST), name)
            incus.assert_called_once_with("snapshot", "list", prep["name"], "--format", "json")

    def test_existing_candidate_from_another_operation_is_untouched(self):
        prep = record("prepare-instance-8", "prepare")
        built = record("instance-8-" + SHA[:12], "candidate", operation="12", commit=SHA, artifact=DIGEST)
        with patch.object(release, "instance", side_effect=[prep, built]), patch.object(release, "incus", return_value="[]") as incus:
            with self.assertRaisesRegex(ValueError, "identity_mismatch"):
                release.candidate("instance-8", "13", SHA, DIGEST)
            incus.assert_called_once()

    def test_an_existing_snapshot_must_match_the_entire_commit_and_artifact(self):
        prep = record("prepare-instance-8", "prepare")
        wrong = record("built-13", "prepare", commit=SHA, artifact="sha256:" + "0" * 64)
        with patch.object(release, "instance", side_effect=[prep, None]), patch.object(release, "incus", return_value=json.dumps([wrong])) as incus:
            with self.assertRaisesRegex(ValueError, "identity_mismatch"):
                release.candidate("instance-8", "13", SHA, DIGEST)
            self.assertTrue(all(call.args[0] == "snapshot" and call.args[1] == "list" for call in incus.call_args_list))

    def test_gateway_clone_requires_the_exact_frozen_preparer_and_snapshot(self):
        receipt = f"13/{SHA}/{DIGEST}"
        identity = {"target": "gateway", "commit": SHA, "artifact": DIGEST, "parked-seal": receipt}
        snapshot = record("built-13", "prepare", status="Frozen", **identity)
        clone = record("gateway-" + SHA[:12], "candidate", status="Stopped", **identity)
        for status, marker, expected in (("Running", receipt, "not_frozen"), ("Frozen", "different", "identity_mismatch")):
            prep = record("prepare-gateway", "prepare", status=status, **{**identity, "parked-seal":marker})
            with patch.object(release, "instance", side_effect=[prep, None]), patch.object(release, "incus", return_value="[]") as incus:
                with self.assertRaisesRegex(ValueError, expected):
                    release.candidate("gateway", "13", SHA, DIGEST)
                incus.assert_called_once_with("snapshot", "list", "prepare-gateway", "--format", "json")
        prep = record("prepare-gateway", "prepare", status="Frozen", **identity)
        with patch.object(release, "instance", side_effect=[prep, None, clone]), patch.object(
            release, "incus", side_effect=["[]", "", json.dumps([snapshot]), "", "", ""]
        ) as incus:
            self.assertEqual(release.candidate("gateway", "13", SHA, DIGEST), clone["name"])
            self.assertEqual([call.args[0] for call in incus.call_args_list], ["snapshot", "snapshot", "snapshot", "copy", "snapshot", "start"])


    def test_retire_stops_the_former_live_without_freezing_or_swap(self):
        name = "instance-8-" + SHA[:12]
        running = {**record(name, "candidate", commit=SHA), "config": {**record(name, "candidate", commit=SHA)["config"], "boot.autostart": "true"}}
        stopped = {**running, "status": "Stopped"}
        with patch.object(release, "instance", side_effect=[running, stopped]), patch.object(release, "incus") as incus, \
                patch.object(release, "power") as power_call, patch.object(release, "Path") as path:
            self.assertEqual(release.main(["retire", "instance-8", SHA[:12], "14"]), {"name": name, "outcome": "stopped"})
        self.assertEqual(incus.call_args_list, [call("config", "set", name, "boot.autostart=false"),
                                                call("stop", name, "--timeout", "120", timeout=180)])
        power_call.assert_not_called()
        path.assert_not_called()

    def test_retire_thaws_a_frozen_release_and_forces_a_stuck_shutdown(self):
        name = "gateway-" + SHA[:12]
        frozen = {**record(name, "candidate", status="Frozen", commit=SHA, target="gateway")}
        frozen["config"]["boot.autostart"] = "false"
        error = subprocess.CalledProcessError(1, ["incus", "stop"])
        with patch.object(release, "instance", side_effect=[frozen, {**frozen, "status": "Stopped"}]), \
                patch.object(release, "incus", side_effect=[error, ""]) as incus, patch.object(release, "power") as power_call:
            release.main(["retire", "gateway", SHA[:12], "14"])
        power_call.assert_called_once_with(name, "restore", "14")
        self.assertEqual(incus.call_args_list, [call("stop", name, "--timeout", "120", timeout=180), call("stop", name, "--force", timeout=180)])

    def test_resume_cold_starts_a_stopped_retained_release(self):
        stopped = {**record("instance-8-" + SHA[:12], "candidate", status="Stopped", commit=SHA)}
        with patch.object(release, "incus") as incus, patch.object(release, "power", return_value={"outcome": "restored", "state_after": "running"}) as power_call:
            self.assertEqual(release.wake(stopped, "14"), stopped["name"])
        incus.assert_called_once_with("start", stopped["name"])
        self.assertEqual(power_call.call_count, 2)

    def gateway(self, name, role, status="Stopped", **tags):
        item = record(name, role, status=status, target="gateway", **tags)
        item.setdefault("devices", {})
        return item

    def test_gateway_preparer_itself_becomes_the_candidate_without_a_copy(self):
        seal = {"operation": "13", "commit": SHA, "artifact": DIGEST}
        prep = self.gateway("prepare-gateway", "prepare", status="Running", **seal, **{"parked-seal": f"13/{SHA}/{DIGEST}"})
        name = "gateway-" + SHA[:12]
        renamed = {**prep, "name": name, "status": "Stopped"}
        promoted = {**renamed, "config": {**renamed["config"], release.PREFIX + "role": "candidate"}}
        with patch.object(release, "instance", side_effect=[None, prep, renamed, None, promoted]), \
                patch.object(release, "incus") as incus, patch.object(release, "power") as power_call:
            self.assertEqual(release.main(["promote", "gateway", "13", SHA, DIGEST]), name)
        self.assertEqual([c.args[0] for c in incus.call_args_list], ["stop", "rename", "config", "start"])
        self.assertNotIn("copy", [c.args[0] for c in incus.call_args_list])
        self.assertIn("raw.lxc=" + release.GATEWAY_MOUNTS, incus.call_args_list[2].args)
        power_call.assert_called_once_with("prepare-gateway", "discard")
        with self.assertRaisesRegex(ValueError, "gateway_only"):
            release.promote("instance-8", "13", SHA, DIGEST)

    def test_a_candidate_that_never_served_returns_to_the_preparation_slot(self):
        name = "gateway-" + SHA[:12]
        candidate = self.gateway(name, "candidate", status="Running", operation="13", commit=SHA)
        candidate["config"]["boot.autostart"] = "false"
        candidate["config"]["raw.lxc"] = release.GATEWAY_MOUNTS
        with patch.object(release, "instance", side_effect=[candidate, None]), patch.object(release, "incus") as incus, \
                patch.object(release, "power"), patch.object(release, "clear_wanted") as clear:
            self.assertEqual(release.main(["demote", "gateway", "13", SHA]), "prepare-gateway")
        actions = [c.args[:2] for c in incus.call_args_list]
        self.assertEqual(actions[0], ("stop", name))
        clear.assert_called_once_with(name)
        self.assertIn(("config", "unset"), actions)
        self.assertEqual(actions[-1], ("rename", name))
        # A retry after the unset and the role change only renames.
        done = {**candidate, "status": "Stopped", "config": {**{k: v for k, v in candidate["config"].items() if k != "raw.lxc"},
                                                              release.PREFIX + "role": "prepare"}}
        with patch.object(release, "instance", side_effect=[done, None]), patch.object(release, "incus") as incus, \
                patch.object(release, "power"), patch.object(release, "clear_wanted"):
            release.demote("gateway", "13", SHA)
        self.assertEqual([c.args[0] for c in incus.call_args_list], ["rename"])
        # A retry after the rename only discards the candidate's power records.
        with patch.object(release, "instance", return_value=None), patch.object(release, "power") as power_call:
            self.assertEqual(release.demote("gateway", "13", SHA), "")
        power_call.assert_called_once_with(name, "discard")
        serving = {**candidate, "config": {**candidate["config"], "boot.autostart": "true"}}
        with patch.object(release, "instance", return_value=serving), self.assertRaisesRegex(ValueError, "still_active"):
            release.demote("gateway", "13", SHA)

    def test_seed_recreates_a_stopped_preparer_from_live_without_its_address_or_work(self):
        live = self.gateway("gateway-" + SHA[:12], "candidate", status="Running", commit=SHA)
        seeded = self.gateway("prepare-gateway", "prepare", **{"seeded-from": SHA, "commit": SHA})
        seeded["config"]["raw.lxc"] = "x"
        seeded["devices"] = {"eth0": {"ipv4.address": "10.2.3.4"}}
        outputs = {("snapshot", "list"): ["[]", json.dumps([{"name": "seed-14"}])]}
        link = "/etc/systemd/system/multi-user.target.wants/orbit-gateway-horizon.service"

        def run(*args, **kwargs):
            for key, values in outputs.items():
                if args[:len(key)] == key:
                    return values.pop(0)
            return ""
        with patch.object(release, "instance", side_effect=[live, None, seeded]), patch.object(release, "incus", side_effect=run) as incus, \
                patch.object(release, "wanted_links", side_effect=[[link], []]), patch.object(release, "detach") as detach, \
                patch.object(release, "gateway_slots") as slots, patch.object(release, "require_clone_copy") as clone_copy:
            self.assertEqual(release.main(["seed", "gateway", SHA[:12], "14"]), "prepare-gateway")
        detach.assert_called_once_with(live, "prepare-gateway")
        slots.assert_called_once_with("gateway")
        clone_copy.assert_called_once_with(live)
        self.assertIn(call("config", "set", "prepare-gateway", release.PREFIX + "seed-ready=true"), incus.call_args_list)
        copy = next(c.args for c in incus.call_args_list if c.args[0] == "copy")
        self.assertIn("eth0,ipv4.address=", copy)
        self.assertIn("boot.autostart=false", copy)
        unset = [c.args for c in incus.call_args_list if c.args[:2] == ("config", "unset")]
        self.assertIn(("config", "unset", "prepare-gateway", "raw.lxc"), unset)
        self.assertIn(("config", "device", "unset", "prepare-gateway", "eth0", "ipv4.address"), [c.args for c in incus.call_args_list])
        self.assertIn(call("file", "delete", "prepare-gateway" + link, timeout=60), incus.call_args_list)
        self.assertEqual(incus.call_args_list[-2].args, ("snapshot", "delete", live["name"], "seed-14"))

    def zfs_calls(self, outputs):
        calls = []

        def run(command, **kwargs):
            calls.append(command[1:])
            key = "own" if "-d" in command else command[1]
            return subprocess.CompletedProcess(command, 0, stdout=outputs.get(key, ""))
        return calls, run

    def test_detach_promotes_the_preparer_so_live_no_longer_keeps_the_release_before_it(self):
        live = {"name": "gateway-" + SHA[:12], "expanded_devices": {"root": {"pool": "orbit-releases"}}}
        root = "tank/gateway-releases/containers/orbit-releases_"
        listing = "\n".join([
            root + "gateway-" + SHA[:12] + "@deleted-old\t-",
            root + "prepare-gateway@deleted-seed\t" + root + "gateway-" + SHA[:12],
            root + "gateway-bbbbbbbbbbbb@snapshot-kept\t-",
            root + "other@deleted-foreign\t-",
        ])
        own = "\n".join([root + "gateway-" + SHA[:12] + "@deleted-old\t5", root + "gateway-" + SHA[:12] + "@deleted-seed\t9",
                         root + "gateway-" + SHA[:12] + "@snapshot-later\t12"])
        calls, run = self.zfs_calls({"get": root + "gateway-" + SHA[:12] + "@deleted-seed\n", "list": listing, "own": own})
        pool = json.dumps({"driver": "zfs", "config": {"source": "tank/gateway-releases"}})
        with patch.object(release.subprocess, "run", side_effect=run), patch.object(release.subprocess, "check_output", return_value=pool):
            release.detach(live, "prepare-gateway")
        self.assertIn(["promote", root + "prepare-gateway"], calls)
        # Only an orphaned deleted-* release snapshot goes: one a clone needs, a recorded one and a foreign one stay.
        self.assertEqual([c for c in calls if c[0] == "destroy"], [["destroy", root + "gateway-" + SHA[:12] + "@deleted-old"]])

    def test_detach_refuses_a_seed_snapshot_that_incus_still_records_and_skips_other_drivers(self):
        live = {"name": "gateway-" + SHA[:12], "expanded_devices": {"root": {"pool": "orbit-releases"}}}
        root = "tank/r/containers/orbit-releases_"
        calls, run = self.zfs_calls({"get": root + "gateway-" + SHA[:12] + "@snapshot-seed-14\n"})
        pool = json.dumps({"driver": "zfs", "config": {"source": "tank/r"}})
        with patch.object(release.subprocess, "run", side_effect=run), patch.object(release.subprocess, "check_output", return_value=pool), \
                self.assertRaisesRegex(ValueError, "still_recorded"):
            release.detach(live, "prepare-gateway")
        self.assertNotIn("promote", [c[0] for c in calls])
        with patch.object(release.subprocess, "run") as run_dir, \
                patch.object(release.subprocess, "check_output", return_value=json.dumps({"driver": "dir", "config": {}})):
            release.detach(live, "prepare-gateway")
        run_dir.assert_not_called()

    def test_detach_refuses_to_move_a_snapshot_incus_still_records(self):
        live = {"name": "gateway-" + SHA[:12], "expanded_devices": {"root": {"pool": "orbit-releases"}}}
        root = "tank/r/containers/orbit-releases_gateway-" + SHA[:12]
        own = root + "@snapshot-backup\t3\n" + root + "@deleted-seed\t9"
        calls, run = self.zfs_calls({"get": root + "@deleted-seed\n", "own": own})
        pool = json.dumps({"driver": "zfs", "config": {"source": "tank/r"}})
        with patch.object(release.subprocess, "run", side_effect=run), patch.object(release.subprocess, "check_output", return_value=pool), \
                self.assertRaisesRegex(ValueError, "recorded_snapshot_would_move"):
            release.detach(live, "prepare-gateway")
        self.assertNotIn("promote", [c[0] for c in calls])

    def test_a_seed_refuses_a_pool_that_would_copy_files_and_a_fourth_container(self):
        live = {"name": "gateway-" + SHA[:12], "expanded_devices": {"root": {"pool": "orbit-releases"}}}
        for pool in ({"driver": "zfs", "config": {"source": "tank/r", "zfs.clone_copy": "false"}},
                     {"driver": "zfs", "config": {"source": "tank/r", "zfs.clone_copy": "rebase"}},
                     {"driver": "dir", "config": {"source": "/var/lib/incus/storage-pools/r"}}):
            with patch.object(release.subprocess, "check_output", return_value=json.dumps(pool)), \
                    self.assertRaisesRegex(ValueError, "copies_files"):
                release.require_clone_copy(live)
        with patch.object(release.subprocess, "check_output", return_value=json.dumps({"driver": "zfs", "config": {"source": "t"}})):
            release.require_clone_copy(live)
        three = [{"name": n, "config": {}} for n in ("gateway-" + SHA[:12], "gateway-" + "b" * 12, "gateway-" + "c" * 12)]
        with patch.object(release, "incus", return_value=json.dumps(three)), self.assertRaisesRegex(ValueError, "container_limit"):
            release.gateway_slots("gateway")
        with patch.object(release, "incus", return_value=json.dumps(three[:2])):
            release.gateway_slots("gateway")

    def test_a_release_is_deleted_only_after_its_dependent_clones_are_promoted_off_it(self):
        prep = {"name": "prepare-gateway", "expanded_devices": {"root": {"pool": "orbit-releases"}}}
        root = "tank/r/containers/orbit-releases_"
        live, old = root + "gateway-" + SHA[:12], root + "gateway-" + "b" * 12
        listings = [
            # The preparer is the origin of live (newer snapshot) and old (older snapshot).
            f"{root}prepare-gateway@deleted-old\t5\t{old}\n{root}prepare-gateway@deleted-seed\t9\t{live}\n",
            # Promoting live moved both snapshots onto it; nothing depends on the preparer any more.
            "",
        ]
        calls = []

        def run(command, **kwargs):
            calls.append(command[1:])
            stdout = listings.pop(0) if command[1] == "list" else ""
            return subprocess.CompletedProcess(command, 0, stdout=stdout)
        pool = json.dumps({"driver": "zfs", "config": {"source": "tank/r"}})
        with patch.object(release.subprocess, "run", side_effect=run), patch.object(release.subprocess, "check_output", return_value=pool), \
                patch.object(release, "incus") as incus, patch.object(release, "power") as power_call:
            release.remove(prep)
        self.assertEqual([c for c in calls if c[0] == "promote"], [["promote", live]])
        incus.assert_called_once_with("delete", "prepare-gateway")
        power_call.assert_called_once_with("prepare-gateway", "discard")

    def test_removal_refuses_to_move_a_recorded_snapshot_or_feed_a_foreign_clone(self):
        prep = {"name": "prepare-gateway", "expanded_devices": {"root": {"pool": "orbit-releases"}}}
        root = "tank/r/containers/orbit-releases_"
        pool = json.dumps({"driver": "zfs", "config": {"source": "tank/r"}})
        for listing, error in ((f"{root}prepare-gateway@snapshot-backup\t3\t{root}gateway-{SHA[:12]}\n", "recorded_snapshot_would_move"),
                               (f"{root}prepare-gateway@deleted-x\t3\ttank/other/clone\n", "foreign_clone")):
            def run(command, listing=listing, **kwargs):
                return subprocess.CompletedProcess(command, 0, stdout=listing)
            with self.subTest(error=error), patch.object(release.subprocess, "run", side_effect=run), \
                    patch.object(release.subprocess, "check_output", return_value=pool), patch.object(release, "incus") as incus, \
                    self.assertRaisesRegex(ValueError, error):
                release.remove(prep)
            incus.assert_not_called()

    def test_a_preparer_with_an_unfinished_seed_never_wakes(self):
        prep = self.gateway("prepare-gateway", "prepare", operation="13", **{"seeded-from": SHA, "seed-ready": "false"})
        with patch.object(release, "instance", return_value=prep), patch.object(release, "power") as power_call, \
                self.assertRaisesRegex(ValueError, "seed_unfinished"):
            release.main(["wake", "gateway", "14"])
        power_call.assert_not_called()

    def test_a_promoted_preparer_is_detached_once_and_prune_runs_after_an_old_is_deleted(self):
        live = {"name": "gateway-" + SHA[:12], "expanded_devices": {"root": {"pool": "orbit-releases"}}}
        calls, run = self.zfs_calls({"get": "tank/r/containers/orbit-releases_gateway-cccccccccccc@deleted-x\n"})
        pool = json.dumps({"driver": "zfs", "config": {"source": "tank/r"}})
        with patch.object(release.subprocess, "run", side_effect=run), patch.object(release.subprocess, "check_output", return_value=pool):
            release.detach(live, "prepare-gateway")
        self.assertNotIn("promote", [c[0] for c in calls])
        self.assertIn("list", [c[0] for c in calls])

    def test_seed_refuses_a_preparer_whose_units_stay_enabled(self):
        live = self.gateway("gateway-" + SHA[:12], "candidate", status="Running", commit=SHA)
        seeded = self.gateway("prepare-gateway", "prepare", **{"seeded-from": SHA})
        link = "/etc/systemd/system/timers.target.wants/orbit-runtime-hibernator.timer"
        with patch.object(release, "instance", side_effect=[live, seeded]), patch.object(release, "incus", return_value="[]"), \
                patch.object(release, "wanted_links", side_effect=[[link], [link]]), self.assertRaisesRegex(ValueError, "units_enabled"):
            release.seed("gateway", SHA[:12], "14")

    def test_wanted_links_reads_the_stopped_copy(self):
        def pull(*args, **kwargs):
            wants = Path(args[-1]) / "system" / "multi-user.target.wants"
            wants.mkdir(parents=True)
            (wants / "orbit-process-4-horizon.service").touch()
            (wants / "ssh.service").touch()
        with patch.object(release, "incus", side_effect=pull):
            self.assertEqual(release.wanted_links("prepare-gateway"),
                             ["/etc/systemd/system/multi-user.target.wants/orbit-process-4-horizon.service"])

    def test_gateway_capacity_reserves_no_swap_for_a_preparer_that_is_never_frozen(self):
        gib = 1024**3
        meminfo = f"MemAvailable: {6 * gib // 1024} kB\nSwapFree: 0 kB"
        pool = json.dumps({"space": {"total": 20 * gib, "used": 0}})
        with patch.object(Path, "read_text", return_value=meminfo), patch("subprocess.check_output", return_value=pool):
            self.assertEqual(release.capacity_estimate((0, 0, 0), (2 * gib, gib, 0), "pool", reclaim=False)["required_bytes"]["swap"], 0)

    def test_delayed_wake_cannot_claim_the_preparer_from_a_newer_build(self):
        with patch.object(release, "instance", return_value=record("prepare-instance-8", "prepare")), patch.object(release, "power") as power_call:
            with self.assertRaisesRegex(ValueError, "preparation_changed"):
                release.main(["wake", "instance-8", "12"])
            power_call.assert_not_called()

    def test_power_target_must_match_the_fixed_project_and_complete_container_name(self):
        for name in ("prepare-gateway", "prepare-instance-8", "gateway-" + SHA[:12], "instance-8-" + SHA[:12]):
            with self.subTest(name=name):
                self.assertEqual(power.parse_args(["prepare", "--release", name, "--operation", "2"]), ("prepare", name, 2, False))
                intent = {"schema_version": 1, "operation_id": 2, "instance_id": name, "incus_project": "orbit-releases", "incus_name": name, "web": None}
                self.assertEqual(power.parse_intent(json.dumps(intent), name, 2), intent)
                for key, invalid in (("incus_project", "default"), ("incus_name", "other-container")):
                    with self.assertRaises(power.Refused):
                        power.parse_intent(json.dumps({**intent, key: invalid}), name, 2)
        with self.assertRaises(power.Refused):
            power.parse_args(["discard", "--release", "../instances/8"])

    def test_an_instance_and_its_preparer_have_independent_receipts(self):
        host = power.Host(state="/state")
        self.assertEqual(host.instance_dir(8), "/state/instances/8")
        self.assertEqual(host.instance_dir("prepare-instance-8"), "/state/releases/prepare-instance-8")


if __name__ == "__main__":
    unittest.main()
