#!/usr/bin/python3
"""Existing worker-unit pre-start memory and credential-file checks."""
import os
import stat
import sys

MINIMUM_MEMORY_BYTES = 0


def check():
    with open("/proc/meminfo", encoding="ascii") as stream:
        rows = [line for line in stream if line.startswith("MemAvailable:")]
    fields = rows[0].split() if len(rows) == 1 else []
    if (len(fields) != 3 or fields[2] != "kB" or not fields[1].isdigit()
            or int(fields[1]) * 1024 < MINIMUM_MEMORY_BYTES):
        raise ValueError()
    for path, mode, directory in [
        ("/etc/orbit/swamp-worker", 0o700, True),
        ("/etc/orbit/swamp-worker/enrollment.token", 0o600, False),
        ("/etc/orbit/swamp-worker/server.token", 0o600, False),
    ]:
        value = os.lstat(path)
        right_kind = stat.S_ISDIR(value.st_mode) if directory else stat.S_ISREG(value.st_mode)
        if value.st_uid != 0 or stat.S_IMODE(value.st_mode) != mode or not right_kind:
            raise ValueError()
    if os.geteuid() != 0:
        raise ValueError()


if __name__ == "__main__":
    try:
        check()
    except (OSError, ValueError, KeyError):
        print("Node worker credential custody or available RAM check failed", file=sys.stderr)
        sys.exit(1)
