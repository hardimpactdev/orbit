#!/usr/bin/python3
"""Incus stand-in for the workspace Git reader: runs real Git on a local repository and fails closed on any argv that
is not fixed text or a hex object id, so no workspace name can reach a command line."""
import json
import os
import pathlib
import re
import subprocess
import sys
import time

fixture = pathlib.Path(os.environ["WORKSPACE_READ_FIXTURE"])
containers = json.loads((fixture / "containers.json").read_text())
args = sys.argv[1:]
log = fixture / "argv.log"
with log.open("a") as handle:
    handle.write(json.dumps(args) + "\n")

if args[0] == "query":
    match = re.fullmatch(r"/1\.0/instances/(instance-[1-9][0-9]*)/state\?project=(orbit-instances|orbit-tasks)", args[1])
    if not match or match.group(1) not in containers:
        sys.exit(1)
    print(json.dumps({"status": containers[match.group(1)]["status"]}))
    sys.exit(0)

prefix = ["exec", "--project", args[2], "--mode=non-interactive", args[4], "--", "/usr/bin/timeout", args[7], "/usr/sbin/runuser",
          "-u", "agent", "--", "/usr/bin/env", "-i", "HOME=/home/agent", "GIT_CONFIG_NOSYSTEM=1", "GIT_TERMINAL_PROMPT=0",
          "GIT_OPTIONAL_LOCKS=0", "/usr/bin/git", "-c", "safe.directory=/srv/workspace", "-c", "core.fsmonitor=false", "-c",
          "core.hooksPath=/dev/null", "-c", "core.untrackedCache=false", "--no-optional-locks", "-C", "/srv/workspace"]
assert args[:len(prefix)] == prefix and args[7].isdecimal(), args
fixed = {"for-each-ref", "--format=%(objectname) %(refname)", "refs/heads", "refs/stash", "log", "-g", "-z", "--format=%H %gd: %gs",
         "status", "--porcelain=v1", "--untracked-files=all", "--no-renames",
         "symbolic-ref", "-q", "--short", "HEAD", "rev-list", "--ignore-missing", "--no-walk", "--not", "--remotes", "rev-parse", "--is-inside-work-tree"}
for argument in args[len(prefix):]:
    if argument not in fixed and not re.fullmatch(r"[0-9a-f]{40}|[0-9a-f]{64}", argument):
        sys.exit(99)
container = containers[args[4]]
time.sleep(container.get("sleep", 0))
command = ["git", "-c", "core.hooksPath=/dev/null", "--no-optional-locks", "-C", container["repo"], *args[len(prefix):]]
result = subprocess.run(command, env={"PATH": "/usr/bin:/bin", "HOME": str(fixture), "GIT_CONFIG_NOSYSTEM": "1"},
                        stdin=subprocess.DEVNULL, capture_output=True, check=False)
sys.stdout.buffer.write(result.stdout)
sys.exit(result.returncode)
