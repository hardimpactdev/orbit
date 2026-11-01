"""Public intent and ordinary local file operations; official SSH owns transport."""

import json
import os
import re
import subprocess
import tempfile
from pathlib import Path

ACTION_JSON = ""
WG = Path("/etc/wireguard")
SERVICE = "wg-quick@orbit.service"
FILES = ("orbit.conf", "orbit.key", "orbit.dns-link")


def command(argv, stdin=None, check=True):
    result = subprocess.run(
        argv, check=False, input=stdin, text=True, capture_output=True
    )
    if check and result.returncode:
        raise RuntimeError("wireguard.peer_action_failed")
    return result


def resolver_settings(link):
    if not re.fullmatch(r"[A-Za-z0-9_.:+-]+", link):
        raise ValueError("wireguard.peer_dns_link_invalid")
    settings = {}
    for field in (
        "dns",
        "domain",
        "default-route",
        "llmnr",
        "mdns",
        "dnssec",
        "dnsovertls",
        "nta",
    ):
        result = command(["/usr/bin/resolvectl", field, link])
        settings[field] = result.stdout.split(":", 1)[-1].strip().split()
    return settings


def publish(path, value):
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    descriptor, candidate = tempfile.mkstemp(prefix=".orbit-", dir=path.parent)
    try:
        with os.fdopen(descriptor, "w") as stream:
            stream.write(value)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(candidate, path)
    finally:
        Path(candidate).unlink(missing_ok=True)


def saved_directory(data):
    path = Path(data["snapshot"]["directory"])
    if path.parent != Path("/run") or not path.name.startswith("orbit-peer-backup-"):
        raise ValueError("wireguard.peer_backup_invalid")
    return path


def finish(action):
    try:
        print(json.dumps(action(json.loads(ACTION_JSON)), separators=(",", ":")))
    except (KeyError, OSError, ValueError, RuntimeError, TypeError):
        print(json.dumps({"error_code": "wireguard.peer_action_failed"}))
        raise SystemExit(1)
