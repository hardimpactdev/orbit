"""Owned Process observations and fixed local command transport. No action dispatcher."""

import errno
import json
import os
import re
import stat
import subprocess
from pathlib import Path

UNITS = Path("/etc/systemd/system")
ROOT_UID = 0
ROOT_GID = 0
SYSTEMCTL = "/usr/bin/systemctl"
DOCKER = "/usr/bin/docker"
SOCKET = "--host=unix:///var/run/docker.sock"
SLOTS = ("canonical", "candidate", "rollback-running", "rollback-stopped", "failed")
STATES = {
    "active",
    "reloading",
    "inactive",
    "failed",
    "activating",
    "deactivating",
    "maintenance",
}
INSPECT = '{{ index .Config.Labels "orbit.managed" }}{{ printf "\\n" }}{{ index .Config.Labels "orbit.container.kind" }}{{ printf "\\n" }}{{ index .Config.Labels "orbit.process.id" }}{{ printf "\\n" }}{{ index .Config.Labels "orbit.process.spec" }}{{ printf "\\n" }}{{.State.Status}}'


class Refused(Exception):
    def __init__(self, code):
        self.code = code


def command(argv):
    # Control steps do not forward parent material or Docker endpoint overrides.
    env = {
        key: value
        for key, value in os.environ.items()
        if not key.startswith(("ORBIT_", "DOCKER_"))
    }
    return subprocess.run(argv, env=env, text=True, capture_output=True, check=False)


def control_input():
    value = json.loads(os.environ["ORBIT_PROCESS_CONTROL"])
    process_id = value.get("process_id")
    runtime = value.get("runtime")
    if (
        type(process_id) is not int
        or process_id < 1
        or runtime not in ("systemd", "docker")
    ):
        raise Refused("process.invalid")
    suffix = r"\.service" if runtime == "systemd" else ""
    if not isinstance(value.get("name"), str) or not re.fullmatch(
        rf"orbit-process-{process_id}-[a-z0-9](?:[a-z0-9-]*[a-z0-9])?{suffix}",
        value["name"],
    ):
        raise Refused("process.invalid")
    if type(value.get("on_demand")) is not bool:
        raise Refused("process.invalid")
    slot = value.get("slot", "canonical")
    if slot not in SLOTS or runtime == "systemd" and slot != "canonical":
        raise Refused("process.invalid")
    expected = value.get("expected_spec")
    if expected is not None and (
        runtime != "docker"
        or not isinstance(expected, str)
        or not re.fullmatch("[a-f0-9]{64}", expected)
    ):
        raise Refused("process.invalid")
    return value


def artifact_name(value):
    slot = value.get("slot", "canonical")
    return value["name"] + ("" if slot == "canonical" else "-" + slot)


def observe(value):
    """Return bounded public state only; unit content and container configuration stay local."""
    name = artifact_name(value)
    if value["runtime"] == "systemd":
        try:
            fd = os.open(UNITS / name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        except FileNotFoundError:
            return {"present": False, "state": "absent"}
        except OSError as error:
            if error.errno == errno.ELOOP:
                raise Refused("process.name_collision") from None
            raise
        with os.fdopen(fd, "rb") as source:
            info = os.fstat(source.fileno())
            if (
                not stat.S_ISREG(info.st_mode)
                or info.st_uid != ROOT_UID
                or info.st_gid != ROOT_GID
                or info.st_nlink != 1
                or info.st_mode & 0o022
            ):
                raise Refused("process.name_collision")
            marker = f"X-Orbit-Process-ID={value['process_id']}".encode()
            marked = any(line.rstrip(b"\r\n") == marker for line in source)
        if not marked:
            raise Refused("process.name_collision")
        state = command([SYSTEMCTL, "is-active", name]).stdout.strip()
        if state not in STATES:
            raise Refused("process.status_failed")
        return {"present": True, "state": state}
    listed = command(
        [
            DOCKER,
            SOCKET,
            "container",
            "ls",
            "--all",
            "--filter",
            f"name=^/{name}$",
            "--format",
            "{{.Names}}",
        ]
    )
    if listed.returncode:
        raise Refused("process.status_failed")
    if not listed.stdout.strip():
        return {"present": False, "state": "absent"}
    if listed.stdout.strip() != name:
        raise Refused("process.name_collision")
    inspected = command(
        [DOCKER, SOCKET, "container", "inspect", "--format", INSPECT, name]
    )
    parts = inspected.stdout.strip().splitlines()
    if inspected.returncode or len(parts) != 5:
        raise Refused("process.status_failed")
    managed, kind, owner, spec, state = parts
    if (managed, kind, owner) != ("true", "process", str(value["process_id"])):
        raise Refused("process.name_collision")
    if not re.fullmatch("[a-f0-9]{64}", spec) or not re.fullmatch(
        "[a-z][a-z_-]{0,63}", state
    ):
        raise Refused("process.status_failed")
    if value.get("expected_spec") is not None and value["expected_spec"] != spec:
        raise Refused("process.spec_changed")
    return {"present": True, "state": state, "spec": spec}


def owned(value):
    state = observe(value)
    if not state["present"]:
        raise Refused("process.not_found")
    return state


def main(action):
    try:
        result = action(control_input())
    except Refused as error:
        print(json.dumps({"error_code": error.code}))
        raise SystemExit(1) from None
    except Exception:
        print('{"error_code":"process.command_failed"}')
        raise SystemExit(1) from None
    print(json.dumps(result))
