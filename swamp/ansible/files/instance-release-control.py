#!/usr/bin/python3
"""Run one fixed live-control family against the checked serving Instance while holding its switch lock."""
import os
import runpy
import subprocess
import sys
from pathlib import Path

LIB = Path("/usr/local/libexec")
FAMILIES = {"process", "schedule", "environment"}


def control(node, instance, sha, family, args, api, execute=subprocess.run):
    if family not in FAMILIES:
        raise ValueError("unknown production control family")
    current_fire = sha == "current" and family == "schedule" and args[:1] == ["fire"]
    release = api["Release"](instance, "0" * 40 if current_fire else sha, 1)
    if api["positive"](node) != api["positive"](release.node["node_id"]):
        raise ValueError("production control Node changed")
    with api["lock"](release.private / "lock"):
        inventory = api["read"](release.private / "inventory.json", {})
        state = api["read"](release.operation_file, {})
        if current_fire:
            sha = inventory.get("live")
            if not isinstance(sha, str) or not api["SHA"].fullmatch(sha):
                raise ValueError("no verified live release")
        if inventory.get("live") != sha or (state and not (state.get("retired") or state.get("cleaned"))):
            raise ValueError("production release changed or is switching")
        release.check_selection(sha)
        release.identity(sha)
        return execute([str(LIB / ("orbit-instance-control-" + family)), str(release.instance), sha,
                        release.container(sha), *args], check=False).returncode


if __name__ == "__main__":
    try:
        if os.geteuid() != 0 or len(sys.argv) < 6:
            raise ValueError("expected Node, Instance, commit, family and action")
        sys.exit(control(*sys.argv[1:5], sys.argv[5:], runpy.run_path(str(LIB / "orbit-instance-release-phase"))))
    except (KeyError, ValueError, OSError, subprocess.SubprocessError):
        sys.exit("deployment.live_control_unavailable: could not verify or control the selected release")
