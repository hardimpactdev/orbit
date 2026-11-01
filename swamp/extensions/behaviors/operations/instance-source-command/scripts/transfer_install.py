"""Install the native private archive as an independent development checkout."""
import json
import os
import tarfile
from pathlib import Path

SOURCE_INPUT = None


def install(value):
    destination = Path(value["path"])
    if destination.exists() or destination.is_symlink():
        raise ValueError("instance.transfer_destination_exists")
    destination.mkdir(parents=True, mode=0o750)
    with tarfile.open(value["archive"], "r:") as bundle:
        bundle.extractall(destination, filter="data")
    info = destination.stat()
    if info.st_uid != os.getuid():
        raise ValueError("instance.transfer_source_owner_invalid")
    return {"device": info.st_dev, "inode": info.st_ino, "path": str(destination)}


if __name__ == "__main__":
    print(json.dumps(install(SOURCE_INPUT)))
