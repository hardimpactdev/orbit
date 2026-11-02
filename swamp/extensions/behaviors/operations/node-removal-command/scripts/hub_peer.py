"""Withdraw one public peer from the existing hub configuration and live interface."""

import fcntl
import json
import os
import re
import subprocess
import tempfile
from pathlib import Path

REMOVAL_INPUT = None
CONFIG = Path("/etc/wireguard/orbit.conf")
PEERS = Path("/etc/wireguard/orbit-peers.d")
LOCK = Path("/run/lock/orbit-wireguard-peer.lock")


def without_peer(text, public_key):
    sections = re.split(r"(?m)(?=^\s*\[(?:Interface|Peer)\]\s*$)", text)
    return "".join(section for section in sections if not (
        re.match(r"\s*\[Peer\]", section)
        and re.search(r"(?m)^\s*PublicKey\s*=\s*" + re.escape(public_key) + r"\s*$", section)
    ))


def write(text):
    fd, name = tempfile.mkstemp(prefix="or-rm-", suffix=".conf", dir=CONFIG.parent)
    try:
        with os.fdopen(fd, "w") as stream:
            os.fchmod(stream.fileno(), 0o600)
            stream.write(text)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(name, CONFIG)
    finally:
        Path(name).unlink(missing_ok=True)


def withdraw(data):
    key = data["public_key"]
    if not re.fullmatch(r"[A-Za-z0-9+/]{43}=", key):
        raise ValueError("wireguard.peer_invalid")
    fd = os.open(LOCK, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, "w") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        before = CONFIG.read_text()
        after = without_peer(before, key)
        if after != before:
            write(after)
        # The hub also loads one file per peer; drop this Node's and nothing else.
        for path in PEERS.glob("*.conf"):
            if re.search(r"(?m)^\s*PublicKey\s*=\s*" + re.escape(key) + r"\s*$", path.read_text()):
                path.unlink()
        # Only this peer leaves the live interface: a whole syncconf would drop every peer the file does not list.
        subprocess.run(["/usr/bin/wg", "set", "orbit", "peer", key, "remove"], check=True,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    return {"withdrawn": True}


if __name__ == "__main__":
    print(json.dumps(withdraw(REMOVAL_INPUT)))
