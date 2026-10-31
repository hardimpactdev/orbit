"""Real filesystem ownership checks and fixed command boundaries, without host effects."""

import importlib.util
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

SCRIPTS = Path(__file__).parent / "scripts"


def load(name):
    spec = importlib.util.spec_from_file_location(name, SCRIPTS / (name + ".py"))
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


common = load("process_common")
control = load("control")
enable = load("enable")
verify = load("verify")


class ProcessCommands(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.units = Path(self.temp.name)
        self.value = {
            "process_id": 17,
            "runtime": "systemd",
            "name": "orbit-process-17-worker.service",
            "on_demand": False,
        }
        self.unit = self.units / self.value["name"]
        self.unit.write_text("[Unit]\nX-Orbit-Process-ID=17\n")
        self.unit.chmod(0o644)
        self.calls = []
        self.state = "active"
        self.boot = False
        self.addCleanup(patch.stopall)
        patch.object(common, "UNITS", self.units).start()
        patch.object(common, "ROOT_UID", os.getuid()).start()
        patch.object(common, "ROOT_GID", os.getgid()).start()

        def run(argv):
            self.calls.append(argv)
            return SimpleNamespace(
                returncode=0, stdout=self.state if argv[1] == "is-active" else ""
            )

        patch.object(common, "command", side_effect=run).start()
        patch.object(control, "command", side_effect=run).start()
        patch.object(enable, "command", side_effect=run).start()

    def test_control_and_boot_policy_are_distinct_actions(self):
        with patch.dict(os.environ, {"ORBIT_PROCESS_OPERATION": "restart"}):
            self.assertEqual(control.control(self.value), {"ready": True})
        self.assertEqual(
            self.calls[-1], [common.SYSTEMCTL, "restart", self.value["name"]]
        )
        self.assertFalse(any("enable" in call for call in self.calls))
        with patch.dict(os.environ, {"ORBIT_PROCESS_ENABLE": "enable"}):
            enable.enable(self.value)
        self.assertEqual(
            self.calls[-1], [common.SYSTEMCTL, "enable", self.value["name"]]
        )

    def test_foreign_marker_is_refused_before_manager_mutation(self):
        self.unit.write_text("X-Orbit-Process-ID=18\n")
        with patch.dict(os.environ, {"ORBIT_PROCESS_OPERATION": "stop"}):
            with self.assertRaises(common.Refused):
                control.control(self.value)
        self.assertEqual(self.calls, [])

    def test_hardlinked_unit_is_refused(self):
        os.link(self.unit, self.units / "foreign-link")
        with self.assertRaises(common.Refused):
            common.observe(self.value)
        self.assertEqual(self.calls, [])

    def test_large_admitted_command_keeps_owned_unit_controllable(self):
        self.unit.write_text(
            "[Service]\nExecStart=/bin/true "
            + " ".join('"' + "x" * 4096 + '"' for _ in range(18))
            + "\n[Unit]\nX-Orbit-Process-ID=17\n"
        )
        self.assertGreater(self.unit.stat().st_size, 65536)
        self.assertEqual(
            common.observe(self.value), {"present": True, "state": "active"}
        )
        with patch.dict(os.environ, {"ORBIT_PROCESS_OPERATION": "restart"}):
            self.assertEqual(control.control(self.value), {"ready": True})
        self.unit.write_text(
            self.unit.read_text().replace(
                "X-Orbit-Process-ID=17", "X-Orbit-Process-ID=18"
            )
        )
        self.calls.clear()
        with self.assertRaises(common.Refused):
            common.observe(self.value)
        self.assertEqual(self.calls, [])

    def test_symlink_and_fifo_are_refused_without_reading_or_mutation(self):
        target = self.units / "other-unit"
        self.unit.rename(target)
        self.unit.symlink_to(target)
        with self.assertRaises(common.Refused) as failure:
            common.observe(self.value)
        self.assertEqual(failure.exception.code, "process.name_collision")
        self.unit.unlink()
        os.mkfifo(self.unit)
        with self.assertRaises(common.Refused) as failure:
            common.observe(self.value)
        self.assertEqual(failure.exception.code, "process.name_collision")
        self.assertEqual(self.calls, [])

    def test_failed_state_is_not_ready_for_running_intent(self):
        self.state = "failed"
        with patch.dict(os.environ, {"ORBIT_PROCESS_DESIRED": "running"}):
            with self.assertRaises(common.Refused) as failure:
                verify.verify(self.value)
        self.assertEqual(failure.exception.code, "process.state_mismatch")
        self.assertTrue(all(call[1] == "is-active" for call in self.calls))

    def test_identity_is_checked_before_a_host_read(self):
        with patch.dict(
            os.environ,
            {
                "ORBIT_PROCESS_CONTROL": json.dumps(
                    {**self.value, "name": "orbit-process-18-worker.service"}
                )
            },
        ):
            with self.assertRaises(common.Refused):
                common.control_input()
        self.assertEqual(self.calls, [])

    def test_docker_control_pins_socket_and_refuses_foreign_labels(self):
        value = {**self.value, "runtime": "docker", "name": "orbit-process-17-worker"}

        def run(argv):
            self.calls.append(argv)
            if "ls" in argv:
                return SimpleNamespace(returncode=0, stdout=value["name"])
            return SimpleNamespace(
                returncode=0, stdout="true\nprocess\n18\n" + "a" * 64 + "\nrunning"
            )

        with (
            patch.object(common, "command", side_effect=run),
            patch.dict(os.environ, {"ORBIT_PROCESS_OPERATION": "stop"}),
        ):
            with self.assertRaises(common.Refused):
                control.control(value)
        self.assertTrue(all(call[1] == common.SOCKET for call in self.calls))
        self.assertFalse(any("stop" in call for call in self.calls))
