"""Read the source filesystem identity, or verify an unused transfer destination."""
import json
import os
from pathlib import Path

SOURCE_INPUT = None


def probe(value):
    path = Path(value["path"])
    if value["destination"]:
        if path.exists() or path.is_symlink():
            raise ValueError("instance.transfer_destination_exists")
        if not Path(value["root"]).is_dir() or Path(value["root"]) not in path.parents:
            raise ValueError("instance.transfer_destination_invalid")
        return {"ready": True}
    info = path.lstat()
    if path.is_symlink() or not path.is_dir() or path.resolve() != path or info.st_uid != os.getuid():
        raise ValueError("instance.transfer_source_invalid")
    return {"device": info.st_dev, "inode": info.st_ino, "path": str(path)}


if __name__ == "__main__":
    print(json.dumps(probe(SOURCE_INPUT)))
