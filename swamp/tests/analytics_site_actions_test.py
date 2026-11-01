"""Plausible action-group diagnostics and session lifetime against a disposable CE fixture."""

import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from tests.fixtures.plausible_http import PlausibleFixture

SCRIPT = ROOT / "extensions/behaviors/operations/analytics-site/scripts/site.py"


class PlausibleSiteAction(unittest.TestCase):
    def setUp(self):
        self.provider = PlausibleFixture()
        self.addCleanup(self.provider.close)
        self.site = {
            "domain": "shop.test",
            "previous_domain": None,
            "timezone": "UTC",
            "goals": [],
        }

    def invoke(self):
        with tempfile.TemporaryDirectory() as scratch:
            reply = subprocess.run(
                [sys.executable, "-B", str(SCRIPT)],
                cwd=scratch,
                env=dict(
                    os.environ,
                    ORBIT_ANALYTICS_INPUT=json.dumps(
                        {"base_url": self.provider.url, "site": self.site}
                    ),
                    ORBIT_ANALYTICS_CREDENTIALS=json.dumps(self.provider.credentials),
                ),
                check=False,
                capture_output=True,
                text=True,
                timeout=5,
            )
            self.assertEqual(list(Path(scratch).iterdir()), [])
        for value in [
            self.provider.password,
            self.provider.api_key,
            self.provider.plugin,
            self.provider.cookie,
            "private-csrf-826495",
        ]:
            self.assertNotIn(value, reply.stdout + reply.stderr)
        return reply

    def test_repeat_observes_the_existing_site_and_always_logs_out(self):
        self.assertEqual(self.invoke().returncode, 0)
        self.assertEqual(self.invoke().returncode, 0)
        self.assertEqual(self.provider.sites, {"shop.test": "UTC"})
        self.assertEqual(self.provider.calls.count(("POST", "/sites")), 1)
        self.assertEqual(self.provider.calls.count(("GET", "/logout")), 2)

    def test_safe_stderr_names_the_failed_provider_action_and_http_status(self):
        self.provider.fail = "/api/v2/query"
        reply = self.invoke()
        self.assertEqual(reply.returncode, 1)
        self.assertEqual(
            json.loads(reply.stdout), {"error_code": "analytics.stats_unauthorized"}
        )
        self.assertIn("Plausible Stats API returned HTTP 503", reply.stderr)
        self.assertIn(("GET", "/logout"), self.provider.calls)

    def test_cleanup_failure_preserves_the_primary_failure_and_reports_both(self):
        self.provider.fail_paths = {"/api/v2/query", "/logout"}
        reply = self.invoke()
        self.assertEqual(
            json.loads(reply.stdout), {"error_code": "analytics.stats_unauthorized"}
        )
        self.assertIn("Cleanup: Plausible logout returned HTTP 503", reply.stderr)
        self.assertIn("Plausible Stats API returned HTTP 503", reply.stderr)

    def test_malformed_provider_status_is_normalized_without_a_provider_traceback(self):
        self.provider.malformed_paths = {"/login"}
        reply = self.invoke()
        self.assertEqual(reply.returncode, 1)
        self.assertEqual(
            json.loads(reply.stdout), {"error_code": "analytics.management_unavailable"}
        )
        self.assertIn(
            "Plausible GET /login: response unavailable or unreadable", reply.stderr
        )
        self.assertNotIn("Traceback", reply.stderr)

    def test_malformed_logout_preserves_the_primary_failure_without_provider_text(self):
        self.provider.fail = "/api/v2/query"
        self.provider.malformed_paths = {"/logout"}
        reply = self.invoke()
        self.assertEqual(
            json.loads(reply.stdout), {"error_code": "analytics.stats_unauthorized"}
        )
        self.assertIn("Cleanup: Plausible GET /logout", reply.stderr)
        self.assertIn("Plausible Stats API returned HTTP 503", reply.stderr)
        self.assertNotIn("Traceback", reply.stderr)

    def test_logout_failure_stops_a_successful_site_from_claiming_success(self):
        self.provider.fail = "/logout"
        reply = self.invoke()
        self.assertEqual(reply.returncode, 1)
        self.assertEqual(
            json.loads(reply.stdout), {"error_code": "analytics.logout_failed"}
        )
        self.assertIn("Plausible logout returned HTTP 503", reply.stderr)


if __name__ == "__main__":
    unittest.main()
