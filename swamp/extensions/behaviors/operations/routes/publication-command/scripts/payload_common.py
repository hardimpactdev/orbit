"""Fixed public candidate storage; actions are separate placed native steps."""

import hashlib
import json
import os
import re
import stat
from pathlib import Path

ROOT = Path("/etc/caddy")
UUID = re.compile(r"[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}")


def metadata():
    value = json.loads(os.environ["ORBIT_CADDY_DELTA_META"])
    if not UUID.fullmatch(value["run_id"]) or not re.fullmatch(
        r"[a-f0-9]{64}", value["sha256"]
    ):
        raise ValueError("caddy.delta_input_invalid")
    if (
        not isinstance(value["content_bytes"], int)
        or not 0 < value["content_bytes"] <= 4194304
    ):
        raise ValueError("caddy.delta_input_too_large")
    return value


def directory(path):
    info = path.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
        raise ValueError("caddy.delta_stage_unsafe")


def stage_for(value):
    base = ROOT / ".orbit-deltas"
    directory(ROOT)
    directory(base)
    stage = base / value["run_id"]
    directory(stage)
    return stage


def payload(stage, value):
    chunks = []
    for offset in range(0, value["content_bytes"], 32768):
        path = stage / str(offset)
        info = path.lstat()
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0:
            raise ValueError("caddy.delta_chunk_unsafe")
        block = path.read_bytes()
        if len(block) != min(32768, value["content_bytes"] - offset):
            raise ValueError("caddy.delta_chunk_invalid")
        chunks.append(block)
    content = b"".join(chunks)
    if hashlib.sha256(content).hexdigest() != value["sha256"]:
        raise ValueError("caddy.delta_input_invalid")
    return json.loads(content)
