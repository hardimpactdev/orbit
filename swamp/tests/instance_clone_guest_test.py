"""Exercise clone files at a private filesystem boundary, without Incus or root."""

import contextlib
import importlib.util
import io
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location(
    "clone_guest", Path(__file__).parents[1] / "scripts/instance_clone_guest.py"
)
GUEST = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(GUEST)


class CloneGuestTest(unittest.TestCase):
    def test_agent_preserves_unmanaged_json_and_identity_and_repairs_only_managed_keys(
        self,
    ):
        with tempfile.TemporaryDirectory() as folder:
            base = Path(folder)
            for name in [
                "home/agent/.claude",
                "home/agent/.t3/userdata",
                "home/agent/.config/orbit",
            ]:
                (base / name).mkdir(parents=True, exist_ok=True)
            settings = base / "home/agent/.t3/userdata/settings.json"
            settings.write_text(
                json.dumps({"theme": "dark", "linkedEnvironmentsEnabled": False})
            )
            container = {
                "workspace": "/srv/workspace",
                "clone": {
                    "files": [
                        {
                            "path": "/home/agent/.t3/userdata/settings.json",
                            "uid": os.getuid(),
                            "gid": os.getgid(),
                            "mode": "0600",
                            "json": {"linkedEnvironmentsEnabled": True},
                        }
                    ],
                    "links": [],
                    "model_environment": {
                        "path": "/home/agent/.config/orbit/model.env",
                        "uid": os.getuid(),
                        "gid": os.getgid(),
                        "mode": "0600",
                    },
                },
            }
            commands = []

            def command(argv, **_kwargs):
                commands.append(argv)
                # Configured identity stays; the trust list must narrow to this workspace.
                key = argv[-1]
                value = (
                    "/srv/workspace\n"
                    if key == "safe.directory"
                    else "Custom Identity\n"
                )
                return subprocess.CompletedProcess(argv, 0, value, "")

            def inside(value):
                return base / str(value).lstrip("/")

            def run():
                output = io.StringIO()
                with (
                    patch.object(GUEST.pathlib, "Path", inside),
                    patch.object(GUEST.subprocess, "run", command),
                    patch.object(
                        GUEST.sys,
                        "stdin",
                        io.StringIO("ORBIT_MODEL_KEY=private-proof\n"),
                    ),
                    contextlib.redirect_stdout(output),
                ):
                    GUEST.phase_main("agent", json.dumps(container))
                self.assertNotIn("private-proof", output.getvalue())
                return json.loads(output.getvalue())

            self.assertIn("/home/agent/.t3/userdata/settings.json", run()["changes"])
            self.assertEqual(
                json.loads(settings.read_text()),
                {"theme": "dark", "linkedEnvironmentsEnabled": True},
            )
            self.assertEqual(settings.stat().st_mode & 0o777, 0o600)
            self.assertEqual(run()["changes"], [])
            self.assertFalse(any("--replace-all" in argv for argv in commands))
            settings.write_text("{broken")
            with self.assertRaises(json.JSONDecodeError):
                run()


if __name__ == "__main__":
    unittest.main()
