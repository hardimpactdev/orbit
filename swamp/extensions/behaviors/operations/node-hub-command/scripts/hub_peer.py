#!/usr/bin/env python3
"""One existing-hub peer mutation. Native model serialization encloses fresh reads."""

import fcntl
import ipaddress
import json
import os
import re
import stat
import subprocess
import tempfile
from pathlib import Path

WG = Path("/etc/wireguard")
UID = 0
LOCK = Path("/run/lock/orbit-wireguard-peer.lock")
LIMIT = 1024 * 1024
KEY = re.compile(r"[A-Za-z0-9+/]{43}=")


class Refusal(Exception):
    def __init__(self, code="wireguard.hub_state_unsupported"):
        self.code = code


def owned(path, directory=False):
    info = path.lstat()
    kind = stat.S_ISDIR if directory else stat.S_ISREG
    if not kind(info.st_mode) or info.st_uid != UID or info.st_mode & 0o077:
        raise Refusal()


def read_config():
    owned(WG, True)
    path = WG / "orbit.conf"
    owned(path)
    raw = path.read_bytes()
    if len(raw) > LIMIT:
        raise Refusal()
    return raw.decode()


def parse_config(text):
    blocks = re.split(r"(?m)^\s*\[Peer\]\s*$", text)
    interface = blocks.pop(0)
    if not re.match(r"^\s*\[Interface\]\s*\n", interface):
        raise Refusal()
    fields = {}
    for line in interface.splitlines()[1:]:
        if not line.strip() or line.lstrip().startswith("#"):
            continue
        key, sep, value = line.partition("=")
        key, value = key.strip(), value.strip()
        if (
            not sep
            or key not in ["Address", "ListenPort", "PrivateKey"]
            or key in fields
        ):
            raise Refusal()
        fields[key] = value
    if set(fields) != {"Address", "ListenPort", "PrivateKey"} or not KEY.fullmatch(
        fields["PrivateKey"]
    ):
        raise Refusal()
    peers = []
    for raw in blocks:
        values = {}
        for line in raw.splitlines():
            if not line.strip() or line.lstrip().startswith("#"):
                continue
            key, sep, value = line.partition("=")
            key, value = key.strip(), value.strip()
            if not sep or key not in ["PublicKey", "AllowedIPs"] or key in values:
                raise Refusal()
            values[key] = value
        if set(values) != {"PublicKey", "AllowedIPs"} or not KEY.fullmatch(
            values["PublicKey"]
        ):
            raise Refusal()
        address = ipaddress.ip_network(values["AllowedIPs"], strict=True)
        if address.version != 4 or address.prefixlen != 32:
            raise Refusal()
        peers.append(
            {
                "public_key": values["PublicKey"],
                "address": str(address.network_address),
                "raw": raw,
            }
        )
    for key in ["public_key", "address"]:
        if len({peer[key] for peer in peers}) != len(peers):
            raise Refusal()
    return interface, fields, peers


def public_peer(raw, context):
    if raw is None:
        return None
    if (
        set(raw) != {"node_id", "name", "address", "public_key"}
        or raw["node_id"] != context
    ):
        raise Refusal("wireguard.hub_config_invalid")
    if not isinstance(raw["name"], str) or not re.fullmatch(
        r"[A-Za-z0-9._-]{1,253}", raw["name"]
    ):
        raise Refusal("wireguard.hub_config_invalid")
    if not isinstance(raw["public_key"], str) or not KEY.fullmatch(raw["public_key"]):
        raise Refusal("wireguard.hub_config_invalid")
    ipaddress.IPv4Address(raw["address"])
    return {"public_key": raw["public_key"], "address": raw["address"]}


def render(text, action):
    if set(action) != {
        "schema_version",
        "hub_id",
        "hub_address",
        "port",
        "context_node_id",
        "context_address",
        "restoring",
        "before",
        "after",
    }:
        raise Refusal("wireguard.hub_config_invalid")
    if (
        action["schema_version"] != 1
        or type(action["hub_id"]) is not int
        or type(action["context_node_id"]) is not int
        or min(action["hub_id"], action["context_node_id"]) < 1
        or action["hub_id"] == action["context_node_id"]
    ):
        raise Refusal("wireguard.hub_config_invalid")
    ipaddress.IPv4Interface(action["hub_address"])
    ipaddress.IPv4Address(action["context_address"])
    if type(action["restoring"]) is not bool:
        raise Refusal("wireguard.hub_config_invalid")
    before = public_peer(action["before"], action["context_node_id"])
    after = public_peer(action["after"], action["context_node_id"])
    if any(
        peer and peer["address"] == action["hub_address"].split("/")[0]
        for peer in [before, after]
    ):
        raise Refusal("wireguard.hub_config_invalid")
    interface, fields, peers = parse_config(text)
    if fields["Address"] != action["hub_address"] or fields["ListenPort"] != str(
        action["port"]
    ):
        raise Refusal("wireguard.hub_config_invalid")
    identities = [p for p in [before, after] if p]
    candidates = [
        p
        for p in peers
        if p["address"] == action["context_address"]
        or any(
            p["public_key"] == q["public_key"] or p["address"] == q["address"]
            for q in identities
        )
    ]

    def matches(peer, desired):
        return (
            desired is not None
            and {k: peer[k] for k in ["public_key", "address"]} == desired
        )

    if len(candidates) > 1:
        raise Refusal("wireguard.hub_conflict")
    current = candidates[0] if candidates else None
    if current is not None and not (
        matches(current, before) or matches(current, after)
    ):
        raise Refusal("wireguard.hub_conflict")
    if current is None and before is not None and after is not None:
        # A missing expected peer may represent a concurrent removal; never resurrect it.
        raise Refusal("wireguard.hub_conflict")
    if (current is None and after is None) or (
        current is not None and matches(current, after)
    ):
        return text, False
    remaining = [p for p in peers if p is not current]
    output = interface.rstrip() + "\n"
    for peer in remaining:
        output += "\n[Peer]" + peer["raw"]
    if after:
        output = (
            output.rstrip()
            + "\n\n[Peer]\n# "
            + action["after"]["name"]
            + "\nPublicKey = "
            + after["public_key"]
            + "\nAllowedIPs = "
            + after["address"]
            + "/32\n"
        )
    return output, output != text


def command(args):
    options = {
        "stdin": subprocess.DEVNULL,
        "stdout": subprocess.PIPE,
        "stderr": subprocess.DEVNULL,
        "env": {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL": "C"},
        "timeout": 30,
        "check": False,
    }
    try:
        if args[0] == "/usr/bin/wg-quick":
            result = subprocess.run(["/usr/bin/wg-quick", *args[1:]], **options)
        elif args[0] == "/usr/bin/wg":
            result = subprocess.run(["/usr/bin/wg", *args[1:]], **options)
        elif args[0] == "/usr/bin/systemctl":
            result = subprocess.run(["/usr/bin/systemctl", *args[1:]], **options)
        else:
            raise Refusal("wireguard.hub_config_invalid")
    except (OSError, subprocess.TimeoutExpired):
        raise Refusal("wireguard.hub_start_failed") from None
    if result.returncode or len(result.stdout) > LIMIT:
        raise Refusal("wireguard.hub_start_failed")
    return result.stdout


def publish_file(path, data):
    if path.exists() or path.is_symlink():
        owned(path)
    fd, name = tempfile.mkstemp(prefix=".orbit-hub-", dir=WG)
    try:
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "w") as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def sync_config(config):
    candidate = WG / "orbit-candidate.conf"
    runtime = WG / "orbit-native-runtime.conf"
    try:
        publish_file(candidate, config)
        stripped = command(["/usr/bin/wg-quick", "strip", str(candidate)]).decode()
        publish_file(runtime, stripped)
        command(["/usr/bin/wg", "syncconf", "orbit", str(runtime)])
    finally:
        for path in [candidate, runtime]:
            if path.exists():
                owned(path)
                path.unlink()


def apply(action):
    old = read_config()
    desired, changed = render(old, action)
    command(["/usr/bin/systemctl", "is-active", "--quiet", "wg-quick@orbit"])
    try:
        if changed:
            publish_file(WG / "orbit.conf", desired)
        # Also repair interruption between file publication and kernel sync on native resume.
        sync_config(desired)
    except (Refusal, OSError, ValueError) as error:
        try:
            if changed:
                publish_file(WG / "orbit.conf", old)
            sync_config(old)
        except (Refusal, OSError):
            raise Refusal("wireguard.hub_restore_failed") from None
        if isinstance(error, Refusal):
            raise
        raise Refusal() from None
    return {
        "schema_version": 1,
        "hub_id": action["hub_id"],
        "context_node_id": action["context_node_id"],
        "projected": True,
        "restored": action["restoring"],
        "changed": changed,
    }


def main():
    try:
        raw = os.environ.pop("ORBIT_HUB_CONTEXT")
        if len(raw.encode()) > 8192:
            raise Refusal("wireguard.hub_config_invalid")
        # The existing WireGuard tool lock also coordinates remaining canonical writers.
        fd = os.open(LOCK, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
        try:
            info = os.fstat(fd)
            if (
                not stat.S_ISREG(info.st_mode)
                or info.st_uid != UID
                or info.st_mode & 0o077
            ):
                raise Refusal()
            fcntl.flock(fd, fcntl.LOCK_EX)
            result = apply(json.loads(raw))
        finally:
            os.close(fd)
    except Refusal as error:
        result = {"error_code": error.code}
    except (OSError, ValueError, KeyError, TypeError):
        result = {"error_code": "wireguard.hub_state_unsupported"}
    print(json.dumps(result, separators=(",", ":")))
    return int("error_code" in result)


if __name__ == "__main__":
    raise SystemExit(main())
