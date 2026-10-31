"""Prepare one isolated start tree with dependencies and declared test overlays."""

import io
import json
import os
import shutil
import subprocess
import sys
import tarfile
from pathlib import Path

from check_common import inside, overlay_path
from repository_common import Refusal, checkout, emit


def prepare():
    value = json.load(sys.stdin)
    item = json.loads(sys.argv[1])
    root = checkout(value)
    base = value["base"]
    destination = str(Path(__file__).parent.parent / ("base-" + sys.argv[2]))
    if not base or base.startswith("-") or any(c.isspace() for c in base):
        raise Refusal("tasks.deliverable_invalid")
    shutil.rmtree(destination, ignore_errors=True)
    os.makedirs(destination)
    archive = subprocess.check_output(
        ["git", "-C", root, "archive", base], stderr=subprocess.DEVNULL
    )
    with tarfile.open(fileobj=io.BytesIO(archive)) as tree:
        tree.extractall(destination, filter="data")
    for path, directories, _ in os.walk(root):
        directories[:] = [
            name
            for name in directories
            if name not in {".git", "vendor", "node_modules"}
        ]
        for name in ("vendor", "node_modules"):
            source = os.path.join(path, name)
            if os.path.isdir(source):
                target = os.path.join(destination, os.path.relpath(source, root))
                shutil.rmtree(target, ignore_errors=True)
                shutil.copytree(source, target, symlinks=True)
    for relative in item.get("paths", []):
        source = overlay_path(root, relative)
        target = overlay_path(destination, relative)
        if not os.path.isfile(source) or os.path.islink(source):
            raise Refusal("tasks.deliverable_invalid")
        os.makedirs(os.path.dirname(target), exist_ok=True)
        shutil.copy2(source, target)
    if not os.path.isdir(inside(destination, item.get("directory", "."))):
        raise Refusal("tasks.deliverable_invalid")
    return {"checkout_path": destination}


if __name__ == "__main__":
    emit(prepare)
