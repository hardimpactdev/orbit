"""One application prerequisite directory; never change its existing ancestors."""

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
    scope = data["scope"]
    paths = {
        "apps": Path(data["home"]) / "apps",
        "composer_parent": COMPOSER.parent,
        "composer_home": COMPOSER,
        "composer_vendor": COMPOSER / "vendor",
        "composer_bin": COMPOSER / "vendor/bin",
    }
    path = paths[scope]
    uid, gid = (0, 0) if scope == "composer_parent" else (account.pw_uid, group.gr_gid)
    try:
        before = path.lstat()
        if not stat.S_ISDIR(before.st_mode) or (before.st_uid, before.st_gid) != (uid, gid):
            raise ValueError("Prerequisite directory belongs to another owner")
    except FileNotFoundError:
        before = None
    changed = before is None or stat.S_IMODE(before.st_mode) != 0o755
    if before is None:
        path.mkdir(mode=0o755)
        os.chown(path, uid, gid)
    if changed:
        os.chmod(path, 0o755)
    return {"ready": True, "changed": changed}


if __name__ == "__main__":
    try:
        print(json.dumps(apply(ROLE_INPUT)))
    except (ValueError, KeyError, OSError):
        print(json.dumps({"error_code": "role.prerequisite_conflict"}))
        sys.exit(1)
