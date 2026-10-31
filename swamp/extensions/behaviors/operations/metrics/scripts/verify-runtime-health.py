import json
import os
import subprocess

v = json.loads(os.environ["ORBIT_CONTAINER_INTENT"])
actual = subprocess.run(
    ["/usr/bin/docker", "container", "inspect", "--", v["name"]],
    capture_output=True,
    text=True,
    check=True,
)
state = json.loads(actual.stdout)[0]["State"]
if not state["Running"]:
    raise SystemExit("metrics.runtime_not_healthy")
status = state.get("Health", {}).get("Status")
if status != "healthy":
    if status != "starting":
        raise SystemExit("metrics.runtime_not_healthy")
    # Since StartedAt closes the inspect/subscribe race by replaying a just-emitted event.
    events = subprocess.Popen(
        [
            "/usr/bin/docker",
            "events",
            "--since",
            state["StartedAt"],
            "--filter",
            "container=" + v["name"],
            "--filter",
            "event=health_status: healthy",
            "--format",
            "{{json .}}",
        ],
        stdout=subprocess.PIPE,
        text=True,
    )
    try:
        event = json.loads(events.stdout.readline())
        if (
            event.get("Action") != "health_status: healthy"
            or event.get("Actor", {}).get("Attributes", {}).get("name") != v["name"]
        ):
            raise ValueError()
    except Exception:
        raise SystemExit("metrics.runtime_not_healthy") from None
    finally:
        events.terminate()
        events.wait()
print('{"healthy":true}')
