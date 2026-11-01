"""Close world access to one existing checkout environment file."""

import json
import os
import stat
import sys
from pathlib import Path

ROLE_INPUT = None


def apply(data):
    checkout = Path(data["checkout"])
    if not checkout.is_dir() or os.path.realpath(checkout) != str(checkout):
        return {"ready": True, "changed": False}
    environment = checkout / ".env"
    try:
        metadata = environment.lstat()
    except FileNotFoundError:
        return {"ready": True, "changed": False}
    changed = False
    if stat.S_ISREG(metadata.st_mode):
        mode = stat.S_IMODE(metadata.st_mode)
        changed = bool(mode & 0o007)
        if changed:
            os.chmod(environment, mode & ~0o007, follow_symlinks=False)
    return {"ready": True, "changed": changed}


if __name__ == "__main__":
    try:
        print(json.dumps(apply(ROLE_INPUT)))
    except OSError:
        print('{"error_code":"agent.checkout_not_closed"}')
        sys.exit(1)
