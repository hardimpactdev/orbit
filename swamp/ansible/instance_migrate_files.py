"""Record archived leaves and refuse untracked paths missing from that copy."""

import json
import os
import stat
import subprocess
import sys
import tarfile
from pathlib import Path


def record(archive, manifest):
    selected = Path(manifest).read_bytes().split(b"\0")[:-1]
    with tarfile.open(archive, encoding="utf-8", errors="surrogateescape") as contents:
        members = [(os.fsencode(item.name).removeprefix(b"./").rstrip(b"/"), item.isdir()) for item in contents]
    copied = {name for name, _ in members}
    directories = [name.rstrip(b"/") + b"/" for name in selected if name.endswith(b"/")]
    untracked = set(selected)
    count = sum(not directory and (name in untracked or any(name.startswith(prefix) for prefix in directories))
                for name, directory in members)
    return {"untracked_files_copied": count, "copied_paths": [name.hex() for name in sorted(copied)]}


def check(checkout, receipt):
    root = os.fsencode(checkout)
    selected = subprocess.check_output([
        "/usr/bin/git", "-c", "core.hooksPath=/dev/null", "-c", "core.fsmonitor=false", "-c", "safe.directory=*",
        "-C", checkout, "ls-files", "-z", "--others",
    ]).split(b"\0")[:-1]
    current = set()
    for name in selected:
        path = os.path.join(root, name)
        current.add(name.rstrip(b"/"))
        if stat.S_ISDIR(os.lstat(path).st_mode):
            for folder, directories, files in os.walk(path, followlinks=False):
                current.update(os.path.relpath(os.path.join(folder, child), root) for child in directories + files)
    copied = {bytes.fromhex(name) for name in receipt["copied_paths"]}
    added = sorted(current - copied)
    return {"count": len(added), "paths": [os.fsdecode(name) for name in added[:20]]}


if __name__ == "__main__":
    if sys.argv[1] == "record":
        result = record(sys.argv[2], sys.argv[3])
    # Only a repeated retirement can legitimately have no source checkout.
    elif sys.argv[1] == "retire" and not Path(sys.argv[2]).exists():
        result = {"count": 0, "paths": []}
    else:
        result = check(sys.argv[2], json.load(sys.stdin))
    print(json.dumps(result))
