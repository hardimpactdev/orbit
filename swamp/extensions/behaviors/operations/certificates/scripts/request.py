"""Reuse a current leaf or request a public CSR with a Node-local key."""

import base64
import os
import re

from local import account, directory, emit, openssl, valid


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
    if not key.exists():
        openssl("genrsa", "-out", key, "2048")
    key.chmod(0o600)
    os.chown(key, user.pw_uid, user.pw_gid)
    csr = openssl("req", "-new", "-key", key, "-subj", "/CN=" + leaf["domain"])
    return {"state": "request", "request_base64": base64.b64encode(csr).decode()}


if __name__ == "__main__":
    emit(request)
