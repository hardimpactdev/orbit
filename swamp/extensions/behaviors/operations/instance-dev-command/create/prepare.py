"""Prepare phase: account, destination, branch and ownership refusals before the first write.

The intent is argv[1]. Prints one public JSON object."""
import json
import os
import pwd
import re
import subprocess
import sys
from pathlib import Path
from urllib.parse import urlsplit


def repository_identity(origin):
    """The canonical source identity accepts HTTPS and Git SSH spelling."""
    if origin.startswith('git@'):
        match = re.fullmatch(r'git@([^:/\s]+):([^\s]+)', origin)
        if not match:
            raise ValueError('instance.source_identity_invalid')
        host, path = match.groups()
    else:
        parsed = urlsplit(origin)
        if parsed.scheme not in ('https', 'ssh') or not parsed.hostname or parsed.password or parsed.query or parsed.fragment or parsed.username not in (None, 'git'):
            raise ValueError('instance.source_identity_invalid')
        host, path = parsed.hostname, parsed.path
    path = path.strip('/').removesuffix('.git').rstrip('/')
    if not path:
        raise ValueError('instance.source_identity_invalid')
    return host.lower() + '/' + path


def origin(directory):
    result = subprocess.run(["/usr/bin/git", "-c", "core.hooksPath=/dev/null", "-C", str(directory), "config", "--get",
                             "remote.origin.url"], capture_output=True, text=True, check=False)
    if result.returncode not in (0, 1):
        raise ValueError("instance.source_identity_invalid")
    return result.stdout.strip()


def prepare(value):
    source = value["source"]
    checkout, root = Path(source["checkout"]), Path(source["root"])
    if not checkout.is_absolute() or root not in checkout.parents:
        raise ValueError("instance.source_invalid")
    if pwd.getpwnam(source["user"]).pw_uid != os.geteuid():
        raise ValueError("instance.source_ownership_mismatch")
    if any(path.is_symlink() for path in (checkout, *checkout.parents)):
        raise ValueError("instance.source_invalid")
    branch = value["resolve"]["branch"]
    result = subprocess.run(["/usr/bin/git", "check-ref-format", "--branch", branch], capture_output=True, check=False)
    if result.returncode:
        raise ValueError("instance.branch_invalid")
    protocols = value["resolve"]["protocols"]
    if not protocols or not all(isinstance(item, str) and re.fullmatch(r"[a-z]+", item) and item != "ext" for item in protocols):
        raise ValueError("instance.source_invalid")
    identity = repository_identity(source["repository"])
    seed, commit = source.get("seed_repository"), value["resolve"].get("seed_commit")
    if bool(seed) != bool(commit):
        raise ValueError("instance.source_invalid")
    if seed and not checkout.exists():
        if Path(seed).is_symlink() or repository_identity(origin(seed)) != identity:
            raise ValueError("instance.source_identity_invalid")
        if not re.fullmatch(r"[a-f0-9]{40}(?:[a-f0-9]{24})?", commit):
            raise ValueError("instance.source_identity_invalid")
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
        existing = origin(checkout)
        if existing and repository_identity(existing) != identity:
            raise ValueError("instance.source_identity_invalid")
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
