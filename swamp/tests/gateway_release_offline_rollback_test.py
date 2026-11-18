"""Exercise the offline and retained-container rollback using the real release commands."""
import fcntl
import json
import signal
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from gateway_release_recovery_test import (
    CONTROLLER,
    HORIZON,
    MANAGED_HORIZON,
    OPERATION,
    WORKFLOW,
    GatewayReleaseFixture,
)


class GatewayOfflineRollback(GatewayReleaseFixture):
    def test_reconciliation_fences_offline_rollback_until_its_own_finalizer(self):
        self.retained_releases()
        self.assert_ok(self.run_script(self.phase, "reconcile-begin", self.sha, "51"))
        original = self.inventory.read_bytes()
        result = self.run_script(self.console, self.old[:12])
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("workflows are being reconciled", result.stderr)
        self.assertNotEqual(self.run_script(self.phase, "reconcile-end", self.sha, "52").returncode, 0)
        self.assertEqual(self.inventory.read_bytes(), original)
        self.assert_ok(self.run_script(self.phase, "reconcile-verify", self.sha, "51"))
        self.assert_ok(self.run_script(self.phase, "reconcile-end", self.sha, "51"))
        self.assert_ok(self.run_script(self.console, self.old[:12]))

    def test_incompatible_retained_helper_and_workflow_are_refused_before_rollback(self):
        self.retained_releases(CONTROLLER, WORKFLOW)
        for name in (CONTROLLER, WORKFLOW):
            self.assertNotEqual(self.git("rev-parse", f"{self.sha}:{name}"), self.git("rev-parse", f"{self.old}:{name}"))
        initial = [(path, path.read_bytes()) for path in (self.inventory, self.upstream, self.state / "events")]
        for script, args in (
            (self.console, (self.old[:12],)),
            (self.phase, ("build-checked", self.old, OPERATION, "true", "{}", "true")),
            (self.phase, ("drain", self.old, OPERATION, HORIZON)),
            (self.phase, ("switch", self.old, OPERATION, self.sha[:12], HORIZON)),
        ):
            with self.subTest(args=args):
                result = self.run_script(script, *args)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn("gateway.release_recovery_incompatible", result.stderr)
                for path, before in initial:
                    self.assertEqual(path.read_bytes(), before)
                self.assert_container_serves()
        self.assert_ok(self.run_script(self.phase, "release-unused", self.old, OPERATION))
        for path, before in initial:
            self.assertEqual(path.read_bytes(), before)

    def test_native_rollback_requires_compatible_activation_workflow_even_with_a_corrected_helper(self):
        self.retained_releases(WORKFLOW)
        self.assertEqual(self.git("rev-parse", f"{self.sha}:{CONTROLLER}"), self.git("rev-parse", f"{self.old}:{CONTROLLER}"))
        self.assertNotEqual(self.git("rev-parse", f"{self.sha}:{WORKFLOW}"), self.git("rev-parse", f"{self.old}:{WORKFLOW}"))
        result = self.run_script(self.phase, "build-checked", self.old, OPERATION, "true", "{}", "true")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(f"gateway.release_recovery_incompatible: {self.old} lacks container recovery protocol 1 in {WORKFLOW}", result.stderr)
        self.assert_container_serves()

    def test_native_failed_promotion_verifies_the_previous_release_before_acknowledging(self):
        self.retained_releases()
        self.assert_ok(self.run_script(self.phase, "build-checked", self.old, OPERATION, "true", "{}", "true"))
        (self.state / f"{self.candidate}-{MANAGED_HORIZON}").touch()
        self.assert_ok(self.run_script(self.phase, "switch", self.old, OPERATION, self.sha[:12], HORIZON))
        self.assertEqual(set((self.journal / "horizons").read_text().splitlines()), {HORIZON, MANAGED_HORIZON})
        (self.state / f"broken-public-container-{self.old}").touch()
        result = self.run_script(self.phase, "public-ready", self.old, OPERATION)
        self.assertNotEqual(result.returncode, 0)
        wrong = self.state / f"wrong-public-{self.sha}"
        wrong.touch()
        result = self.run_script(self.phase, "switch-back", self.old, OPERATION)
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn("gateway.release_switched_back", result.stderr)
        self.assert_pending(self.old)
        self.assertFalse((self.state / f"{self.candidate}-{MANAGED_HORIZON}").exists())
        self.assertEqual(json.loads(self.inventory.read_text())["live"], self.old)

        wrong.unlink()
        result = self.run_script(self.console, "--recover", interrupt="during container public status")
        self.assertEqual(result.returncode, -signal.SIGKILL, result.stderr)
        self.assert_pending(self.old)
        result = self.run_script(self.phase, "switch-back", self.old, OPERATION)
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertIn("gateway.release_switched_back", result.stderr)
        self.assertFalse((self.journal / "operation").exists())
        self.assertEqual(json.loads(self.inventory.read_text())["live"], self.sha)
        self.assertTrue((self.state / f"{self.candidate}-{MANAGED_HORIZON}").exists())

    def test_offline_rollback_journals_the_original_units_before_draining(self):
        self.retained_releases()
        units = [MANAGED_HORIZON, "orbit-process-73-horizon.service"]
        for unit in units:
            (self.state / f"{self.candidate}-{unit}").touch()
        unrelated = self.state / f"{self.candidate}-orbit-process-72-mailer.service"
        unrelated.touch()
        with (self.home / ".orbit/gateway-release.lock").open() as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            result = self.run_script(self.console, self.old[:12])
            self.assertNotEqual(result.returncode, 0)
            self.assertIn("another Gateway release holds the lock", result.stderr)
            self.assert_container_serves()
        (self.state / f"{self.candidate}-{HORIZON}").unlink()
        result = self.run_script(self.console, self.old[:12], interrupt=f"after {self.candidate} drain")
        self.assertEqual(result.returncode, -signal.SIGKILL, result.stderr)
        self.assert_pending(self.old)
        self.assertEqual((self.journal / "horizons").read_text().splitlines(), units)
        self.assertEqual(json.loads(self.inventory.read_text())["live"], self.sha)
        events = (self.state / "events").read_text().splitlines()
        self.assertIn(f"drain {self.candidate} locked=True operation={OPERATION} units={','.join(units)}", events)
        self.assertTrue(unrelated.exists())
        self.assert_ok(self.run_script(self.console, "--recover"))
        for unit in units:
            self.assertTrue((self.state / f"{self.candidate}-{unit}").exists())
        self.assertTrue(unrelated.exists())
        self.assertFalse((self.journal / "operation").exists())

    def test_offline_failed_rollback_keeps_the_journal_when_both_endpoints_fail(self):
        self.retained_releases()
        (self.state / f"broken-public-container-{self.old}").touch()
        unavailable = self.state / f"wrong-public-{self.sha}"
        unavailable.touch()
        result = self.run_script(self.console, self.old[:12])
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn("restored previous traffic", result.stderr)
        self.assertIn("public Gateway did not verify restored release", result.stderr)
        self.assert_pending(self.old)
        self.assertFalse((self.state / f"{self.candidate}-{HORIZON}").exists())
        self.assertFalse((self.state / f"{self.retained}-{HORIZON}").exists())
        self.assertNotEqual(self.run_script(self.console, "--recover").returncode, 0)
        self.assert_pending(self.old)
        unavailable.unlink()
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_container_serves()

    def test_healthy_retained_container_rollback(self):
        self.retained_releases()
        self.assert_ok(self.run_script(self.console, self.old[:12]))
        self.assertEqual(json.loads(self.inventory.read_text())["live"], self.old)
        self.assertEqual(json.loads(self.inventory.read_text())["old"], self.sha)
        self.assertEqual((self.state / "site").read_text(), "container")
        self.assertTrue((self.state / f"gateway-{self.old[:12]}-{HORIZON}").exists())
        self.assertFalse((self.state / f"{self.candidate}-{HORIZON}").exists())


if __name__ == "__main__":
    unittest.main()
