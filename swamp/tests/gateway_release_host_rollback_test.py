"""Exercise the host PHP-FPM rollback using the real release commands."""
import json
import os
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from gateway_release_recovery_test import (
    HORIZON,
    MANAGED_HORIZON,
    GatewayReleaseFixture,
)


class GatewayHostRollback(GatewayReleaseFixture):
    def test_host_fallback_refuses_after_the_first_move_even_if_php_fpm_is_healthy(self):
        self.assert_ok(self.run_move())
        before = self.inventory.read_bytes()
        for option, refusal in (("--host", "stopped old container"), (self.old[:12], "no stopped old release"), ("", "no stopped old release")):
            result = self.run_script(self.console, option)
            self.assertIn(refusal, result.stderr)
            self.assertEqual(self.inventory.read_bytes(), before)
        self.assert_container_serves()

    def test_first_move_preserves_stopped_registered_processes(self):
        receipt = json.loads((self.state / "receipt").read_text())
        (self.state / "receipt").write_text(json.dumps({**receipt, "horizon_unit": MANAGED_HORIZON}))
        (self.state / f"host-{HORIZON}").unlink()
        self.assert_ok(self.run_move())
        self.assertFalse((self.state / f"{self.candidate}-{MANAGED_HORIZON}").exists())
        self.assertFalse((self.state / f"{self.candidate}-{HORIZON}").exists())

    def test_move_cannot_race_an_interrupted_forward_handover(self):
        self.assert_interrupted(self.run_move("after container switch"))
        result = self.run_move()
        self.assertIn("--recover", result.stderr)
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_container_serves()

    def test_first_move_keeps_host_horizon_when_the_new_queue_cannot_start(self):
        failed = self.state / "broken-horizon"
        failed.touch()
        self.assertNotEqual(self.run_move().returncode, 0)
        self.assert_pending(self.sha)
        self.assertTrue((self.state / f"host-{HORIZON}").exists())
        self.assertFalse((self.journal / "host-retired").exists())
        failed.unlink()
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_container_serves()

    def test_ordinary_fpm_release_and_rollback(self):
        release = self.home / "releases" / self.old[:12]
        release.mkdir()
        (release / "REVISION").write_text(self.old)
        self.copy("bin/gateway-rollback", release / "bin/gateway-rollback")
        for sha in (self.old, self.sha):
            web = self.home / "web/releases" / sha[:12]
            web.mkdir(parents=True)
            (web / "index.html").touch()

        switch = self.copy("swamp/scripts/gateway-release-switch.sh", self.root / "switch")
        result = self.run_script(switch, self.old[:12], self.old, HORIZON)
        self.assert_ok(result)
        self.assertEqual(json.loads(result.stdout), [])
        self.assertEqual((self.home / "orbit").resolve(), release)
        self.assertEqual(os.readlink(self.home / "web/current"), f"releases/{self.old[:12]}")

        self.assert_ok(self.run_script(self.console, self.sha[:12]))
        self.assertEqual((self.home / "orbit").resolve(), self.checkout)
        self.assertEqual(os.readlink(self.home / "web/current"), f"releases/{self.sha[:12]}")
        self.assertEqual((self.state / "events").read_text().count("systemctl reload php8.5-fpm"), 2)


if __name__ == "__main__":
    unittest.main()
