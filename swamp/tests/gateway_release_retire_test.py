"""Retire, verification and slot rules of the three Gateway containers, with the real release commands."""
import json
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from gateway_release_recovery_test import (
    EARLIER,
    HORIZON,
    OPERATION,
    GatewayReleaseFixture,
)


class GatewayReleaseRetire(GatewayReleaseFixture):
    def test_retire_keeps_the_former_stopped_and_replaces_the_earlier_old_only_then(self):
        self.serving_former()
        self.inventory.write_text(json.dumps({"version": 2, "live": self.old, "old": EARLIER, "host_retired": True}))
        (self.state / "earlier").write_text("Stopped")
        (self.state / f"gateway-{EARLIER[:12]}-boot").write_text("false")
        self.assert_ok(self.switch())
        # The candidate serves. Until retire decides, the inventory keeps the earlier old, which still exists.
        self.assertEqual(json.loads(self.inventory.read_text())["old"], EARLIER)
        self.assertTrue((self.state / "earlier").exists())
        self.assert_ok(self.run_script(self.phase, "start-horizon", self.sha, OPERATION, HORIZON))
        self.assert_ok(self.run_script(self.phase, "retire", self.sha, OPERATION))
        events = (self.state / "events").read_text()
        # Verify the new live, drain it, pass the response gate, stop the former live, keep it as the most recent
        # verified release and delete the earlier one, then rebuild preparation.
        self.assertLess(events.rindex(f"public container {self.sha}"), events.index(f"probe {self.retained}"))
        self.assertLess(events.index(f"probe {self.retained}"), events.index("stop former"))
        self.assertLess(events.index("stop former"), events.index("delete earlier"))
        self.assertLess(events.index("delete earlier"), events.index("seed prep"))
        self.assertFalse((self.state / "earlier").exists())
        self.assert_parked("former", self.old)
        self.assertIn(f"systemctl disable {HORIZON}", events)
        self.assert_container_serves()
        self.inventory_is(self.sha, self.old, verified=(self.sha, self.old))
        self.assertTrue((self.state / "prep").exists())
        self.assert_at_most_three()

    def test_a_missing_former_live_keeps_the_earlier_old(self):
        self.serving_former()
        self.inventory.write_text(json.dumps({"version": 2, "live": self.old, "old": EARLIER, "host_retired": True}))
        (self.state / "earlier").write_text("Stopped")
        (self.state / f"gateway-{EARLIER[:12]}-boot").write_text("false")
        self.assert_ok(self.switch())
        (self.state / "former").unlink()
        self.assert_ok(self.run_script(self.phase, "start-horizon", self.sha, OPERATION, HORIZON))
        self.assert_ok(self.run_script(self.phase, "retire", self.sha, OPERATION))
        self.assertTrue((self.state / "earlier").exists())
        self.assertEqual(json.loads(self.inventory.read_text())["old"], EARLIER)
        self.assertTrue((self.state / "prep").exists())
        self.assert_at_most_three()

    def unverified_live_with_earlier_old(self, verified):
        self.serving_former()
        self.inventory.write_text(json.dumps({"version": 2, "live": self.old, "old": EARLIER,
                                              "verified": verified, "host_retired": True}))
        (self.state / "earlier").write_text("Stopped")
        (self.state / f"gateway-{EARLIER[:12]}-boot").write_text("false")
        (self.state / f"broken-public-container-{self.sha}").touch()
        self.assert_ok(self.switch())
        self.assert_ok(self.run_script(self.phase, "start-horizon", self.sha, OPERATION, HORIZON))
        self.assertIn("public check failed", self.run_script(self.phase, "retire", self.sha, OPERATION).stderr)
        self.assertFalse((self.journal / "operation").exists())
        self.assert_at_most_three()

    def test_two_failed_releases_in_a_row_keep_the_verified_rollback_target(self):
        # The serving release never verified and the new one fails too: the verified earlier release stays old, the
        # unverified former live stays parked beside it, and preparation waits for a verification.
        self.unverified_live_with_earlier_old([EARLIER])
        self.assert_parked("former", self.old)
        self.assertTrue((self.state / "earlier").exists())
        self.assertFalse((self.state / "prep").exists())
        self.inventory_is(self.sha, EARLIER, verified=(EARLIER,), earlier=self.old)

    def test_a_failed_release_keeps_every_earlier_release_until_live_verifies(self):
        # Verified A served and verified B was old. C takes traffic and fails its public check: A and B both stay,
        # three containers, and nothing rebuilds preparation.
        self.unverified_live_with_earlier_old([self.old, EARLIER])
        self.assert_parked("former", self.old)
        self.assertTrue((self.state / "earlier").exists())
        self.assertFalse((self.state / "prep").exists())
        self.inventory_is(self.sha, self.old, verified=(self.old, EARLIER), earlier=EARLIER)
        # The next build refuses while C still fails; once C verifies, --recover frees the slot and rebuilds preparation.
        self.assertIn("has not passed its public check", self.direct("build", "false", "{}", "false").stderr)
        (self.state / f"broken-public-container-{self.sha}").unlink()
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assertFalse((self.state / "earlier").exists())
        self.assert_parked("former", self.old)
        self.assertTrue((self.state / "prep").exists())
        self.inventory_is(self.sha, self.old, verified=(self.sha, self.old))
        self.assert_at_most_three()

    def test_a_failed_second_release_never_makes_an_unverified_former_live_old(self):
        # Nothing ever verified, and the new release fails its public check too: old stays empty.
        self.serving_former()
        self.inventory.write_text(json.dumps({"version": 2, "live": self.old, "old": None, "verified": [], "host_retired": True}))
        (self.state / f"broken-public-container-{self.sha}").touch()
        self.assert_ok(self.switch())
        self.assert_ok(self.run_script(self.phase, "start-horizon", self.sha, OPERATION, HORIZON))
        self.assertIn("public check failed", self.run_script(self.phase, "retire", self.sha, OPERATION).stderr)
        # Nothing is verified: old stays empty, and the unverified former live waits as the earlier release.
        self.assert_parked("former", self.old)
        self.inventory_is(self.sha, None, earlier=self.old)
        self.assert_at_most_three()

    def test_a_verified_candidate_keeps_the_verified_earlier_old_over_an_unverified_former(self):
        self.serving_former()
        self.inventory.write_text(json.dumps({"version": 2, "live": self.old, "old": EARLIER,
                                              "verified": [EARLIER], "host_retired": True}))
        (self.state / "earlier").write_text("Stopped")
        (self.state / f"gateway-{EARLIER[:12]}-boot").write_text("false")
        self.assert_ok(self.switch())
        self.assert_ok(self.run_script(self.phase, "start-horizon", self.sha, OPERATION, HORIZON))
        self.assert_ok(self.run_script(self.phase, "retire", self.sha, OPERATION))
        self.assertFalse((self.state / "former").exists())
        self.inventory_is(self.sha, EARLIER, verified=(self.sha, EARLIER))
        self.assert_at_most_three()

    def test_a_missing_old_is_forgotten_instead_of_blocking_releases(self):
        self.released()
        (self.state / "former").unlink()
        self.assert_ok(self.run_script(self.phase, "release-unused", self.old, "43"))
        result = self.direct("build", "false", "{}", "false")
        self.assertIn("gateway.release_container_missing", result.stderr)
        self.assertIn("already serves", result.stderr)
        self.assertIsNone(json.loads(self.inventory.read_text())["old"])

    def test_a_candidate_that_never_served_returns_to_the_preparation_slot(self):
        self.serving_former()
        self.assert_ok(self.run_script(self.phase, "release-unused", self.sha, OPERATION))
        self.assertFalse((self.state / "candidate").exists())
        self.assertTrue((self.state / "prep").exists())
        self.assertEqual(json.loads(self.inventory.read_text())["live"], self.old)
        self.assert_at_most_three()

    def test_a_retried_build_returns_its_candidate_before_it_can_seed_a_fourth_container(self):
        self.serving_former()
        self.inventory.write_text(json.dumps({"version": 2, "live": self.old, "old": EARLIER,
                                              "verified": [self.old, EARLIER], "host_retired": True}))
        (self.state / "earlier").write_text("Stopped")
        (self.state / f"gateway-{EARLIER[:12]}-boot").write_text("false")
        self.direct("build", "false", "{}", "false")
        events = (self.state / "events").read_text()
        self.assertIn("demote candidate", events)
        self.assertNotIn("seed prep", events)
        self.assertTrue((self.state / "prep").exists())
        self.assert_at_most_three()

    def test_an_unverified_former_live_never_becomes_old(self):
        self.serving_former()
        self.inventory.write_text(json.dumps({"version": 2, "live": self.old, "old": None,
                                              "verified": [], "host_retired": True}))
        self.assert_ok(self.switch())
        self.assert_ok(self.run_script(self.phase, "start-horizon", self.sha, OPERATION, HORIZON))
        self.assert_ok(self.run_script(self.phase, "retire", self.sha, OPERATION))
        self.assertFalse((self.state / "former").exists())
        self.assertTrue((self.state / "prep").exists())
        self.inventory_is(self.sha, None, verified=(self.sha,))
        self.assert_at_most_three()

    def test_a_running_old_refuses_the_next_build(self):
        self.released()
        (self.state / "former").write_text("Running")
        self.assertIn("is running; finish its rollback", self.direct("build", "false", "{}", "false").stderr)
        (self.state / "former").write_text("Stopped")
        self.assertIn("already serves", self.direct("build", "false", "{}", "false").stderr)



if __name__ == "__main__":
    unittest.main()
