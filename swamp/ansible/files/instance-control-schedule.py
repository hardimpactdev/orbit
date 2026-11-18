#!/usr/bin/env python3
"""Run and manage only the admitted Schedule on the verified AppProd release."""
import hashlib
import json
import os
import re
import subprocess
import sys
import tempfile
import uuid
from pathlib import Path

BASE = Path("/var/lib/orbit/production")
WORK = Path("/srv/workspace/current")


def fire(incus, identifier, digest, limit, cwd):
    script = sys.stdin.buffer.read()
    if hashlib.sha256(script).hexdigest() != digest or not 0 < int(limit) <= 86400:
        raise ValueError("invalid Schedule content or runtime")
    directory = Path(cwd)
    if not directory.is_relative_to(WORK) or ".." in directory.parts:
        raise ValueError("invalid Schedule directory")
    result = subprocess.run(["systemd-cat", "--identifier=orbit-schedule-" + identifier, *incus,
                             "systemd-run", "--quiet", "--wait", "--pipe", "--collect", "--uid=orbit",
                             "-p", "WorkingDirectory=" + str(directory), "-p", "RuntimeMaxSec=" + limit,
                             "-p", "TimeoutStopSec=5", "--", "/bin/bash", "--login", "-s"],
                            input=script, timeout=int(limit) + 10, check=False)
    return result.returncode


def publish(path, record):
    with tempfile.NamedTemporaryFile(mode="w", dir=path.parent, delete=False) as file:
        staged = Path(file.name)
        try:
            json.dump(record, file)
            file.flush()
            os.fsync(file.fileno())
            os.replace(staged, path)
        finally:
            staged.unlink(missing_ok=True)


def main():
    instance, sha, machine, action, identifier, *args = sys.argv[1:]
    if not re.fullmatch("[1-9][0-9]*", instance) or not re.fullmatch("[0-9a-f]{40}", sha) or machine != f"instance-{instance}-{sha[:12]}":
        raise ValueError("invalid release")
    if str(uuid.UUID(identifier)) != identifier:
        raise ValueError("invalid Schedule")
    incus = ["incus", "--force-local", "--project", "orbit-releases", "exec", machine, "--"]
    if action == "fire":
        return fire(incus, identifier, *args)
    if args or action not in {"create", "enable", "destroy"}:
        raise ValueError("invalid Schedule action")
    plan = json.load(sys.stdin)
    if plan["instance_id"] != int(instance) or plan["release"] != sha or plan["id"] != identifier or plan["action"] != action:
        raise ValueError("wrong Schedule owner")
    path = BASE / instance / ".release" / (machine + ".json")
    record = json.loads(path.read_text())
    timer = "orbit-schedule-" + identifier + ".timer"
    service = "orbit-schedule-" + identifier + ".service"
    script = "/etc/orbit/schedules/" + identifier + ".sh"
    names = [timer, service]
    def guest(*command, content=None):
        subprocess.run([*incus, *command], check=True, timeout=120, input=content, text=True,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    if action == "destroy":
        guest("systemctl", "disable", "--now", timer)
        # A command already running can finish; removing the unit prevents future runs.
        guest("rm", "-f", "--", "/etc/systemd/system/" + timer, "/etc/systemd/system/" + service, script)
        units = []
    elif action == "enable":
        if timer not in [unit["name"] for unit in record["units"]]:
            raise ValueError("unknown Schedule on this release")
        guest("systemctl", "enable", "--now", timer)
        units = [{"name": service, "desired": "stopped"}, {"name": timer, "desired": "running"}]
    else:
        material = plan["units"]
        if sorted(unit["name"] for unit in material) != sorted(names):
            raise ValueError("wrong Schedule definitions")
        guest("install", "-d", "-m", "0755", "/etc/orbit/schedules")
        for unit in material:
            if unit["name"] == service:
                if unit["script"]["path"] != script:
                    raise ValueError("wrong Schedule script")
                guest("install", "-m", "0400", "-o", "orbit", "/dev/null", script)
                guest("tee", script, content=unit["script"]["content"])
            guest("tee", "/etc/systemd/system/" + unit["name"], content=unit["content"])
        units = [{"name": unit["name"], "desired": unit["desired"]} for unit in material]
    guest("systemctl", "daemon-reload")
    if action == "create":
        enabled = any(unit["name"] == timer and unit["desired"] == "running" for unit in units)
        guest("systemctl", "enable" if enabled else "disable", "--now", timer)
    record["units"] = [unit for unit in record["units"] if unit["name"] not in names] + units
    publish(path, record)
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (KeyError, OSError, ValueError, subprocess.SubprocessError):
        sys.exit("schedule.control_failed")
