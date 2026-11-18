#!/usr/bin/env python3
"""Operate one target's Incus preparer and immutable releases. Swamp owns ordering and traffic."""

import argparse
import ipaddress
import json
import math
import re
import subprocess
import sys
import time
from pathlib import Path

PROJECT = "orbit-releases"
ADDRESS_TIMEOUT = 15  # Same deadline as one private HTTP readiness probe.
PREFIX = "user.orbit.release."
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
    return capacity_estimate(preparation, served, prep["expanded_devices"]["root"]["pool"])


def capacity_estimate(preparation, served, pool):
    prep_need, prep_resident, prep_swap = preparation
    live_need, live_resident, live_swap = served
    working = max(prep_need, live_need)
    # Keep live available while preparation and hot overlap. Existing resident memory and
    # swap slots are already excluded from the Node's free counts.
    ram = 2 * working - prep_resident + max(0, live_need - live_resident) + 1024**3
    swap = working - prep_swap + live_need - live_swap
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
    if existing is None:
        if not sealed:
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
        return None
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
    if args.action in ("wake", "sleep"):
        record = preparer(args.target)
        if args.action == "wake":
            if int(record["config"].get(PREFIX + "operation", 0)) > int(args.operation):
                raise ValueError("release.preparation_changed")
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
        return sleep(record, args.operation)
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
