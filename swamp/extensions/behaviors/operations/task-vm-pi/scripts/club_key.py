"""Publish the proof VM's swamp-club collective key to its canonical VM-local file."""
import os
import sys
import tempfile
from pathlib import Path


def publish(value):
    if not value:
        raise ValueError("empty key")
    directory = Path("/home/orbit/.config/swamp")
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    fd, candidate = tempfile.mkstemp(prefix=".club-api-key-", dir=directory)
    try:
        with os.fdopen(fd, "w") as output:
            output.write(value)
            output.flush()
            os.fchmod(output.fileno(), 0o600)
            os.fsync(output.fileno())
        os.replace(candidate, directory / "club-api-key")
    finally:
        Path(candidate).unlink(missing_ok=True)


if __name__ == "__main__":
    try:
        publish(sys.stdin.read().strip())
        print('{"configured":true}')
    except (OSError, ValueError):
        print("task_vm.runtime_failed", file=sys.stderr)
        raise SystemExit(1) from None
