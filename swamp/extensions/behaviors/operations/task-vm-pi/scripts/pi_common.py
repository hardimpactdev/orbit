"""Canonical Pi paths and safe filesystem operations for the placed actions."""
import hashlib
import json
import os
import pathlib
import pwd
import re
import secrets
import stat
import sys

HOME = pathlib.Path("/home/orbit")
CACHE = pathlib.Path("/var/cache/orbit/pi")
MAX_BYTES = 256 * 1024 * 1024
ERROR = "task_vm.pi_artifact_failed"


def metadata(path, uid, directory=False):
    try:
        info = path.lstat()
    except FileNotFoundError:
        return None
    if (info.st_uid != uid or info.st_mode & 0o022
            or not (stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode))):
        raise ValueError()
    return info


def account():
    who = pwd.getpwnam("orbit")
    if who.pw_uid < 1 or pathlib.Path(who.pw_dir) != HOME or metadata(HOME, who.pw_uid, True) is None:
        raise ValueError()
    for path in reversed(HOME.parents):
        info = path.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_mode & 0o022 or info.st_uid not in (0, os.geteuid()):
            raise ValueError()
    return who


def opened(path, uid):
    if metadata(path, uid) is None:
        raise ValueError()
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    info = os.fstat(fd)
    if not stat.S_ISREG(info.st_mode) or info.st_uid != uid or info.st_mode & 0o022:
        os.close(fd)
        raise ValueError()
    return fd


def digest(fd):
    os.lseek(fd, 0, os.SEEK_SET)
    total = 0
    value = hashlib.sha256()
    while block := os.read(fd, 1024 * 1024):
        total += len(block)
        if total > MAX_BYTES:
            raise ValueError()
        value.update(block)
    if total == 0:
        raise ValueError()
    return value.hexdigest()


def current(sha):
    who = account()
    for path in (HOME / ".local", HOME / ".local/bin"):
        metadata(path, who.pw_uid, True)
    target = HOME / ".local/bin/pi-server"
    info = metadata(target, who.pw_uid)
    if info is None or info.st_size == 0 or stat.S_IMODE(info.st_mode) != 0o755 or info.st_gid != who.pw_gid:
        return False
    fd = opened(target, who.pw_uid)
    try:
        return digest(fd) == sha
    finally:
        os.close(fd)


def inspect(vm, sha):
    return {"task_vm_id": vm, "sha256": sha, "current": current(sha)}


def install(vm, sha):
    if current(sha):
        return {"task_vm_id": vm, "sha256": sha, "installed": True, "changed": False}
    who = account()
    for path in (*reversed(CACHE.parents), CACHE):
        info = path.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid not in (0, os.geteuid()) or info.st_mode & 0o022:
            raise ValueError()
    source = opened(CACHE / (sha + ".bin"), os.geteuid())
    candidate = None
    try:
        if digest(source) != sha:
            raise ValueError()
        for path in (HOME / ".local", HOME / ".local/bin"):
            if metadata(path, who.pw_uid, True) is None:
                path.mkdir(mode=0o755)
            os.chown(path, who.pw_uid, who.pw_gid)
            os.chmod(path, 0o755)
        target = HOME / ".local/bin/pi-server"
        candidate = target.with_name(".orbit-pi-" + secrets.token_hex(12))
        fd = os.open(candidate, os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        try:
            os.lseek(source, 0, os.SEEK_SET)
            total = 0
            while block := os.read(source, 1024 * 1024):
                total += len(block)
                if total > MAX_BYTES:
                    raise ValueError()
                pending = memoryview(block)
                while pending:
                    pending = pending[os.write(fd, pending):]
            if digest(fd) != sha:
                raise ValueError()
            os.fchown(fd, who.pw_uid, who.pw_gid)
            os.fchmod(fd, 0o755)
            os.fsync(fd)
            os.replace(candidate, target)
        finally:
            os.close(fd)
    finally:
        os.close(source)
        if candidate is not None:
            candidate.unlink(missing_ok=True)
    return {"task_vm_id": vm, "sha256": sha, "installed": True, "changed": True}


def verify(vm, sha):
    if not current(sha):
        raise ValueError()
    return {"task_vm_id": vm, "sha256": sha, "installed": True}


def run(action):
    try:
        if len(sys.argv) != 3 or not re.fullmatch("[1-9][0-9]*", sys.argv[1]) or not re.fullmatch("[a-f0-9]{64}", sys.argv[2]):
            raise ValueError()
        print(json.dumps(action(int(sys.argv[1]), sys.argv[2])))
    except Exception:  # noqa: BLE001 -- The command boundary exposes only one fixed error.
        print(ERROR, file=sys.stderr)
        raise SystemExit(1) from None
