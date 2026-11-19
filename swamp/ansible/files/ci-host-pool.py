#!/usr/bin/env python3
"""The CI role's storage, by the same rule as the agent's `ci-runner.host` (apps/agent/src/commands/ci_runner.rs): the marked CI
pool on ZFS or btrfs beside the Node's Instance pool, and the pins that keep an older runner on its pool when the profile root moves.
It never creates a `dir` pool and never changes the host's `default` pool.

  ci-host-pool.py pool|check|pin PROJECT POOL SOURCE_POOL OWNER_KEY

`pool` creates a missing pool and refuses a wrong one, `check` only refuses, `pin` pins each runner whose root comes from the profile
and lies on another pool. It prints {"changed": bool}."""
import json
import re
import subprocess
import sys


def incus(*args):
    return subprocess.run(["incus", *args], text=True, capture_output=True, check=True, timeout=120).stdout


def query(path):
    return json.loads(incus("query", path))


def owned(pool, name, owner):
    driver = pool.get("driver") or ""
    if driver not in ("zfs", "btrfs"):
        raise ValueError(f"ci_runner.host_failed: the {name} pool is {driver}; CI runners need a zfs or btrfs pool")
    if (pool.get("config") or {}).get(owner) != "true":
        raise ValueError(f"ci_runner.host_failed: the {name} pool is not owned by the CI role")


def location(name, source_name, names):
    unavailable = ValueError(f"ci_runner.host_failed: the CI pool needs the Node's {source_name} pool on zfs or btrfs")
    if source_name not in names:
        raise unavailable
    source = query(f"/1.0/storage-pools/{source_name}")
    if source.get("driver") == "zfs":
        zpool = ((source.get("config") or {}).get("source") or "").split("/")[0]
        if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.:-]*", zpool):
            raise unavailable
        return ["zfs", f"source={zpool}/{name}"]
    if source.get("driver") == "btrfs":
        return ["btrfs", f"source=/var/lib/incus/storage-pools/{name}"]
    raise unavailable


def pool(check, name, source_name, owner):
    names = [path.split("?")[0].rsplit("/", 1)[-1] for path in query("/1.0/storage-pools")]
    changed = False
    if name not in names:
        if check:
            raise ValueError(f"ci_runner.host_failed: the {name} pool is missing")
        # Incus creates the missing dataset and refuses one that already holds data.
        incus("--project", "default", "storage", "create", name, *location(name, source_name, names), f"{owner}=true")
        changed = True
    owned(query(f"/1.0/storage-pools/{name}"), name, owner)
    return changed


def pin(project, name):
    changed = False
    for path in query(f"/1.0/instances?project={project}"):
        runner = path.split("?")[0].removeprefix("/1.0/instances/")
        instance = query(f"/1.0/instances/{runner}?project={project}")
        current = ((instance.get("expanded_devices") or {}).get("root") or {}).get("pool") or ""
        if "root" not in (instance.get("devices") or {}) and current and current != name:
            incus("config", "device", "add", runner, "root", "disk", "path=/", f"pool={current}", "--project", project)
            changed = True
    return changed


def main(mode, project, name, source_name, owner):
    if mode == "pin":
        return pin(project, name)
    return pool(mode == "check", name, source_name, owner)


if __name__ == "__main__":
    if len(sys.argv) != 6 or sys.argv[1] not in ("pool", "check", "pin"):
        raise SystemExit(64)
    print(json.dumps({"changed": main(*sys.argv[1:])}))
