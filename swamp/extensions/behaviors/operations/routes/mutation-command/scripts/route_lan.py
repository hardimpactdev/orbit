"""Converge or retire one destination-scoped Route UFW allowance."""

import fcntl
import ipaddress
import json
import os
import re
import subprocess


def ufw(*args):
    return subprocess.run(["/usr/sbin/ufw", *args], capture_output=True, text=True, check=True).stdout


def rules(destination, comment):
    status = ufw("status", "numbered")
    if not re.search(r"(?m)^Status: active$", status):
        raise ValueError("UFW must be active")
    rows = []
    for line in status.splitlines():
        rule, separator, tag = line.partition("#")
        if not separator or tag.strip() != comment:
            continue
        match = re.fullmatch(r"\s*\[\s*([1-9][0-9]*)\]\s+(\S+)\s+443/tcp\s+ALLOW IN\s+(\S+)\s*", rule)
        if match is None:
            raise ValueError("Malformed Route allowance")
        if match[2] == destination:
            rows.append((int(match[1]), match[3]))
    return rows


def apply(value):
    route = value["route_id"]
    if not isinstance(route, int) or isinstance(route, bool) or route < 1:
        raise ValueError("Invalid Route ID")
    destination = str(ipaddress.IPv4Address(value["destination"]))
    comment = f"orbit:route-{route}-lan"
    descriptor = os.open("/run/lock/orbit-firewall.lock", os.O_RDWR | os.O_CREAT, 0o600)
    try:
        fcntl.flock(descriptor, fcntl.LOCK_EX)
        observed = rules(destination, comment)
        if value["operation"] == "converge":
            router = str(ipaddress.IPv4Address(value["router"]))
            if not any(source == router for _, source in observed):
                ufw("allow", "in", "from", router, "to", destination, "port", "443", "proto", "tcp", "comment", comment)
            if not any(source == router for _, source in rules(destination, comment)):
                raise ValueError("Route allowance missing")
        elif value["operation"] == "retire":
            keep = {str(ipaddress.IPv4Address(source)) for source in value["keep"]}
            for number, source in sorted(observed, reverse=True):
                if source not in keep:
                    ufw("--force", "delete", str(number))
            if any(source not in keep for _, source in rules(destination, comment)):
                raise ValueError("Route allowance remains")
        else:
            raise ValueError("Unknown Route LAN action")
    finally:
        os.close(descriptor)
    return {"ready": True}


if __name__ == "__main__":
    try:
        print(json.dumps(apply(json.loads(os.environ["ORBIT_ROUTE_INPUT"]))))
    except (KeyError, ValueError, OSError, subprocess.SubprocessError):
        print('{"error_code":"firewall.withdrawal_failed"}')
        raise SystemExit(1) from None
