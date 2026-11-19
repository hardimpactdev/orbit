#!/usr/bin/env python3
"""Provision the release project and its root profile without selecting a physical disk."""
import ipaddress
import json
import os
import re
import subprocess
import sys
from pathlib import Path

PROJECT = "orbit-releases"
OWNER = "user.orbit.production-releases"


def command(*args):
    return subprocess.run(args, text=True, capture_output=True, check=True, timeout=180).stdout.strip()


def incus(*args):
    return command("incus", "--force-local", *args)


def provision(nested, cidr):
    changed = False
    pools = {row["name"]: row for row in json.loads(incus("storage", "list", "--format", "json"))}
    if PROJECT not in pools:
        if nested:
            raise ValueError("The fleet Node step creates the nested orbit-releases btrfs pool first")
        else:
            source = pools.get("orbit-instances", {})
            dataset = source.get("config", {}).get("source", "")
            if source.get("driver") != "zfs" or not re.fullmatch(r"[a-zA-Z0-9][a-zA-Z0-9_./:-]*", dataset):
                raise ValueError("Provision the orbit-releases ZFS Incus pool on the approved storage device")
            # This child belongs to releases. Never let Incus initialize an unrelated existing dataset.
            child = dataset + "/production-releases"
            if child not in command("zfs", "list", "-Hp", "-o", "name").splitlines():
                command("zfs", "create", "-o", "mountpoint=none", "-o", "org.orbit:purpose=production-releases", child)
            if command("zfs", "get", "-H", "-o", "value,source", "org.orbit:purpose", child).split() != ["production-releases", "local"]:
                raise ValueError("The selected ZFS dataset is not owned by production releases")
            incus("storage", "create", PROJECT, "zfs", "source=" + child, OWNER + "=true")
        changed = True
    pool = json.loads(incus("query", "/1.0/storage-pools/" + PROJECT))
    # A nested Node keeps releases on btrfs (instance-fleet-node.yml), a production Node on ZFS; no layer uses a dir pool.
    driver = "btrfs" if nested else "zfs"
    if pool["driver"] != driver:
        raise ValueError(f"Production releases require a {driver} pool; move the {pool['driver']} pool orbit-releases to {driver}")
    projects = {r["name"]: r for r in json.loads(incus("project", "list", "--format", "json"))}
    if PROJECT not in projects:
        incus("project", "create", PROJECT, "-c", OWNER + "=true", "-c", "features.networks=false",
              "-c", "features.images=true", "-c", "features.profiles=true", "-c", "features.storage.volumes=true")
        changed = True
    config = json.loads(incus("query","/1.0/projects/" + PROJECT))["config"]
    if (config.get(OWNER) != "true" and config.get("user.orbit.purpose") != "gateway-releases") or any(
            config.get(k, default) != default for k, default in {"features.networks":"false","features.images":"true",
            "features.profiles":"true","features.storage.volumes":"true"}.items()):
        raise ValueError("Refusing an incompatible release project")
    profile = json.loads(incus("query","/1.0/profiles/default?project=" + PROJECT))
    root = {"type":"disk","path":"/","pool":PROJECT}
    if not profile["devices"]:
        incus("--project", PROJECT, "profile", "device", "add", "default", "root", "disk", "path=/", "pool=" + PROJECT)
        changed = True
    elif profile["devices"].get("root") != root:
        raise ValueError("Unexpected release project root device")
    subnet = ipaddress.IPv4Network(cidr)
    if subnet.prefixlen != 24 or not subnet.is_private:
        raise ValueError("Invalid assigned Instance bridge range")
    network = "orbitinst0"
    address = f"{subnet.network_address + 1}/24"
    networks = json.loads(incus("network", "list", "--format", "json"))
    if not any(row["name"] == network for row in networks):
        incus("network", "create", network, "ipv4.address=" + address, "ipv6.address=none", "ipv4.nat=false",
              "ipv4.dhcp=true", "dns.mode=none", OWNER + "=true")
        changed = True
    existing = json.loads(incus("query", "/1.0/networks/" + network))
    if (existing["type"] != "bridge" or not existing["managed"] or existing["config"].get("ipv4.address") != address):
        raise ValueError("Assigned production bridge overlaps an existing network")
    daemon = json.loads(incus("query", "/1.0"))["environment"]["server_pid"]
    env = dict(entry.split(b"=", 1) for entry in Path(f"/proc/{int(daemon)}/environ").read_bytes().split(b"\0") if b"=" in entry)
    directory = Path(os.fsdecode(env.get(b"INCUS_DIR", b"/var/lib/incus")))
    if not directory.is_absolute() or not (directory / "unix.socket").is_socket():
        raise ValueError("Cannot identify the release image daemon")
    return {"changed":changed, "directory":str(directory)}


if __name__ == "__main__":
    if len(sys.argv) != 3 or sys.argv[1] not in ("nested", "host"):
        raise SystemExit(64)
    print(json.dumps(provision(sys.argv[1] == "nested", sys.argv[2])))
