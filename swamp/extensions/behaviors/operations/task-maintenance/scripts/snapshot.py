"""Observe checkout changes against the admitted provision base, including new files."""

import json
import subprocess
import sys

from workspace_files import inspect


def changes(value):
    root = inspect({**value, "path": "."})["directory"]
    prefix = [
        "git",
        "-c",
        "core.hooksPath=/dev/null",
        "-c",
        "core.fsmonitor=false",
        "-C",
        root,
    ]
    tracked = subprocess.run(
        [*prefix, "diff", "--quiet", value["base"], "--"], check=False
    )
    if tracked.returncode not in (0, 1):
        raise RuntimeError("maintenance_base_unavailable")
    untracked = subprocess.check_output(
        [*prefix, "ls-files", "--others", "--exclude-standard", "-z"]
    )
    return {"changed": tracked.returncode == 1 or bool(untracked)}


if __name__ == "__main__":
    print(json.dumps(changes(json.load(sys.stdin))), flush=True)
