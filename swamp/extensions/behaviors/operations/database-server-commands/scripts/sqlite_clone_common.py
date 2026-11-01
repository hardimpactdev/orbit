"""SQLite clone filesystem primitives; workflow steps choose one fixed action each."""

import contextlib
import errno
import fcntl
import hashlib
import json
import os
import re
import secrets
import sqlite3
import stat
import sys
import time
from pathlib import Path
from urllib.parse import quote

RESERVE = 1048576
FICLONE = 0x40049409
DEADLINE = time.monotonic() + 600
COPY_FIELDS = {
    "local": {"operation", "checkout", "source", "target_checkout", "target"},
    "export": {"operation", "checkout", "source", "directory"},
    "prepare": {"operation", "target_checkout", "target"},
    "install": {
        "operation",
        "target_checkout",
        "target",
        "incoming",
        "bytes",
        "sha256",
        "mode",
    },
    "cleanup": {"operation", "directory"},
    "remove": {"operation", "checkout", "path"},
}


class Refused(Exception):
    pass


def require(ok):
    if not ok:
        raise Refused()


def path(value):
    require(
        isinstance(value, str)
        and value.startswith("/")
        and len(value) > 1
        and not value.endswith("/")
        and not re.search(r"[\x00\r\n]|(?:^|/)\.{1,2}(?:/|$)|//", value)
    )
    return Path(value)


def inside(base, selected):
    base, selected = path(str(base)), path(str(selected))
    require(base in selected.parents)
    return selected


def lstat(selected):
    try:
        return os.lstat(selected)
    except FileNotFoundError:
        return None


def walk(selected, create_below=None, *, missing_ok=False):
    selected = path(str(selected))
    current = Path("/")
    for part in selected.parts[1:]:
        current /= part
        info = lstat(current)
        if info is None:
            if missing_ok:
                return None
            require(create_below is not None and Path(create_below) in current.parents)
            current.mkdir(mode=0o755)
            info = os.lstat(current)
        require(stat.S_ISDIR(info.st_mode) and not stat.S_ISLNK(info.st_mode))
    return os.lstat(selected)


def regular(selected, uid=None, inode=None, mode=None, links=None):
    selected = path(str(selected))
    walk(selected.parent)
    info = os.lstat(selected)
    require(stat.S_ISREG(info.st_mode) and not stat.S_ISLNK(info.st_mode))
    require(uid is None or info.st_uid == uid)
    require(inode is None or info.st_ino == inode)
    require(mode is None or stat.S_IMODE(info.st_mode) == mode)
    require(links is None or info.st_nlink == links)
    return info


def opened(selected, flags, *, uid=None, inode=None, mode=None, links=None):
    before = regular(selected, uid, inode, mode, links)
    fd = os.open(selected, flags | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        after = os.fstat(fd)
        require(
            before.st_ino == after.st_ino
            and before.st_dev == after.st_dev
            and stat.S_ISREG(after.st_mode)
        )
        return fd
    except BaseException:
        os.close(fd)
        raise


def digest(selected):
    fd = opened(selected, os.O_RDONLY)
    with os.fdopen(fd, "rb") as stream:
        sha = hashlib.sha256()
        while chunk := stream.read(1048576):
            sha.update(chunk)
        return sha.hexdigest()


def sync_dir(selected):
    fd = os.open(selected, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def sync_file(selected):
    fd = opened(selected, os.O_RDONLY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def available(selected):
    info = os.statvfs(selected)
    require(not info.f_flag & os.ST_RDONLY)
    return info.f_bavail * info.f_frsize


def connect(selected, *, readonly=False, immutable=False):
    uri = (
        "file:"
        + quote(str(selected), safe="/")
        + ("?mode=ro" if readonly else "?mode=rw")
    )
    if immutable:
        uri += "&immutable=1"
    db = sqlite3.connect(uri, uri=True, timeout=5, isolation_level=None)
    db.set_progress_handler(lambda: time.monotonic() >= DEADLINE, 1000)
    if readonly:
        db.execute("PRAGMA query_only=ON")
    return db


def checked_payload(selected, size, sha, uid=None, inode=None, private=False):
    info = regular(selected, uid, inode, 0o600 if private else None)
    require(info.st_size == size and digest(selected) == sha)
    with contextlib.closing(connect(selected, readonly=True, immutable=True)) as db:
        require(db.execute("PRAGMA integrity_check").fetchone() == ("ok",))
    return info


def temporary(parent, prefix=".orbit-sqlite-", uid=None):
    selected = Path(parent) / (prefix + secrets.token_hex(8))
    fd = os.open(selected, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    if uid is not None:
        os.fchown(fd, uid.pw_uid, uid.pw_gid)
    return selected, fd


def snapshot(origin, destination, *, seed=False):
    # An existing private file was created with O_EXCL before SQLite opens it.
    with (
        contextlib.closing(connect(origin, readonly=seed)) as source,
        contextlib.closing(connect(destination)) as target,
    ):
        if seed:
            require(source.execute("PRAGMA quick_check").fetchone() == ("ok",))
        pages = source.execute("PRAGMA page_count").fetchone()[0]
        page_size = source.execute("PRAGMA page_size").fetchone()[0]
        budget = available(Path(destination).parent)
        require(
            max(os.stat(origin).st_size, pages * page_size) > 0
            and budget >= max(os.stat(origin).st_size, pages * page_size) + RESERVE
        )
        limit = min(DEADLINE, time.monotonic() + 30)

        def progress(status, remaining, total):
            require(time.monotonic() < limit and total * page_size <= budget - RESERVE)

        source.backup(target, pages=256, progress=progress, sleep=0.01)
        require(target.execute("PRAGMA integrity_check").fetchone() == ("ok",))
    sync_file(destination)


def remove_present(selected):
    try:
        Path(selected).unlink()
    except FileNotFoundError:
        pass


def clone_directory(selected, create=False):
    selected = path(str(selected))
    require(re.fullmatch(r"/tmp/orbit-sqlite-clone-[a-f0-9]{32}", str(selected)))
    if create:
        selected.mkdir(mode=0o700)
    if lstat(selected) is None:
        return selected, False
    info = walk(selected)
    require(info.st_uid == os.geteuid() and stat.S_IMODE(info.st_mode) == 0o700)
    return selected, True


def cleanup_clone(selected):
    selected, exists = clone_directory(selected)
    if not exists:
        return {}
    entries = list(selected.iterdir())
    for entry in entries:
        require(
            entry.name == "snapshot.sqlite"
            or re.fullmatch(r"\.orbit-sqlite-[a-f0-9]{16}", entry.name)
        )
        regular(entry, os.geteuid(), mode=0o600, links=1)
    for entry in entries:
        entry.unlink()
    selected.rmdir()
    return {}


def report(selected, mode, copied):
    return {
        "bytes": os.lstat(selected).st_size,
        "sha256": digest(selected),
        "mode": mode,
        "copy": copied,
    }


def place(database, wal, destination, mode):
    for selected in [
        destination,
        Path(str(destination) + "-wal"),
        Path(str(destination) + "-shm"),
    ]:
        if lstat(selected) is not None:
            regular(selected, os.geteuid())
    os.chmod(database, mode)
    remove_present(str(destination) + "-shm")
    if wal is None:
        remove_present(str(destination) + "-wal")
    else:
        os.chmod(wal, mode)
        os.replace(wal, str(destination) + "-wal")
    os.replace(database, destination)
    sync_dir(destination.parent)


def reflink(origin, parent):
    target, fd = temporary(parent)
    try:
        source = opened(origin, os.O_RDONLY, uid=os.geteuid())
        try:
            fcntl.ioctl(fd, FICLONE, source)
        finally:
            os.close(source)
        return target
    except BaseException:
        remove_present(target)
        raise
    finally:
        os.close(fd)


def copied_local(source, destination, mode):
    partials = []
    # Reflink is used only after proving support and while SQLite holds its write lock.
    use_backup = False
    try:
        probe = reflink(source, destination.parent)
        probe.unlink()
    except OSError as error:
        if error.errno not in (
            errno.EOPNOTSUPP,
            errno.ENOTTY,
            errno.EXDEV,
            errno.EINVAL,
        ):
            raise
        use_backup = True
    if not use_backup:
        with contextlib.closing(connect(source)) as db:
            db.execute("PRAGMA busy_timeout=2000")
            try:
                db.execute("BEGIN IMMEDIATE")
            except sqlite3.OperationalError as error:
                if getattr(error, "sqlite_errorcode", None) not in (
                    sqlite3.SQLITE_BUSY,
                    sqlite3.SQLITE_LOCKED,
                ):
                    raise
                use_backup = True
            if not use_backup:
                try:
                    database = reflink(source, destination.parent)
                    partials.append(database)
                    wal_path = Path(str(source) + "-wal")
                    wal = None
                    if lstat(wal_path) is not None:
                        regular(wal_path, os.geteuid())
                        wal = reflink(wal_path, destination.parent)
                        partials.append(wal)
                finally:
                    db.execute("ROLLBACK")
    try:
        if use_backup:
            database, fd = temporary(destination.parent)
            os.close(fd)
            partials.append(database)
            snapshot(source, database)
            wal = None
        place(database, wal, destination, mode)
        return report(destination, mode, "snapshot" if use_backup else "reflink")
    finally:
        for partial in partials:
            remove_present(partial)


def copy_database(data):
    operation = data.get("operation")
    require(
        operation in COPY_FIELDS
        and set(data) == COPY_FIELDS[operation]
        and os.geteuid() != 0
    )
    if operation == "cleanup":
        return cleanup_clone(data["directory"])
    if operation == "remove":
        selected = inside(data["checkout"], data["path"])
        try:
            regular(selected, os.geteuid())
            selected.unlink()
        except FileNotFoundError:
            pass
        return {}
    if operation in ("prepare", "local", "install"):
        destination = inside(data["target_checkout"], data["target"])
        walk(path(data["target_checkout"]))
        walk(destination.parent, path(data["target_checkout"]))
    if operation == "prepare":
        return {}
    if operation == "install":
        incoming = inside(data["target_checkout"], data["incoming"])
        require(
            incoming.parent == destination.parent
            and re.fullmatch(r"\.orbit-sqlite-[a-f0-9]{32}", incoming.name)
        )
        require(
            type(data["bytes"]) is int
            and data["bytes"] >= 16
            and re.fullmatch(r"[a-f0-9]{64}", data["sha256"])
        )
        checked_payload(
            incoming, data["bytes"], data["sha256"], os.geteuid(), private=True
        )
        require(type(data["mode"]) is int and 0 <= data["mode"] <= 0o7777)
        place(incoming, None, destination, data["mode"] & 0o666)
        return {}
    source = inside(data["checkout"], data["source"])
    info = regular(source, os.geteuid())
    mode = stat.S_IMODE(info.st_mode)
    if operation == "local":
        return copied_local(source, destination, mode)
    directory, _ = clone_directory(data["directory"], create=True)
    temporary_path, fd = temporary(directory)
    os.close(fd)
    try:
        snapshot(source, temporary_path)
        selected = directory / "snapshot.sqlite"
        os.replace(temporary_path, selected)
        return report(selected, mode, "snapshot")
    finally:
        remove_present(temporary_path)


def main(action):
    try:
        intent = json.loads(sys.argv[1])
        result = action(intent)
        print(json.dumps(result))
    except (
        Refused,
        PermissionError,
        FileNotFoundError,
        KeyError,
        TypeError,
        ValueError,
    ):
        print('{"error_code":"instance.sqlite_copy_refused"}')
        raise SystemExit(1) from None
    except Exception:  # noqa: BLE001 -- private client failures stay out of public results.
        print('{"error_code":"database.clone_outcome_unknown"}')
        raise SystemExit(1) from None
