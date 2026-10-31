"""Validate every deliverable path before any Project command runs."""

import json
import os
import sys

from check_common import inside, overlay_path
from repository_common import Refusal, checkout, emit


def validate():
    value = json.load(sys.stdin)
    root = checkout(value)
    for item in value["deliverables"]:
        if item["type"] != "command":
            continue
        if not os.path.isdir(inside(root, item.get("directory", "."))):
            raise Refusal("tasks.deliverable_invalid")
        for relative in item.get("paths", []):
            path = overlay_path(root, relative)
            if not os.path.isfile(path) or os.path.islink(path):
                raise Refusal("tasks.deliverable_invalid")
    return {"valid": True}


if __name__ == "__main__":
    emit(validate)
