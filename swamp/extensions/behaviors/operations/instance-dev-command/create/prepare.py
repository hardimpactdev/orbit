"""Legacy create preparation: account, destination, branch and ownership checks."""
import json
import os
import pwd
import re
import subprocess
import sys
from pathlib import Path


def prepare(value):
    source = value["source"]
    checkout, root = Path(source["checkout"]), Path(source["root"])
    if not checkout.is_absolute() or root not in checkout.parents:
        raise ValueError("instance.source_invalid")
    if pwd.getpwnam(source["user"]).pw_uid != os.geteuid():
        raise ValueError("instance.source_ownership_mismatch")
    if any(path.is_symlink() for path in (checkout, *checkout.parents)):
        raise ValueError("instance.source_invalid")
    branch = value["resolve"].get("branch_override") or (
        value["resolve"]["default_branch"] if value["instance_name"] == "default" else value["instance_name"])
    result = subprocess.run(["/usr/bin/git", "check-ref-format", "--branch", branch], capture_output=True, check=False)
    if result.returncode:
        raise ValueError("instance.branch_invalid")
    seed, commit = source.get("seed_repository"), value["resolve"].get("seed_commit")
    if bool(seed) != bool(commit):
        raise ValueError("instance.source_invalid")
    if seed and not value.get("retained_source"):
        inventory = subprocess.run(["/usr/bin/git", "-c", "core.hooksPath=/dev/null", "-C", seed,
                                    "worktree", "list", "--porcelain", "-z"], capture_output=True, check=True).stdout
        for entry in inventory.split(b"\0\0"):
            fields = entry.split(b"\0")
            if os.fsencode("branch refs/heads/" + branch) in fields and os.fsencode("worktree " + str(checkout)) not in fields:
                raise ValueError("instance.branch_in_use")
    if checkout.exists():
        gitdir = checkout / ".git"
        if gitdir.is_file() and not gitdir.is_symlink():
            gitdir = (checkout / gitdir.read_text().removeprefix("gitdir: ").strip()).resolve()
        info = checkout.stat()
        marker = gitdir / "orbit-source-prepare"
        expected = f"{source['prepare_id']}:{info.st_dev}:{info.st_ino}\n"
        if not checkout.is_dir() or info.st_uid != os.geteuid() or not gitdir.is_dir() or gitdir.is_symlink():
            raise ValueError("instance.source_ownership_mismatch")
        if not marker.is_file() or marker.is_symlink() or marker.read_text() != expected:
            raise ValueError("instance.source_identity_changed")
        return {"ready": True, "created": False}
    checkout.parent.mkdir(parents=True, exist_ok=True)
    if not seed:
        checkout.mkdir(mode=0o755)
        gitdir = checkout / ".git"
        gitdir.mkdir()
        info = checkout.stat()
        marker = gitdir / "orbit-source-prepare"
        marker.write_text(f"{source['prepare_id']}:{info.st_dev}:{info.st_ino}\n")
        marker.chmod(0o600)
    return {"ready": True, "created": True}


if __name__ == "__main__":
    try:
        print(json.dumps(prepare(json.loads(sys.argv[1]))))
    except ValueError as error:
        code = str(error)
        if not re.fullmatch(r"(?:instance|env|app-dev)\.[a-z_]+", code):
            code = "instance.create_source_failed"
        print(json.dumps({"ready": False, "error_code": code}))
        sys.exit(1)
