#!/usr/bin/python3
"""Install the pinned entry before releases depend on it. No load or restart.

Run from the existing privileged publication path on Node 209 with one reviewed SHA.
The installed package survives subsequent source checkout changes.
"""
import argparse
import fcntl
import hashlib
import json
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
from pathlib import Path

CHECKOUT = Path("/home/orbit/apps/orbit-monorepo-staging/checkout")
LIBEXEC = Path("/usr/local/libexec")
LOCK = Path("/home/orbit/.local/state/orbit-swamp/serve-load.lock")
FILES = ("release_load.py", "manifest_grants.py")


def require(value):
    if not value:
        raise RuntimeError("pinned_loader_install_refused")


def trusted_directory(path, owner):
    info = path.lstat()
    require(stat.S_ISDIR(info.st_mode) and info.st_uid == owner and info.st_mode & 0o022 == 0)


def git(*args):
    result = subprocess.run(["/usr/sbin/runuser", "-u", "orbit", "--", "git", "-C", str(CHECKOUT), *args],
                            capture_output=True, check=False, timeout=30)
    require(result.returncode == 0)
    return result.stdout


def install(sha, checkout, destination, read_git, *, owner=0):
    require(isinstance(sha, str) and re.fullmatch(r"[a-f0-9]{40}", sha))
    trusted_directory(destination, owner)
    require(read_git("rev-parse", "HEAD").decode().strip() == sha)
    require(not read_git("status", "--porcelain=v1", "--", "platform/swamp-load"))
    source = checkout / "platform/swamp-load"
    content = {}
    for name in FILES:
        path = source / name
        require(path.is_file() and not path.is_symlink())
        data = path.read_bytes()
        require(data == read_git("show", sha + ":platform/swamp-load/" + name))
        content[name] = data
    hashes = {name: hashlib.sha256(data).hexdigest() for name, data in content.items()}
    packages = destination / "orbit-swamp-release-load"
    packages.mkdir(mode=0o755, exist_ok=True)
    trusted_directory(packages, owner)
    package = packages / sha
    if package.exists():
        trusted_directory(package, owner)
        for name, data in content.items():
            path = package / name
            info = path.lstat()
            require(stat.S_ISREG(info.st_mode) and info.st_uid == owner and info.st_mode & 0o022 == 0
                    and path.read_bytes() == data)
    else:
        candidate = Path(tempfile.mkdtemp(prefix=".install-", dir=packages))
        try:
            for name, data in content.items():
                path = candidate / name
                with path.open("xb") as stream:
                    stream.write(data)
                    stream.flush()
                    os.fsync(stream.fileno())
                path.chmod(0o755 if name == "release_load.py" else 0o644)
            candidate.chmod(0o755)
            candidate.rename(package)
        finally:
            if candidate.exists():
                shutil.rmtree(candidate)
    entry = destination / "orbit-swamp-release-load.py"
    if entry.is_symlink():
        target = entry.readlink()
        require(entry.lstat().st_uid == owner and target.parent.parent == packages
                and re.fullmatch(r"[a-f0-9]{40}", target.parent.name) and target.name == "release_load.py")
    elif entry.exists():
        require(False)
    temporary = destination / (".orbit-swamp-release-load-" + str(os.getpid()))
    try:
        temporary.symlink_to(package / "release_load.py")
        temporary.replace(entry)
    finally:
        if temporary.is_symlink():
            temporary.unlink()
    descriptor = os.open(destination, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)
    return {"schema": "orbit.swamp.release-load-install.v1", "state": "installed", "source_sha": sha,
            "entry": str(entry), "files": hashes, "loads": 0, "restarts": 0, "release_dependency_enabled": False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--sha", required=True)
    args = parser.parse_args()
    try:
        require(os.geteuid() == 0)
        import yaml
        require(callable(yaml.safe_load))
        require(Path("/usr/local/libexec/behavior_versions.py").is_file())
        descriptor = os.open(LOCK, os.O_RDONLY | os.O_NOFOLLOW)
        with os.fdopen(descriptor, "r") as lock:
            require(stat.S_ISREG(os.fstat(lock.fileno()).st_mode))
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            receipt = install(args.sha, CHECKOUT, LIBEXEC, git)
        print(json.dumps(receipt, sort_keys=True))
        return 0
    except Exception:  # noqa: BLE001 -- do not expose privileged command output or source paths
        print(json.dumps({"state": "failed", "code": "pinned_loader_install_refused", "loads": 0, "restarts": 0}))
        return 1


if __name__ == "__main__":
    sys.exit(main())
