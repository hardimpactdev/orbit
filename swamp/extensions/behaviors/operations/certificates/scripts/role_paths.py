"""Canonical fixed role paths for native and retained certificate callers."""

import re
from pathlib import Path

ROOT = Path("/etc/caddy")
KEY_ROOT = Path("/var/lib/orbit/role-certificates")


def role_paths(data):
    role = data["scope"].removeprefix("role-")
    if role not in ("gateway", "metrics", "websocket", "analytics"):
        raise ValueError("certificate.role_input_invalid")
    if not re.fullmatch("[a-f0-9]{16}", data["version"]) or not re.fullmatch(
        "[a-f0-9]{64}", data["root_hash"]
    ):
        raise ValueError("certificate.role_input_invalid")
    if len(data["domain"]) > 253 or not re.fullmatch(
        r"(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?",
        data["domain"],
    ):
        raise ValueError("certificate.role_input_invalid")
    directory = role_directory(data["scope"])
    return role, directory, str(directory)


def role_directory(scope):
    role = scope.removeprefix("role-")
    if scope != "role-" + role or role not in ("gateway", "metrics", "websocket", "analytics"):
        raise ValueError("certificate.role_input_invalid")
    return ROOT / ("orbit-" + ("" if role == "gateway" else role + "-") + "cert-current")
