"""Public intent and fixed read commands; official SSH owns execution."""
import json
import os
import pwd
import subprocess
import sys
from pathlib import Path  # noqa: F401 -- exported to manager queries

# The public input arrives as argv JSON, never as script source.
INPUT = json.loads(sys.argv[1])
ACCOUNT = pwd.getpwnam(INPUT["user"])
ENV = {"HOME": ACCOUNT.pw_dir, "USER": ACCOUNT.pw_name, "LOGNAME": ACCOUNT.pw_name,
       "PATH": "/usr/local/bin:/usr/bin:/bin", "LC_ALL": "C"}

def query(argv, env=None, check=True):
    if ACCOUNT.pw_uid == 0 or os.geteuid() != ACCOUNT.pw_uid:
        raise ValueError("managed account required")
    return subprocess.run(argv, env={**ENV, **(env or {})}, cwd=ACCOUNT.pw_dir,
                          stdin=subprocess.DEVNULL, capture_output=True, text=True, check=check)

def finish(observe):
    try:
        value = observe()
    except (OSError, ValueError, TypeError, KeyError, IndexError, subprocess.SubprocessError):
        value = {"error_code": "tool.version_probe_failed"}
    print(json.dumps(value, separators=(",", ":")))
