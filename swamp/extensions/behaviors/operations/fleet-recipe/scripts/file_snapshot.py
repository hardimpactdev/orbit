"""Snapshot one role file locally; private bytes never enter a native resource."""

import json
import shutil
import stat
import sys
from pathlib import Path

ROLE_INPUT = None
SNAPSHOTS = Path("/var/lib/orbit/role-file-snapshots")


def location(data):
    return SNAPSHOTS / data["run_id"] / data["path"].lstrip("/")


def snapshot(data):
    path = Path(data["path"])
    saved = location(data)
    metadata_path = Path(str(saved) + ".json")
    if metadata_path.exists():
        return {"ready": True, "changed": False}
    saved.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    try:
        metadata = path.lstat()
    except FileNotFoundError:
        metadata = None
    if metadata is not None:
        if not stat.S_ISREG(metadata.st_mode):
            raise ValueError("Role snapshot requires a regular file")
        shutil.copyfile(path, saved)
        saved.chmod(0o600)
    metadata_path.write_text(json.dumps(None if metadata is None else {
        "mode": stat.S_IMODE(metadata.st_mode), "uid": metadata.st_uid, "gid": metadata.st_gid,
    }))
    metadata_path.chmod(0o600)
    return {"ready": True, "changed": False}


if __name__ == "__main__":
    try:
        print(json.dumps(snapshot(ROLE_INPUT)))
    except (ValueError, OSError):
        print('{"error_code":"role.file_snapshot_failed"}')
        sys.exit(1)
