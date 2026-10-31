import shutil
import tempfile
from pathlib import Path

from common import FILES, SERVICE, WG, command, finish, resolver_settings


def snapshot(data):
    directory = Path(tempfile.mkdtemp(prefix="orbit-peer-backup-", dir="/run"))
    present = []
    for name in FILES:
        source = WG / name
        if source.exists():
            shutil.copy2(source, directory / name)
            present.append(name)
    active = (
        command(
            ["/usr/bin/systemctl", "is-active", SERVICE], check=False
        ).stdout.strip()
        == "active"
    )
    recorded = None
    marker = WG / "orbit.dns-link"
    if marker.exists():
        fields = marker.read_text().splitlines()
        if len(fields) < 3:
            raise ValueError("wireguard.peer_dns_link_invalid")
        recorded = {"link": fields[0], "settings": resolver_settings(fields[0])}
    resolver = resolver_settings("orbit") if active else {}
    return {
        "directory": str(directory),
        "present": present,
        "resolver": resolver,
        "recorded_resolver": recorded,
        "active": active,
        "enabled": command(
            ["/usr/bin/systemctl", "is-enabled", SERVICE], check=False
        ).stdout.strip(),
    }


finish(snapshot)
