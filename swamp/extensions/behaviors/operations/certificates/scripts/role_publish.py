"""Publish the canonical role pair; all private-key work stays on its Node."""

import os
import shutil

from local import directory, emit, valid_pair
from role_paths import role_owner, role_paths, role_workspace


def publish(leaf):
    certificate, key, root = role_paths(leaf)
    uid, gid, directory_mode, file_mode = role_owner(leaf)
    if not valid_pair(certificate, key, root, leaf):
        workspace = role_workspace(leaf)
        authority = root if leaf["scope"] == "role-swamp" else workspace / "root.pem"
        if not valid_pair(
            workspace / "cert.pem", workspace / "key.pem", authority, leaf
        ):
            raise ValueError("certificate.role_pair_invalid")
        directory(certificate.parent, uid, gid, directory_mode)
        shutil.copyfile(workspace / "cert.pem", certificate)
        if leaf["scope"] == "role-swamp":
            # Publish the staged Node-local key without retaining another copy.
            os.replace(workspace / "key.pem", key)
        else:
            shutil.copyfile(workspace / "key.pem", key)
            shutil.copyfile(workspace / "root.pem", root)
    directory(certificate.parent, uid, gid, directory_mode)
    files = (
        [certificate, key]
        if leaf["scope"] == "role-swamp"
        else [certificate, key, root]
    )
    for path in files:
        os.chown(path, uid, gid)
        path.chmod(file_mode)
    return {"applied": True}


if __name__ == "__main__":
    emit(publish)
