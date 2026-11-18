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
        for ram, swap, disk, shortage in [(6, 3, 10, None), (5, 3, 10, "ram"), (6, 2, 10, "swap"), (6, 3, 9, "pool")]:
            meminfo = f"MemAvailable: {ram * gib // 1024} kB\nSwapFree: {swap * gib // 1024} kB"
            pool = json.dumps({"space": {"total": disk * gib, "used": 0}})
            with self.subTest(shortage=shortage), patch.object(release, "preparer", return_value=prep), \
                    patch.object(release, "release", return_value=live), patch.object(Path, "read_text", return_value=meminfo), \
                    patch("subprocess.check_output", return_value=pool) as query, patch.object(release, "power") as power:
                if shortage:
                    with self.assertRaisesRegex(ValueError, f"deployment.node_capacity: {shortage} needs"):
                        release.main(args)
                else:
                    self.assertEqual(release.main(args)["required_bytes"], {"ram": 6 * gib, "swap": 3 * gib, "pool": 10 * gib})
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
