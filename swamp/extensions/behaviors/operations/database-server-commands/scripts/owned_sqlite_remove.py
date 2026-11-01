"""Remove only one admitted SQLite file under the SSH account's checkout."""

import json
import os
import re
import stat
import sys
from pathlib import Path


class Refused(Exception):
    pass


def path(value):
    if (
        not isinstance(value, str)
        or not value.startswith("/")
        or len(value) < 2
        or value.endswith("/")
        or re.search(r"[\x00\r\n]|(?:^|/)\.{1,2}(?:/|$)|//", value)
    ):
        raise Refused()
    return Path(value)


def remove(intent):
    checkout, selected = path(intent["checkout"]), path(intent["path"])
    if os.geteuid() == 0 or checkout not in selected.parents:
        raise Refused()
    current = Path("/")
    for part in selected.parent.parts[1:]:
        current /= part
        try:
            info = current.lstat()
        except FileNotFoundError:
            return {"backend": "absent"}
        if not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode):
            raise Refused()
    try:
        info = selected.lstat()
    except FileNotFoundError:
        return {"backend": "absent"}
    if (
        not stat.S_ISREG(info.st_mode)
        or stat.S_ISLNK(info.st_mode)
        or info.st_uid != os.geteuid()
    ):
        raise Refused()
    selected.unlink()
    return {"backend": "absent"}


if __name__ == "__main__":
    try:
        print(json.dumps(remove(json.loads(sys.argv[1]))))
    except (Refused, PermissionError, KeyError, TypeError, ValueError):
        print('{"error_code":"instance.sqlite_copy_refused"}')
        raise SystemExit(1) from None
    except Exception:
        print('{"error_code":"database.destroy_owned_outcome_unknown"}')
        raise SystemExit(1) from None
