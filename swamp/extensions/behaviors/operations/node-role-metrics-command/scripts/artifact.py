"""Deliver one canonical Metrics binary after checksum verification."""

import hashlib
import json
import os
import sys
import tempfile
import urllib.request
from pathlib import Path

METRICS_INPUT = None


def install(data):
    path = Path(data["path"])
    if path.is_symlink() or (path.exists() and not path.is_file()):
        raise ValueError("Metrics file is not regular")
    if path.is_file():
        with path.open("rb") as stream:
            if hashlib.file_digest(stream, "sha256").hexdigest() == data["sha256"]:
                return {"ready": True}
    descriptor, candidate = tempfile.mkstemp(prefix=".orbit-metrics-", dir=path.parent)
    try:
        digest = hashlib.sha256()
        with os.fdopen(descriptor, "wb") as output, urllib.request.urlopen(data["url"], timeout=60) as response:
            while chunk := response.read(65536):
                output.write(chunk)
                digest.update(chunk)
            if digest.hexdigest() != data["sha256"]:
                raise ValueError("metrics.binary_checksum_mismatch")
            output.flush()
            os.fsync(output.fileno())
            os.fchmod(output.fileno(), 0o755)
        os.replace(candidate, path)
    finally:
        Path(candidate).unlink(missing_ok=True)
    return {"ready": True}


if __name__ == "__main__":
    try:
        print(json.dumps(install(METRICS_INPUT)))
    except (ValueError, OSError):
        print('{"error_code":"metrics.binary_install_failed"}')
        sys.exit(1)
