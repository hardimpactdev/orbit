import pathlib
import sys

from public_output import emit, redact_text

counts = pathlib.Path(sys.argv[1]).read_text()
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
diff = redact_text(pathlib.Path(sys.argv[2]).read_text())
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
