"""Wait for Docker's health event once; Docker owns probing, not a family polling loop."""

import subprocess
import time

from server_common import DOCKER, SOCKET, Refused, command, main

READINESS_SECONDS = 120
REAP_SECONDS = 1


def wait(intent, _material):
    deadline = time.monotonic() + READINESS_SECONDS
    since = str(int(time.time()) - 1)
    try:
        observed = command(
            [
                DOCKER,
                SOCKET,
                "inspect",
                "--format",
                "{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}",
                intent["container"],
            ],
            timeout=READINESS_SECONDS,
        )
    except subprocess.TimeoutExpired:
        raise Refused("database.server_not_ready") from None
    if observed.returncode:
        raise Refused("database.server_not_ready")
    if observed.stdout.strip() in ("healthy", "none"):
        return {"ready": True}
    remaining = deadline - time.monotonic()
    if remaining <= 0:
        raise Refused("database.server_not_ready")
    events = subprocess.Popen(
        [
            DOCKER,
            SOCKET,
            "events",
            "--since",
            since,
            "--filter",
            "container=" + intent["container"],
            "--filter",
            "event=health_status: healthy",
            "--format",
            "{{.Action}}",
        ],
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
    )
    try:
        reply = subprocess.run(
            ["/usr/bin/head", "-n", "1"],
            stdin=events.stdout,
            text=True,
            capture_output=True,
            timeout=remaining,
            check=False,
        )
        if reply.returncode or reply.stdout.strip() != "health_status: healthy":
            raise Refused("database.server_not_ready")
    except subprocess.TimeoutExpired:
        raise Refused("database.server_not_ready") from None
    finally:
        if events.stdout is not None:
            events.stdout.close()
        events.terminate()
        try:
            events.wait(timeout=REAP_SECONDS)
        except subprocess.TimeoutExpired:
            events.kill()
            try:
                events.wait(timeout=REAP_SECONDS)
            except subprocess.TimeoutExpired:
                raise Refused("database.server_not_ready") from None
    return {"ready": True}


if __name__ == "__main__":
    main(wait)
