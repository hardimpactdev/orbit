"""Prepare the existing observer identity type; return only its one-way digest."""

import hashlib
import json
import os
import re
import stat
import sys
from pathlib import Path

SECRET = Path("/etc/orbit/agent/secret")


def prepare(expected):
    if expected is not None and (
        not isinstance(expected, str) or re.fullmatch(r"[0-9a-f]{64}", expected) is None
    ):
        raise ValueError("agent.secret_mismatch")
    if not SECRET.exists():
        if expected:
            raise ValueError("agent.secret_missing")
        SECRET.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        if SECRET.parent.is_symlink():
            raise ValueError("agent.secret_path_invalid")
        fd = os.open(
            SECRET, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600
        )
        with os.fdopen(fd, "wb") as stream:
            stream.write(os.urandom(32).hex().encode())
            stream.flush()
            os.fsync(stream.fileno())
    metadata = SECRET.lstat()
    if (
        not stat.S_ISREG(metadata.st_mode)
        or metadata.st_uid != os.geteuid()
        or stat.S_IMODE(metadata.st_mode) != 0o600
    ):
        raise ValueError("agent.secret_path_invalid")
    with SECRET.open("rb") as stream:
        digest = hashlib.file_digest(stream, "sha256").hexdigest()
    if expected and digest != expected:
        raise ValueError("agent.secret_mismatch")
    return {"observer_ready": True, "observer_digest": digest}


if __name__ == "__main__":
    try:
        print(json.dumps(prepare(json.load(sys.stdin))))
    except (OSError, ValueError):
        print('{"error_code":"agent.secret_mismatch"}')
        sys.exit(1)
