"""Observe the admitted release directory once; never prepare or repair a release."""

import os
from pathlib import Path

from process_common import Refused, main


def check(value):
    path = value.get("directory")
    if path is not None:
        if not isinstance(path, str) or not path.startswith("/") or "\0" in path:
            raise Refused("process.invalid")
        if not Path(path).is_dir():
            raise Refused("process.release_unavailable")
    return {"ready": True}


if __name__ == "__main__":
    os.environ.pop("ORBIT_PROCESS_MATERIAL", None)
    main(check)
