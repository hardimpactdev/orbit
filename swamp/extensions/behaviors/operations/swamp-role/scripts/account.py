"""Refuse an unprepared source, wrong account or existing foreign Serve."""

import grp
import importlib.util
import os
import platform
import pwd
from pathlib import Path

from common import control, plan, private_file, ready, run, source_sha, tls_custody


def preflight(data):
    account = pwd.getpwnam("orbit")
    if (
        platform.system() != "Linux"
        or platform.machine() != "x86_64"
        or account.pw_uid == 0
        or account.pw_dir != "/home/orbit"
        or account.pw_gid != grp.getgrnam("orbit").gr_gid
    ):
        raise ValueError("swamp.role_account_invalid")
    if source_sha(data) != data["intent"]["source_sha"]:
        raise ValueError("swamp.role_source_not_prepared")
    alias = Path("/home/orbit/.local/bin/swamp")
    if alias.is_symlink() and alias.readlink() != Path(data["install"]["binary"]):
        raise ValueError("swamp.role_binary_alias_foreign")
    for mount in data["mounts"]:
        source, target = Path(mount["what"]), Path(mount["where"])
        if target.is_mount():
            if not source.exists() or not os.path.samefile(source, target):
                raise ValueError("swamp.role_state_mount_foreign")
        elif target.exists() and (not target.is_dir() or any(target.iterdir())):
            raise ValueError("swamp.role_state_requires_explicit_handoff")
    for binary in ("/usr/bin/caddy", "/usr/bin/git", "/usr/bin/openssl"):
        if not Path(binary).is_file():
            raise ValueError("swamp.role_prerequisites_missing")
    if importlib.util.find_spec("yaml") is None or not Path(
        "/usr/local/libexec/behavior_versions.py"
    ).is_file():
        raise ValueError("swamp.role_release_prerequisites_missing")
    active = (
        control(
            "is-active", "--quiet", "orbit-swamp-serve.service", check=False
        ).returncode
        == 0
    )
    if (
        not active
        and run(
            ["/usr/bin/ss", "-ltnH", "sport = :9418 or sport = :9417"]
        ).stdout.strip()
    ):
        raise ValueError("swamp.role_existing_runtime_owner")
    if active:
        for file in data["files"]:
            path = Path(file["path"])
            if not path.is_file() or path.read_text() != file["content"]:
                raise ValueError("swamp.role_restart_requires_release_owner")
    if data["intent"]["activate"]:
        tls_custody(data)
        for descriptor in data["credential_files"]:
            private_file(descriptor["path"], descriptor["owner"])
    return account


if __name__ == "__main__":
    account = preflight(plan())
    ready(user="orbit", uid=account.pw_uid, gid=account.pw_gid)
