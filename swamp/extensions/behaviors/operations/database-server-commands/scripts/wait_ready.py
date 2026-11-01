"""Wait for Docker's health event once; Docker owns probing, not a family polling loop."""

import subprocess

from server_common import DOCKER, SOCKET, Refused, command, main

REAP_SECONDS = 1


def wait(intent, _material):
    try:
        observed = command(
            [
                DOCKER,
                SOCKET,
                "inspect",
                "--format",
                "{{.Id}} {{.State.StartedAt}} {{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}",
                intent["container"],
            ],
        )
    except subprocess.TimeoutExpired:
        raise Refused("database.server_not_ready") from None
    if observed.returncode:
        raise Refused("database.server_not_ready")
    try:
        container_id, started_at, health = observed.stdout.strip().split()
    except ValueError:
        raise Refused("database.server_not_ready") from None
    if health in ("healthy", "none"):
        return {"ready": True}
    if health == "unhealthy":
        raise Refused("database.server_not_ready")
    events = subprocess.Popen(
        [
            DOCKER,
            SOCKET,
            "events",
            "--since",
            started_at,
            "--filter",
            "container=" + container_id,
            "--filter",
            "event=health_status: healthy",
            "--filter",
            "event=health_status: unhealthy",
            "--filter",
            "event=die",
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
