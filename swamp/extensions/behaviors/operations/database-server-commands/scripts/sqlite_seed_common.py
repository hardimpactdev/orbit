"""SQLite seed ownership and atomic receipt/file actions shared by native steps."""

import contextlib
import dataclasses
import json
import os
import pwd
import re
import signal
import stat
import sys
from pathlib import Path

from sqlite_clone_common import (
    RESERVE,
    Refused,
    available,
    checked_payload,
    connect,
    digest,
    inside,
    lstat,
    opened,
    path,
    regular,
    remove_present,
    require,
    snapshot,
    sync_dir,
    temporary,
    walk,
)


class Unconfirmed(Exception):
    pass


def account(name):
    require(isinstance(name, str) and re.fullmatch(r"[a-z_][a-z0-9_-]{0,31}", name))
    value = pwd.getpwnam(name)
    require(value.pw_uid != 0)
    return value


@contextlib.contextmanager
def as_account(user):
    if os.geteuid() == user.pw_uid:
        yield
        return
    require(os.geteuid() == 0)
    uid, gid, groups = os.geteuid(), os.getegid(), os.getgroups()
    try:
        os.initgroups(user.pw_name, user.pw_gid)
        os.setegid(user.pw_gid)
        os.seteuid(user.pw_uid)
        yield
    finally:
        os.seteuid(uid)
        os.setegid(gid)
        os.setgroups(groups)


@dataclasses.dataclass(frozen=True)
class Source:
    mode: str
    base: str
    source_path: str
    source_user: str
    transport_user: str
    operation: str
    state_dir: str
    snapshot_path: str


@dataclasses.dataclass(frozen=True)
class Target:
    mode: str
    base: str
    target_user: str
    transport_user: str
    operation: str
    state_dir: str
    expected_size: int
    expected_digest: str
    environment: str
    relative_path: str


def state_directory(selected, create=True):
    selected = path(str(selected))
    require(
        re.fullmatch(
            r"/var/lib/orbit/app-instance-sqlite-seeds/(?:source-[1-9][0-9]*-target-[1-9][0-9]*|target-[1-9][0-9]*)(?:-[a-f0-9]{64})?",
            str(selected),
        )
    )
    require(os.geteuid() == 0)
    if lstat(selected) is None and not create:
        return False
    # The fixed shared parent is root-private, like the replaced seed implementation.
    parent = selected.parent
    if lstat(parent) is None:
        if lstat(parent.parent) is None:
            walk(parent.parent.parent)
            parent.parent.mkdir(mode=0o700)
        walk(parent.parent)
        parent.mkdir(mode=0o700)
    for directory in (parent, selected):
        if lstat(directory) is None:
            directory.mkdir(mode=0o700)
        info = walk(directory)
        require(info.st_uid == 0 and stat.S_IMODE(info.st_mode) == 0o700)
    return True


def load_state(directory):
    selected = Path(directory) / "state.json"
    if lstat(selected) is None:
        return None
    fd = opened(selected, os.O_RDONLY, uid=0, mode=0o600, links=1)
    with os.fdopen(fd) as stream:
        value = json.load(stream)
    require(isinstance(value, dict))
    return value


def write_state(directory, value):
    directory = Path(directory)
    selected, fd = temporary(directory, ".state-")
    try:
        with os.fdopen(fd, "w") as stream:
            json.dump(value, stream, separators=(",", ":"))
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(selected, directory / "state.json")
        sync_dir(directory)
    finally:
        remove_present(selected)


def valid_identity(value):
    require(isinstance(value, str) and re.fullmatch(r"[a-f0-9]{64}", value))


def source_seed(raw):
    value = Source(**raw)
    valid_identity(value.operation)
    require(
        re.fullmatch(
            r"/var/lib/orbit/app-instance-sqlite-seeds/source-[1-9][0-9]*-target-[1-9][0-9]*(?:-"
            + value.operation
            + r")?",
            value.state_dir,
        )
    )
    base, source, selected = (
        path(value.base),
        inside(value.base, value.source_path),
        path(value.snapshot_path),
    )
    require(str(selected) == "/tmp/orbit-sqlite-" + value.operation + ".sqlite")
    owner, transport = account(value.source_user), account(value.transport_user)
    require(value.mode in ("prepare", "cleanup"))
    if value.mode == "cleanup" and not state_directory(value.state_dir, False):
        require(lstat(selected) is None)
        return {}
    if value.mode == "prepare" and lstat(value.state_dir) is not None:
        state_directory(value.state_dir)
    state = load_state(value.state_dir)
    if state is not None:
        for key in ("operation", "base", "source_path", "snapshot_path"):
            require(state.get(key) == getattr(value, key))

    def remove_snapshot():
        info = lstat(selected)
        if info is not None:
            require(
                stat.S_ISREG(info.st_mode)
                and info.st_uid in (owner.pw_uid, transport.pw_uid)
            )
            require("inode" not in state or state["inode"] == info.st_ino)
            selected.unlink()

    if value.mode == "cleanup":
        if state is None:
            require(lstat(selected) is None)
        else:
            remove_snapshot()
            (Path(value.state_dir) / "state.json").unlink()
        Path(value.state_dir).rmdir()
        return {}
    if state is not None:
        if state.get("status") == "ready" and lstat(selected) is not None:
            info = regular(selected, transport.pw_uid, state["inode"], 0o600, 1)
            require(
                info.st_size == state["size"] and digest(selected) == state["digest"]
            )
            return {
                "bytes": info.st_size,
                "sha256": state["digest"],
                "snapshot": str(selected),
            }
        remove_snapshot()
        (Path(value.state_dir) / "state.json").unlink()
    else:
        require(lstat(selected) is None)
    with as_account(owner):
        walk(base)
        regular(source)
        fd = opened(source, os.O_RDONLY)
        os.close(fd)
        with contextlib.closing(connect(source, readonly=True)) as origin:
            require(origin.execute("PRAGMA quick_check").fetchone() == ("ok",))
            required = max(
                os.stat(source).st_size,
                origin.execute("PRAGMA page_count").fetchone()[0]
                * origin.execute("PRAGMA page_size").fetchone()[0],
            )
            require(required > 0 and available("/tmp") >= required + RESERVE)
    state_directory(value.state_dir)
    state = {
        key: getattr(value, key)
        for key in ("operation", "base", "source_path", "snapshot_path")
    }
    state["status"] = "preparing"
    write_state(value.state_dir, state)
    with as_account(owner):
        fd = os.open(
            selected, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600
        )
        os.close(fd)
        snapshot(source, selected, seed=True)
    os.chown(selected, transport.pw_uid, transport.pw_gid)
    os.chmod(selected, 0o600)
    info = os.lstat(selected)
    sha = digest(selected)
    state.update(status="ready", inode=info.st_ino, size=info.st_size, digest=sha)
    write_state(value.state_dir, state)
    return {"bytes": info.st_size, "sha256": sha, "snapshot": str(selected)}


def target_seed(raw, *, preserve_installed=False):
    value = Target(**raw)
    valid_identity(value.operation)
    require(
        re.fullmatch(
            r"/var/lib/orbit/app-instance-sqlite-seeds/target-[1-9][0-9]*(?:-"
            + value.operation
            + r")?",
            value.state_dir,
        )
    )
    base = path(value.base)
    destination = inside(base, str(base / value.relative_path))
    require(
        isinstance(value.relative_path, str) and not value.relative_path.startswith("/")
    )
    require(
        value.environment in ("development", "production")
        and value.mode in ("prepare", "install", "cleanup")
    )
    owner, transport = account(value.target_user), account(value.transport_user)
    if value.environment == "production":
        require(base == Path(owner.pw_dir) and value.relative_path == "database.sqlite")
    directory = Path(value.state_dir)
    home = destination.parent

    def destination_info():
        if lstat(destination) is None:
            return None
        return regular(destination, owner.pw_uid, mode=0o600)

    def remove_owned(selected, inode, kind):
        selected = path(selected)
        if kind == "incoming":
            require(
                re.fullmatch(
                    r"/tmp/orbit-sqlite-" + value.operation[:16] + r"-[a-f0-9]{16}",
                    str(selected),
                )
            )
        else:
            require(
                selected.parent == home
                and re.fullmatch(
                    r"\.database\.sqlite\.orbit-[a-f0-9]{16}", selected.name
                )
            )
        if lstat(selected) is not None:
            regular(selected, inode=inode)
            selected.unlink()

    def abandon(state):
        for kind in ("candidate", "incoming"):
            if kind in state:
                remove_owned(state[kind], state[kind + "_inode"], kind)
        (directory / "state.json").unlink()
        directory.rmdir()

    def state_identity(state):
        require(
            state.get("operation") == value.operation
            and state.get("destination") == str(destination)
        )

    def complete(state):
        info = checked_payload(
            destination,
            state["expected_size"],
            state["expected_digest"],
            owner.pw_uid,
            private=True,
        )
        for kind in ("candidate", "incoming"):
            if kind in state:
                remove_owned(state[kind], state[kind + "_inode"], kind)
        state.update(status="complete", destination_inode=info.st_ino)
        for key in ("candidate", "candidate_inode", "incoming", "incoming_inode"):
            state.pop(key, None)
        write_state(directory, state)

    if value.mode == "cleanup":
        if not state_directory(directory, False):
            return {"state": "cleaned"}
        state = load_state(directory)
        if state is None:
            directory.rmdir()
        else:
            state_identity(state)
            if (
                preserve_installed
                and state.get("status") in ("installing", "complete")
                and lstat(destination) is not None
            ):
                if state["status"] == "complete":
                    checked_payload(
                        destination,
                        state["expected_size"],
                        state["expected_digest"],
                        owner.pw_uid,
                        state["destination_inode"],
                        private=True,
                    )
                else:
                    complete(state)
            else:
                abandon(state)
        return {"state": "cleaned"}
    require(type(value.expected_size) is int and value.expected_size > 0)
    valid_identity(value.expected_digest)
    with as_account(owner):
        for selected in (base, home):
            require(walk(selected).st_uid == owner.pw_uid)
        require(os.access(home, os.W_OK | os.X_OK, effective_ids=True))
    if lstat(directory) is not None:
        state_directory(directory)
    state = load_state(directory)
    if state is not None:
        state_identity(state)
        require(type(state.get("expected_size")) is int and state["expected_size"] > 0)
        valid_identity(state.get("expected_digest"))
        if state.get("status") == "complete":
            checked_payload(
                destination,
                state["expected_size"],
                state["expected_digest"],
                owner.pw_uid,
                state["destination_inode"],
                private=True,
            )
            return {"state": "complete"}
        require(state.get("status") in ("prepared", "installing"))
        try:
            present = destination_info()
            if present is not None:
                require(state["status"] == "installing")
                checked_payload(
                    destination,
                    state["expected_size"],
                    state["expected_digest"],
                    owner.pw_uid,
                    private=True,
                )
                complete(state)
                return {"state": "complete"}
        except Refused:
            abandon(state)
            raise
        selected = (
            state["candidate"] if state["status"] == "installing" else state["incoming"]
        )
        if (
            lstat(selected) is None
            or state["expected_size"] != value.expected_size
            or state["expected_digest"] != value.expected_digest
        ):
            abandon(state)
            state = None
    else:
        require(destination_info() is None)
    require(available(home) >= value.expected_size + RESERVE)
    if value.mode == "prepare":
        if state is None:
            state_directory(directory)
            selected, fd = temporary(
                "/tmp", "orbit-sqlite-" + value.operation[:16] + "-", transport
            )
            info = os.fstat(fd)
            os.close(fd)
            state = {
                "operation": value.operation,
                "destination": str(destination),
                "expected_size": value.expected_size,
                "expected_digest": value.expected_digest,
                "incoming": str(selected),
                "incoming_inode": info.st_ino,
                "status": "prepared",
            }
            write_state(directory, state)
            return {"state": "ready", "incoming": str(selected)}
        if state["status"] == "installing":
            try:
                checked_payload(
                    state["candidate"],
                    value.expected_size,
                    value.expected_digest,
                    owner.pw_uid,
                    state["candidate_inode"],
                    private=True,
                )
            except Refused:
                abandon(state)
                raise
            return {"state": "resume"}
        with as_account(transport):
            fd = opened(
                state["incoming"],
                os.O_WRONLY,
                uid=transport.pw_uid,
                inode=state["incoming_inode"],
                mode=0o600,
                links=1,
            )
            try:
                os.ftruncate(fd, 0)
            finally:
                os.close(fd)
        return {"state": "ready", "incoming": state["incoming"]}
    require(state is not None)
    if state["status"] == "prepared":
        checked_payload(
            state["incoming"],
            value.expected_size,
            value.expected_digest,
            transport.pw_uid,
            state["incoming_inode"],
            private=True,
        )
        candidate, fd = temporary(home, ".database.sqlite.orbit-", owner)
        try:
            source = opened(
                state["incoming"],
                os.O_RDONLY,
                uid=transport.pw_uid,
                inode=state["incoming_inode"],
                mode=0o600,
                links=1,
            )
            with os.fdopen(source, "rb") as origin, os.fdopen(fd, "wb") as target:
                while chunk := origin.read(1048576):
                    target.write(chunk)
                target.flush()
                os.fsync(target.fileno())
                inode = os.fstat(target.fileno()).st_ino
            state.update(
                status="installing", candidate=str(candidate), candidate_inode=inode
            )
            write_state(directory, state)
        except BaseException:
            remove_present(candidate)
            raise
    candidate = Path(state["candidate"])
    checked_payload(
        candidate,
        value.expected_size,
        value.expected_digest,
        owner.pw_uid,
        state["candidate_inode"],
        private=True,
    )
    if destination_info() is not None:
        abandon(state)
        raise Refused()
    try:
        os.link(candidate, destination, follow_symlinks=False)
    except FileExistsError:
        abandon(state)
        raise Refused()
    try:
        sync_dir(home)
        complete(state)
    except BaseException:  # noqa: BLE001 -- installation may already have committed.
        raise Unconfirmed()
    return {"state": "changed"}


def target_input(target, mode, ref=None):
    ref = ref or {}
    return {
        **{
            k: v
            for k, v in target.items()
            if k not in ("node_id", "target_instance_id")
        },
        "mode": mode,
        "expected_size": ref.get("bytes", 0),
        "expected_digest": ref.get("sha256", ""),
    }


def main(action):
    try:
        os.umask(0o077)
        signal.alarm(120)
        result = action(json.loads(sys.argv[1]))
        print(json.dumps(result))
    except Unconfirmed:
        print('{"error_code":"instance.sqlite_seed_unconfirmed"}')
        raise SystemExit(1) from None
    except (
        Refused,
        PermissionError,
        FileNotFoundError,
        KeyError,
        TypeError,
        ValueError,
    ):
        print('{"error_code":"instance.sqlite_seed_refused"}')
        raise SystemExit(1) from None
    except Exception:  # noqa: BLE001 -- keep filesystem diagnostics out of public outputs.
        print('{"error_code":"instance.sqlite_seed_failed"}')
        raise SystemExit(1) from None
