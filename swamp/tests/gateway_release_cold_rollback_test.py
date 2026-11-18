"""Roll the Gateway back to its stopped old container, offline and through the native phases, with the real release commands."""
import json
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from gateway_release_recovery_test import HORIZON, GatewayReleaseFixture

class GatewayColdRollback(GatewayReleaseFixture):
    def assert_rolled_back(self):
        self.assertEqual(json.loads(self.inventory.read_text()), {"version": 2, "live": self.old, "old": self.sha, "host_retired": True})
        self.assertEqual((self.state / "former").read_text(), "Running")
        self.assertTrue((self.state / f"{self.retained}-{HORIZON}").exists())
        self.assert_parked("candidate", self.sha)
        self.assertIn("10.1.0.3", self.upstream.read_text())
        self.assertFalse((self.journal / "operation").exists())

    def test_console_rolls_back_to_the_stopped_old_and_forward_again_without_swamp(self):
        self.released()
        self.assertIn("is " + self.old[:12], self.run_script(self.console, self.sha[:12]).stderr)
        result = self.run_script(self.console, "")
        self.assert_ok(result)
        self.assertIn(f"serving {self.old[:12]}", result.stdout)
        self.assert_rolled_back()
        events = (self.state / "events").read_text()
        rollback = events[events.rindex("start former"):]
        self.assertLess(rollback.index("fence gateway-" + self.old[:12]), rollback.index("site container"))
        self.assertLess(rollback.index("site container"), rollback.index("stop candidate"))
        self.assertNotIn("delete", events)
        # The rolled-away release is now old, so a second rollback goes forward again.
        self.assert_ok(self.run_script(self.console, self.sha[:12]))
        self.assertEqual(json.loads(self.inventory.read_text())["live"], self.sha)
        self.assert_parked("former", self.old)

    def test_interrupted_rollback_resumes_with_recover(self):
        self.released()
        self.assertEqual(self.run_script(self.console, "", interrupt=f"before {self.candidate} drain").returncode, -9)
        self.assert_pending(self.old)
        self.assertIn("--recover", self.run_script(self.console, "").stderr)
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_rolled_back()

    def test_native_rollback_phases_switch_through_the_same_journal(self):
        self.released()
        phase = lambda name, *args: self.run_script(self.phase, name, self.old, "43", *args)
        self.assert_ok(phase("build-checked", "true", "{}", "true"))
        self.assertIn(f"config {self.retained} user.orbit.release.operation=43", (self.state / "events").read_text())
        self.assert_ok(phase("migrate"))
        self.assertIn("migrate-exit=0", phase("migrate").stdout)
        self.assert_ok(phase("switch", self.sha[:12], HORIZON))
        self.assertEqual((self.journal / "candidate").read_text().strip(), self.old)
        self.assert_ok(phase("start-horizon", HORIZON))
        self.assert_ok(phase("public-ready"))
        self.assert_ok(phase("retire"))
        self.assert_ok(phase("release-unused"))
        self.assert_rolled_back()

    def test_failed_rollback_before_the_switch_parks_old_again(self):
        self.released()
        (self.state / "broken-config").touch()
        (self.state / "env").write_text("changed")
        result = self.run_script(self.console, "")
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse((self.journal / "operation").exists())
        self.assertEqual(json.loads(self.inventory.read_text()), {"version": 2, "live": self.sha, "old": self.old, "host_retired": True})
        self.assert_parked("former", self.old)
        self.assertTrue((self.state / f"{self.candidate}-{HORIZON}").exists())

    def test_rollback_needs_a_stopped_old_release(self):
        self.serving_former()
        result = self.run_script(self.console, "")
        self.assertIn("no stopped old release", result.stderr)
        self.assertIn("not the retained Gateway release", self.direct("build", "false", "{}", "true").stderr)



if __name__ == "__main__":
    unittest.main()
