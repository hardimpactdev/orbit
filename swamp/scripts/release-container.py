#!/usr/bin/env python3
"""Operate one target's Incus preparer and immutable releases. Swamp owns ordering and traffic."""

import argparse
import ipaddress
import json
import math
import re
import subprocess
import sys
import tempfile
import time
from pathlib import Path

PROJECT = "orbit-releases"
ADDRESS_TIMEOUT = 15  # Same deadline as one private HTTP readiness probe.
PREFIX = "user.orbit.release."
GATEWAY_INVENTORY = Path("/home/orbit/.orbit/gateway-containers.json")
GATEWAY_CANDIDATE = Path("/var/lib/orbit/gateway-release-switch/candidate")
# Bind the writable Laravel cache first. The recursive checkout bind keeps that submount writable.
# All sources are relative to the cloned root filesystem, including on a cold start.
GATEWAY_MOUNTS = "\n".join(
    f"lxc.mount.entry = {path} {path} none {options},relative 0 0"
    for path, options in (
        ("home/orbit/orbit/apps/gateway/bootstrap/cache", "bind"),
        ("home/orbit/orbit", "rbind,ro"),
        ("home/orbit/web", "bind,ro"),
    )
)
OWNER = re.compile(r"gateway|instance-[1-9][0-9]*")
SHA = re.compile(r"[0-9a-f]{40}")
REVISION = re.compile(r"[0-9a-f]{12}")
DIGEST = re.compile(r"sha256:[0-9a-f]{64}")
OPERATION = re.compile(r"[1-9][0-9]*")


def matches(pattern):
    def check(value):
        if not pattern.fullmatch(value):
            raise argparse.ArgumentTypeError("invalid value")
        return value

    return check


def incus(*args, timeout=900):
    return subprocess.run(
        ["incus", "--force-local", "--project", PROJECT, *args], stdin=subprocess.DEVNULL, capture_output=True,
        text=True, check=True, timeout=timeout,
    ).stdout


def instance(name, timeout=900):
    records = json.loads(incus("list", name, "--format", "json", timeout=timeout))
    return next((record for record in records if record["name"] == name), None)


def owned(record, expected):
    if not record or any(record["config"].get(PREFIX + key) != value for key, value in expected.items()):
        raise ValueError("release.container_identity_mismatch")
    return record


def preparer(target):
    return owned(instance("prepare-" + target), {"target": target, "role": "prepare"})


def release(target, revision):
    record = owned(instance(f"{target}-{revision}"), {"target": target, "role": "candidate"})
    sha = record["config"].get(PREFIX + "commit", "")
    if not SHA.fullmatch(sha) or sha[:12] != revision:
        raise ValueError("release.container_revision_mismatch")
    return record


def memory_use(record):
    memory = (record.get("state") or {}).get("memory") or {}
    resident, swap = memory.get("usage", 0), memory.get("swap_usage", 0)
    if min(resident, swap) < 0 or (resident + swap == 0 and record["status"] != "Stopped"):
        raise ValueError("cannot measure " + record["name"])
    # Incus resolves byte and percentage limits against the Node in the state API.
    limit = memory.get("total", 0) if record["expanded_config"].get("limits.memory") else 0
    return max(resident + swap, limit), resident, swap


def host_gateway_memory():
    output = subprocess.check_output([
        "systemctl", "show", "-p", "Id,WorkingDirectory,ActiveState,MemoryCurrent",
        "php8.5-fpm.service", "orbit-*.service",
    ], text=True)
    total = 0
    for block in output.strip().split("\n\n"):
        unit = dict(line.split("=", 1) for line in block.splitlines())
        if unit.get("ActiveState") != "active":
            continue
        if (unit["Id"] == "php8.5-fpm.service"
                or unit.get("WorkingDirectory", "").rstrip("/") == "/home/orbit/orbit/apps/gateway"):
            total += int(unit["MemoryCurrent"])
    if total <= 0:
        raise ValueError("could not measure the serving host Gateway")
    return total, total, 0


def capacity(target, live, host_gateway):
    prep = preparer(target)
    if host_gateway and target != "gateway":
        raise ValueError("host PHP-FPM measurement applies only to the Gateway migration")
    served = host_gateway_memory() if host_gateway else memory_use(release(target, live))
    preparation = memory_use(prep)
    if max(preparation[0], served[0]) <= 0:
        raise ValueError("cannot estimate candidate memory")
    # The Gateway preparer becomes the candidate and is never frozen, so it reclaims nothing to swap.
    return capacity_estimate(preparation, served, prep["expanded_devices"]["root"]["pool"], reclaim=target != "gateway")


def capacity_estimate(preparation, served, pool, reclaim=True):
    prep_need, prep_resident, prep_swap = preparation
    live_need, live_resident, _live_swap = served
    working = max(prep_need, live_need)
    # Keep live available while preparation and hot overlap. Existing resident memory and
    # swap slots are already excluded from the Node's free counts. Only preparation is
    # reclaimed to swap; retire stops the drained live, so it needs none.
    ram = 2 * working - prep_resident + max(0, live_need - live_resident) + 1024**3
    swap = working - prep_swap if reclaim else 0
    meminfo = {}
    for line in Path("/proc/meminfo").read_text().splitlines():
        key, values = line.split(":", 1)
        if key in ("MemAvailable", "SwapFree"):
            meminfo[key] = int(values.split()[0]) * 1024
    if not re.fullmatch(r"[a-zA-Z0-9_.-]+", pool):
        raise ValueError("invalid preparation pool")
    pool_info = subprocess.check_output(
        ["incus", "--force-local", "query", f"/1.0/storage-pools/{pool}/resources"], text=True, timeout=30,
    )
    space = json.loads(pool_info)["space"]
    available = {"ram": meminfo["MemAvailable"], "swap": meminfo["SwapFree"], "pool": space["total"] - space["used"]}
    required = {"ram": ram, "swap": swap, "pool": 10 * 1024**3}
    for resource, need in required.items():
        if available[resource] < need:
            raise ValueError(f"{resource} needs {need} bytes, {available[resource]} available")
    return {"required_bytes": required, "available_bytes": available}


def power(name, action, operation=None):
    arguments = [
        "--unit=orbit-instance-power-" + name, "--wait", "--pipe", "--collect", "--quiet",
        "/usr/local/libexec/orbit-instance-power", action, "--release", name,
    ]
    intent = None
    if operation is not None:
        arguments.extend(["--operation", operation])
        intent = json.dumps({
            "schema_version": 1, "instance_id": name, "incus_project": PROJECT, "incus_name": name,
            "operation_id": int(operation), "web": None,
        })
    result = subprocess.run(["systemd-run", *arguments], input=intent, capture_output=True, text=True, timeout=120, check=True)
    receipt = json.loads(result.stdout)
    if receipt["outcome"] in ("failed", "missing"):
        raise ValueError(receipt.get("error_code") or "release.power_failed")
    return receipt


def wake(record, operation):
    if record["status"] == "Stopped":
        # Clear receipts from a stopped incarnation before start. A cold boot is also
        # the recovery path after a Node reboot loses the earlier frozen processes.
        power(record["name"], "restore", operation)
        incus("start", record["name"])
    # A kernel freeze can survive an Incus daemon restart even when Incus says Running.
    receipt = power(record["name"], "restore", operation)
    if receipt["state_after"] != "running":
        raise ValueError("release.container_unavailable")
    return record["name"]


def sleep(record, operation):
    if record["config"].get("boot.autostart") != "false":
        raise ValueError("release.container_still_active")
    if len(Path("/proc/swaps").read_text().splitlines()) < 2:
        raise ValueError("release.swap_unavailable")
    receipt = power(record["name"], "prepare", operation)
    if receipt["outcome"] not in ("frozen", "already_frozen"):
        raise ValueError("release.container_not_frozen")
    path = Path("/sys/fs/cgroup") / ("lxc.payload." + PROJECT + "_" + record["name"])
    memory = dict(line.split() for line in (path / "memory.stat").read_text().splitlines())
    receipt["residual_anon_bytes"] = int(memory["anon"])
    receipt["swap_bytes"] = int((path / "memory.swap.current").read_text())
    if receipt["swap_bytes"] == 0 or receipt["residual_anon_bytes"] > 32 * 1024 * 1024:
        power(record["name"], "restore", operation)
        raise ValueError("release.memory_not_reclaimed")
    return receipt


def stop(record, operation):
    """Park a drained former live on disk. It holds no memory or swap, and only an explicit rollback starts it."""
    name = record["name"]
    if record["config"].get("boot.autostart") != "false":
        incus("config", "set", name, "boot.autostart=false")
    if record["status"] == "Frozen":
        # Thaw first; Incus cannot shut down a frozen container cleanly.
        power(name, "restore", operation)
    if record["status"] != "Stopped":
        try:
            incus("stop", name, "--timeout", "120", timeout=180)
        except subprocess.CalledProcessError:
            # The handover already drained its requests and workers. A guest that ignores
            # the shutdown must not hold the release open.
            incus("stop", name, "--force", timeout=180)
    if instance(name)["status"] != "Stopped":
        raise ValueError("release.container_not_stopped")
    return {"name": name, "outcome": "stopped"}


def halt(record, operation):
    """Shut a container down cleanly; a frozen one is thawed first."""
    if record["status"] == "Frozen":
        power(record["name"], "restore", operation)
    if record["status"] != "Stopped":
        incus("stop", record["name"], "--timeout", "120", timeout=180)


def promote(target, operation, commit, artifact):
    """The Gateway keeps three containers: its sealed preparer itself becomes the candidate.

    Incus renames only a stopped container. Its boot mounts then make the checkout and web build read-only."""
    if target != "gateway":
        raise ValueError("release.promote_gateway_only")
    name = f"{target}-{commit[:12]}"
    seal = {"target": target, "operation": operation, "commit": commit, "artifact": artifact}
    existing = instance(name)
    if existing is None:
        prep = owned(preparer(target), {**seal, "role": "prepare", "parked-seal": f"{operation}/{commit}/{artifact}"})
        halt(prep, operation)
        incus("rename", prep["name"], name)
        existing = instance(name)
    if instance("prepare-" + target) is None:
        # Also on a retry after the rename: the preparer's power records belong to no container now.
        power("prepare-" + target, "discard")
    owned(existing, seal)
    if existing["config"].get(PREFIX + "role") != "candidate":
        incus("config", "set", name, "boot.autostart=false", PREFIX + "role=candidate", "raw.lxc=" + GATEWAY_MOUNTS)
        existing = instance(name)
    owned(existing, {**seal, "role": "candidate"})
    if existing["status"] == "Stopped":
        incus("start", name)
    elif existing["status"] != "Running":
        raise ValueError("release.candidate_not_running")
    return name


def demote(target, operation, commit):
    """Return a Gateway candidate that never served to the preparation slot, warm, writable and stopped."""
    name = f"{target}-{commit[:12]}"
    record = instance(name)
    if record is None:
        # A retry after the rename still discards the candidate's power records.
        power(name, "discard")
        return ""
    owned(record, {"target": target, "operation": operation, "commit": commit})
    if record["config"].get("boot.autostart") == "true":
        raise ValueError("release.container_still_active")
    if instance("prepare-" + target) is not None:
        # Another preparer already holds the slot. This build never served, so it goes.
        remove(record, target, force=True)
        return name
    halt(record, operation)
    # Units the switch enabled before it failed must not start in preparation. Read the stopped container itself.
    clear_wanted(name)
    # Each change is checked against the current record, so a retry after a crash completes it.
    if "raw.lxc" in record["config"]:
        incus("config", "unset", name, "raw.lxc")
    if record["devices"].get("eth0", {}).get("ipv4.address"):
        incus("config", "device", "unset", name, "eth0", "ipv4.address")
    if record["config"].get(PREFIX + "role") != "prepare":
        incus("config", "set", name, PREFIX + "role=prepare")
    incus("rename", name, "prepare-" + target)
    power(name, "discard")
    return "prepare-" + target


def seed(target, revision, operation):
    """Recreate a missing Gateway preparer from the new live, stopped, with no serving state or enabled work."""
    if target != "gateway":
        raise ValueError("release.seed_gateway_only")
    name = "prepare-" + target
    live = release(target, revision)
    snapshot = "seed-" + operation
    prep = instance(name)
    stale = prep is not None and prep["config"].get(PREFIX + "seeded-from") not in (None, live["config"][PREFIX + "commit"])
    if prep is None or stale:
        # Refuse a pool that would copy files before anything changes.
        require_clone_copy(live)
    if stale:
        # Preparation is always a clone of the current live. One cloned from another release is replaced.
        owned(prep, {"target": target, "role": "prepare"})
        halt(prep, operation)
        remove(prep, target)
        prep = None
    if prep is None:
        gateway_slots(target)
        snapshots = json.loads(incus("snapshot", "list", live["name"], "--format", "json"))
        if not any(entry["name"] == snapshot for entry in snapshots):
            incus("snapshot", "create", live["name"], snapshot)
        # The live address reservation stays with live; the preparer takes a fresh DHCP lease.
        incus("copy", live["name"] + "/" + snapshot, name, "--stateless", "--device", "eth0,ipv4.address=",
              "--config", "boot.autostart=false", "--config", PREFIX + "role=prepare",
              "--config", PREFIX + "operation=" + operation, "--config", PREFIX + "seeded-from=" + live["config"][PREFIX + "commit"],
              "--config", PREFIX + "seed-ready=false")
        prep = instance(name)
    # A crash can stop a seed at any step. Until seed-ready is set, every run redoes the cleanup and no wake starts it.
    unfinished = prep["config"].get(PREFIX + "seeded-from") and prep["config"].get(PREFIX + "seed-ready") != "true"
    if unfinished and prep["status"] == "Stopped":
        owned(prep, {"target": target, "role": "prepare"})
        for key in ("raw.lxc", PREFIX + "commit", PREFIX + "artifact", PREFIX + "parked-seal"):
            if key in prep["config"]:
                incus("config", "unset", name, key)
        if prep["devices"].get("eth0", {}).get("ipv4.address"):
            incus("config", "device", "unset", name, "eth0", "ipv4.address")
        # Live's enabled Processes, timers and web server must not start when the next release wakes the preparer.
        clear_wanted(name)
    # Incus forgets the seed snapshot. A ZFS clone still depends on it, so Incus keeps it as a hidden deleted-* snapshot.
    if any(entry["name"] == snapshot for entry in json.loads(incus("snapshot", "list", live["name"], "--format", "json"))):
        incus("snapshot", "delete", live["name"], snapshot)
    detach(live, name)
    if unfinished:
        incus("config", "set", name, PREFIX + "seed-ready=true")
    return name


def zfs(*args):
    return subprocess.run(["zfs", *args], stdin=subprocess.DEVNULL, capture_output=True, text=True, check=True, timeout=120).stdout


def pool_info(record):
    pool = record["expanded_devices"]["root"]["pool"]
    if not re.fullmatch(r"[a-zA-Z0-9_.-]+", pool):
        raise ValueError("invalid release pool")
    return json.loads(subprocess.check_output(["incus", "--force-local", "query", f"/1.0/storage-pools/{pool}"], text=True, timeout=30))


def datasets(record):
    """The ZFS pool dataset behind an instance's root disk, or None when the pool uses another driver."""
    info = pool_info(record)
    if info["driver"] != "zfs":
        return None
    return info["config"].get("zfs.pool_name") or info["config"]["source"]


def require_clone_copy(record):
    """A seed must be a ZFS clone. With zfs.clone_copy false or rebase, Incus would send the files instead."""
    info = pool_info(record)
    if info["driver"] != "zfs" or info["config"].get("zfs.clone_copy", "true") != "true":
        raise ValueError("release.pool_copies_files: the release pool needs the zfs driver with zfs.clone_copy=true; a seed never copies files")


def gateway_slots(target):
    """Refuse a new container when the Gateway already holds three."""
    records = json.loads(incus("list", "--format", "json"))
    owned = [item for item in records if item["name"] == "prepare-" + target or re.fullmatch(target + r"-[0-9a-f]{12}", item["name"])
             or item.get("config", {}).get(PREFIX + "target") == target]
    if len(owned) >= 3:
        raise ValueError("release.gateway_container_limit: three Gateway containers exist; finish or demote the candidate first")


def detach(live, name):
    """End the clone chain without copying a file.

    The preparer is a ZFS clone of live's seed snapshot, so each live would depend on the release before it and a
    deleted old would only move to deleted/. Promoting the preparer moves the snapshots Incus already forgot
    (deleted-*) from live to the preparer; live becomes the dependent clone instead. Incus records no snapshot of
    either dataset that moves, and it reads clones from ZFS on every delete. A deleted old then frees its blocks.
    zfs.clone_copy=false or rebase would send the files instead."""
    root = datasets(live)
    if root is None:
        return
    prefix = root + "/containers/" + PROJECT + "_"
    origin = zfs("get", "-H", "-o", "value", "origin", prefix + name).strip()
    if origin.startswith(prefix + live["name"] + "@"):
        if "@deleted-" not in origin:
            raise ValueError("release.seed_snapshot_still_recorded")
        # Promotion moves every snapshot of live up to the origin. Each must be one Incus already forgot.
        listing = zfs("list", "-H", "-p", "-t", "snapshot", "-o", "name,createtxg", "-d", "1", prefix + live["name"])
        snapshots = [line.split("\t") for line in listing.splitlines() if line.strip()]
        limit = next(int(txg) for snap, txg in snapshots if snap == origin)
        moving = [snap for snap, txg in snapshots if int(txg) <= limit]
        if any("@deleted-" not in snap for snap in moving):
            raise ValueError("release.recorded_snapshot_would_move: delete live's Incus snapshots before the seed")
        zfs("promote", prefix + name)
    prune(root)


def prune(root):
    """Destroy deleted-* release snapshots that no clone needs any more, as Incus does for its own deleted objects."""
    prefix = root + "/containers/" + PROJECT + "_"
    listing = zfs("list", "-H", "-p", "-t", "snapshot", "-o", "name,clones", "-r", root + "/containers")
    for line in listing.splitlines():
        snapshot, _, clones = line.partition("\t")
        owned_name = re.fullmatch(re.escape(prefix) + r"(prepare-gateway|gateway-[0-9a-f]{12})@deleted-[^@/]+", snapshot)
        if owned_name and clones.strip() in ("", "-"):
            zfs("destroy", snapshot)


def owned_names(target):
    """The exact container names a target owns: its preparer and the releases its records name."""
    if target != "gateway":
        return {"prepare-" + target} | {item["name"] for item in json.loads(incus("list", "--format", "json"))
                                        if re.fullmatch(re.escape(target) + r"-[0-9a-f]{12}", item["name"])}
    shas = []
    if GATEWAY_INVENTORY.exists():
        inventory = json.loads(GATEWAY_INVENTORY.read_text())
        shas += [inventory.get(key) for key in ("live", "old", "earlier")]
    if GATEWAY_CANDIDATE.exists():
        # During a handover the new live is recorded in the journal before the inventory names it.
        shas.append(GATEWAY_CANDIDATE.read_text().strip())
    return {"prepare-gateway"} | {"gateway-" + sha[:12] for sha in shas if isinstance(sha, str) and SHA.fullmatch(sha)}


def free_dependents(record, target):
    """Move the ZFS origin role off a release before it is deleted, without copying a file.

    After a promotion the preparer, or a release promoted from it, can be the origin of live and old. Incus cannot
    destroy such a dataset; it would move it to deleted/ and keep its blocks for ever. Promoting each dependent clone,
    newest origin first, moves the snapshots it needs onto it. Only snapshots Incus already forgot may move."""
    root = datasets(record)
    if root is None:
        return
    prefix = root + "/containers/" + PROJECT + "_"
    dataset = prefix + record["name"]
    allowed = {prefix + name for name in owned_names(target)}
    for _ in range(64):
        listing = zfs("list", "-H", "-p", "-t", "snapshot", "-o", "name,createtxg,clones", "-d", "1", dataset)
        rows = [line.split("\t") for line in listing.splitlines() if line.strip()]
        dependents = [(int(txg), clones.split(",")) for _snap, txg, clones in rows if clones.strip() not in ("", "-")]
        if not dependents:
            return
        limit, clones = max(dependents)
        # Every clone of every snapshot that would move must be one of this target's own containers, by exact name.
        moving = [clone for txg, names in dependents if txg <= limit for clone in names]
        if any(clone not in allowed for clone in moving):
            raise ValueError("release.foreign_clone: a dataset outside the release containers depends on " + record["name"])
        if any("@deleted-" not in snap for snap, txg, _clones in rows if int(txg) <= limit):
            raise ValueError("release.recorded_snapshot_would_move: delete the Incus snapshots of " + record["name"] + " first")
        zfs("promote", clones[0])
    raise ValueError("release.dependents_remain: " + record["name"])


def remove(record, target, force=False):
    """Delete a release container only once no other release depends on its dataset."""
    free_dependents(record, target)
    incus("delete", record["name"], *(["--force"] if force else []))
    power(record["name"], "discard")


def clear_wanted(name):
    # Read the stopped container itself, so a retry sees what is left, and fail on any link that cannot go.
    for path in wanted_links(name):
        incus("file", "delete", name + path, timeout=60)
    if wanted_links(name):
        raise ValueError("release.preparation_units_enabled")


def wanted_links(name):
    """The enablement links of Orbit units in a stopped container's systemd directory."""
    with tempfile.TemporaryDirectory() as directory:
        incus("file", "pull", "--recursive", name + "/etc/systemd/system", directory, timeout=120)
        root = Path(directory) / "system"
        return sorted("/etc/systemd/system/" + str(path.relative_to(root)) for path in root.glob("*.wants/orbit-*"))


def rest(target, operation):
    """Stop this operation's running preparer after a failed build. The Gateway never freezes it."""
    record = instance("prepare-" + target)
    if record is None or record["config"].get(PREFIX + "operation") != operation:
        return ""
    owned(record, {"target": target, "role": "prepare"})
    halt(record, operation)
    return record["name"]


def candidate(target, operation, commit, artifact):
    prep = preparer(target)
    name = f"{target}-{commit[:12]}"
    seal = {"target": target, "operation": operation, "commit": commit, "artifact": artifact}
    candidate_identity = {**seal, "role": "candidate"}
    existing = instance(name)
    source = prep["name"]
    snapshot = "built-" + operation
    snapshots = json.loads(incus("snapshot", "list", source, "--format", "json"))
    sealed = next((entry for entry in snapshots if entry["name"] == snapshot), None)
    if target == "gateway":
        owned(prep, {**seal, "role": "prepare", "parked-seal": f"{operation}/{commit}/{artifact}"})
        if prep["status"] != "Frozen":
            raise ValueError("release.preparation_not_frozen")
    if existing is None:
        if not sealed:
            if target != "gateway":
                if prep["status"] != "Running" or prep["config"].get(PREFIX + "operation") != operation:
                    raise ValueError("release.preparation_changed")
                incus("config", "set", source, *(PREFIX + key + "=" + value for key, value in seal.items()))
            incus("snapshot", "create", source, snapshot)
            snapshots = json.loads(incus("snapshot", "list", source, "--format", "json"))
            sealed = next((entry for entry in snapshots if entry["name"] == snapshot), None)
        owned(sealed, {**seal, "role": "prepare"})
        mounts = ["--config", "raw.lxc=" + GATEWAY_MOUNTS] if target == "gateway" else []
        incus("copy", source + "/" + snapshot, name, "--config", "boot.autostart=false",
              "--config", PREFIX + "role=candidate", *mounts, "--stateless")
        existing = instance(name)
    owned(existing, candidate_identity)
    # A successful clone owns its root disk independently. Removing the transient snapshot also
    # completes an interrupted candidate step, without deleting another operation's build.
    if sealed:
        owned(sealed, {**seal, "role": "prepare"})
        incus("snapshot", "delete", source, snapshot)
    if existing["status"] == "Stopped":
        incus("start", name)
    elif existing["status"] != "Running":
        raise ValueError("release.candidate_not_running")
    return name


def address(record):
    nic = record["expanded_devices"].get("eth0", {})
    if nic.get("type") != "nic" or not nic.get("network"):
        raise ValueError("release.container_address_requires_managed_bridge")
    network = ((record.get("state") or {}).get("network") or {}).get("eth0", {})
    values = [entry["address"] for entry in network.get("addresses", [])
              if entry["family"] == "inet" and entry["scope"] == "global"]
    if not values:
        return ""
    if len(values) != 1 or not ipaddress.IPv4Address(values[0]).is_private:
        raise ValueError("release.container_address_unavailable")
    ip = values[0]
    reservation = nic.get("ipv4.address")
    if reservation and reservation != ip:
        raise ValueError("release.container_address_changed")
    if not reservation:
        # Caddy and the service bind this address. Persist the private DHCP reservation across Node reboots.
        action = "set" if "eth0" in record["devices"] else "override"
        incus("config", "device", action, record["name"], "eth0", "ipv4.address=" + ip)
    return ip


def wait_address(record, *, refresh=False):
    deadline = time.monotonic() + ADDRESS_TIMEOUT
    name = record["name"]
    identity = {key: record["config"][PREFIX + key] for key in ("target", "role", "commit")}
    reservation = record["expanded_devices"].get("eth0", {}).get("ipv4.address")

    def remaining():
        seconds = deadline - time.monotonic()
        if seconds <= 0:
            raise ValueError("release.container_address_unavailable")
        return seconds

    def observed(candidate):
        owned(candidate, identity)
        if candidate["status"] != "Running":
            raise ValueError("release.candidate_not_running")
        if reservation and candidate["expanded_devices"].get("eth0", {}).get("ipv4.address") != reservation:
            raise ValueError("release.container_address_changed")
        return address(candidate)

    try:
        if refresh:
            record = instance(name, timeout=remaining())
        remaining()
        if ip := observed(record):
            return ip

        # networkd waits for IPv4 on eth0 even when another interface is already online.
        # Its own deadline also stops the guest command if its Incus client times out.
        timeout = remaining()
        incus("exec", name, "--", "/usr/lib/systemd/systemd-networkd-wait-online",
              "--interface=eth0:routable", "--ipv4", f"--timeout={math.ceil(timeout)}", timeout=timeout)
        record = instance(name, timeout=remaining())
        remaining()
        if ip := observed(record):
            return ip
    except subprocess.TimeoutExpired:
        pass
    raise ValueError("release.container_address_unavailable")


def main(argv):
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="action", required=True)
    for command in ("wake", "candidate", "sleep", "resume", "retire", "address", "forget"):
        action = commands.add_parser(command)
        action.add_argument("target", type=matches(OWNER))
        if command in ("retire", "resume", "address", "forget"):
            action.add_argument("revision", type=matches(REVISION))
        if command not in ("address", "forget"):
            action.add_argument("operation", type=matches(OPERATION))
        if command == "candidate":
            action.add_argument("commit", type=matches(SHA))
            action.add_argument("artifact", type=matches(DIGEST))
    for command in ("promote", "demote"):
        action = commands.add_parser(command)
        action.add_argument("target", type=matches(OWNER))
        action.add_argument("operation", type=matches(OPERATION))
        action.add_argument("commit", type=matches(SHA))
        if command == "promote":
            action.add_argument("artifact", type=matches(DIGEST))
    seeding = commands.add_parser("seed")
    seeding.add_argument("target", type=matches(OWNER))
    seeding.add_argument("revision", type=matches(REVISION))
    seeding.add_argument("operation", type=matches(OPERATION))
    removal = commands.add_parser("remove")
    removal.add_argument("target", type=matches(OWNER))
    removal.add_argument("revision", type=matches(REVISION))
    removal.add_argument("--force", action="store_true")
    resting = commands.add_parser("rest")
    resting.add_argument("target", type=matches(OWNER))
    resting.add_argument("operation", type=matches(OPERATION))
    admission = commands.add_parser("capacity")
    admission.add_argument("target", type=matches(OWNER))
    serving = admission.add_mutually_exclusive_group(required=True)
    serving.add_argument("--live", type=matches(REVISION))
    serving.add_argument("--host-gateway", action="store_true")
    args = parser.parse_args(argv)
    if args.action == "capacity":
        try:
            return capacity(args.target, args.live, args.host_gateway)
        except (KeyError, ValueError, OSError, subprocess.SubprocessError) as error:
            code = "gateway.release_node_capacity" if args.target == "gateway" else "deployment.node_capacity"
            raise ValueError(f"{code}: {error}") from error
    if args.action == "candidate":
        return candidate(args.target, args.operation, args.commit, args.artifact)
    if args.action == "promote":
        return promote(args.target, args.operation, args.commit, args.artifact)
    if args.action == "demote":
        return demote(args.target, args.operation, args.commit)
    if args.action == "seed":
        return seed(args.target, args.revision, args.operation)
    if args.action == "rest":
        return rest(args.target, args.operation)
    if args.action == "remove":
        record = release(args.target, args.revision)
        if record["config"].get("boot.autostart") == "true":
            raise ValueError("release.container_still_active")
        remove(record, args.target, force=args.force)
        return record["name"]
    if args.action in ("wake", "sleep"):
        record = preparer(args.target)
        if args.action == "wake":
            if int(record["config"].get(PREFIX + "operation", 0)) > int(args.operation):
                raise ValueError("release.preparation_changed")
            if record["config"].get(PREFIX + "seeded-from") and record["config"].get(PREFIX + "seed-ready") != "true":
                raise ValueError("release.preparation_seed_unfinished: run the seed again before waking it")
            wake(record, args.operation)
            incus("config", "set", record["name"], PREFIX + "operation=" + args.operation)
            return record["name"]
        if record["config"].get(PREFIX + "operation") != args.operation:
            raise ValueError("release.preparation_changed")
        return sleep(record, args.operation)
    if args.action == "forget":
        name = f"{args.target}-{args.revision}"
        if instance(name) is not None:
            raise ValueError("release.container_still_exists")
        power(name, "discard")
        return name
    record = release(args.target, args.revision)
    if args.action == "address":
        return wait_address(record)
    if args.action == "retire":
        return stop(record, args.operation)
    name = wake(record, args.operation)
    if record["status"] == "Stopped":
        wait_address(record, refresh=True)
    return name


if __name__ == "__main__":
    try:
        result = main(sys.argv[1:])
        print(json.dumps(result) if isinstance(result, dict) else result)
    except subprocess.CalledProcessError as error:
        # Incus output can contain guest config; only the command's public error reaches run history.
        sys.exit(f"release.container_command_failed: {error.cmd[0]} exited {error.returncode}")
    except (subprocess.TimeoutExpired, ValueError, OSError) as error:
        sys.exit(str(error))
