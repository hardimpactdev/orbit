import json
import subprocess
import sys

value = json.load(sys.stdin)


def git(*args):
    return subprocess.check_output(
        ["git", *args], cwd=value["checkout_path"], stderr=subprocess.DEVNULL, text=True
    ).strip()


git("reset", "--hard", value["candidate"])
git("clean", "-fd")
print(json.dumps({"head": git("rev-parse", "HEAD"), "tree": git("write-tree")}))
