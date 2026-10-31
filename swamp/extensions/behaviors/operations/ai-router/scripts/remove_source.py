import json
import os
import pathlib
import shutil
import stat
import sys

ROOT = pathlib.Path("/var/lib/orbit")


class Refused(Exception):
    pass


def directory(path):
    try:
        s = path.lstat()
    except FileNotFoundError:
        return False
    if not stat.S_ISDIR(s.st_mode):
        raise Refused()
    return True


def remove(value):
    if (
        type(value) is not dict
        or set(value) != {"retired"}
        or type(value["retired"]) is not bool
    ):
        raise Refused()
    for parent in [ROOT, *ROOT.parents]:
        directory(parent)
    target = ROOT / ("proxycli" if value["retired"] else "ai-router")
    if not directory(target):
        return {"removed": False}
    shutil.rmtree(target)
    return {"removed": True}


if __name__ == "__main__":
    try:
        result = remove(json.loads(os.environ["ORBIT_AI_SOURCE_INTENT"]))
    except Refused:
        print(json.dumps({"error_code": "ai-router.source_unsafe"}))
        sys.exit(1)
    except (OSError, ValueError, KeyError, TypeError):
        print(json.dumps({"error_code": "ai-router.source_remove_failed"}))
        sys.exit(1)
    else:
        print(json.dumps(result))
