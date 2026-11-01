"""Read one configured maintenance directory without executing project code."""

import json
import os
import sys
from pathlib import Path


def inspect(value):
    root = os.path.abspath(value["checkout_path"])
    relative = value["path"]
    if relative != "." and (
        os.path.isabs(relative)
        or any(part in ("", ".", "..") for part in relative.split("/"))
    ):
        raise ValueError("Invalid maintenance path")
    directory = os.path.join(root, relative) if relative != "." else root
    if os.path.realpath(root) != root or os.path.realpath(directory) != directory:
        raise ValueError("Maintenance directory is not a captured regular path")
    if os.path.commonpath([root, directory]) != root:
        raise ValueError("Maintenance directory escapes checkout")
    files = {}
    for name in (
        "composer.json",
        "composer.lock",
        "package.json",
        "package-lock.json",
        "bun.lock",
    ):
        path = Path(directory, name)
        if path.exists():
            if path.is_symlink() or not path.is_file() or path.stat().st_size > 8388608:
                raise ValueError("Maintenance manifest is not a bounded regular file")
            files[name] = path.read_text()
    return {
        "directory": directory,
        "files": files,
        "node_modules": os.path.isdir(os.path.join(directory, "node_modules")),
    }


if __name__ == "__main__":
    print(json.dumps(inspect(json.load(sys.stdin))), flush=True)
