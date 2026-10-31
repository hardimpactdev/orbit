"""Reuse a current leaf or request a public CSR with a Node-local key."""

import os
import re

from local import account, directory, emit, request_for_key, valid


def request(leaf):
    user, root, caddy = account(leaf)
    if valid(root, caddy, leaf):
        return {"state": "current"}
    version = leaf["version"]
    if not re.fullmatch(r"[a-f0-9]{16}", version):
        raise ValueError("certificate.version_invalid")
    if (root / "versions" / version).exists():
        raise ValueError("certificate.version_conflict")
    for path in (root.parent.parent, root.parent, root):
        directory(path, user.pw_uid, user.pw_gid, 0o700)
    directory(root / "versions", user.pw_uid, user.pw_gid, 0o700)
    candidate = root / "versions" / (version + ".candidate")
    directory(candidate, user.pw_uid, user.pw_gid, 0o700)
    key = candidate / "key.pem"
    result = request_for_key(key, leaf["domain"])
    os.chown(key, user.pw_uid, user.pw_gid)
    return result


if __name__ == "__main__":
    emit(request)
