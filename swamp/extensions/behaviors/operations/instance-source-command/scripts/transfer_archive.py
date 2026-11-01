"""Capture one private checkout archive, including its independent Git metadata."""
import json
import os
import tarfile
from pathlib import Path

SOURCE_INPUT = None


def capture(value):
    source = Path(value["path"])
    common, gitdir = Path(value["common"]), Path(value["git_directory"])
    if (common / "objects/info/alternates").exists():
        raise ValueError("instance.transfer_source_invalid")
    if gitdir == common:
        siblings = common / "worktrees"
        if siblings.is_dir() and any(siblings.iterdir()):
            raise ValueError("instance.transfer_source_shared")
    for lock in ("index.lock", "HEAD.lock", "config.lock", "shallow.lock"):
        if (gitdir / lock).exists() or (common / lock).exists():
            raise ValueError("instance.source_busy")
    archive = Path(value["archive"])
    fd = os.open(archive, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, "wb") as output, tarfile.open(fileobj=output, mode="w|") as bundle:
        def working(info):
            info.name = info.name.removeprefix("./")
            return None if info.name == ".git" or info.name.startswith(".git/") else info

        def metadata(info):
            parts = Path(info.name).parts
            if any(part in ("worktrees", "commondir", "gitdir", "config.worktree") or part.endswith(".lock") for part in parts[1:]):
                return None
            return info

        bundle.add(source, arcname=".", filter=working)
        bundle.add(common, arcname=".git", filter=metadata)
        if gitdir != common:
            for name in ("HEAD", "index", "ORIG_HEAD", "logs/HEAD"):
                path = gitdir / name
                if path.is_file():
                    bundle.add(path, arcname=".git/" + name, recursive=False)
    return {"captured": True}


if __name__ == "__main__":
    print(json.dumps(capture(SOURCE_INPUT)))
