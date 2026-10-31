"""Converge one hibernation directory, preserving protected ancestor modes."""

import grp
import json
import os
import stat
import sys

ROLE_INPUT = None
PROTECTED = {"/dev", "/dev/shm", "/etc", "/proc", "/run", "/sys", "/tmp", "/var", "/var/tmp"}
PATHS = {"/dev/shm/orbit/hibernation": 0o755, "/data/caddy/orbit/hibernation": 0o2775}


def apply(data):
    path = data["path"]
    mode = PATHS[path]
    gid = grp.getgrnam("caddy").gr_gid
    current = path
    while current != "/":
        if os.path.lexists(current) and not stat.S_ISDIR(os.lstat(current).st_mode):
            raise ValueError("Directory is not a real path")
        current = os.path.dirname(current)
    ancestors, parent = [], os.path.dirname(path)
    while parent != "/" and parent not in PROTECTED:
        ancestors.append(parent)
        parent = os.path.dirname(parent)
    changed = False
    for directory in reversed(ancestors):
        if not os.path.isdir(directory):
            os.mkdir(directory, 0o755)
            changed = True
        if stat.S_IMODE(os.stat(directory).st_mode) != 0o755:
            os.chmod(directory, 0o755)
            changed = True
    if not os.path.isdir(path):
        os.mkdir(path, 0o755)
        changed = True
    info = os.stat(path)
    if (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)) != (0, gid, mode):
        os.chown(path, 0, gid)
        os.chmod(path, mode)
        changed = True
    return {"ready": True, "changed": changed}


if __name__ == "__main__":
    try:
        print(json.dumps(apply(ROLE_INPUT)))
    except (ValueError, KeyError, OSError):
        print(json.dumps({"error_code": "hibernation.node_failed"}))
        sys.exit(1)
