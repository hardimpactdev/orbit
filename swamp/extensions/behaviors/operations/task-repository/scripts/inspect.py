"""Validate the one tracked entrypoint and admitted analyzer provenance."""

import json
import sys

from repository_common import Refusal, checkout, emit, executable, git


def inspect():
    value = json.load(sys.stdin)
    root = checkout(value)
    analysis = "plan_sha256" in value
    relative = "bin/task-analyze" if analysis else value["script"]
    try:
        digest = executable(root, relative)
    except Refusal:
        if analysis:
            raise Refusal("tasks.analysis_unavailable") from None
        raise
    if analysis and (
        git(root, "rev-parse", "--verify", value["base"] + "^{commit}") != value["base"]
        or git(root, "symbolic-ref", "--short", "HEAD") != value["branch"]
        or git(root, "remote", "get-url", "origin") != value["repository"]
    ):
        raise Refusal("tasks.analysis_stale")
    if analysis:
        git(root, "merge-base", "--is-ancestor", value["base"], "HEAD")
    return {"entrypoint_sha256": digest}


if __name__ == "__main__":
    emit(inspect)
