"""The production refresh: config cache, FPM reload and Process restarts, each with its legacy error code."""

import importlib.util
import json
import types
import unittest
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("refresh", Path(__file__).parent / "scripts" / "refresh.py")
assert spec and spec.loader
refresh = importlib.util.module_from_spec(spec)
spec.loader.exec_module(refresh)

CONFIG = {"user": "orbit-app-7", "home": "/home/orbit-app-7", "version": "8.5", "application": "/home/orbit-app-7/current"}
PLAN = {"config": CONFIG, "fpm_service": "orbit-orbit-app-7-php8.5-fpm.service",
        "processes": [{"id": 4, "runtime": "systemd", "name": "orbit-process-4-worker.service"},
                      {"id": 5, "runtime": "docker", "name": "orbit-process-5-queue"}]}


class Refresh(unittest.TestCase):
    def run_refresh(self, plan=PLAN, outcome="config_cached", failing=()):
        calls = []

        def run(argv):
            calls.append(argv)
            failed = any(word in argv for word in failing)
            return types.SimpleNamespace(returncode=1 if failed else 0, stdout="exited\n" if "inspect" in argv and failed else "running\n")

        with patch.object(refresh, "config_cache", return_value=outcome), patch.object(refresh, "run", side_effect=run):
            return (*refresh.main([json.dumps(plan)]), calls)

    def test_rebuilds_the_cache_reloads_fpm_and_restarts_each_process(self):
        result, code, calls = self.run_refresh()
        self.assertEqual((result, code), ({"runtime_refresh": "config_cache_and_opcache_refreshed", "restarted_process_ids": [4, 5]}, 0))
        self.assertEqual(calls[0], ["/usr/bin/systemctl", "reload", PLAN["fpm_service"]])
        self.assertIn(["/usr/bin/systemctl", "restart", "orbit-process-4-worker.service"], calls)
        self.assertIn([*refresh.DOCKER, "container", "restart", "orbit-process-5-queue"], calls)

    def test_an_uncached_release_still_resets_opcache(self):
        self.assertEqual(self.run_refresh(outcome="config_uncached")[0]["runtime_refresh"], "opcache_refreshed_config_uncached")

    def test_no_current_release_skips_fpm_and_processes(self):
        result, code, calls = self.run_refresh(outcome="no_current_release")
        self.assertEqual((result, code, calls), ({"runtime_refresh": "skipped_no_current_release", "restarted_process_ids": []}, 0, []))

    def test_without_php_only_processes_restart(self):
        result, _, calls = self.run_refresh(plan={**PLAN, "config": None, "fpm_service": None})
        self.assertEqual(result, {"runtime_refresh": "skipped_no_php_version", "restarted_process_ids": [4, 5]})
        self.assertNotIn("reload", json.dumps(calls))

    def test_each_failure_returns_its_error_code(self):
        self.assertEqual(self.run_refresh(failing=("reload",))[:2], ({"error_code": "env.php_runtime_refresh_failed"}, 1))
        self.assertEqual(self.run_refresh(failing=("restart",))[:2], ({"error_code": "env.runtime_refresh_failed"}, 1))
        with patch.object(refresh, "config_cache", side_effect=refresh.Refused("env.config_cache_refresh_failed")):
            self.assertEqual(refresh.main([json.dumps(PLAN)]), ({"error_code": "env.config_cache_refresh_failed"}, 1))


if __name__ == "__main__":
    unittest.main()
