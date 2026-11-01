import fcntl
import json
import os
import stat
import subprocess
import tempfile
import time
from pathlib import Path

ROOT = Path("/")
LOCK = Path("/run/lock/orbit/caddy.lock")
UID, GID = 0, 0
CONTENT = b"# Managed by Orbit.\n[Unit]\nAfter=wg-quick@orbit.service\nWants=wg-quick@orbit.service\n"


class Refused(Exception):
    def __init__(self, code="service.ordering_failed"):
        self.code = code


def inspect(path, directory):
    try:
        meta = path.lstat()
    except FileNotFoundError:
        return None
    if meta.st_uid != UID or not (
        stat.S_ISDIR(meta.st_mode) if directory else stat.S_ISREG(meta.st_mode)
    ):
        raise Refused("service.ordering_unsafe")
    return meta


def control(args, active=False):
    result = subprocess.run(
        ["/usr/bin/systemctl", *args],
        stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        timeout=15,
        check=False,
    )
    if active and result.returncode in (0, 3):
        return result.returncode == 0
    if result.returncode:
        raise Refused()
    return True


def publish(path, content, mode, uid, gid):
    descriptor, candidate = tempfile.mkstemp(prefix=".orbit-vpn-", dir=path.parent)
    try:
        with os.fdopen(descriptor, "wb") as output:
            os.fchown(output.fileno(), uid, gid)
            os.fchmod(output.fileno(), mode)
            output.write(content)
            output.flush()
            os.fsync(output.fileno())
        os.replace(candidate, path)
    finally:
        if os.path.lexists(candidate):
            os.unlink(candidate)


def converge_ordering():
    for name in ("etc", "etc/systemd", "etc/systemd/system"):
        if inspect(ROOT / name, True) is None:
            raise Refused()
    directory = ROOT / "etc/systemd/system/caddy.service.d"
    path = directory / "orbit-vpn.conf"
    before_directory = inspect(directory, True)
    before = inspect(path, False)
    content = path.read_bytes() if before else None
    was_active = control(["is-active", "--quiet", "caddy"], True)
    file_changed = (
        content != CONTENT
        or before is None
        or before.st_gid != GID
        or stat.S_IMODE(before.st_mode) != 0o644
    )
    directory_changed = (
        before_directory is None
        or before_directory.st_gid != GID
        or stat.S_IMODE(before_directory.st_mode) != 0o755
    )
    if not file_changed and not directory_changed and was_active:
        return {"completed": True, "changed": False}
    try:
        directory.mkdir(mode=0o755, exist_ok=True)
        if directory_changed:
            os.chown(directory, UID, GID)
            os.chmod(directory, 0o755)
        if file_changed:
            publish(path, CONTENT, 0o644, UID, GID)
            control(["daemon-reload"])
        if file_changed or not was_active:
            control(["restart", "caddy"])
    except (Refused, OSError, subprocess.SubprocessError):
        try:
            if before_directory is not None:
                directory.mkdir(mode=0o755, exist_ok=True)
            if before is not None:
                publish(
                    path,
                    content,
                    stat.S_IMODE(before.st_mode),
                    before.st_uid,
                    before.st_gid,
                )
            elif path.exists():
                path.unlink()
            if before_directory is not None:
                os.chown(directory, before_directory.st_uid, before_directory.st_gid)
                os.chmod(directory, stat.S_IMODE(before_directory.st_mode))
            elif directory.exists():
                directory.rmdir()
            if file_changed:
                control(["daemon-reload"])
            control(["restart" if was_active else "stop", "caddy"])
        except (Refused, OSError, subprocess.SubprocessError):
            raise Refused("service.ordering_recovery_failed")
        raise Refused()
    return {"completed": True, "changed": True}


def main():
    try:
        LOCK.parent.mkdir(mode=0o700, exist_ok=True)
        meta = LOCK.parent.lstat()
        if (
            not stat.S_ISDIR(meta.st_mode)
            or meta.st_uid != UID
            or meta.st_gid != GID
            or stat.S_IMODE(meta.st_mode) != 0o700
        ):
            raise Refused()
        descriptor = os.open(
            LOCK, os.O_CREAT | os.O_APPEND | os.O_WRONLY | os.O_NOFOLLOW, 0o600
        )
        with os.fdopen(descriptor, "a") as lock:
            meta = os.fstat(lock.fileno())
            if (
                not stat.S_ISREG(meta.st_mode)
                or meta.st_uid != UID
                or meta.st_gid != GID
            ):
                raise Refused()
            os.fchmod(lock.fileno(), 0o600)
            until = time.monotonic() + 30
            while True:
                try:
                    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    break
                except BlockingIOError:
                    if time.monotonic() >= until:
                        raise Refused()
                    time.sleep(0.05)
            result = converge_ordering()
    except Refused as error:
        result = {"completed": False, "error_code": error.code}
    except (OSError, subprocess.SubprocessError):
        result = {"completed": False, "error_code": "service.ordering_failed"}
    print(json.dumps(result))
    return result
