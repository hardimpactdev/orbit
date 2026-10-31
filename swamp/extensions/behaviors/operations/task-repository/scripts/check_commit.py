"""Create the admitted checked candidate with one Git reference transaction."""

import json
import os
import subprocess
import sys

from repository_common import checkout, emit, git, snapshot


def commit():
    value = json.load(sys.stdin)
    checked = json.loads(sys.argv[1])
    root = checkout(value)
    ref = "refs/orbit/check-operations/" + str(value["operation_id"])
    existing = subprocess.run(
        ["git", "-C", root, "rev-parse", "--verify", "--quiet", ref + "^{commit}"],
        capture_output=True,
        text=True,
        check=False,
    )
    if existing.returncode == 0:
        candidate = existing.stdout.strip()
        return {
            "commit": candidate,
            "tree": git(root, "rev-parse", candidate + "^{tree}"),
        }
    if snapshot(root) != {"head": checked["head"], "tree": checked["tree"]}:
        return {"changed": True}
    env = {
        **os.environ,
        "GIT_AUTHOR_NAME": "Orbit",
        "GIT_AUTHOR_EMAIL": "orbit@users.noreply.github.com",
        "GIT_COMMITTER_NAME": "Orbit",
        "GIT_COMMITTER_EMAIL": "orbit@users.noreply.github.com",
    }
    candidate = subprocess.check_output(
        [
            "git",
            "-c",
            "core.hooksPath=/dev/null",
            "-C",
            root,
            "commit-tree",
            checked["tree"],
            "-p",
            checked["head"],
        ],
        input=value["message"],
        env=env,
        text=True,
    ).strip()
    transaction = (
        "start\nupdate HEAD "
        + candidate
        + " "
        + checked["head"]
        + "\ncreate "
        + ref
        + " "
        + candidate
        + "\nprepare\ncommit\n"
    )
    subprocess.run(
        ["git", "-C", root, "update-ref", "--stdin"],
        input=transaction,
        text=True,
        check=True,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    return {"commit": candidate, "tree": checked["tree"]}


if __name__ == "__main__":
    emit(commit)
