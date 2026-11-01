"""One Plausible database initialization command; private environment arrives on stdin."""

import json
import re
import subprocess
import sys


def initialize(phase, definition, environment, run_id):
    if phase not in ("createdb", "migrate") or not re.fullmatch(
        r"[a-f0-9-]{36}", run_id
    ):
        raise ValueError("Invalid initialization")
    if not isinstance(environment, dict) or any(
        not re.fullmatch(r"[A-Za-z_][A-Za-z0-9_]*", key)
        or not isinstance(value, str)
        or any(c in value for c in "\n\r\0")
        for key, value in environment.items()
    ):
        raise ValueError("Invalid environment")
    argv = [
        "/usr/bin/docker",
        "--host=unix:///var/run/docker.sock",
        "run",
        "--rm",
        "--name",
        f"orbit-analytics-{run_id}-{phase}",
        "--env-file",
        "/dev/stdin",
    ]
    for mount in definition["mounts"]:
        argv.extend(
            [
                "--volume",
                mount["source"]
                + ":"
                + mount["target"]
                + (":ro" if mount["read_only"] else ""),
            ]
        )
    argv.extend([definition["image"], "/entrypoint.sh", "db", phase])
    result = subprocess.run(
        argv,
        input="".join(f"{key}={value}\n" for key, value in environment.items()),
        text=True,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        check=False,
    )
    if result.returncode:
        raise RuntimeError("Initialization failed")
    return {"ready": True}


if __name__ == "__main__":
    try:
        result = initialize(
            sys.argv[1], json.loads(sys.argv[2]), json.load(sys.stdin), sys.argv[3]
        )
    except (ValueError, TypeError, KeyError, IndexError, OSError, RuntimeError):
        print('{"error_code":"analytics.initialization_failed"}')
        raise SystemExit(1) from None
    print(json.dumps(result))
