"""Restrict the registered environment file without following a symlink."""
import json
import os
import stat
from pathlib import Path

SOURCE_INPUT = None


def protect(value):
    path = Path(value["application"]) / ".env"
    if path.exists() or path.is_symlink():
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
        try:
            info = os.fstat(fd)
            if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid():
                raise ValueError("instance.source_owner_invalid")
            os.fchmod(fd, stat.S_IMODE(info.st_mode) & ~0o007)
        finally:
            os.close(fd)
    return {"protected": True}


if __name__ == "__main__":
    print(json.dumps(protect(SOURCE_INPUT)))
