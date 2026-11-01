"""Canonical fixed role paths for native and retained certificate callers."""

import grp
import hashlib
import os
import pwd
import re
import stat
from pathlib import Path

ROOT = Path("/etc/caddy")
KEY_ROOT = Path("/var/lib/orbit/role-certificates")
SWAMP_HOME = Path("/home/orbit")
SWAMP_ROOT = Path("/usr/local/share/ca-certificates/orbit-root.crt")


def role_paths(data):
    role = data["scope"].removeprefix("role-")
    if data["scope"] != "role-" + role or role not in (
        "gateway",
        "metrics",
        "websocket",
        "analytics",
        "swamp",
    ):
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
    if role == "swamp":
        if data["domain"] != "swamp.orbit":
            raise ValueError("certificate.role_input_invalid")
        role_owner(data)
        directory = SWAMP_HOME / ".config/swamp/serve-tls"
        for path, kind in [
            (directory, stat.S_ISDIR),
            (directory / "cert.pem", stat.S_ISREG),
            (directory / "key.pem", stat.S_ISREG),
        ]:
            if os.path.lexists(path) and not kind(path.lstat().st_mode):
                raise ValueError("certificate.role_path_invalid")
        try:
            if hashlib.sha256(SWAMP_ROOT.read_bytes()).hexdigest() != data["root_hash"]:
                raise ValueError("certificate.root_mismatch")
        except OSError as error:
            raise ValueError("certificate.root_mismatch") from error
        return directory / "cert.pem", directory / "key.pem", SWAMP_ROOT
    directory = role_directory(data["scope"])
    name = "reverb" if role == "websocket" else role
    return (
        directory / (name + ".pem"),
        directory / (name + ".key"),
        directory / "root-ca.pem",
    )


def role_owner(data):
    if data["scope"] == "role-swamp":
        try:
            user = pwd.getpwnam("orbit")
            gid = grp.getgrnam("orbit").gr_gid
        except KeyError as error:
            raise ValueError("certificate.account_invalid") from error
        if user.pw_dir != str(SWAMP_HOME):
            raise ValueError("certificate.account_invalid")
        return user.pw_uid, gid, 0o700, 0o600
    return 0, grp.getgrnam("caddy").gr_gid, 0o750, 0o640


def role_directory(scope):
    role = scope.removeprefix("role-")
    if scope != "role-" + role or role not in (
        "gateway",
        "metrics",
        "websocket",
        "analytics",
    ):
        raise ValueError("certificate.role_input_invalid")
    return ROOT / (
        "orbit-" + ("" if role == "gateway" else role + "-") + "cert-current"
    )


def role_workspace(data):
    if data["scope"] == "role-swamp":
        # Keep key publication on one filesystem; the private key is renamed, never copied.
        return SWAMP_HOME / ".config/swamp/serve-tls/.pending" / data["version"]
    return KEY_ROOT / data["scope"] / data["version"]
