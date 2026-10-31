"""Native dedicated-pool monitoring with unmodified official file actions."""

import grp
import json
import os
import pwd
import shutil
import subprocess
import sys
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from tests.cfgmgmt_fixture import install_cfgmgmt
from tests.native_harness import NativeTestCase

ROOT = Path(__file__).resolve().parents[1]


class PhpMonitorCommandNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/php/monitor-command")
        self.connection = dict(
            install_cfgmgmt(self), nodeIdentityFile="/unused/fixture-key", become=True
        )
        self.host = self.repo / "php"
        self.directory = self.host / "orbit-app-9/generated"
        self.directory.mkdir(parents=True)
        (self.directory / "php-fpm.conf").write_text("existing main")
        (self.directory / "pool.conf").write_text("previous pool")
        (self.directory.parent / "local.conf").write_text("operator local tuning")
        self.home = self.repo / "home"
        (self.home / "current").mkdir(parents=True)
        self.audit = self.repo / "audit"
        commands = self.repo / "commands"
        commands.mkdir()
        script = r"""#!/usr/bin/env python3
import json, os, sys
from pathlib import Path
name=Path(sys.argv[0]).name
with open(os.environ['FIXTURE_AUDIT'],'a') as out:out.write(json.dumps([name,*sys.argv[1:]])+'\n')
if name=='php-fpm8.5' and os.environ.get('FIXTURE_FAIL')=='validate':sys.exit(1)
"""
        for name in ("php-fpm8.5", "systemctl"):
            path = commands / name
            path.write_text(script)
            path.chmod(0o700)
        self.native_env.update(FIXTURE_AUDIT=str(self.audit))
        workflow = yaml.safe_load(
            (ROOT / "workflows/workflow-operation-php-monitor.yaml").read_text()
        )
        for job in workflow["jobs"]:
            job.pop("target")
            job.pop("labels")
            for step in job["steps"]:
                self.assertIn("target", step)
                self.assertIn("labels", step)
                step.pop("target")
                step.pop("labels")
                task = step["task"]
                if task["behaviorType"] == "@swamp/cfgmgmt/file":
                    task["globalArgs"].update(
                        path=str(self.directory / "pool.conf"),
                        owner=pwd.getpwuid(os.getuid()).pw_name,
                        group=grp.getgrgid(os.getgid()).gr_name,
                        become=False,
                    )
                if task["behaviorType"] == "command/shell":
                    original_probe = (
                        task["inputs"]["run"] if step["name"] == "probe" else None
                    )
                    task["inputs"]["run"] = (
                        task["inputs"]["run"]
                        .replace("sudo --preserve-env=PHP_INI_SCAN_DIR ", "")
                        .replace("sudo ", "")
                        .replace("/etc/orbit/php-fpm", str(self.host))
                        .replace("/usr/sbin/php-fpm", str(commands / "php-fpm"))
                        .replace("/usr/bin/systemctl", str(commands / "systemctl"))
                    )
                    if original_probe is not None:
                        task["inputs"]["run"] = original_probe.replace(
                            "/etc/orbit/php-fpm", str(self.host)
                        )
                    if step["name"] == "validate":
                        task["inputs"]["env"]["ORBIT_PHP_MAIN"] = str(
                            self.directory / "php-fpm.conf"
                        )
        (self.repo / "workflows/workflow-operation-php-monitor.yaml").write_text(
            yaml.safe_dump(workflow, sort_keys=False)
        )
        self.runtime = {
            "user": "orbit-app-9",
            "home": str(self.home),
            "version": "8.5",
            "marker": "fixture:9",
            "mode": "monitor",
            "application": str(self.home / "current"),
            "initial_application": str(self.home / "releases/initial"),
            "configuration": {
                "main": "existing main",
                "pool": "desired monitoring pool",
                "initial_pool": "initial monitoring pool",
                "local_defaults": "local",
                "master_ini": "ini",
                "unit": "unit",
            },
        }

    def run_monitor(self, success=True):
        inputs = {
            "operation_id": 17,
            "node_id": 209,
            "kind": "metrics.reconcile",
            "execution_secrets": {},
            "intent": {
                "node_id": 9,
                "control_node_id": 14,
                "connection": self.connection,
                "php_runtime": self.runtime,
            },
        }
        result = self.cli(
            "workflow",
            "run",
            "operation-php-monitor",
            "--input",
            json.dumps(inputs),
            success=success,
        )
        return json.loads(result.stdout)

    def test_monitor_writes_only_pool_then_validates_and_reloads(self):
        self.assertEqual(self.run_monitor()["status"], "succeeded")
        self.assertEqual(
            (self.directory / "pool.conf").read_text(), "desired monitoring pool"
        )
        self.assertEqual((self.directory / "php-fpm.conf").read_text(), "existing main")
        self.assertEqual(
            (self.directory.parent / "local.conf").read_text(), "operator local tuning"
        )
        calls = [json.loads(line) for line in self.audit.read_text().splitlines()]
        self.assertEqual(
            calls,
            [
                [
                    "php-fpm8.5",
                    "-t",
                    "--fpm-config",
                    str(self.directory / "php-fpm.conf"),
                ],
                ["systemctl", "reload", "orbit-orbit-app-9-php8.5-fpm.service"],
            ],
        )

    def test_validation_failure_skips_reload(self):
        self.native_env["FIXTURE_FAIL"] = "validate"
        self.assertEqual(self.run_monitor(success=False)["status"], "failed")
        self.assertNotIn("systemctl", self.audit.read_text())

    def test_initial_pool_is_selected_without_a_current_release(self):
        (self.home / "current").rmdir()
        self.run_monitor()
        self.assertEqual(
            (self.directory / "pool.conf").read_text(), "initial monitoring pool"
        )

    def test_absent_runtime_is_reported_without_file_or_service_effects(self):
        (self.directory / "php-fpm.conf").unlink()
        (self.directory / "pool.conf").unlink()
        result = self.run_monitor()
        self.assertEqual(result["status"], "succeeded")
        history = json.loads(
            self.cli("workflow", "history", "get", result["id"]).stdout
        )
        completion = next(job for job in history["jobs"] if job["name"] == "completion")
        terminal = next(
            step for step in completion["steps"] if step["name"] == "result"
        )
        self.assertEqual(
            json.loads(terminal["outputs"]["stdout"]), {"ready": True, "present": False}
        )
        self.assertFalse((self.directory / "pool.conf").exists())
        self.assertFalse(self.audit.exists())

    def test_current_pool_is_selected_through_a_private_production_home(self):
        if os.geteuid() == 0:
            self.skipTest("Permission-denied fixture requires a non-root worker")
        if (
            shutil.which("sudo") is None
            or subprocess.run(
                ["sudo", "-n", "true"], check=False, capture_output=True
            ).returncode
        ):
            self.skipTest(
                "Private production-home fixture requires noninteractive sudo"
            )
        owner = f"{os.getuid()}:{os.getgid()}"
        self.addCleanup(
            subprocess.run,
            ["sudo", "-n", "chown", "-R", owner, str(self.home)],
            check=True,
        )
        self.home.chmod(0o700)
        subprocess.run(
            ["sudo", "-n", "chown", "65534:65534", str(self.home)], check=True
        )
        self.assertNotEqual(
            subprocess.run(
                ["/usr/bin/test", "-d", str(self.home / "current")], check=False
            ).returncode,
            0,
        )
        self.assertEqual(self.run_monitor()["status"], "succeeded")
        self.assertEqual(
            (self.directory / "pool.conf").read_text(), "desired monitoring pool"
        )
