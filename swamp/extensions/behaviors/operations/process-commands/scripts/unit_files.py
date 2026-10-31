"""Shared file and admitted-material checks. Workflow steps own installation ordering."""

import hashlib
import json
import os
import re
import stat
import sys
import tempfile
import unicodedata
from pathlib import Path

import process_common as common
from process_common import Refused

ORBIT_ROOT = Path("/etc/orbit")
ANALYZE = "/usr/bin/systemd-analyze"
ACCOUNT_HOME = "/__orbit_process_account_home__"


def digest(content):
    return hashlib.sha256(content.encode()).hexdigest()


def installation(value):
    data = json.loads(os.environ["ORBIT_PROCESS_INSTALLATION"])
    if (
        not isinstance(data, dict)
        or data.get("runtime") != value["runtime"]
        or data.get("name") != value["name"]
        or data.get("process_id") != value["process_id"]
        or data.get("desired") not in ("running", "stopped")
        or type(data.get("on_demand")) is not bool
    ):
        raise Refused("process.invalid")
    key = "content_hash" if data["runtime"] == "systemd" else "spec"
    if not isinstance(data.get(key), str) or not re.fullmatch(
        "[a-f0-9]{64}", data[key]
    ):
        raise Refused("process.invalid")
    return data


def material(value, admitted):
    # A single existing installation value or the existing per-Process batch map.
    # Core command/shell supplies env. Official SSH exec selects stdin explicitly.
    # Remove the vault environment before any child command in either mode.
    environment = os.environ.pop("ORBIT_PROCESS_MATERIAL", None)
    raw = sys.stdin.read() if "--material-stdin" in sys.argv[1:] else environment
    data = json.loads(raw)
    if isinstance(data, dict) and str(value["process_id"]) in data:
        data = data[str(value["process_id"])]["installation"]
    if not isinstance(data, dict) or data.get("runtime") != admitted["runtime"]:
        raise Refused("process.invalid")
    if admitted["runtime"] == "docker":
        if data.get("spec") != admitted["spec"]:
            raise Refused("process.spec_changed")
        return data
    content, environment = data.get("content"), data.get("environment")
    if (
        not isinstance(content, str)
        or not isinstance(environment, str)
        or "\0" in content
        or "\0" in environment
        or digest(content) != admitted["content_hash"]
        or f"X-Orbit-Process-ID={value['process_id']}" not in content.splitlines()
    ):
        raise Refused("process.invalid")
    return data


def resolve_content(data, admitted):
    content = data["content"]
    user = admitted.get("account_user")
    if user is None:
        if ACCOUNT_HOME in content:
            raise Refused("process.account_unavailable")
        return content
    if (
        not isinstance(user, str)
        or not re.fullmatch("[a-z_][a-z0-9_-]{0,31}", user)
        or user == "root"
        or f"\nUser={user}\n" not in content
        or ACCOUNT_HOME not in content
    ):
        raise Refused("process.account_unavailable")
    reply = common.command(["/usr/bin/getent", "passwd", user])
    rows = reply.stdout.rstrip("\n").split("\n")
    fields = rows[0].split(":")
    home = fields[5] if len(fields) == 7 else ""
    if (
        reply.returncode
        or len(rows) != 1
        or len(fields) != 7
        or fields[0] != user
        or not re.fullmatch("[1-9][0-9]*", fields[2])
        or not re.fullmatch("[0-9]+", fields[3])
        or not home.startswith("/")
        or len(home) > 4096
        or any(unicodedata.category(char) == "Cc" for char in home)
        or any(part in ("", ".", "..") for part in home.split("/")[1:])
    ):
        raise Refused("process.account_unavailable")
    directive = "".join(
        "%%"
        if char == "%"
        else "\\x" + format(ord(char), "02x")
        if char in " \"'$\\"
        else char
        for char in home
    )
    argument = "".join(
        "$$"
        if char == "$"
        else "%%"
        if char == "%"
        else "\\" + char
        if char in '\\"'
        else char
        for char in home
    )
    return "\n".join(
        line.replace(
            ACCOUNT_HOME, argument if line.startswith("ExecStart=") else directive
        )
        for line in content.split("\n")
    )


def owned_directory(path, private=False):
    info = path.lstat()
    if (
        not stat.S_ISDIR(info.st_mode)
        or info.st_uid != common.ROOT_UID
        or info.st_gid != common.ROOT_GID
        or info.st_mode & 0o022
        or private
        and stat.S_IMODE(info.st_mode) != 0o700
    ):
        raise Refused("process.name_collision")


def directory(path, private=True):
    try:
        path.mkdir(mode=0o700)
    except FileExistsError:
        pass
    owned_directory(path, private)


def read_file(path, process_id=None, private=False):
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except FileNotFoundError:
        return None
    except OSError:
        raise Refused("process.name_collision") from None
    with os.fdopen(fd, "rb") as source:
        info = os.fstat(source.fileno())
        if (
            not stat.S_ISREG(info.st_mode)
            or info.st_uid != common.ROOT_UID
            or info.st_gid != common.ROOT_GID
            or info.st_nlink != 1
            or info.st_mode & 0o022
            or private
            and stat.S_IMODE(info.st_mode) != 0o600
        ):
            raise Refused("process.name_collision")
        content = source.read().decode()
    if (
        process_id is not None
        and f"X-Orbit-Process-ID={process_id}" not in content.splitlines()
    ):
        raise Refused("process.name_collision")
    return content


def sync_directory(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def write_file(path, content, mode=0o600):
    fd, temporary = tempfile.mkstemp(prefix=".orbit-", dir=path.parent)
    try:
        with os.fdopen(fd, "wb") as output:
            os.fchmod(output.fileno(), mode)
            output.write(content.encode())
            output.flush()
            os.fsync(output.fileno())
        os.replace(temporary, path)
        sync_directory(path.parent)
    finally:
        Path(temporary).unlink(missing_ok=True)


def checkpoint(value, admitted, create=False):
    operation = os.environ["ORBIT_OPERATION_ID"]
    if not re.fullmatch("[1-9][0-9]*", operation):
        raise Refused("process.invalid")
    key = admitted.get("content_hash", admitted.get("spec"))
    paths = [
        ORBIT_ROOT,
        ORBIT_ROOT / "process-commands",
        ORBIT_ROOT / "process-commands" / operation,
        ORBIT_ROOT / "process-commands" / operation / str(value["process_id"]),
        ORBIT_ROOT / "process-commands" / operation / str(value["process_id"]) / key,
    ]
    for index, path in enumerate(paths):
        if create:
            directory(path, index != 0)
        else:
            owned_directory(path, index != 0)
    return paths[-1]


def unit_input(value):
    admitted = installation(value)
    if admitted["runtime"] != "systemd":
        raise Refused("process.invalid")
    owned_directory(common.UNITS)
    return admitted


def staged(value, admitted):
    path = checkpoint(value, admitted) / value["name"]
    content = read_file(path, value["process_id"], private=True)
    expected = os.environ["ORBIT_PROCESS_STAGED_HASH"]
    if (
        not re.fullmatch("[a-f0-9]{64}", expected)
        or content is None
        or digest(content) != expected
    ):
        raise Refused("process.recovery_required")
    return path, content
