"""Remove only the directory observed by this native transfer."""
import json
import shutil
from pathlib import Path

SOURCE_INPUT = None


def remove(value):
    proof = value["proof"]
    path = Path(proof["path"])
    info = path.lstat()
    if not path.is_dir() or path.is_symlink() or info.st_dev != proof["device"] or info.st_ino != proof["inode"]:
        raise ValueError("instance.transfer_source_changed")
    shutil.rmtree(path)
    return {"removed": True}


if __name__ == "__main__":
    print(json.dumps(remove(SOURCE_INPUT)))
