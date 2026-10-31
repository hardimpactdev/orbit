"""Actual native PHP monitoring DAG against owned scratch files and fake tool boundaries."""

import hashlib
import json
import os
import pwd
import sys
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from tests.native_harness import NativeTestCase

ROOT = Path(__file__).resolve().parents[1]
FAMILY = "operations/php/monitor-command"


class PhpMonitorCommandNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors(FAMILY)
        self.host = self.repo / "host"
        self.host.mkdir()
        self.user = pwd.getpwuid(os.getuid()).pw_name
        home = self.host / "home" / self.user
        application = home / "releases/initial"
        application.mkdir(parents=True)
        self.directory = self.host / "etc/orbit/php-fpm" / self.user
        generated = self.directory / "generated"
        generated.mkdir(parents=True)
        self.units = self.host / "units"
        self.units.mkdir()
        (self.host / "locks").mkdir()
        (self.host / "proc/sys/kernel/random").mkdir(parents=True)
        (self.host / "proc/sys/kernel/random/boot_id").write_text(
            "a075eb23-7ac4-4487-80d1-57a038a92676\n"
        )
        service = f"orbit-{self.user}-php8.5-fpm.service"
        self.service = self.host / "service.json"
        self.service.write_text(
            json.dumps({"active": True, "fail_reload": False, "calls": []})
        )
        self.fpm = self.host / "php-fpm"
        self.fpm.write_text("""#!/usr/bin/env python3
import os, sys
if "-i" in sys.argv: print(os.environ["PHP_INI_SCAN_DIR"].split(":")[-1]+"/master.ini")
""")
        self.fpm.chmod(0o700)
        common = (
            self.repo / "extensions/behaviors" / FAMILY / "scripts/runtime_common.py"
        )
        common.write_text(
            common.read_text()
            + f"""
class FixtureHost(Host):
    def __init__(self):
        base=P({str(self.host)!r})
        self.homes=base/'home';self.etc=base/'etc/orbit';self.units=base/'units'
        self.locks=base/'locks';self.proc=base/'proc';self.php_conf=base/'php';self.sockets=base/'sockets'
        self.root_uid=os.getuid();self.root_gid=self.caddy_gid=os.getgid()
    def active(self, service): return json.loads(P({str(self.service)!r}).read_text())['active']
    def pid(self, service): return 11
    def start(self, pid): return 100
    def fpm(self, version): return P({str(self.fpm)!r})
    def ok(self, *args):
        path=P({str(self.service)!r});state=json.loads(path.read_text());state['calls'].append(list(args))
        fail=state['fail_reload'] and args[0]=='reload'
        if fail: state['fail_reload']=False
        path.write_text(json.dumps(state));return not fail
Host=FixtureHost
"""
        )
        # Fixture-only import keeps the actual script assets, ownership checks,
        # file transactions and native dependencies intact.
        common.write_text("import json\n" + common.read_text())
        main = f"[global]\ninclude = {generated}/pool.conf\ninclude = {self.directory}/local.conf\n"
        pool = f"[orbit-{self.user}]\nchdir = {application}\n"
        desired = (
            pool
            + f"pm.status_path = /orbit-fpm-status\npm.status_listen = /run/php/{self.user}.sock.status\n"
        )
        self.prior = pool + f"\n; Orbit application release: {application}\n"
        local = f"[orbit-{self.user}]\npm = ondemand\n"
        for path, text in [
            (self.directory / "orbit.identity", "fixture:9"),
            (self.directory / "local.conf", local),
            (generated / "php-fpm.conf", main),
            (generated / "pool.conf", self.prior),
            (generated / "master.ini", "; fixture\n"),
            (
                generated / "local.sha256",
                hashlib.sha256(local.encode()).hexdigest() + "\n",
            ),
            (self.units / service, "unit\n"),
        ]:
            path.write_text(text)
            path.chmod(0o644)
        generation = hashlib.sha256(
            b"".join(
                line + b"\n"
                for line in self.prior.encode().split(b"\n")
                if line.startswith((b"chdir = ", b"; Orbit application release: "))
            )
        ).hexdigest()
        receipt = self.directory / ".runtime-generation.applied"
        receipt.write_text(
            generation + "\na075eb23-7ac4-4487-80d1-57a038a92676\n11\n100\n"
        )
        receipt.chmod(0o600)
        self.runtime = {
            "user": self.user,
            "home": str(home),
            "version": "8.5",
            "marker": "fixture:9",
            "mode": "monitor",
            "application": str(application),
            "initial_application": str(application),
            "configuration": {
                "main": main,
                "pool": desired,
                "initial_pool": desired,
                "local_defaults": local,
                "master_ini": "; fixture\n",
                "unit": "unit\n",
            },
        }
        workflow = yaml.safe_load(
            (ROOT / "workflows/workflow-operation-php-monitor.yaml").read_text()
        )
        for job in workflow["jobs"]:
            self.assertIn("target", job)
            job.pop("target")
            job.pop("labels")
            for step in job["steps"]:
                self.assertIn("target", step)
                step.pop("target")
                step.pop("labels")
                task = step["task"]
                if task["behaviorType"] == "command/shell":
                    task["inputs"]["run"] = task["inputs"]["run"].replace(
                        "sudo --preserve-env=ORBIT_PHP_MONITOR_INPUT ", ""
                    )
        (self.repo / "workflows/workflow-operation-php-monitor.yaml").write_text(
            yaml.safe_dump(workflow, sort_keys=False)
        )

    def run_monitor(self, success=True):
        return self.cli(
            "workflow",
            "run",
            "operation-php-monitor",
            "--input",
            json.dumps(
                {
                    "operation_id": 17,
                    "node_id": 9,
                    "kind": "metrics.reconcile",
                    "intent": {"php_runtime": self.runtime},
                    "execution_secrets": {},
                }
            ),
            success=success,
        )

    def test_monitor_success_changes_only_pool_and_reloads(self):
        self.run_monitor()
        self.assertIn(
            "pm.status_path", (self.directory / "generated/pool.conf").read_text()
        )
        self.assertEqual(
            json.loads(self.service.read_text())["calls"],
            [["reload", f"orbit-{self.user}-php8.5-fpm.service"]],
        )
        self.assertFalse((self.directory / ".metrics-pool.pending").exists())
        self.assertFalse(list(self.directory.glob(".metrics-native-*")))

    def test_reload_failure_restores_original_pool_in_native_recovery_branch(self):
        data = json.loads(self.service.read_text())
        data["fail_reload"] = True
        self.service.write_text(json.dumps(data))
        self.run_monitor(success=False)
        self.assertEqual(
            (self.directory / "generated/pool.conf").read_text(), self.prior
        )
        self.assertEqual(len(json.loads(self.service.read_text())["calls"]), 2)
        self.assertFalse((self.directory / ".metrics-pool.pending").exists())

    def test_unrelated_pool_change_refuses_before_publication(self):
        original = self.prior + "[unrelated]\nchdir = /srv/new\n"
        (self.directory / "generated/pool.conf").write_text(original)
        self.run_monitor(success=False)
        self.assertEqual((self.directory / "generated/pool.conf").read_text(), original)
        self.assertEqual(json.loads(self.service.read_text())["calls"], [])

    def test_stopped_service_is_not_started(self):
        data = json.loads(self.service.read_text())
        data["active"] = False
        self.service.write_text(json.dumps(data))
        self.run_monitor()
        self.assertEqual(json.loads(self.service.read_text())["calls"], [])
