"""Strict Project dotenv parsing shared by Instance and Tasks setup actions."""

import os
import re
import stat


def parse_project_environment(dotenv):
    if len(dotenv.encode()) > 1048576:
        raise ValueError("instance.step_not_admitted")
    env = {}

    def unescape(match):
        return {
            "n": chr(10),
            "r": chr(13),
            chr(92): chr(92),
            chr(34): chr(34),
            "$": "$",
        }[match.group(1)]

    for line in dotenv.splitlines():
        if not line.strip() or line.lstrip().startswith("#"):
            continue
        match = re.fullmatch(r"([A-Za-z_][A-Za-z0-9_]{0,254})=(.*)", line)
        if not match:
            raise ValueError("instance.step_not_admitted")
        key, text = match.groups()
        if key.startswith(("LD_", "BASH_")) or "\0" in text:
            raise ValueError("instance.step_not_admitted")
        if text.startswith('"'):
            if not text.endswith('"') or len(text) < 2:
                raise ValueError("instance.step_not_admitted")
            text = text[1:-1]
            if re.search(r'\\(?![nr\\"$])', text):
                raise ValueError("instance.step_not_admitted")
            text = re.sub(r'\\([nr\\"$])', unescape, text)
        elif '"' in text or "'" in text:
            raise ValueError("instance.step_not_admitted")
        if key in env or len(text.encode()) > 65536 or len(env) >= 1024:
            raise ValueError("instance.step_not_admitted")
        env[key] = text
    return env


def load_project_environment(root):
    try:
        descriptor = os.open(
            os.path.join(root, ".env"),
            os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC | os.O_NONBLOCK,
        )
    except FileNotFoundError:
        return {}
    with os.fdopen(descriptor, "r", encoding="utf-8") as stream:
        details = os.fstat(stream.fileno())
        if (
            not stat.S_ISREG(details.st_mode)
            or details.st_uid != os.getuid()
            or details.st_gid != os.getgid()
            or details.st_mode & 0o022
            or details.st_size > 1048576
        ):
            raise ValueError("instance.step_layout_invalid")
        return parse_project_environment(stream.read(1048577))
