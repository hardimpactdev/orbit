"""Publish the existing Pi bearer to its canonical VM-local file."""
import os
import sys
import tempfile
from pathlib import Path


def publish(value):
    directory = Path("/home/orbit/.pi/agent")
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    fd, candidate = tempfile.mkstemp(prefix=".token-", dir=directory)
    try:
        with os.fdopen(fd, "w") as output:
            output.write(value)
            output.flush()
            os.fchmod(output.fileno(), 0o600)
            os.fsync(output.fileno())
        os.replace(candidate, directory / "orbit-token")
    finally:
        Path(candidate).unlink(missing_ok=True)


if __name__ == "__main__":
    try:
        publish(sys.stdin.read().strip())
        print('{"configured":true}')
    except (OSError, ValueError):
        print("task_vm.runtime_failed", file=sys.stderr)
        raise SystemExit(1) from None
