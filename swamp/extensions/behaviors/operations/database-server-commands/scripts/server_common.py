"""Private client execution for one server action. No retries or workflow control."""

import contextlib
import json
import subprocess
import sys
import tempfile

DOCKER = "/usr/bin/docker"
SOCKET = "--host=unix:///var/run/docker.sock"


class Refused(Exception):
    def __init__(self, code):
        self.code = code


def command(argv, stdin=None, *, timeout=None):
    return subprocess.run(
        argv, input=stdin, text=True, capture_output=True, check=False, timeout=timeout
    )


@contextlib.contextmanager
def credentials(intent, material):
    variable = {"mysql": "MYSQL_PWD", "pgsql": "PGPASSWORD", "redis": "REDISCLI_AUTH"}[
        intent["driver"]
    ]
    # Docker consumes the 0600 file; the value never enters docker exec argv.
    with tempfile.NamedTemporaryFile(
        mode="w", prefix="orbit-database-client-"
    ) as target:
        target.write(variable + "=" + material["password"] + "\n")
        target.flush()
        yield target.name


def client(intent, material, argv, stdin=None):
    with credentials(intent, material) as path:
        return command(
            [
                DOCKER,
                SOCKET,
                "exec",
                "-i",
                "--env-file",
                path,
                intent["container"],
                *argv,
            ],
            stdin,
        )


def valkey(intent, material, *args):
    reply = client(
        intent, material, ["valkey-cli", "--user", "default", "--raw", *args]
    )
    if (
        reply.returncode
        or reply.stdout.startswith(("NOAUTH", "WRONGPASS", "ERR ", "NOPERM"))
        or "AUTH failed" in reply.stderr
    ):
        raise Refused("database.server_adoption_refused")
    return reply.stdout.rstrip("\n")


def main(action):
    try:
        intent = json.loads(sys.argv[1])
        material = json.load(sys.stdin)
        result = action(intent, material)
    except Refused as error:
        print(json.dumps({"error_code": error.code}))
        raise SystemExit(1) from None
    except (KeyError, OSError, TypeError, ValueError):
        print('{"error_code":"database.server_create_outcome_unknown"}')
        raise SystemExit(1) from None
    print(json.dumps(result))
