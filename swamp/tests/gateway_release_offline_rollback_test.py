"""Exercise the offline and retained-container rollback using the real release commands."""
import fcntl
import json
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from gateway_release_recovery_test import (
    CONTROLLER,
    HORIZON,
    OPERATION,
    WORKFLOW,
    GatewayReleaseFixture,
)


class GatewayOfflineRollback(GatewayReleaseFixture):
    def test_reconciliation_fences_candidate_switches_until_its_own_finalizer(self):
        self.assert_ok(self.run_move())
        self.assert_ok(self.run_script(self.phase, "reconcile-begin", self.sha, "51"))
        self.assertNotEqual(self.run_script(self.phase, "switch", self.sha, OPERATION, self.sha[:12], HORIZON).returncode, 0)
        self.assertNotEqual(self.run_script(self.phase, "reconcile-end", self.sha, "52").returncode, 0)
        self.assert_ok(self.run_script(self.phase, "reconcile-end", self.sha, "51"))
        self.assert_container_serves()

    def test_incompatible_target_is_refused_before_any_mutation(self):
        self.serving_former()
        for name in (CONTROLLER, WORKFLOW):
            path = self.mirror / name
            original = path.read_text()
            path.write_text(original.replace("recovery-protocol: 2", "recovery-protocol: 1"))
            bad = self.commit("incompatible target")
            result = self.run_script(self.phase, "build-checked", bad, OPERATION, "false", "{}", "false")
            self.assertIn("gateway.release_recovery_incompatible", result.stderr)
            path.write_text(original)
        self.assertEqual(json.loads(self.inventory.read_text())["live"], self.old)
        self.assertFalse((self.journal / "operation").exists())

    def test_legacy_parked_release_is_deleted_before_another_clone_is_allowed(self):
        self.serving_former()
        self.inventory.write_text(json.dumps({"version":1, "live":self.sha, "old":self.old, "host_retired":True}))
        (self.state / "former").write_text("Frozen")
        (self.state / (self.retained + "-boot")).write_text("false")
        self.assertIn("already serves", self.direct("build", "false", "{}", "false").stderr)
        self.assertFalse((self.state / "former").exists())
        self.assertIsNone(json.loads(self.inventory.read_text())["old"])

    def test_orphan_clone_refuses_a_fourth_gateway_container(self):
        self.serving_former()
        (self.state / "orphan").touch()
        original = self.inventory.read_bytes()
        result = self.direct("build", "false", "{}", "false")
        self.assertIn("another Gateway clone", result.stderr)
        self.assertTrue((self.state / "orphan").exists())
        self.assertEqual(self.inventory.read_bytes(), original)

    def test_rollback_and_old_switch_back_phases_are_refused(self):
        self.serving_former()
        original = self.inventory.read_bytes()
        for phase in ("restore", "switch-back", "restore-host", "drain"):
            result = self.run_script(self.phase, phase, self.old, OPERATION)
            self.assertIn("gateway.release_fix_forward", result.stderr)
        self.assertIn("rollback is unavailable", self.direct("build", "false", "{}", "true").stderr)
        self.assertEqual(self.inventory.read_bytes(), original)

    def test_wrong_operation_cannot_finish_or_delete_the_committed_candidate(self):
        self.serving_former()
        self.assert_ok(self.switch())
        for phase in ("recover", "retire", "release-unused"):
            result = self.run_script(self.phase, phase, self.sha, "80")
            self.assertNotEqual(result.returncode, 0)
        self.assert_pending(self.sha)
        self.assertTrue((self.state / "candidate").exists())
        self.assertTrue((self.state / "former").exists())
        self.assert_ok(self.run_script(self.console, "--recover"))

    def test_interrupted_console_recovery_still_uses_the_shared_lock(self):
        self.serving_former()
        self.assert_ok(self.switch())
        with (self.home / ".orbit/gateway-release.lock").open() as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            result = self.run_script(self.console, "--recover")
            self.assertIn("another Gateway release holds the lock", result.stderr)
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_container_serves()


if __name__ == "__main__":
    unittest.main()
