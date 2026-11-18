"""The shared drain before a former live stops, and interrupted or refused rollbacks, with the real release commands."""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from gateway_release_recovery_test import (
    HORIZON,
    OPERATION,
    GatewayReleaseFixture,
)


class GatewayReleaseDrain(GatewayReleaseFixture):
    def test_retire_without_the_workflow_wait_probes_once_per_retry_until_the_bytes_drain(self):
        self.serving_former()
        (self.state / f"busy-{self.retained}").write_text("2")
        self.assert_ok(self.switch())
        self.assert_ok(self.run_script(self.phase, "start-horizon", self.sha, OPERATION, HORIZON))
        # A failing probe and lookup never count as drained, and the start is saved before either runs.
        self.assert_ok(self.run_script(self.phase, "quiesce", self.sha, OPERATION))
        (self.state / "broken-gate").touch()
        self.assertIn("queued response bytes", self.run_script(self.phase, "retire", self.sha, OPERATION).stderr)
        self.assertIn("incus list failed", (self.state / "events").read_text())
        self.assertRegex((self.root / "var/release-response-wait" / self.retained).read_text(), rf"^{OPERATION} waiting \d+$")
        self.assertNotEqual((self.state / "former").read_text(), "Stopped")
        (self.state / "broken-gate").unlink()
        for _ in range(2):
            self.assertIn("queued response bytes", self.run_script(self.console, "--recover").stderr)
            self.assertNotEqual((self.state / "former").read_text(), "Stopped")
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_parked("former", self.old)
        self.assertFalse((self.root / "var/release-response-wait" / self.retained).exists())

    def test_retire_stops_the_former_live_once_the_wait_bound_passed(self):
        self.serving_former()
        (self.state / f"busy-{self.retained}").write_text("99")
        self.assert_ok(self.switch())
        self.assert_ok(self.run_script(self.phase, "start-horizon", self.sha, OPERATION, HORIZON))
        self.assertIn("queued response bytes", self.run_script(self.phase, "retire", self.sha, OPERATION).stderr)
        (self.root / "var/release-response-wait" / self.retained).write_text(f"{OPERATION} waiting 1\n")
        result = self.run_script(self.phase, "retire", self.sha, OPERATION)
        self.assert_ok(result)
        self.assertIn("gateway.release_response_wait_timeout", result.stderr)
        self.assert_parked("former", self.old)

    def test_retire_uses_the_workflow_wait_record_without_probing(self):
        self.serving_former()
        (self.state / f"busy-{self.retained}").write_text("5")
        self.assert_ok(self.switch())
        self.assert_ok(self.run_script(self.phase, "start-horizon", self.sha, OPERATION, HORIZON))
        self.assert_ok(self.run_script(self.phase, "quiesce", self.sha, OPERATION))
        waits = self.root / "var/release-response-wait"
        waits.mkdir(parents=True, exist_ok=True)
        (waits / self.retained).write_text(f"{OPERATION} drained\n")
        self.assert_ok(self.run_script(self.phase, "retire", self.sha, OPERATION))
        self.assertNotIn(f"probe {self.retained}", (self.state / "events").read_text())
        self.assert_parked("former", self.old)

    def test_a_rollback_killed_after_old_started_is_parked_by_recover(self):
        self.released()
        result = self.run_script(self.console, "", interrupt="after former start")
        self.assertEqual(result.returncode, -9)
        self.assertFalse((self.journal / "operation").exists())
        self.assertTrue((self.journal / "rollback").exists())
        self.assertEqual((self.state / "former").read_text(), "Running")
        # A failed Incus lookup proves nothing: --recover fails and the marker stays.
        (self.state / "broken-lookup").touch()
        self.assertNotEqual(self.run_script(self.console, "--recover").returncode, 0)
        self.assertTrue((self.journal / "rollback").exists())
        self.assertEqual((self.state / "former").read_text(), "Running")
        (self.state / "broken-lookup").unlink()
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assertFalse((self.journal / "rollback").exists())
        self.assert_parked("former", self.old)
        self.inventory_is(self.sha, self.old, verified=(self.sha, self.old))
        # The forward path no longer finds a running old.
        self.assertIn("already serves", self.direct("build", "false", "{}", "false").stderr)
        self.assert_at_most_three()

    def test_quiesce_stops_the_former_web_server_and_names_the_container_for_the_response_wait(self):
        self.serving_former()
        self.assert_ok(self.switch())
        self.assert_ok(self.run_script(self.phase, "start-horizon", self.sha, OPERATION, HORIZON))
        result = self.run_script(self.phase, "quiesce", self.sha, OPERATION)
        self.assert_ok(result)
        self.assertEqual(result.stdout.strip(), self.retained)
        self.assertFalse((self.state / f"{self.retained}-orbit-gateway-web.service").exists())
        self.assertNotEqual((self.state / "former").read_text(), "Stopped")
        self.assertTrue((self.journal / "operation").exists())
        self.assert_ok(self.run_script(self.phase, "retire", self.sha, OPERATION))
        self.assert_parked("former", self.old)
        self.assertFalse((self.journal / "operation").exists())
        self.assert_at_most_three()

    def test_a_rollback_to_a_missing_old_is_refused_and_the_old_forgotten(self):
        self.released()
        (self.state / "former").unlink()
        result = self.run_script(self.console, "")
        self.assertIn("the stopped old is now none", result.stderr)
        self.inventory_is(self.sha, None, verified=(self.sha,))
        self.assertFalse((self.journal / "rollback").exists())
        self.assert_at_most_three()


if __name__ == "__main__":
    unittest.main()
