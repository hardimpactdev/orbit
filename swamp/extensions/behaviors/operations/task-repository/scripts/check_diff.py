"""Observe the subtask diff against its immutable base; no command execution."""

import json
import sys

from repository_common import checkout, emit, git


def observe():
    value = json.load(sys.stdin)
    if not value["base"] and len(sys.argv) < 3:
        return {"diff": None}
    before = json.loads(sys.argv[1])
    start = value["base"]
    if len(sys.argv) > 2:
        start = before["tree"]
        before = json.loads(sys.argv[2])
    return {
        "diff": [
            {"status": line.partition("\t")[0][:1], "path": line.partition("\t")[2]}
            for line in git(
                checkout(value),
                "diff-tree",
                "-r",
                "--no-renames",
                "--name-status",
                start,
                before["tree"],
            ).splitlines()
            if "\t" in line
        ]
    }


if __name__ == "__main__":
    emit(observe)
