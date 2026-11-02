"""Verify the copied public binary and publish its immutable guest cache entry."""
import hashlib
import json
import os
import shutil
import sys
import tempfile
from pathlib import Path


def stage(path, sha):
    if hashlib.sha256(path.read_bytes()).hexdigest() != sha:
        raise ValueError("task_vm.pi_artifact_failed")
    cache = Path("/var/cache/orbit/pi")
    cache.mkdir(parents=True, exist_ok=True)
    fd, candidate = tempfile.mkstemp(prefix=".pi-", dir=cache)
    try:
        with os.fdopen(fd, "wb") as output, path.open("rb") as source:
            shutil.copyfileobj(source, output)
            output.flush()
            os.fchmod(output.fileno(), 0o644)
            os.fsync(output.fileno())
        os.replace(candidate, cache / (sha + ".bin"))
    finally:
        Path(candidate).unlink(missing_ok=True)
    return {"staged": True, "sha256": sha}


if __name__ == "__main__":
    try:
        print(json.dumps(stage(Path(sys.argv[1]), sys.argv[2])))
    except (OSError, ValueError, IndexError):
        print("task_vm.pi_artifact_failed", file=sys.stderr)
        raise SystemExit(1) from None
