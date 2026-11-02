import json
import subprocess
import sys

from public_output import emit, redact_text

value = json.load(sys.stdin)
before = json.loads(sys.argv[1])
base = before["head"] if value["role"] == "reviewer" else before["tree"]


def git(*args):
    return subprocess.check_output(
        ["git", *args], cwd=value["checkout_path"], stderr=subprocess.DEVNULL, text=True
    )


counts = git("diff", "--numstat", "--no-renames", "-z", base, "--")
files = []
for row in counts.split("\0"):
    if row:
        added, removed, name = row.split("\t", 2)
        files.append(
            {
                "path": name,
                "insertions": 0 if added == "-" else int(added),
                "deletions": 0 if removed == "-" else int(removed),
            }
        )
diff = redact_text(git("diff", "--no-renames", base, "--"))
emit(
    {
        "files": files[:500],
        "diff": diff[:65536],
        "files_complete": len(files) <= 500,
        "diff_available": len(diff) <= 65536,
        "summary": {
            "files": len(files),
            "insertions": sum(f["insertions"] for f in files),
            "deletions": sum(f["deletions"] for f in files),
        },
    }
)
