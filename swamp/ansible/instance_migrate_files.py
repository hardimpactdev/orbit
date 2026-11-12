"""Count archived untracked leaves without reading the checkout after copying."""

import json
import os
import sys
import tarfile
from pathlib import Path


def count_untracked_leaves(archive, manifest):
    selected = set(Path(manifest).read_bytes().split(b"\0")[:-1])
    directories = [name.rstrip(b"/") + b"/" for name in selected if name.endswith(b"/")]
    count = 0
    with tarfile.open(archive, encoding="utf-8", errors="surrogateescape") as contents:
        for entry in contents:
            if entry.isdir():
                continue
            name = os.fsencode(entry.name).removeprefix(b"./")
            if name in selected or any(name.startswith(prefix) for prefix in directories):
                count += 1
    return count


if __name__ == "__main__":
    print(json.dumps({"untracked_files_copied": count_untracked_leaves(sys.argv[1], sys.argv[2])}))
