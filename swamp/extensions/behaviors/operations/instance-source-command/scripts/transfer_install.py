"""Install the native private archive as an independent development checkout."""
import json
import os
import tarfile
import tempfile
from pathlib import Path

SOURCE_INPUT = None


def install(value):
    destination = Path(value["path"])
    if destination.exists() or destination.is_symlink():
        raise ValueError("instance.transfer_destination_exists")
    destination.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix=".orbit-transfer-", dir=destination.parent) as temporary:
        staged = Path(temporary) / "checkout"
        staged.mkdir(mode=0o750)
        with tarfile.open(value["archive"], "r:") as bundle:
            bundle.extractall(staged, filter="data")
        if staged.stat().st_uid != os.getuid():
            raise ValueError("instance.transfer_source_owner_invalid")
        if destination.exists() or destination.is_symlink():
            raise ValueError("instance.transfer_destination_exists")
        staged.rename(destination)
    info = destination.stat()
    return {"device": info.st_dev, "inode": info.st_ino, "path": str(destination)}


if __name__ == "__main__":
    print(json.dumps(install(SOURCE_INPUT)))
