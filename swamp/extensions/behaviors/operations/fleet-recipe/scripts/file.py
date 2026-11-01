"""Publish one admitted role file atomically; service control is a separate step."""

import grp
import json
import os
import pwd
import stat
import sys
import tempfile
from pathlib import Path

ROLE_INPUT = None


def apply(data):
    path = Path(data["path"])
    content = data["content"]
    if not isinstance(content, str):
        raise TypeError("Invalid role file content")
    content = content.encode()
    user, group = data["owner"].split(":")
    uid = int(user) if user.isdecimal() else pwd.getpwnam(user).pw_uid
    gid = int(group) if group.isdecimal() else grp.getgrnam(group).gr_gid
    mode = int(data["mode"], 8)
    if path.is_symlink():
        raise ValueError("Role file is not a regular file")
    try:
        before = path.read_bytes()
        metadata = path.stat()
        marker = data["marker"]
        if marker and not before.startswith(marker.encode()):
            raise ValueError("Role file is not managed")
        if before == content and (metadata.st_uid, metadata.st_gid, stat.S_IMODE(metadata.st_mode)) == (uid, gid, mode):
            return {"ready": True, "changed": False}
    except FileNotFoundError:
        pass
    path.parent.mkdir(parents=True, exist_ok=True)
    descriptor, candidate = tempfile.mkstemp(prefix=".orbit-role-", dir=path.parent)
    try:
        with os.fdopen(descriptor, "wb") as output:
            os.fchown(output.fileno(), uid, gid)
            os.fchmod(output.fileno(), mode)
            output.write(content)
            output.flush()
            os.fsync(output.fileno())
        os.replace(candidate, path)
    finally:
        Path(candidate).unlink(missing_ok=True)
    return {"ready": True, "changed": True}


if __name__ == "__main__":
    try:
        print(json.dumps(apply(ROLE_INPUT)))
    except (ValueError, KeyError, TypeError, OSError):
        print(json.dumps({"error_code": "role.file_failed"}))
        sys.exit(1)
