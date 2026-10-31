"""Create the one canonical private Metrics service directory."""

import json
import os
from pathlib import Path

METRICS_INPUT = None


def apply(data):
    path = Path(data["directory"])
    path.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chown(path, 0, 0)
    path.chmod(0o700)
    return {"ready": True}


if __name__ == "__main__":
    print(json.dumps(apply(METRICS_INPUT)))
