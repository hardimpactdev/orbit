"""Publish one signed role certificate beside its existing Node-local key."""

import grp
import json
import os
import shutil

from local import directory, emit, valid_pair, write_signature
from role_paths import KEY_ROOT, role_paths


def publish(leaf):
    role, current, _ = role_paths(leaf)
    if valid_pair(
        current / (role + ".pem"),
        current / (role + ".key"),
        current / "root-ca.pem",
        leaf,
    ):
        return {"applied": True}
    workspace = KEY_ROOT / leaf["scope"] / leaf["version"]
    write_signature(
        workspace,
        leaf,
        json.loads(os.environ["ORBIT_CERTIFICATE_SIGNED"]),
        check_expiry=True,
    )
    gid = grp.getgrnam("caddy").gr_gid
    directory(current, 0, gid, 0o750)
    for source, target in (
        ("cert.pem", role + ".pem"),
        ("key.pem", role + ".key"),
        ("root.pem", "root-ca.pem"),
    ):
        path = current / target
        shutil.copyfile(workspace / source, path)
        os.chown(path, 0, gid)
        path.chmod(0o640)
    shutil.rmtree(workspace)
    return {"applied": True}


if __name__ == "__main__":
    emit(publish)
