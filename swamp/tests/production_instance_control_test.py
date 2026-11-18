#!/usr/bin/env python3
"""AppProd live controls share the Node selection and switch lock."""
from contextlib import contextmanager
import hashlib
import io
import json
from pathlib import Path
import re
import runpy
from types import SimpleNamespace
import unittest
from unittest.mock import Mock

FILES = Path(__file__).resolve().parents[1] / "ansible/files"
CONTROL = runpy.run_path(str(FILES / "instance-release-control.py"))["control"]
PROCESS = runpy.run_path(str(FILES / "instance-control-process.py"))["process"]
SHA = "a" * 40
NAME = "instance-41-" + SHA[:12]
UNIT = "orbit-process-13-queue--worker.service"


class ProductionInstanceControl(unittest.TestCase):
    def setUp(self):
        self.held = False
        self.executed = []
        self.inventory = {"live": SHA}
        self.state = {"retired": True}
        self.release = SimpleNamespace(instance=41, private=Path("/41/.release"), node={"node_id": 9},
                                       operation_file=Path("/41/.release/operation.json"),
                                       check_selection=Mock(), identity=Mock(), container=lambda sha: NAME)

        def release(instance, sha, op):
            if instance != "41" or re.fullmatch(r"[a-f0-9]{40}", sha) is None:
                raise ValueError()
            return self.release

        @contextmanager
        def lock(path):
            self.assertEqual(path, Path("/41/.release/lock"))
            self.held = True
            try:
                yield
            finally:
                self.held = False

        self.api = {"Release": release, "lock": lock, "positive": int, "SHA": re.compile(r"[0-9a-f]{40}"),
                    "read": lambda path, default: self.inventory if path.name == "inventory.json" else self.state}

    def run_control(self, sha=SHA, family="process", args=None, node="9"):
        def invoke(argv, check):
            self.assertTrue(self.held)
            self.release.check_selection.assert_called_with(SHA)
            self.release.identity.assert_called_with(SHA)
            self.executed.append(argv)
            return SimpleNamespace(returncode=0)
        return CONTROL(node, "41", sha, family, args or ["start", UNIT], self.api, invoke)

    def test_controls_the_checked_instance_while_the_release_lock_is_held(self):
        self.assertEqual(self.run_control(), 0)
        self.assertEqual(self.executed[0], ["/usr/local/libexec/orbit-instance-control-process", "41", SHA, NAME, "start", UNIT])
        self.assertFalse(self.held)

    def test_stale_node_commit_switching_or_wrong_family_cannot_reach_the_guest(self):
        for request in [{"node": "99"}, {"sha": "f"*40}, {"family": "custom"}, {"sha": "current"}]:
            with self.subTest(request=request), self.assertRaises(ValueError):
                self.run_control(**request)
        self.state = {"switched": True}
        with self.assertRaises(ValueError):
            self.run_control()
        self.assertFalse(self.executed)

    def test_only_a_schedule_fire_can_follow_the_current_selection(self):
        with self.assertRaises(ValueError):
            self.run_control("current", "schedule", ["logs"])
        self.assertEqual(self.run_control("current", "schedule", ["fire", "fixture"]), 0)
        self.assertEqual(self.executed[0][2:4], [SHA, NAME])

    def test_process_definition_retries_reload_and_restart_the_admitted_unit(self):
        calls = []
        def run(argv, **kw):
            calls.append((argv, kw))
            return SimpleNamespace(returncode=0)
        env = "PRIVATE=fixture\n"
        plan = {"unit": "fixture", "environment": env, "desired": "running", "activation_pending": True,
                "mounts": ["/srv/shared"], "environment_path": "/etc/orbit/processes/13/"+hashlib.sha256(env.encode()).hexdigest()+".env"}
        self.assertEqual(PROCESS("41", SHA, NAME, "update", UNIT, [], io.StringIO(json.dumps(plan)), run), 0)
        self.assertTrue(all(argv[:7] == ["incus", "--force-local", "--project", "orbit-releases", "exec", NAME, "--"] for argv, _ in calls))
        self.assertTrue(all(env not in argv for argv, _ in calls))
        self.assertIn(["mountpoint", "--quiet", "--", "/srv/shared"], [argv[7:] for argv, _ in calls])
        self.assertEqual([argv[7:] for argv, _ in calls[-3:]],
                         [["systemctl", "daemon-reload"], ["systemctl", "enable", "--", UNIT], ["systemctl", "restart", "--", UNIT]])
        with self.assertRaises(ValueError):
            PROCESS("42", SHA, NAME, "stop", UNIT, [], run=run)


if __name__ == "__main__":
    unittest.main()
