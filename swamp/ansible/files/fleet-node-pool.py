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

MARK = "user.orbit.pool-move"  # `<pool>:<state>` on an instance this move stopped; `<pool>` on the btrfs pool it made


def incus(*args, timeout=600):
    return subprocess.run(["incus", *args], capture_output=True, text=True, check=True, timeout=timeout, stdin=subprocess.DEVNULL).stdout


def query(path):
    return json.loads(incus("query", "--", path))


def pools():
    return {row["name"]: row for row in query("/1.0/storage-pools?recursion=1")}


def create(name, *config):
    """The first create right after btrfs-progs arrives once hung with no operation (task copy 3728, 2026-11-19): the create is killed
    after 120 s, and the converge task runs this script once more, which goes on from what it finds."""
    incus("storage", "create", name, "btrfs", "source=/var/lib/incus/storage-pools/" + name, *config, timeout=120)


def target(entry):
    """(kind, name, project, path parts) of a used_by URL."""
    url = urllib.parse.urlparse(entry)
    project = urllib.parse.parse_qs(url.query).get("project", ["default"])[0]
    parts = url.path.split("/")
    return parts[2], urllib.parse.unquote(parts[-1]), project, parts


def instance(name, project):
    return query(f"/1.0/instances/{urllib.parse.quote(name)}?project={project}")


def refuse(reason):
    raise SystemExit("instance.host_unavailable: " + reason)


def preflight(pool):
    """Refuse before anything stops when the move would lose something or could not finish."""
    rows = [target(entry) for entry in pools()[pool]["used_by"] or []]
    held = {(name, project) for kind, name, project, _ in rows if kind == "instances"}
    for kind, name, project, parts in rows:
        if kind == "instances":
            record = instance(name, project)
            if record.get("ephemeral"):
                refuse(f"{project}/{name} on the dir pool {pool} is ephemeral and a stop deletes it; delete or recreate it, then converge again")
            if query(f"/1.0/instances/{urllib.parse.quote(name)}/backups?project={project}"):
                refuse(f"{project}/{name} on the dir pool {pool} has Incus backups that a move drops; export and delete them, then converge again")
        elif kind == "storage-pools" and parts[4:6] == ["volumes", "custom"]:
            path = f"/1.0/storage-pools/{pool}/volumes/custom/{urllib.parse.quote(name)}"
            if query(f"{path}/backups?project={project}"):
                refuse(f"the volume {project}/{name} on the dir pool {pool} has Incus backups that a move drops; export and delete them")
            for user in query(f"{path}?project={project}")["used_by"] or []:
                kind_of, user_name, user_project, _ = target(user)
                if kind_of != "instances" or (user_name, user_project) not in held:
                    refuse(f"the volume {project}/{name} on the dir pool {pool} is used by {user}, outside the pool's instances; "
                           "detach it, then converge again")


def unsize_root(devices, unset):
    """Drop `size` from every disk device at `/`: Incus applies it on btrfs with qgroups, which an unprivileged nested Node refuses."""
    for device, config in devices.items():
        if config.get("type") == "disk" and config.get("path") == "/" and "size" in config:
            unset(device)


def halt(name, project, owner):
    """Stop an instance for the move and record the state it had, once."""
    record = instance(name, project)
    if MARK not in record["config"]:
        incus("config", "set", name, "--project", project, f"{MARK}={owner}:{record['status'].lower()}")
    if record["status"] != "Stopped":
        incus("stop", name, "--project", project, "--force")


def empty(source, destination, owner):
    """Move every instance and custom volume of `source` to `destination` and then point its profiles there; drop its image caches."""
    rows = [target(entry) for entry in pools()[source]["used_by"] or []]
    instances = [(name, project) for kind, name, project, _ in rows if kind == "instances"]
    profiles = [(name, project) for kind, name, project, _ in rows if kind == "profiles"]
    for name, project in instances:
        halt(name, project, owner)
    for name, project in profiles:
        profile = query(f"/1.0/profiles/{urllib.parse.quote(name)}?project={project}")
        unsize_root({d: c for d, c in profile["devices"].items() if c.get("pool") == source},
                    lambda device: incus("profile", "device", "unset", name, device, "size", "--project", project))
    for kind, name, project, parts in rows:
        if kind == "storage-pools" and parts[4:6] == ["volumes", "custom"]:
            incus("storage", "volume", "move", f"{source}/{name}", f"{destination}/{name}", "--project", project)
        elif kind == "storage-pools" and parts[4:6] == ["volumes", "image"]:
            incus("storage", "volume", "delete", source, "image/" + name, "--project", project)
    for name, project in instances:
        record = instance(name, project)

        def unset(device):
            if device not in record["devices"]:
                incus("config", "device", "override", name, device, "--project", project)
            incus("config", "device", "unset", name, device, "size", "--project", project)
        unsize_root(record["expanded_devices"], unset)
        incus("move", name, "--project", project, "--storage", destination)
    for name, project in profiles:
        profile = query(f"/1.0/profiles/{urllib.parse.quote(name)}?project={project}")
        for device, config in profile["devices"].items():
            if config.get("pool") == source:
                incus("profile", "device", "set", name, device, "pool=" + destination, "--project", project)


def marked(pool, spare):
    """The instances this move of `pool` stopped and that sit on `pool` or its own spare."""
    return [record for record in query("/1.0/instances?recursion=1&all-projects=true")
            if record["config"].get(MARK, "").startswith(pool + ":")
            and record["expanded_devices"].get("root", {}).get("pool") in (pool, spare)]


def resume(records):
    """Return each moved instance to the state it had before the move; one already there only loses its marker."""
    for record in records:
        name, project, status = record["name"], record["project"], record["status"]
        state = record["config"][MARK].split(":", 1)[1]
        if state in ("running", "frozen") and status == "Stopped":
            incus("start", name, "--project", project)
            status = "Running"
        if state == "frozen" and status == "Running":
            incus("pause", name, "--project", project)
        incus("config", "unset", name, MARK, "--project", project)


def converge(pool):
    spare = pool + "-move"
    found = pools()
    if spare in found and found[spare].get("config", {}).get(MARK) != pool:
        refuse(f"the pool {spare} was not made by the move of {pool}; move what it holds and delete it, then converge again")
    driver = found.get(pool, {}).get("driver", "btrfs")
    if driver not in ("btrfs", "dir"):
        refuse(f"the Incus pool {pool} uses the {driver} driver; a fleet Node pool must be btrfs")
    changed = False
    if driver == "dir":
        if spare not in found:
            preflight(pool)
            create(spare, f"{MARK}={pool}")
        empty(pool, spare, pool)
        incus("storage", "delete", pool)
        changed = True
    if pool not in pools():
        create(pool)
        changed = True
    if spare in pools():
        empty(spare, pool, pool)
        incus("storage", "delete", spare)
        changed = True
    records = marked(pool, spare)
    if records:
        resume(records)
        changed = True
    return {"changed": changed}


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit(64)
    print(json.dumps(converge(sys.argv[1])))
