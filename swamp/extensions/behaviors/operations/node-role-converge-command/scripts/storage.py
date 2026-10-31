"""Prepare the admitted application storage root without adopting existing paths."""

import grp
import json
import os
import pwd
import stat
import sys

ROLE_INPUT = None


def apply(data):
    root = data["root"]
    uid = pwd.getpwnam(data["user"]).pw_uid
    gid = grp.getgrnam(data["group"]).gr_gid
    if not os.path.isabs(root) or any(part in (".", "..") for part in root.split("/")):
        raise ValueError("Invalid storage root")
    current, changed = "/", False
    for part in filter(None, root.split("/")):
        current = os.path.join(current, part)
        if os.path.lexists(current):
            if not stat.S_ISDIR(os.lstat(current).st_mode) or os.path.realpath(current) != current:
                raise ValueError("Storage root is not a real directory")
        else:
            if not data.get("create_missing", True):
                raise ValueError("Storage root must already exist")
            os.mkdir(current, 0o755)
            os.chown(current, uid, gid)
            changed = True
    metadata = os.lstat(root)
    mode = stat.S_IMODE(metadata.st_mode)
    if metadata.st_uid != uid or metadata.st_gid != gid or mode & 0o700 != 0o700 or mode & 0o022:
        raise ValueError("Existing storage root is not usable")
    return {"ready": True, "changed": changed}


if __name__ == "__main__":
    try:
        print(json.dumps(apply(ROLE_INPUT)))
    except (ValueError, KeyError, OSError):
        print(json.dumps({"error_code": "node.storage_root_unusable"}))
        sys.exit(1)
