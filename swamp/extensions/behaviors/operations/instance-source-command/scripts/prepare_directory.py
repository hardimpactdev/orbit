"""Prepare one admitted directory and retain the existing source-prepare ownership marker."""
import json
import os
from pathlib import Path

SOURCE_INPUT = None


def prepare(value):
    source = value["source"]
    checkout, root = Path(source["checkout"]), Path(source["root"])
    marker_id = source.get("prepare_id")
    seeded = source.get("seed_repository") and value["resolve"].get("seed_commit")
    if root not in checkout.parents or checkout.is_symlink():
        raise ValueError("instance.source_invalid")
    if checkout.exists():
        info = checkout.stat()
        gitdir = checkout / ".git"
        if gitdir.is_file():
            gitdir = (checkout / gitdir.read_text().removeprefix("gitdir: ").strip()).resolve()
        if not checkout.is_dir() or not gitdir.is_dir() or info.st_uid != os.getuid():
            raise ValueError("instance.source_invalid")
        marker = gitdir / "orbit-source-prepare"
        expected = f"{marker_id}:{info.st_dev}:{info.st_ino}\n"
        if marker_id and (not marker.is_file() or marker.is_symlink() or marker.read_text() != expected):
            raise ValueError("instance.source_identity_changed")
        return {"created": not (gitdir / "HEAD").is_file(), "owned": marker_id is not None}
    if seeded:
        checkout.parent.mkdir(parents=True, exist_ok=True)
    else:
        checkout.mkdir(parents=True, mode=0o755)
        gitdir = checkout / ".git"
        gitdir.mkdir()
        if marker_id:
            info = checkout.stat()
            marker = gitdir / "orbit-source-prepare"
            marker.write_text(f"{marker_id}:{info.st_dev}:{info.st_ino}\n")
            marker.chmod(0o600)
    return {"created": True, "owned": marker_id is not None}


if __name__ == "__main__":
    print(json.dumps(prepare(SOURCE_INPUT)))
