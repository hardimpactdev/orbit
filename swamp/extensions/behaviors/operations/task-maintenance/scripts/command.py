"""Execute exactly one policy-selected package command in its captured directory."""

import json
import subprocess
import sys

from workspace_files import inspect

if __name__ == "__main__":
    value = json.load(sys.stdin)
    observed = inspect(value)
    action = json.loads(sys.argv[1])
    process = subprocess.run(
        action["argv"], cwd=observed["directory"], capture_output=True, check=False
    )
    if len(process.stdout) > 8388608:
        raise ValueError("Maintenance command output is too large")
    if not action.get("report") and process.returncode != 0:
        raise SystemExit(process.returncode if process.returncode > 0 else 255)
    print(
        json.dumps(
            {
                "code": process.returncode,
                "stdout": process.stdout.decode(errors="replace"),
            }
        ),
        flush=True,
    )
