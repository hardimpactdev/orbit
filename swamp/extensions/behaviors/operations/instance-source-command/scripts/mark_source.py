"""Retain the source-prepare marker after native Git worktree preparation."""
import json
from pathlib import Path

SOURCE_INPUT = None


def mark(value):
    path = Path(value["checkout"])
    gitdir = Path(value["git_directory"].strip())
    if value["prepare_id"]:
        info = path.stat()
        marker = gitdir / "orbit-source-prepare"
        expected = f'{value["prepare_id"]}:{info.st_dev}:{info.st_ino}\n'
        if marker.exists() and (marker.is_symlink() or marker.read_text() != expected):
            raise ValueError("instance.source_identity_changed")
        marker.write_text(expected)
        marker.chmod(0o600)
    return {"ready": True}


if __name__ == "__main__":
    print(json.dumps(mark(SOURCE_INPUT)))
