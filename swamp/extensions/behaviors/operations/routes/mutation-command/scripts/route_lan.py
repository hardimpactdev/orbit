"""One-time removal of destination/comment Route rules after official tuple convergence."""

import fcntl
import json
import os
import re
import subprocess


def ufw(*args):
    return subprocess.run(
        ["/usr/sbin/ufw", *args], capture_output=True, text=True, check=True
    ).stdout


def rules(legacy_router=False):
    status = ufw("status", "numbered")
    if not re.search(r"(?m)^Status: active$", status):
        raise ValueError("UFW must be active")
    rows = []
    for line in status.splitlines():
        rule, separator, tag = line.partition("#")
        route = re.fullmatch(r"orbit:route-[1-9][0-9]*-lan", tag.strip())
        router = (
            re.fullmatch(
                r"orbit:router-lan-(https|dns-tcp|dns-udp)(?::[1-9][0-9]*)?",
                tag.strip(),
            )
            if legacy_router
            else None
        )
        if not separator or not (route or router):
            continue
        port = (
            {"https": "443/tcp", "dns-tcp": "53/tcp", "dns-udp": "53/udp"}[router[1]]
            if router
            else "443/tcp"
        )
        match = re.fullmatch(
            r"\s*\[\s*([1-9][0-9]*)\]\s+\S+\s+"
            + re.escape(port)
            + r"\s+ALLOW IN\s+\S+\s*",
            rule,
        )
        if match is None:
            raise ValueError("Malformed old Route allowance")
        rows.append(int(match[1]))
    return rows


def apply():
    legacy_router = (
        json.loads(os.environ.get("ORBIT_ROUTE_INPUT", "{}")).get("legacy_router")
        is True
    )
    descriptor = os.open("/run/lock/orbit-firewall.lock", os.O_RDWR | os.O_CREAT, 0o600)
    try:
        fcntl.flock(descriptor, fcntl.LOCK_EX)
        for number in sorted(rules(legacy_router), reverse=True):
            ufw("--force", "delete", str(number))
        if rules(legacy_router):
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
