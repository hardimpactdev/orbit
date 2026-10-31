"""Verify the existing observer identity without rotating or returning it."""

import hashlib
import json
import sys
from pathlib import Path

ROLE_INPUT = None
SECRET = Path("/etc/orbit/agent/secret")


def verify(expected):
    if not isinstance(expected, str) or len(expected) != 64:
        raise ValueError("Invalid observer identity")
    if hashlib.sha256(SECRET.read_bytes()).hexdigest() != expected:
        raise ValueError("Observer identity differs from admission")
    return {"ready": True, "changed": False}


if __name__ == "__main__":
    try:
        print(json.dumps(verify(sys.stdin.read())))
    except (ValueError, OSError):
        print('{"error_code":"agent.secret_mismatch"}')
        sys.exit(1)
