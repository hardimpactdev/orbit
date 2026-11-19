#!/usr/bin/env python3
"""Keep one pool of an Instance fleet Node on btrfs (docs/reference/development-containers.md#instance-host-in-the-fleet).

Run on the Node: `fleet-node-pool.py <pool>`; prints `{"changed": bool}`. A missing pool is created as btrfs on the Node's root. A `dir`
pool, such as the release pool of an older nested app-prod Node, moves to btrfs with nothing lost: every instance and custom volume
moves to the btrfs pool `<pool>-move`, the profiles point there, the dir pool goes and comes back as btrfs, and everything moves back.
Each instance keeps its rootfs, snapshots and config and returns to the state it had (running, frozen or stopped); a btrfs root device
carries no `size` (instance_root_disk.py). Each step reads what is left, so a converge stopped part way finishes on the next run. Any
other driver refuses with instance.host_unavailable.
"""
import json
import subprocess
import sys
import urllib.parse

MARK = "user.orbit.pool-move"  # the state an instance had before the move stopped it


def incus(*args, timeout=600):
    return subprocess.run(["incus", *args], capture_output=True, text=True, check=True, timeout=timeout, stdin=subprocess.DEVNULL).stdout


def query(path):
    return json.loads(incus("query", "--", path))


def pools():
    return {row["name"]: row for row in query("/1.0/storage-pools?recursion=1")}


def create(name):
    """The first create right after btrfs-progs arrives once hung with no operation (task copy 3728, 2026-11-19): the create is killed
    after 120 s, and the converge task runs this script once more, which goes on from what it finds."""
    incus("storage", "create", name, "btrfs", "source=/var/lib/incus/storage-pools/" + name, timeout=120)


def target(entry):
    """(kind, name, project) of a used_by URL."""
    url = urllib.parse.urlparse(entry)
    project = urllib.parse.parse_qs(url.query).get("project", ["default"])[0]
    parts = url.path.split("/")
    return parts[2], urllib.parse.unquote(parts[-1]), project, parts


def halt(name, project):
    """Stop an instance for the move and record the state it had, once."""
    record = query(f"/1.0/instances/{urllib.parse.quote(name)}?project={project}")
    if MARK not in record["config"]:
        incus("config", "set", name, "--project", project, f"{MARK}={record['status'].lower()}")
    if record["status"] != "Stopped":
        incus("stop", name, "--project", project, "--force")
    return record


def unsized(name, project, record):
    """Drop the root `size`: Incus applies it on btrfs with qgroups, which an unprivileged nested Node refuses."""
    if "size" not in record["expanded_devices"].get("root", {}):
        return
    if "root" not in record["devices"]:
        incus("config", "device", "override", name, "root", "--project", project)
    incus("config", "device", "unset", name, "root", "size", "--project", project)


def empty(source, destination):
    """Move every instance and custom volume of `source` to `destination`, point its profiles there and drop its image caches."""
    used = pools()[source]["used_by"] or []
    rows = [target(entry) for entry in used]
    for kind, name, project, _ in rows:
        if kind == "instances":
            halt(name, project)
    for kind, name, project, parts in rows:
        if kind == "storage-pools" and parts[4:6] == ["volumes", "custom"]:
            incus("storage", "volume", "move", f"{source}/{name}", f"{destination}/{name}", "--project", project)
        elif kind == "storage-pools" and parts[4:6] == ["volumes", "image"]:
            incus("storage", "volume", "delete", source, "image/" + name, "--project", project)
        elif kind == "instances":
            unsized(name, project, query(f"/1.0/instances/{urllib.parse.quote(name)}?project={project}"))
            incus("move", name, "--project", project, "--storage", destination)
        elif kind == "profiles":
            profile = query(f"/1.0/profiles/{urllib.parse.quote(name)}?project={project}")
            for device, config in profile["devices"].items():
                if config.get("pool") == source:
                    incus("profile", "device", "set", name, device, "pool=" + destination, "--project", project)


def resume():
    """Return each moved instance to the state it had before the move."""
    for record in query("/1.0/instances?recursion=1&all-projects=true"):
        state = record["config"].get(MARK)
        if state is None:
            continue
        name, project = record["name"], record["project"]
        if state in ("running", "frozen") and record["status"] == "Stopped":
            incus("start", name, "--project", project)
        if state == "frozen":
            incus("pause", name, "--project", project)
        incus("config", "unset", name, MARK, "--project", project)


def converge(pool):
    spare = pool + "-move"
    found = pools()
    driver = found.get(pool, {}).get("driver", "btrfs")
    if driver not in ("btrfs", "dir"):
        raise SystemExit(f"instance.host_unavailable: the Incus pool {pool} uses the {driver} driver; a fleet Node pool must be btrfs")
    changed = False
    if driver == "dir":
        if spare not in found:
            create(spare)
        empty(pool, spare)
        incus("storage", "delete", pool)
        changed = True
    if pool not in pools():
        create(pool)
        changed = True
    if spare in pools():
        empty(spare, pool)
        incus("storage", "delete", spare)
        changed = True
    if any(MARK in record["config"] for record in query("/1.0/instances?recursion=1&all-projects=true")):
        resume()
        changed = True
    return {"changed": changed}


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit(64)
    print(json.dumps(converge(sys.argv[1])))
