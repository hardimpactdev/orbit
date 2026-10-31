#!/usr/bin/env python3
"""Synthetic SSH boundary for the native retirement DAG; never opens a network connection."""

import hashlib
import json
import os
import stat
import sys
from pathlib import Path

args = sys.argv[1:]
key = Path(args[args.index("-i") + 1])
known = Path(
    next(
        value.split("=", 1)[1]
        for value in args
        if value.startswith("UserKnownHostsFile=")
    )
)
assert stat.S_IMODE(key.stat().st_mode) == 0o600
assert stat.S_IMODE(known.stat().st_mode) == 0o600
assert "ORBIT_SSH_IDENTITY" not in os.environ
assert hashlib.sha256(key.read_bytes()).hexdigest() == os.environ["FIXTURE_KEY_SHA256"]
assert known.read_text().startswith("orbit-removal-target ssh-ed25519 ")
assert "StrictHostKeyChecking=yes" in args
assert "GlobalKnownHostsFile=/dev/null" in args
assert "ConnectionAttempts=1" in args
program = sys.stdin.read() if "/usr/bin/python3 -" in args[-1] else ""
with Path(os.environ["FIXTURE_SSH_CALLS"]).open("a") as stream:
    stream.write(
        json.dumps(
            {
                "remote": args[-1],
                "program": program,
                "target": args[-2],
                "key": str(key),
            }
        )
        + "\n"
    )
if (
    os.environ.get("FIXTURE_FAIL_STOP") == "1"
    and args[-1] == "sudo -n /usr/bin/systemctl stop orbit-node-worker.service"
):
    raise SystemExit(1)
if "config.toml" in program:
    present = os.environ.get("FIXTURE_EMPTY_HOST") != "1"
    print(
        json.dumps(
            {
                "ready": True,
                "worker_present": present,
                "observer_present": present,
                "artifacts_present": present,
            }
        )
    )
raise SystemExit(3 if " is-active " in args[-1] else 0)
