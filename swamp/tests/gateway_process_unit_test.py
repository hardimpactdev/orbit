"""Exercise release-bound Process changes, retries, and capture into the preparation image."""
import fcntl
import hashlib
import importlib.machinery
import importlib.util
import io
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock, patch

SCRIPT = Path(__file__).resolve().parents[1] / "ansible/files/gateway-web/orbit-gateway-unit"
loader = importlib.machinery.SourceFileLoader("gateway_unit", str(SCRIPT))
unit = importlib.util.module_from_spec(importlib.util.spec_from_loader(loader.name, loader))
loader.exec_module(unit)


class GatewayUnitTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.home = Path(temporary.name)
        self.state = self.home / "private"
        self.sha = "a" * 40
        self.name = "orbit-process-7-schedule-work.service"
        (self.home / "gateway-containers.json").write_text(json.dumps({"version": 1, "live": self.sha, "old": None}))
        (self.home / "gateway-release.lock").touch()
        for key, value in (("HOME", self.home), ("STATE", self.state)):
            fix = patch.object(unit, key, value)
            fix.start()
            self.addCleanup(fix.stop)
        incus = patch.object(unit, "guest", return_value=0)
        self.guest = incus.start()
        self.addCleanup(incus.stop)
        check = patch.object(unit, "checked_machine")
        self.check = check.start()
        self.addCleanup(check.stop)

    def change(self, action):
        return unit.live_command(self.sha, action, self.name)

    def test_failure_to_match_revision_or_acquire_lock_never_controls_a_unit(self):
        with self.assertRaisesRegex(ValueError, "serving Gateway changed"):
            unit.live_command("b" * 40, "stop", self.name)
        with (self.home / "gateway-release.lock").open() as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            with self.assertRaises(BlockingIOError):
                self.change("start")
        self.guest.assert_not_called()
        self.assertFalse(self.state.exists())

    def test_changes_replay_off_traffic_without_starting_background_work(self):
        env = "SCHEDULER_NAME=example\n"
        path = "/etc/orbit/processes/7/" + hashlib.sha256(env.encode()).hexdigest() + ".env"
        data = {"unit": "[Service]\nExecStart=/bin/true\n", "mounts": [], "environment": env, "environment_path": path, "desired": "running"}
        with patch.object(unit.sys, "stdin", Mock(buffer=io.BytesIO(json.dumps(data).encode()))):
            self.change("update")
        record = self.state / (self.name + ".json")
        self.assertEqual(json.loads(record.read_bytes())["definition"], data)
        self.assertEqual(record.stat().st_mode & 0o777, 0o600)
        self.guest.reset_mock()
        unit.replay("apply-preparation", ["4"])
        self.check.assert_called_with("prepare-gateway", role="prepare", operation="4")
        self.assertNotIn("start", [arg for cmd in self.guest.call_args_list for arg in cmd.args])
        self.guest.assert_any_call("prepare-gateway", "systemctl", "disable", "--now", "--", self.name)
        self.change("stop")
        self.assertEqual(json.loads(record.read_text())["desired"], "stopped")
        self.change("destroy")
        self.assertEqual(json.loads(record.read_text()), {"removed": True})
        self.guest.reset_mock()
        unit.replay("apply-candidate", ["4", self.sha])
        self.check.assert_called_with("gateway-" + self.sha[:12], role="candidate", commit=self.sha)
        self.guest.assert_any_call("gateway-" + self.sha[:12], "rm", "-rf", "--", "/etc/orbit/processes/7")

    def test_drain_and_reads_do_not_change_the_next_release(self):
        self.change("drain")
        self.change("resume")
        self.change("status")
        unit.live_command(self.sha, "logs", self.name, "20")
        self.assertFalse(self.state.exists())

    def test_another_unit_or_environment_path_is_refused(self):
        with self.assertRaisesRegex(ValueError, "invalid Gateway unit"):
            unit.live_command(self.sha, "start", "sshd.service")
        with self.assertRaisesRegex(ValueError, "invalid Gateway Process environment path"):
            unit.definition(json.dumps({"unit": "x", "desired": "running", "environment": "", "environment_path": "/root/x", "mounts": []}), self.name)
        self.guest.assert_not_called()


if __name__ == "__main__":
    unittest.main()
