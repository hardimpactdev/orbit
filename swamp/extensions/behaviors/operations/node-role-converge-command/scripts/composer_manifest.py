"""Create a missing shared Composer manifest without changing existing intent."""

import grp
import json
import os
import pwd
import stat
import sys
from pathlib import Path

ROLE_INPUT = None
COMPOSER = Path("/opt/orbit/composer")


def apply(data):
    account = pwd.getpwnam(data["user"])
    group = grp.getgrnam(data["group"])
    path = COMPOSER / "composer.json"
    try:
        before = path.lstat()
        if not stat.S_ISREG(before.st_mode) or (before.st_uid, before.st_gid) != (account.pw_uid, group.gr_gid):
            raise ValueError("Composer manifest belongs to another owner")
        return {"ready": True, "changed": False}
    except FileNotFoundError:
        pass
    with path.open("x") as output:
        output.write('{"require":{}}\n')
        os.fchown(output.fileno(), account.pw_uid, group.gr_gid)
        os.fchmod(output.fileno(), 0o644)
    return {"ready": True, "changed": True}


if __name__ == "__main__":
    try:
        print(json.dumps(apply(ROLE_INPUT)))
    except (ValueError, KeyError, OSError):
        print(json.dumps({"error_code": "role.prerequisite_conflict"}))
        sys.exit(1)
