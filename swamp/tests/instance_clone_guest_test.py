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
            "php": None,
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
                        "network-check-source",
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

    def readiness(self, phase, php=None, fail=None, entrypoint=False, existing=False):
        container = {
            "workspace": "/srv/workspace",
            "branch": "proof",
            "expected_commit": "abc123",
            "php": php,
            "units": [],
            "processes": [],
            "clone": {
                "seed_commit": "abc123",
                "t3": {"internal_port": 3773, "t3": "/opt/agent/bin/t3"},
            },
        }
        commands = []

        def command(argv, **_kwargs):
            # The fake boundary rejects PHP exactly as a container without PHP does.
            actual = argv[4:] if argv[0] == "runuser" else argv
            commands.append(actual)
            code, stdout = 0, ""
            if actual[0] == "php":
                code = 1 if php is None else 0
            if actual[0] == fail:
                code = 17
            if actual[0] == "curl":
                stdout = json.dumps(
                    {"environmentId": "proof", "serverVersion": "0.1.7"}
                )
            if actual[0] == "git":
                stdout = "proof\n" if "symbolic-ref" in actual else "abc123\n"
            if actual[0] == "/opt/agent/bin/t3":
                stdout = actual[actual.index("--label") + 1] + "-PRIVATE-TOKEN\n"
            return subprocess.CompletedProcess(argv, code, stdout, "PRIVATE-STDERR")

        sessions = {
            key: {"token": key + "-PRIVATE-TOKEN"} for key in ["gateway", "swamp"]
        }
        response = io.BytesIO(
            json.dumps(
                {"authenticated": True, "scopes": ["access:read", "access:write"]}
            ).encode()
        )
        response.status = 200

        def opened():
            response.seek(0)
            return response

        connection = MagicMock()
        connection.getresponse.side_effect = opened

        output, error = io.StringIO(), io.StringIO()
        with (
            patch.object(GUEST.subprocess, "run", command),
            patch.object(
                GUEST.sys,
                "stdin",
                io.StringIO(json.dumps(sessions) if existing else ""),
            ),
            patch.object(GUEST.http.client, "HTTPConnection", return_value=connection),
            contextlib.redirect_stdout(output),
            contextlib.redirect_stderr(error),
        ):
            if entrypoint:
                with (
                    patch("sys.argv", [str(SPEC.origin), phase, json.dumps(container)]),
                    self.assertRaises(SystemExit) as exit_status,
                ):
                    runpy.run_path(SPEC.origin, run_name="__main__")
                self.assertEqual(exit_status.exception.code, 1)
            else:
                GUEST.phase_main(phase, json.dumps(container))
        self.assertNotIn("PRIVATE", error.getvalue())
        return commands, output.getvalue(), error.getvalue()

    def test_non_php_sessions_keep_both_authenticated_sessions_without_php(self):
        for existing in [False, True]:
            with self.subTest(existing=existing):
                commands, output, _ = self.readiness("sessions", existing=existing)
                result = json.loads(output)
                self.assertEqual(result["phase"], "sessions")
                self.assertEqual(result["private_reply"]["gateway"]["status"], 200)
                self.assertEqual(result["private_reply"]["swamp"]["status"], 200)
                self.assertEqual(
                    result["changes"], [] if existing else ["sessions-issued"]
                )
                self.assertFalse(any(argv[0] == "php" for argv in commands))

    def test_non_php_check_has_no_php_probe(self):
        commands, _, _ = self.readiness("check")
        self.assertFalse(any(argv[0] == "php" for argv in commands))

    def test_php_readiness_still_checks_the_project_runtime(self):
        for phase in ["sessions", "check"]:
            with self.subTest(phase=phase):
                commands, _, _ = self.readiness(
                    phase, php="/opt/runtime/php/8.5/bin/php"
                )
                self.assertIn(["php", "-v"], commands)

    def test_command_failure_names_command_and_exit_code_without_private_output(self):
        _, output, error = self.readiness("sessions", fail="curl", entrypoint=True)
        self.assertEqual(output, "")
        self.assertEqual(
            error, "instance.container_sessions_failed: command curl exited 17\n"
        )

    def test_broken_php_still_fails_readiness(self):
        _, output, error = self.readiness(
            "sessions",
            php="/opt/runtime/php/8.5/bin/php",
            fail="php",
            entrypoint=True,
        )
        self.assertEqual(output, "")
        self.assertEqual(
            error, "instance.container_sessions_failed: command php exited 17\n"
        )

    def test_non_php_workspace_does_not_write_php_configuration(self):
        with tempfile.TemporaryDirectory() as folder:
            base = Path(folder)
            (base / "srv/workspace/.git").mkdir(parents=True)
            env = base / "srv/workspace/.env"
            env.write_text("PRIVATE-ENV")
            container = {
                "workspace": "/srv/workspace",
                "branch": "proof",
                "expected_commit": "abc123",
                "bundle": "/tmp/proof.bundle",
                "php": None,
                "environment_file": "/srv/workspace/.env",
                "units": [],
                "processes": [],
            }

            def command(argv, **_kwargs):
                if "/usr/local/libexec/orbit-instance-node-runtime.py" in argv:
                    return subprocess.CompletedProcess(argv, 0, '{"status":"unchanged"}', "")
                value = "proof\n" if "symbolic-ref" in argv else "abc123\n"
                return subprocess.CompletedProcess(argv, 0, value, "")

            output = io.StringIO()
            with (
                patch.object(
                    GUEST.pathlib, "Path", lambda p: base / str(p).lstrip("/")
                ),
                patch.object(GUEST.subprocess, "run", command),
                patch.object(GUEST.os, "walk", return_value=[]),
                patch.object(
                    GUEST.sys,
                    "stdin",
                    io.StringIO(json.dumps({"environment": "PRIVATE-ENV"})),
                ),
                contextlib.redirect_stdout(output),
            ):
                GUEST.phase_main("workspace", json.dumps(container))
            self.assertEqual(json.loads(output.getvalue())["changes"], [])
            self.assertFalse((base / "etc/orbit/php").exists())

    def test_php_workspace_writes_the_shared_php_settings_and_restarts_running_units(
        self,
    ):
        with tempfile.TemporaryDirectory() as folder:
            base = Path(folder)
            (base / "srv/workspace/.git").mkdir(parents=True)
            (base / "srv/workspace/.env").write_text("PRIVATE-ENV")
            unit = {"name": "orbit-frankenphp.service", "content": "[Service]\n"}
            # The unit exists and runs with the settings of before the change.
            (base / "etc/systemd/system").mkdir(parents=True)
            (base / "etc/systemd/system" / unit["name"]).write_text(unit["content"])
            settings = base / "etc/orbit/php/conf.d/99-orbit-runtime.ini"
            settings.parent.mkdir(parents=True)
            settings.write_text("memory_limit = 1G\n")
            container = {
                "workspace": "/srv/workspace",
                "branch": "proof",
                "expected_commit": "abc123",
                "bundle": "/tmp/proof.bundle",
                "php": "/opt/orbit-runtime/current/php/8.5/bin/php",
                "environment_file": "/srv/workspace/.env",
                "units": [unit],
                "web_config": {"path": "/etc/orbit/frankenphp.Caddyfile", "content": ":8080 {\n    php_server\n}\n"},
                "processes": [],
            }
            commands = []

            def command(argv, **_kwargs):
                if "/usr/local/libexec/orbit-instance-node-runtime.py" in argv:
                    return subprocess.CompletedProcess(argv, 0, '{"status":"unchanged"}', "")
                commands.append(list(argv))
                value = "proof\n" if "symbolic-ref" in argv else "abc123\n"
                return subprocess.CompletedProcess(argv, 0, value, "")

            class RootOwned(type(base)):
                """A file of the test user reads as root's, as in the container."""

                def stat(self, **kwargs):
                    values = list(super().stat(**kwargs))
                    values[4:6] = [0, 0]
                    return os.stat_result(values)

            def workspace():
                commands.clear()
                with (
                    patch.object(
                        GUEST.pathlib,
                        "Path",
                        lambda p: RootOwned(base / str(p).lstrip("/")),
                    ),
                    patch.object(GUEST.subprocess, "run", command),
                    patch.object(GUEST.os, "walk", return_value=[]),
                    patch.object(GUEST.os, "chown"),
                    patch.object(
                        GUEST.sys,
                        "stdin",
                        io.StringIO(json.dumps({"environment": "PRIVATE-ENV"})),
                    ),
                    contextlib.redirect_stdout(io.StringIO()),
                ):
                    GUEST.phase_main("workspace", json.dumps(container))
                return list(commands)

            restart = ["systemctl", "restart", unit["name"]]
            self.assertIn(restart, workspace())
            self.assertNotIn(restart, workspace())
            web_config = base / "etc/orbit/frankenphp.Caddyfile"
            self.assertEqual(web_config.read_text(), container["web_config"]["content"])
            # A stale Caddyfile must repair and restart even with an unchanged unit and PHP settings.
            web_config.write_text("stale\n")
            self.assertIn(restart, workspace())
            self.assertEqual(web_config.read_text(), container["web_config"]["content"])
            self.assertNotIn(restart, workspace())
            written = settings.read_text()
            self.assertEqual(
                written,
                "memory_limit = 1G\n"
                "auto_prepend_file = /opt/orbit-runtime/current/php-data-bridge/bridge/prepend.php\n",
            )
            # The container phase writes the same file for a default and a deployment: both writers hold one content.
            phase = (
                Path(__file__).parents[1] / "ansible/tasks/instance-container-phase.yml"
            ).read_text()
            line = next(
                x for x in phase.splitlines() if "container_php_ini_content:" in x
            )
            self.assertEqual(json.loads(line.split(":", 1)[1].strip()), written)

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
                if "/usr/local/libexec/orbit-instance-node-runtime.py" in argv:
                    return subprocess.CompletedProcess(argv, 0, '{"status":"unchanged"}', "")
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
