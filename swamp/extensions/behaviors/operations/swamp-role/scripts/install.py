"""Install the admitted official Swamp binary without changing native state."""

import hashlib
import json
import os
import platform
import stat
import subprocess
import sys
import tempfile
from pathlib import Path

BINARY = Path("/usr/local/bin/swamp")
MEMINFO = Path("/proc/meminfo")


def digest(path):
    with path.open("rb") as stream:
        return hashlib.file_digest(stream, "sha256").hexdigest()


def preflight(spec):
    if spec["binary"] != str(BINARY) or platform.machine() != "x86_64":
        raise ValueError("swamp.role_install_invalid")
    rows = [
        line
        for line in MEMINFO.read_text().splitlines()
        if line.startswith("MemAvailable:")
    ]
    fields = rows[0].split() if len(rows) == 1 else []
    if (
        len(fields) != 3
        or fields[2] != "kB"
        or not fields[1].isdigit()
        or int(fields[1]) * 1024 < spec["minimum_memory_bytes"]
    ):
        raise ValueError("swamp.node_worker_memory_low")
    for path in (BINARY.parent.parent.parent, BINARY.parent.parent, BINARY.parent):
        metadata = path.lstat()
        if (
            not stat.S_ISDIR(metadata.st_mode)
            or metadata.st_uid != 0
            or metadata.st_mode & 0o022
        ):
            raise ValueError("swamp.role_install_directory_invalid")


def install(spec):
    preflight(spec)
    artifact = spec["artifact"]
    if BINARY.is_file() and not BINARY.is_symlink():
        metadata = BINARY.stat()
        if (
            metadata.st_uid == 0
            and metadata.st_gid == 0
            and stat.S_IMODE(metadata.st_mode) == 0o755
            and metadata.st_size == artifact["bytes"]
            and digest(BINARY) == artifact["sha256"]
        ):
            return {"ready": True, "changed": False, "sha256": artifact["sha256"]}
    with tempfile.TemporaryDirectory(
        prefix=".orbit-swamp-", dir=BINARY.parent
    ) as stage:
        staged = Path(stage) / "swamp"
        subprocess.run(
            [
                "/usr/bin/curl",
                "--fail",
                "--silent",
                "--show-error",
                "--location",
                "--proto",
                "=https",
                "--proto-redir",
                "=https",
                "--connect-timeout",
                "8",
                "--max-time",
                "300",
                "--output",
                str(staged),
                artifact["url"],
            ],
            check=True,
        )
        if (
            staged.stat().st_size != artifact["bytes"]
            or digest(staged) != artifact["sha256"]
        ):
            raise ValueError("swamp.role_install_digest_mismatch")
        os.chown(staged, 0, 0)
        staged.chmod(0o755)
        os.replace(staged, BINARY)
    return {"ready": True, "changed": True, "sha256": artifact["sha256"]}


if __name__ == "__main__":
    try:
        print(json.dumps(install(json.load(sys.stdin))))
    except (
        ValueError,
        OSError,
        KeyError,
        TypeError,
        subprocess.SubprocessError,
    ) as error:
        code = (
            str(error) if isinstance(error, ValueError) else "swamp.role_install_failed"
        )
        print(code, file=sys.stderr)
        raise SystemExit(1)
