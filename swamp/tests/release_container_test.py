"""Exercise the identity checks on a release container and its immutable snapshot."""

import importlib.util
import json
import unittest
from pathlib import Path
from unittest.mock import patch


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


class ReleaseTest(unittest.TestCase):
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
