"""Publish the hub's peer list (legacy agent wireguard.project-hub, minus the Gateway-held private key).

argv[1] is the public intent: {hub_id, peers: [{name, public_key, address}]}. The hub keeps its own [Interface]
section and private key; only the [Peer] sections change, and an identical list writes nothing. Validates the
file, keeps a rollback copy, installs it, live-syncs or starts wg-quick@orbit, and requires it active, enabled
and serving exactly these peers. Any failure puts the previous file and service state back. Prints one public
JSON line; exit 1 carries a public error_code.
"""

import fcntl
import ipaddress
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

WG = Path("/etc/wireguard")
LIVE = WG / "orbit.conf"
BACKUP = WG / ".orbit.conf.rollback"
SERVICE = "wg-quick@orbit"
# Shared with every peer WireGuard and DNS change on the Node (hub_peer.py).
LOCK = Path("/run/lock/orbit-wireguard-peer.lock")
KEY = re.compile(r"[A-Za-z0-9+/]{43}=")
NAME = re.compile(r"[A-Za-z0-9._-]{1,253}")
HOOK = re.compile(r"^\s*(PreUp|PostUp|PreDown|PostDown)\s*=", re.IGNORECASE | re.MULTILINE)


class Refusal(Exception):
    def __init__(self, code):
        self.code = code


def ok(*argv, stdout=subprocess.DEVNULL):
    """One fixed program per call: systemctl, wg-quick or wg."""
    options = {"stdout": stdout, "stderr": subprocess.DEVNULL}
    if argv[0] == "systemctl":
        return subprocess.run(["/usr/bin/systemctl", *argv[1:]], check=False, **options).returncode == 0
    if argv[0] == "wg-quick":
        return subprocess.run(["/usr/bin/wg-quick", *argv[1:]], check=False, **options).returncode == 0
    return subprocess.run(["/usr/bin/wg", *argv[1:]], check=False, **options).returncode == 0


def private(path, data):
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, "wb") as handle:
        handle.write(data)


def sync():
    """wg syncconf reads a stripped copy; the hub keeps its links up."""
    with tempfile.NamedTemporaryFile(dir=WG, prefix="orbit-wireguard.", delete=True) as runtime:
        os.chmod(runtime.name, 0o600)
        return ok("wg-quick", "strip", str(LIVE), stdout=runtime) and ok("wg", "syncconf", "orbit", runtime.name)


def peers_match(config):
    wanted = set(re.findall(r"^\s*PublicKey\s*=\s*(\S+)\s*$", config, re.MULTILINE))
    shown = subprocess.run(["/usr/bin/wg", "show", "orbit", "peers"], capture_output=True, text=True, check=False)
    return shown.returncode == 0 and set(shown.stdout.split()) == wanted


def render(peers):
    """The live [Interface] section, unchanged, followed by the admitted peers."""
    if not LIVE.is_file() or LIVE.is_symlink():
        raise Refusal("wireguard.hub_state_unsupported")
    current = LIVE.read_text()
    interface = re.split(r"^\s*\[Peer\]", current, maxsplit=1, flags=re.MULTILINE)[0].rstrip("\n")
    if not re.match(r"\s*\[Interface\]", interface):
        raise Refusal("wireguard.hub_state_unsupported")
    lines = [interface]
    for peer in peers:
        if not KEY.fullmatch(peer["public_key"]) or not NAME.fullmatch(peer["name"]):
            raise Refusal("wireguard.hub_config_invalid")
        address = ipaddress.IPv4Address(peer["address"])
        lines += ["", "[Peer]", f"# {peer['name']}", f"PublicKey = {peer['public_key']}", f"AllowedIPs = {address}/32"]
    return current, "\n".join(lines) + "\n"


def publish(peers):
    current, config = render(peers)
    if len(config) > 1024 * 1024 or HOOK.search(config):
        raise Refusal("wireguard.hub_config_invalid")
    if config == current and ok("systemctl", "is-active", "--quiet", SERVICE) and peers_match(config):
        return
    candidate = WG / "orbit-candidate.conf"
    try:
        private(candidate, config.encode())
    except OSError:
        raise Refusal("wireguard.hub_install_failed") from None
    if not ok("wg-quick", "strip", str(candidate)):
        candidate.unlink(missing_ok=True)
        raise Refusal("wireguard.hub_config_invalid")
    try:
        BACKUP.unlink(missing_ok=True)
        if LIVE.is_file() and not LIVE.is_symlink():
            shutil.copy2(LIVE, BACKUP)
        os.replace(candidate, LIVE)
    except OSError:
        candidate.unlink(missing_ok=True)
        BACKUP.unlink(missing_ok=True)
        raise Refusal("wireguard.hub_install_failed") from None
    was_active = ok("systemctl", "is-active", "--quiet", SERVICE)
    was_enabled = ok("systemctl", "is-enabled", "--quiet", SERVICE)
    started = (ok("systemctl", "enable", SERVICE) and (sync() if was_active else ok("systemctl", "start", SERVICE))
               and ok("systemctl", "is-active", "--quiet", SERVICE) and ok("systemctl", "is-enabled", "--quiet", SERVICE)
               and peers_match(config))
    if not started:
        if BACKUP.is_file():
            os.replace(BACKUP, LIVE)
            if not was_active:
                ok("systemctl", "stop", SERVICE)
            elif not sync():
                ok("systemctl", "restart", SERVICE)
        else:
            LIVE.unlink(missing_ok=True)
            ok("systemctl", "stop", SERVICE)
        ok("systemctl", "enable" if was_enabled else "disable", SERVICE)
        raise Refusal("wireguard.hub_start_failed")
    BACKUP.unlink(missing_ok=True)


def main():
    intent = json.loads(sys.argv[1])
    hub_id = intent["hub_id"]
    descriptor = os.open(LOCK, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        fcntl.flock(descriptor, fcntl.LOCK_EX)
        publish(intent["peers"])
    except (KeyError, TypeError, ValueError):
        print(json.dumps({"schema_version": 1, "hub_id": hub_id, "error_code": "wireguard.hub_config_invalid"}))
        sys.exit(1)
    except Refusal as refusal:
        print(json.dumps({"schema_version": 1, "hub_id": hub_id, "error_code": refusal.code}))
        sys.exit(1)
    finally:
        os.close(descriptor)
    print(json.dumps({"schema_version": 1, "hub_id": hub_id, "projected": True}))


if __name__ == "__main__":
    main()
