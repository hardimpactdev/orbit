"""Whether a new version of a shared folder keeps everything the old version holds, byte for byte.

docs/reference/development-containers.md#shared-folders. A running process runs only what existed when it started. So a new
version that only adds files, such as a new part or a new program link, leaves every running process valid, and nothing needs a
restart. Anything the old version holds that the new one changes or lacks (a file's bytes or mode, a link's target, a type)
needs one. Prints `{"same": bool, "differs": [first paths]}`; links are compared, never followed.
"""

import filecmp
import json
import os
import stat
import sys
from pathlib import Path


def differences(old, new, limit=5):
    old, new = Path(old), Path(new)
    if not old.is_dir():
        return ["(the version in use is missing)"]
    found = []
    # os.walk follows no links: a link to a directory is compared as a link.
    for root, directories, files in os.walk(old):
        for name in sorted(directories + files):
            relative = (Path(root) / name).relative_to(old)
            before, after = old / relative, new / relative
            try:
                a, b = before.lstat(), after.lstat()
            except FileNotFoundError:
                found.append(str(relative))
            else:
                if stat.S_IFMT(a.st_mode) != stat.S_IFMT(b.st_mode) or stat.S_IMODE(a.st_mode) != stat.S_IMODE(b.st_mode):
                    found.append(str(relative))
                elif stat.S_ISLNK(a.st_mode) and os.readlink(before) != os.readlink(after):
                    found.append(str(relative))
                elif stat.S_ISREG(a.st_mode) and not filecmp.cmp(before, after, shallow=False):
                    found.append(str(relative))
            if len(found) >= limit:
                return found
    return found


if __name__ == "__main__":
    differs = differences(sys.argv[1], sys.argv[2])
    print(json.dumps({"same": not differs, "differs": differs}))
