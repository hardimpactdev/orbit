"""Atomically choose restore or retirement for one original migration."""

import datetime
import json
import os
import sys
import tempfile
from pathlib import Path


def claim(path: Path, side: str, owner: str) -> bool:
    """Publish a complete claim once; a crash before effects is safe to rerun."""
    value = {
        "side": side,
        "owner": owner,
        "at": datetime.datetime.now(datetime.UTC).isoformat(),
    }
    # link() is the single atomic create: neither a partial JSON file nor an
    # overwritten winner can be visible, even if this process dies.
    with tempfile.NamedTemporaryFile(dir=path.parent, prefix=".migrate-claim-") as stage:
        stage.write(json.dumps(value).encode())
        stage.flush()
        os.fsync(stage.fileno())
        try:
            os.link(stage.name, path)
        except FileExistsError:
            previous = json.loads(path.read_text())
            if previous.get("side") != side or previous.get("owner") != owner:
                raise ValueError("instance.migrate_restore_unsafe" if side == "restore" else "instance.migrate_superseded") from None
            return False
    return True


if __name__ == "__main__":
    try:
        changed = claim(Path(sys.argv[1]), sys.argv[2], sys.argv[3])
    except ValueError as error:
        print(str(error))
        sys.exit(1)
    print(json.dumps({"changed": changed}))
