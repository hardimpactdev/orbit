"""Canonical Schedule artifact ownership and file operations used by single-action steps."""

import hashlib
import json
import os
import re
import stat
import subprocess
import tempfile
from pathlib import Path

ROOT_UID = 0
ROOT_GID = 0
CANDIDATES = Path("/etc/orbit/schedule-candidates")


class Refused(Exception):
    def __init__(self, code="schedule.install_failed"):
        self.code = code


def require(condition, code="schedule.artifact_conflict"):
    if not condition:
        raise Refused(code)


def intent():
    value = json.loads(os.environ["ORBIT_SCHEDULE_INPUT"])
    require(re.fullmatch(r"[a-f0-9-]{36}", value["schedule"]["id"]))
    return value


def paths(value):
    identity = value["schedule"]["id"]
    return {
        "service": Path("/etc/systemd/system") / f"orbit-schedule-{identity}.service",
        "timer": Path("/etc/systemd/system") / f"orbit-schedule-{identity}.timer",
        "script": Path("/etc/orbit/schedules") / f"{identity}.sh",
        "callback": Path("/etc/orbit/schedules") / f"{identity}.complete",
    }


def staging(value):
    run = os.environ["ORBIT_SCHEDULE_RUN"]
    require(re.fullmatch(r"[a-f0-9-]{36}", run))
    return CANDIDATES / value["schedule"]["id"] / run


def owned(path, mode, gid=None):
    try:
        info = path.lstat()
    except FileNotFoundError:
        return None
    require(
        stat.S_ISREG(info.st_mode)
        and info.st_uid == ROOT_UID
        and info.st_nlink == 1
        and stat.S_IMODE(info.st_mode) == mode
        and info.st_size <= 65536
        and (gid is None or info.st_gid == gid)
    )
    return path.read_text()


def directories():
    for path in [
        Path("/etc/orbit"),
        Path("/etc/orbit/schedules"),
        CANDIDATES,
        Path("/etc/systemd/system"),
    ]:
        try:
            info = path.lstat()
        except FileNotFoundError:
            continue
        require(
            stat.S_ISDIR(info.st_mode)
            and info.st_uid == ROOT_UID
            and info.st_gid == ROOT_GID
            and not info.st_mode & 0o022
        )


def manager(*args):
    return subprocess.run(
        ["/usr/bin/systemctl", *args], capture_output=True, text=True, check=False
    )


def timer_state(unit):
    values = []
    for action in ["is-enabled", "is-active"]:
        value = manager(action, unit).stdout.strip()
        require(re.fullmatch("[a-z-]+", value), "schedule.target_unavailable")
        values.append(value)
    return values[0] == "enabled", values[1] == "active"


def atomic(path, content, uid=ROOT_UID, gid=ROOT_GID, mode=0o644):
    handle, temporary = tempfile.mkstemp(prefix=".orbit-schedule-", dir=path.parent)
    try:
        with os.fdopen(handle, "w") as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.chown(temporary, uid, gid)
        os.chmod(temporary, mode)
        os.replace(temporary, path)
    finally:
        Path(temporary).unlink(missing_ok=True)


def snapshot(value):
    return json.loads((staging(value) / "snapshot.json").read_text())


def report(action):
    try:
        result = action()
        print(json.dumps(result or {"ready": True}))
    except (Refused, OSError, ValueError, KeyError) as error:
        print(
            json.dumps(
                {
                    "error_code": error.code
                    if isinstance(error, Refused)
                    else "schedule.install_failed"
                }
            )
        )
        raise SystemExit(1)


def command_matches(script, prefix, digest):
    if not script.startswith(prefix) or not script.endswith("\n"):
        return False
    command = script[len(prefix) : -1]
    return (
        bool(command)
        and not any(c in command for c in "\0\r\n")
        and hashlib.sha256(command.encode()).hexdigest() == digest
    )
