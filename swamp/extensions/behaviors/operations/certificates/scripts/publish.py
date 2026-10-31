"""Publish the public signature using only the key already on this Node."""

import grp
import json
import os
import shutil

from local import CADDY, account, current_link, directory, emit, valid, write_signature


def publish(leaf):
    user, root, caddy = account(leaf)
    if valid(root, caddy, leaf):
        return {"applied": True}
    version = leaf["version"]
    candidate = root / "versions" / (version + ".candidate")
    published = root / "versions" / version
    # Resume a publication interrupted after the user pair was committed.
    source = published if published.is_dir() else candidate
    signed = json.loads(os.environ["ORBIT_CERTIFICATE_SIGNED"])
    write_signature(source, leaf, signed)
    for name in ("cert.pem", "root.pem"):
        os.chown(source / name, user.pw_uid, user.pw_gid)
    if source == candidate:
        candidate.rename(published)
    current_link(root, version)
    gid = grp.getgrnam("caddy").gr_gid
    for path in (CADDY, caddy, caddy / "versions", caddy / "versions" / version):
        directory(path, 0, gid, 0o750)
    for name, mode in (("cert.pem", 0o644), ("key.pem", 0o640)):
        path = caddy / "versions" / version / name
        shutil.copyfile(published / name, path)
        path.chmod(mode)
        os.chown(path, 0, gid)
    current_link(caddy, version)
    return {"applied": True}


if __name__ == "__main__":
    emit(publish)
