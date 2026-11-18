#!/usr/bin/env python3
"""Fence queued host Schedule fires while a legacy project moves into a release."""
import hashlib
import os
import pwd
import re
import runpy
import subprocess
import sys
import uuid
from pathlib import Path


def main(user, directory, shell, login, limit, schedule, digest):
    account = re.fullmatch(r"orbit-app-([1-9][0-9]*)", user)
    if not account or not 0 < int(limit) <= 86400 or str(uuid.UUID(schedule)) != schedule:
        raise ValueError("invalid Schedule")
    owner = pwd.getpwnam(user)
    home = Path("/home") / user
    if owner.pw_dir != str(home) or not Path(directory).resolve().is_relative_to(home) or shell not in ("", "/bin/sh", "/bin/bash"):
        raise ValueError("invalid Schedule account")
    data = sys.stdin.buffer.read()
    if hashlib.sha256(data).hexdigest() != digest:
        raise ValueError("invalid Schedule command")
    api = runpy.run_path("/usr/local/libexec/orbit-instance-release-phase")
    with api["legacy_schedule_lock"](account[1], shared=True) as adopted:
        if adopted.exists():
            raise ValueError("Schedule moved to a production release")
        return subprocess.run(["systemd-run", "--quiet", "--wait", "--pipe", "--collect", "--uid=" + user,
                               "-p", "WorkingDirectory=" + directory, "-p", "RuntimeMaxSec=" + limit, "-p", "TimeoutStopSec=5",
                               "--", "systemd-cat", "--identifier=orbit-schedule-" + schedule, shell or owner.pw_shell,
                               *(["--login"] if login == "1" else []), "-s"], input=data, timeout=int(limit) + 10, check=False).returncode


if __name__ == "__main__":
    try:
        if os.geteuid():
            raise ValueError("run as root")
        sys.exit(main(*sys.argv[1:]))
    except (KeyError, ValueError, OSError, subprocess.SubprocessError):
        sys.exit("schedule.target_unavailable")
