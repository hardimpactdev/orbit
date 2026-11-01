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
LOCK = Path("/run/lock/orbit-wireguard-peer.lock")


def without_peer(text, public_key):
    sections = re.split(r"(?m)(?=^\s*\[(?:Interface|Peer)\]\s*$)", text)
    return "".join(section for section in sections if not (
        re.match(r"\s*\[Peer\]", section)
        and re.search(r"(?m)^\s*PublicKey\s*=\s*" + re.escape(public_key) + r"\s*$", section)
    ))


def sync(text):
    fd, name = tempfile.mkstemp(prefix="or-rm-", suffix=".conf", dir=CONFIG.parent)
    runtime = None
    try:
        with os.fdopen(fd, "w") as stream:
            os.fchmod(stream.fileno(), 0o600)
            stream.write(text)
            stream.flush()
            os.fsync(stream.fileno())
        stripped = subprocess.run(["/usr/bin/wg-quick", "strip", name], capture_output=True, check=True).stdout
        runtime, runtime_name = tempfile.mkstemp(prefix=".orbit-runtime-", dir=CONFIG.parent)
        with os.fdopen(runtime, "wb") as stream:
            os.fchmod(stream.fileno(), 0o600)
            stream.write(stripped)
        runtime = runtime_name
        subprocess.run(["/usr/bin/wg", "syncconf", "orbit", runtime], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        os.replace(name, CONFIG)
    finally:
        Path(name).unlink(missing_ok=True)
        if isinstance(runtime, str):
            Path(runtime).unlink(missing_ok=True)


def withdraw(data):
    if not re.fullmatch(r"[A-Za-z0-9+/]{43}=", data["public_key"]):
        raise ValueError("wireguard.peer_invalid")
    fd = os.open(LOCK, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, "w") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        before = CONFIG.read_text()
        after = without_peer(before, data["public_key"])
        try:
            sync(after)
        except Exception:
            sync(before)
            raise
    return {"withdrawn": True}


if __name__ == "__main__":
    print(json.dumps(withdraw(REMOVAL_INPUT)))
