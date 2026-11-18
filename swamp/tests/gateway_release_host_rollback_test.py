"""Exercise the host PHP-FPM rollback using the real release commands."""
import json
import os
import socket
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from gateway_release_recovery_test import HORIZON, GatewayReleaseFixture


class GatewayHostRollback(GatewayReleaseFixture):
    def test_host_rollback_refuses_broken_listener_before_drain(self):
        self.assert_ok(self.run_move())
        events = (self.state / "events").read_text()
        with socket.socket(socket.AF_UNIX) as unavailable:
            unavailable.bind(str(self.sock))
            result = self.run_script(self.console, "--host")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Gateway host is not ready", result.stderr)
        self.assertEqual((self.state / "events").read_text(), events)
        self.assert_container_serves()

        self.sock.unlink()
        self.listener(complete=False)
        result = self.run_script(self.console, "--host")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("incomplete FastCGI response", result.stderr)
        self.assertEqual((self.state / "events").read_text(), events)
        self.assert_container_serves()

    def test_host_rollback_refuses_wrong_served_commit_before_drain(self):
        self.listener(self.old)
        self.assert_ok(self.run_move())
        events = (self.state / "events").read_text()
        result = self.run_script(self.console, "--host")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("host PHP-FPM did not report the selected release", result.stderr)
        self.assertEqual((self.state / "events").read_text(), events)
        self.assert_container_serves()

    def test_host_rollback_restores_container_on_public_failure(self):
        self.listener()
        self.assert_ok(self.run_move())
        (self.state / "broken-public").touch()
        result = self.run_script(self.console, "--host")
        self.assertNotEqual(result.returncode, 0)
        self.assert_container_serves()

    def test_host_rollback_recovers_before_drain(self):
        self.listener()
        self.assert_ok(self.run_move())
        self.assert_interrupted(self.run_script(self.console, "--host", interrupt=f"before {self.candidate} drain"))
        self.assertTrue((self.state / f"{self.candidate}-{HORIZON}").exists())
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_container_serves()

    def test_host_rollback_recovers_after_drain_before_switch(self):
        self.listener()
        self.assert_ok(self.run_move())
        self.assert_interrupted(self.run_script(self.console, "--host", interrupt=f"after {self.candidate} drain"))
        self.assertEqual((self.state / "site").read_text(), "container")
        self.assertTrue(self.inventory.exists())
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_container_serves()

    def test_host_rollback_recovers_after_switch_before_verification(self):
        self.listener()
        self.assert_ok(self.run_move())
        self.assert_interrupted(self.run_script(self.console, "--host", interrupt="after host switch"))
        self.assertEqual((self.state / "site").read_text(), "host")
        self.assertTrue(self.inventory.exists())
        unavailable = self.state / f"broken-public-container-{self.sha}"
        unavailable.touch()
        result = self.run_script(self.console, "--recover")
        self.assertNotEqual(result.returncode, 0)
        self.assert_pending(self.sha)
        self.assertTrue((self.journal / "host-fallback/inventory").exists())
        self.assertFalse((self.state / f"{self.candidate}-{HORIZON}").exists())
        unavailable.unlink()
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_container_serves()

    def test_host_rollback_completes_cleanup_after_verified_switch(self):
        self.listener()
        self.assert_ok(self.run_move())
        self.assert_interrupted(self.run_script(self.console, "--host", interrupt="after candidate deletion"))
        self.assertTrue((self.journal / "host-fallback/verified").exists())
        unavailable = self.state / "broken-public"
        unavailable.touch()
        result = self.run_script(self.console, "--recover")
        self.assertNotEqual(result.returncode, 0)
        self.assert_pending(self.sha)
        unavailable.unlink()
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_host_serves()

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
