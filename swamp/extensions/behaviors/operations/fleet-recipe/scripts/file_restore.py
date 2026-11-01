"""Restore one role file from its original run's local snapshot."""

import json
import os
import shutil
import sys
import tempfile
from pathlib import Path

ROLE_INPUT = None
SNAPSHOTS = Path("/var/lib/orbit/role-file-snapshots")


def restore(data):
    path = Path(data["path"])
    saved = SNAPSHOTS / data["run_id"] / data["path"].lstrip("/")
    metadata = json.loads(Path(str(saved) + ".json").read_text())
    if metadata is None:
        path.unlink(missing_ok=True)
    else:
        descriptor, candidate = tempfile.mkstemp(prefix=".orbit-role-restore-", dir=path.parent)
        try:
            with os.fdopen(descriptor, "wb") as output, saved.open("rb") as previous:
                shutil.copyfileobj(previous, output)
                os.fchown(output.fileno(), metadata["uid"], metadata["gid"])
                os.fchmod(output.fileno(), metadata["mode"])
                output.flush()
                os.fsync(output.fileno())
            os.replace(candidate, path)
        finally:
            Path(candidate).unlink(missing_ok=True)
    return {"ready": True, "changed": True}


if __name__ == "__main__":
    try:
        print(json.dumps(restore(ROLE_INPUT)))
    except (ValueError, KeyError, OSError):
        print('{"error_code":"role.file_restore_failed"}')
        sys.exit(1)
