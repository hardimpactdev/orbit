"""A default's untracked files that Git does not ignore go, after its deployment's checkout and before its seed.

Runs as the workspace's owner in the default container: `python3 -I -c <this> <workspace> <environment file>`. The `.env` is named
in the checkout's own `info/exclude` first, so it stays. Git runs with the task check's settings: no global or system configuration,
the agent's ignore file. It cleans in rounds while an untracked `.gitignore` hid more, at most 64, as a task copy's clean does.
Tracked files are never touched. Prints one JSON line with names only: `removed_count`, the first 20 `removed`, and `left_count`
when a file could not be removed.
"""

import json
import os
import re
import subprocess
import sys

CLEANED_NAMES = 20
CLEAN_ROUNDS = 64
AGENT_IGNORE = "/home/agent/.config/git/ignore"

workspace, environment_file = sys.argv[1], sys.argv[2]
env = dict(os.environ, GIT_CONFIG_GLOBAL="/dev/null", GIT_CONFIG_NOSYSTEM="1", LC_ALL="C")
git = ["git", "-c", "core.hooksPath=/dev/null", "-c", "core.fsmonitor=false", "-c", "safe.directory=" + workspace, "-c", "core.quotePath=false",
       "-c", "core.excludesFile=" + AGENT_IGNORE]


def run(*args):
    return subprocess.run(git + list(args), cwd=workspace, env=env, capture_output=True, text=True, errors="surrogateescape", check=False)


if environment_file.startswith(workspace + "/"):
    exclude = run("rev-parse", "--git-path", "info/exclude").stdout.strip()
    exclude = exclude if os.path.isabs(exclude) else os.path.join(workspace, exclude)
    entry = "/" + re.sub(r"([\\*?\[])", r"\\\1", environment_file[len(workspace) + 1:])
    held = open(exclude).read() if os.path.isfile(exclude) else ""
    if entry not in held.splitlines():
        os.makedirs(os.path.dirname(exclude), exist_ok=True)
        with open(exclude, "a") as stream:
            stream.write(("" if not held or held.endswith("\n") else "\n") + entry + "\n")

removed = []
for _ in range(CLEAN_ROUNDS):
    found = [line.removeprefix("Would remove ") for line in run("clean", "-d", "-ff", "-n").stdout.splitlines() if line.startswith("Would remove ")]
    if not found or set(found) <= set(removed):
        break
    run("clean", "-d", "-ff", "--quiet")
    removed += found
left = [line for line in run("status", "--porcelain", "--untracked-files=all").stdout.splitlines() if line.startswith("??")]
print(json.dumps({"removed_count": len(removed), "removed": sorted(removed)[:CLEANED_NAMES], **({"left_count": len(left)} if left else {})}))
