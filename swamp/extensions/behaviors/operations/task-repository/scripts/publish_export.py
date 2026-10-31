"""Export only Git objects reachable from the admitted workspace HEAD."""

import json
import sys
from pathlib import Path

from repository_common import emit, git


def export():
    value = json.load(sys.stdin)
    destination = str(Path(__file__).parent.parent / "candidate.bundle")
    git(value["checkout_path"], "bundle", "create", destination, "HEAD")
    return {"exported": True}


if __name__ == "__main__":
    emit(export)
