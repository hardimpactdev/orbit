"""database:server:create phases against a recorded Docker CLI: branches, secrets off argv, error codes."""

import json
import subprocess
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).with_name("server")))
import create

SECRET = "root-password-sentinel"
INTENT = {
    "driver": "mysql",
    "process_id": 7,
    "container": "orbit-process-7-app",
    "spec": "a" * 64,
    "scrub_spec": "b" * 64,
    "initialize": True,
    "password_variable": "MYSQL_ROOT_PASSWORD",
    "volume": None,
    "container_input": {
        "image": "mysql:8.4",
        "command": ["mysqld"],
        "restart": "unless-stopped",
        "working_directory": None,
        "ports": ["10.0.0.5:3306:3306"],
        "mounts": [
            {"source": "orbit-app-data", "target": "/var/lib/mysql", "read_only": False}
        ],
    },
}


def container(spec, running=True, process_id="7", env=()):
    return json.dumps(
        [
            {
                "Id": "c" * 64,
                "Config": {
                    "Labels": {
                        "orbit.process.id": process_id,
                        "orbit.process.spec": spec,
                    },
                    "Env": list(env),
                },
                "State": {
                    "Running": running,
                    "StartedAt": "2026-11-02T00:00:00Z",
                    "Health": {"Status": "healthy"},
                },
            }
        ]
    )


class Docker:
    """Answers `docker` calls from a table of (argv prefix -> (code, stdout)); records argv and env files."""

    def __init__(self, answers):
        self.answers, self.calls, self.env_files = answers, [], []

    def __call__(self, *argv, stdin=None):
        self.calls.append(list(argv))
        assert SECRET not in repr(argv)
        if "--env-file" in argv:
            self.env_files.append(Path(argv[argv.index("--env-file") + 1]).read_text())
        for prefix, (code, stdout) in self.answers.items():
            if list(argv[: len(prefix.split())]) == prefix.split():
                return subprocess.CompletedProcess(argv, code, stdout, "")
        return subprocess.CompletedProcess(argv, 0, "", "")


class ServerCreate(unittest.TestCase):
    def run_phase(self, phase, answers, intent=INTENT):
        docker = Docker(answers)
        with patch.object(create, "docker", docker):
            try:
                return getattr(create, phase)(dict(intent), SECRET), docker
            except create.Refused as error:
                return {"error_code": error.code}, docker

    def test_start_creates_with_the_password_only_in_a_removed_env_file(self):
        result, docker = self.run_phase("start", {"container inspect": (1, "")})
        self.assertEqual(result, {"created": True})
        created = next(call for call in docker.calls if call[0] == "create")
        self.assertIn("orbit.process.spec=" + "a" * 64, created)
        self.assertIn("10.0.0.5:3306:3306", created)
        self.assertEqual(docker.env_files, ["MYSQL_ROOT_PASSWORD=" + SECRET + "\n"])
        self.assertFalse(Path(created[created.index("--env-file") + 1]).exists())
        self.assertEqual(docker.calls[-1], ["start", "orbit-process-7-app"])

    def test_start_reuses_a_recorded_container_and_replaces_a_changed_one(self):
        result, docker = self.run_phase(
            "start", {"container inspect": (0, container("b" * 64, running=False))}
        )
        self.assertEqual(result, {"created": False})
        self.assertEqual(docker.calls[-1], ["start", "orbit-process-7-app"])
        result, docker = self.run_phase(
            "start", {"container inspect": (0, container("d" * 64))}
        )
        self.assertEqual(result, {"created": True})
        self.assertIn(["rm", "--force", "orbit-process-7-app"], docker.calls)

    def test_start_restarts_a_running_valkey_so_it_reads_the_rewritten_acl_file(self):
        redis = {**INTENT, "driver": "redis", "initialize": False}
        result, docker = self.run_phase(
            "start", {"container inspect": (0, container("b" * 64))}, redis
        )
        self.assertEqual(result, {"created": False})
        self.assertEqual(
            docker.calls[-1], ["restart", "--time", "30", "orbit-process-7-app"]
        )
        _, docker = self.run_phase(
            "start", {"container inspect": (0, container("b" * 64))}
        )
        self.assertFalse(any(call[0] in ("restart", "start") for call in docker.calls))

    def test_healthy_reads_docker_health_events(self):
        starting = container("a" * 64).replace('"healthy"', '"starting"')
        for line, expected in (
            ("health_status: healthy\n", {"healthy": True}),
            ("die\n", {"error_code": "database.server_unhealthy"}),
        ):
            with patch.object(create.subprocess, "Popen") as events:
                events.return_value.stdout = iter([line])
                result, _ = self.run_phase(
                    "healthy", {"container inspect": (0, starting)}
                )
            self.assertEqual(result, expected)
            self.assertIn("event=health_status", events.call_args.args[0])

    def test_login_passes_the_client_password_in_an_env_file(self):
        result, docker = self.run_phase("login", {"exec": (0, "1\n")})
        self.assertEqual(result, {"login": True})
        self.assertEqual(docker.env_files, ["MYSQL_PWD=" + SECRET + "\n"])
        result, _ = self.run_phase("login", {"exec": (1, "")})
        self.assertEqual(result, {"error_code": "database.server_login_failed"})

    def test_scrub_recreates_without_the_first_start_env(self):
        answers = {
            "container inspect": (
                0,
                container("a" * 64, env=["MYSQL_ROOT_PASSWORD=x", "PATH=/bin"]),
            ),
            "exec": (0, "1\n"),
        }
        result, docker = self.run_phase("scrub", answers)
        self.assertEqual(result, {"login": True})
        created = next(call for call in docker.calls if call[0] == "create")
        self.assertNotIn("--env-file", created)
        self.assertIn("orbit.process.spec=" + "b" * 64, created)
        self.assertIn(["stop", "--time", "60", "orbit-process-7-app"], docker.calls)

    def test_scrub_of_a_clean_container_only_checks_the_login(self):
        result, docker = self.run_phase(
            "scrub",
            {
                "container inspect": (0, container("b" * 64, env=["PATH=/bin"])),
                "exec": (0, "1\n"),
            },
        )
        self.assertEqual(result, {"login": True})
        self.assertFalse(
            any(call[0] in ("create", "rm", "stop") for call in docker.calls)
        )

    def test_acl_file_goes_on_stdin_into_the_volume(self):
        docker = Docker({})
        with patch.object(
            create,
            "docker",
            side_effect=lambda *argv, stdin=None: (
                docker(*argv) if stdin == "user default\n" else None
            ),
        ) as call:
            self.assertEqual(
                create.acl(
                    {**INTENT, "driver": "redis", "volume": "orbit-cache-data"},
                    "user default\n",
                ),
                {"written": True},
            )
        self.assertIn(
            "type=volume,source=orbit-cache-data,target=/data", call.call_args.args
        )


if __name__ == "__main__":
    unittest.main()
