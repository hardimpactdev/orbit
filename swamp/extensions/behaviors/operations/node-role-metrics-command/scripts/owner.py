"""Read the existing Metrics file ownership marker before publication."""

import json
import stat
import sys
from pathlib import Path

METRICS_INPUT = None
ROOT = Path("/etc/orbit/metrics")


def verify(paths):
    for value in paths:
        path = Path(value)
        for current in [path, *path.parents]:
            try:
                metadata = current.lstat()
            except FileNotFoundError:
                continue
            if stat.S_ISLNK(metadata.st_mode) or (current != path and not stat.S_ISDIR(metadata.st_mode)):
                raise ValueError("metrics.configuration_ownership_drift")
            if current == path and not stat.S_ISREG(metadata.st_mode):
                raise ValueError("metrics.configuration_ownership_drift")
    if ROOT.exists() and (ROOT / ".orbit-owner").read_bytes() != b"metrics\n":
        raise ValueError("metrics.configuration_ownership_drift")
    return {"ready": True}


if __name__ == "__main__":
    try:
        print(json.dumps(verify(METRICS_INPUT)))
    except (ValueError, OSError):
        print('{"error_code":"metrics.configuration_ownership_drift"}')
        sys.exit(1)
