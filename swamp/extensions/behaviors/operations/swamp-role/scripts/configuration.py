"""Publish public files and mount declarations; preserve existing native state."""

import os
import pwd
from pathlib import Path

from common import directory, mount_units, plan, publish, ready
from install import digest


def configure(data):
    account = pwd.getpwnam("orbit")
    for path in (
        data["config"],
        data["config"] + "/serve-tls",
        "/home/orbit/.local/bin",
        "/home/orbit/.cache/swamp-workers",
        "/var/lib/orbit-node-worker",
    ):
        directory(path, account.pw_uid, account.pw_gid)
    for mount in data["mounts"]:
        # Existing state and bind mounts retain their contents and ownership.
        for path in (mount["what"], mount["where"]):
            if not Path(path).exists():
                directory(path, account.pw_uid, account.pw_gid)
    for file in data["files"]:
        publish(data, file)
    for name, mount in mount_units(data):
        content = f"[Unit]\nDescription=Orbit Swamp native state\n\n[Mount]\nWhat={mount['what']}\nWhere={mount['where']}\nType=none\nOptions=bind\n"
        publish(
            data,
            {
                "path": "/etc/systemd/system/" + name,
                "content": content,
                "owner": "root:root",
                "mode": "0644",
                "marker": "[Unit]\nDescription=Orbit Swamp native state\n",
            },
        )
    alias = Path("/home/orbit/.local/bin/swamp")
    if alias.exists() and not alias.is_symlink():
        if digest(alias) != data["install"]["artifact"]["sha256"]:
            raise ValueError("swamp.role_binary_alias_foreign")
    elif not alias.is_symlink():
        alias.symlink_to(data["install"]["binary"])
        os.chown(alias, account.pw_uid, account.pw_gid, follow_symlinks=False)


if __name__ == "__main__":
    configure(plan())
    ready()
