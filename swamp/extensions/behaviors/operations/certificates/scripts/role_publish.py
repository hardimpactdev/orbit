"""Publish one signed role certificate beside its existing Node-local key."""

import grp
import os
import shutil

from local import directory, emit, valid_pair
from role_paths import KEY_ROOT, role_paths


def publish(leaf):
    name, current, _ = role_paths(leaf)
    if valid_pair(
        current / (name + ".pem"),
        current / (name + ".key"),
        current / "root-ca.pem",
        leaf,
    ):
        return {"applied": True}
    workspace = KEY_ROOT / leaf["scope"] / leaf["version"]
    if not valid_pair(
        workspace / "cert.pem", workspace / "key.pem", workspace / "root.pem", leaf
    ):
        raise ValueError("certificate.role_pair_invalid")
    gid = grp.getgrnam("caddy").gr_gid
    directory(current, 0, gid, 0o750)
    for source, target in (
        ("cert.pem", name + ".pem"),
        ("key.pem", name + ".key"),
        ("root.pem", "root-ca.pem"),
    ):
        path = current / target
        shutil.copyfile(workspace / source, path)
        os.chown(path, 0, gid)
        path.chmod(0o640)
    return {"applied": True}


if __name__ == "__main__":
    emit(publish)
