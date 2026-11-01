"""Environment-only stdin and Docker create requests preserve private bytes."""

import io
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from scripts_test import common, load

files = load("unit_files")
environment = load("lifecycle_environment")
load("docker_files")
container = load("lifecycle_container")


class LifecycleScripts(unittest.TestCase):
    def test_immutable_environment_is_atomic_0600_and_digest_bound(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp) / "orbit"
            intent = Path(temp) / "intent.json"
            value = {
                "process_id": 17,
                "runtime": "systemd",
                "name": "orbit-process-17-proof.service",
                "on_demand": False,
            }
            secret = '# Orbit Process 17\nTOKEN="private-value"\n'
            target = root / "processes/17" / (files.digest(secret) + ".env")
            intent.write_text(
                json.dumps(
                    {
                        "installation": {
                            **value,
                            "environment_path": str(target),
                            "unit": "EnvironmentFile=" + str(target) + "\n",
                        }
                    }
                )
            )
            with (
                patch.object(common, "ROOT_UID", os.getuid()),
                patch.object(common, "ROOT_GID", os.getgid()),
                patch.object(
                    environment,
                    "Path",
                    side_effect=lambda p: root if p == "/etc/orbit" else Path(p),
                ),
                patch.object(sys, "argv", ["script", str(intent)]),
            ):
                for _ in range(2):
                    with patch.object(sys, "stdin", io.StringIO(secret)):
                        self.assertEqual(environment.publish(value), {"ready": True})
                self.assertEqual(target.read_text(), secret)
                self.assertEqual(target.stat().st_mode & 0o777, 0o600)
                with (
                    patch.object(sys, "stdin", io.StringIO(secret + "OTHER=true\n")),
                    self.assertRaises(common.Refused),
                ):
                    environment.publish(value)
                self.assertEqual(target.read_text(), secret)

    def test_container_private_environment_uses_stdin_and_foreign_spec_refuses(self):
        with tempfile.TemporaryDirectory() as temp:
            intent = Path(temp) / "intent.json"
            value = {
                "process_id": 17,
                "runtime": "docker",
                "name": "orbit-process-17-proof",
                "on_demand": False,
            }
            definition = {
                "image": "example:1",
                "command": ["echo", "ok"],
                "restart": "no",
                "working_directory": "/work",
                "ports": [],
                "mounts": [],
            }
            intent.write_text(
                json.dumps(
                    {
                        "installation": {
                            **value,
                            "spec": "a" * 64,
                            "definition": definition,
                        }
                    }
                )
            )
            with patch.object(sys, "argv", ["script", str(intent)]):
                with (
                    patch.object(
                        sys, "stdin", io.StringIO('{"TOKEN":"private-value"}')
                    ),
                    patch.object(container, "observe", return_value={"present": False}),
                    patch.object(container, "request_create") as create,
                ):
                    container.create(value)
                    body = create.call_args.args[1]
                    self.assertEqual(body["Env"], ["TOKEN=private-value"])
                    self.assertEqual(body["Cmd"], ["echo", "ok"])
                    self.assertEqual(body["WorkingDir"], "/work")
                    self.assertNotIn("private-value", intent.read_text())
                with (
                    patch.object(sys, "stdin", io.StringIO("{}")),
                    patch.object(
                        container,
                        "observe",
                        return_value={"present": True, "spec": "b" * 64},
                    ),
                    patch.object(container, "request_create") as create,
                ):
                    with self.assertRaises(common.Refused):
                        container.create(value)
                    create.assert_not_called()
