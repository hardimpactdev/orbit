"""One-time removal of destination/comment Route rules after official tuple convergence."""

import fcntl
import json
import os
import re
import subprocess


def ufw(*args):
    return subprocess.run(["/usr/sbin/ufw", *args], capture_output=True, text=True, check=True).stdout


def rules():
    status = ufw("status", "numbered")
    if not re.search(r"(?m)^Status: active$", status):
        raise ValueError("UFW must be active")
    rows = []
    for line in status.splitlines():
        rule, separator, tag = line.partition("#")
        if not separator or not re.fullmatch(r"orbit:route-[1-9][0-9]*-lan", tag.strip()):
            continue
        match = re.fullmatch(r"\s*\[\s*([1-9][0-9]*)\]\s+\S+\s+443/tcp\s+ALLOW IN\s+\S+\s*", rule)
        if match is None:
            raise ValueError("Malformed old Route allowance")
        rows.append(int(match[1]))
    return rows


def apply():
    descriptor = os.open("/run/lock/orbit-firewall.lock", os.O_RDWR | os.O_CREAT, 0o600)
    try:
        fcntl.flock(descriptor, fcntl.LOCK_EX)
        for number in sorted(rules(), reverse=True):
            ufw("--force", "delete", str(number))
        if rules():
            raise ValueError("Old Route allowance remains")
    finally:
        os.close(descriptor)
    return {"ready": True}


if __name__ == "__main__":
    try:
        print(json.dumps(apply()))
    except (ValueError, OSError, subprocess.SubprocessError):
        print('{"error_code":"firewall.migration_failed"}')
        raise SystemExit(1) from None
