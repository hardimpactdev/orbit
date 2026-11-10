"""Exercise clone files at a private filesystem boundary, without Incus or root."""

import contextlib
import importlib.util
import io
import json
import os
import runpy
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

SPEC = importlib.util.spec_from_file_location(
    "clone_guest", Path(__file__).parents[1] / "scripts/instance_clone_guest.py"
)
GUEST = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(GUEST)


class CloneGuestTest(unittest.TestCase):
    def test_sessions_check_only_loopback_t3_and_reject_http_and_scope_failures(self):
        container = {
            "workspace": "/srv/workspace",
            "branch": "feature",
            "expected_commit": "abc",
            "clone": {"t3": {"internal_port": 3773}},
            "units": [],
            "processes": [],
        }
        existing = {
            "gateway": {"token": "private-gateway"},
            "swamp": {"token": "private-swamp"},
        }

        def command(argv, **_kwargs):
            if "curl" in argv:
                value = '{"environmentId":"clone-env","serverVersion":"0.1.7"}'
            elif "symbolic-ref" in argv:
                value = "feature"
            elif "rev-parse" in argv:
                value = "abc"
            else:
                value = ""
            return subprocess.CompletedProcess(argv, 0, value, "")

        for status, scopes, error in [
            (200, ["access:read", "access:write"], None),
            (401, ["access:read", "access:write"], RuntimeError),
            (200, ["access:read"], AssertionError),
        ]:
            with self.subTest(status=status, scopes=scopes):
                connection = MagicMock()

                def response(status=status, scopes=scopes):
                    body = io.BytesIO(
                        json.dumps({"authenticated": True, "scopes": scopes}).encode()
                    )
                    body.status = status
                    return body

                connection.getresponse.side_effect = response
                output = io.StringIO()
                with (
                    patch.object(
                        GUEST.http.client, "HTTPConnection", return_value=connection
                    ) as connect,
                    patch.object(GUEST.subprocess, "run", command),
                    patch.object(GUEST.sys, "stdin", io.StringIO(json.dumps(existing))),
                    contextlib.redirect_stdout(output),
                ):
                    if error:
                        with self.assertRaises(error):
                            GUEST.phase_main("sessions", json.dumps(container))
                        self.assertEqual(output.getvalue(), "")
                    else:
                        GUEST.phase_main("sessions", json.dumps(container))
                        reply = json.loads(output.getvalue())
                        self.assertEqual(reply["changes"], [])
                        self.assertEqual(
                            reply["private_reply"]["gateway"]["token"],
                            "private-gateway",
                        )
                        self.assertEqual(connection.request.call_count, 2)
                    connect.assert_called_with("127.0.0.1", 3773, timeout=5)
                    connection.request.assert_any_call(
                        "GET",
                        "/api/auth/session",
                        headers={"Authorization": "Bearer private-gateway"},
                    )
                    self.assertEqual(connection.request.call_count, 1 if error else 2)
                    self.assertEqual(connection.close.call_count, 1 if error else 2)

    def test_workspace_forwards_one_json_vault_value_without_printing_secrets(self):
        payload = {
            "environment": "KEY=private\n--orbit-run-1--\n",
            "processes": {"/etc/orbit/processes/1/env": "QUEUE=private\n"},
        }
        calls = []

        def command(argv, **kwargs):
            calls.append((argv, kwargs.get("input")))
            value = '{"changes":[]}' if "exec" in argv else "abc"
            return subprocess.CompletedProcess(argv, 0, value, "")

        with tempfile.TemporaryDirectory() as folder:
            container = {
                "name": "instance-8",
                "project": "fixture",
                "instance_id": 8,
                "mirror": folder,
                "branch": "feature",
            }
            output = io.StringIO()
            with (
                patch.object(
                    sys,
                    "argv",
                    [
                        "clone.py",
                        json.dumps(container),
                        "guest-source",
                        "orbit",
                        folder,
                        "workspace",
                    ],
                ),
                patch.object(sys, "stdin", io.StringIO(json.dumps(payload))),
                patch.object(subprocess, "run", command),
                contextlib.redirect_stdout(output),
            ):
                runpy.run_path(
                    str(Path(__file__).parents[1] / "scripts/instance_clone.py")
                )
            delivered = [
                json.loads(content) for argv, content in calls if "exec" in argv
            ]
            self.assertEqual(delivered, [payload])
            self.assertNotIn("private", output.getvalue())

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
