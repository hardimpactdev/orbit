"""Remove an initially absent Metrics directory only after its files are restored."""

import json
from pathlib import Path

METRICS_INPUT = None


def remove(data):
    path = Path(data["directory"])
    if path.exists():
        path.rmdir()
    return {"ready": True}


if __name__ == "__main__":
    print(json.dumps(remove(METRICS_INPUT)))
