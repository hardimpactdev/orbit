"""Remove one completed role file's local rollback copy."""

import json
import sys
from pathlib import Path

ROLE_INPUT = None
SNAPSHOTS = Path("/var/lib/orbit/role-file-snapshots")


def cleanup(data):
    saved = SNAPSHOTS / data["run_id"] / data["path"].lstrip("/")
    changed = saved.exists() or Path(str(saved) + ".json").exists()
    saved.unlink(missing_ok=True)
    Path(str(saved) + ".json").unlink(missing_ok=True)
    return {"ready": True, "changed": changed}


if __name__ == "__main__":
    try:
        print(json.dumps(cleanup(ROLE_INPUT)))
    except OSError:
        print('{"error_code":"role.file_snapshot_cleanup_failed"}')
        sys.exit(1)
