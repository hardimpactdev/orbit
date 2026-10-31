"""Apply one captured environment or URL write. Private material comes from vault env."""

import json
import os
import pwd
import re
import stat
import subprocess
import sys
import tempfile
from pathlib import Path


def write(path, content, owner, mode=0o600):
    previous = path.read_text() if path.is_file() else None
    if content == previous:
        return
    descriptor, temporary = tempfile.mkstemp(prefix=".orbit-env-", dir=path.parent)
    try:
        with os.fdopen(descriptor, "w") as output:
            os.fchmod(output.fileno(), mode)
            os.fchown(output.fileno(), owner.pw_uid, owner.pw_gid)
            output.write(content)
        os.replace(temporary, path)
    finally:
        Path(temporary).unlink(missing_ok=True)


def lines(content, key):
    return list(re.finditer(r"(?m)^" + key + r"=([^\r\n]*)", content))


def put(content, key, value):
    matches = lines(content, key)
    if not matches:
        return content + ("\n" if content and not content.endswith("\n") else "") + value + "\n"
    match = matches[0]
    return content[:match.start()] + value + content[match.end():]


def plain(content, key):
    matches = lines(content, key)
    value = matches[0][1].strip() if matches else ""
    return value[1:-1] if re.fullmatch(r"(['\"]).*\1", value) else value


def replace_cached_url(source, url):
    tokens = [match for match in re.finditer(r"'(?:\\.|[^'\\])*'|\"(?:\\.|[^\"\\])*\"|/\*[\s\S]*?\*/|//[^\n]*|\#[^\n]*|=>|[()[\],]|[A-Za-z_][A-Za-z0-9_]*|\S", source) if not re.match(r"/\*|//|#", match[0])]
    stack, found, pending = [], [], None
    for index, match in enumerate(tokens):
        token = match[0]
        if token in ("(", "["):
            stack.append((stack[-1] if stack else []) + ([pending] if pending else []))
            pending = None
        elif token in (")", "]"):
            if not stack:
                raise ValueError("Invalid Laravel cache")
            stack.pop()
            pending = None
        elif token == ",":
            pending = None
        elif token[0] in "'\"" and index + 1 < len(tokens) and tokens[index + 1][0] == "=>":
            key = token[1:-1]
            if len(stack) == 2 and stack[-1] == ["app"] and key == "url":
                if index + 2 >= len(tokens) or tokens[index + 2][0][0] not in "'\"":
                    raise ValueError("Invalid Laravel cache")
                found.append(tokens[index + 2])
            pending = key
    if stack or len(found) != 1:
        raise ValueError("Invalid Laravel cache")
    match = found[0]
    return source[:match.start()] + "'" + url.replace("\\", "\\\\").replace("'", "\\'") + "'" + source[match.end():]


def apply(value, private):
    data = value["input"] | private
    owner = pwd.getpwnam(data.get("user", data.get("owner")))
    if value["name"] == "instance.write-environment":
        base = Path(data["base"])
        if data["require_home"] and base != Path(owner.pw_dir):
            raise ValueError("Unexpected production home")
        path = base / data["file"]
        if not data["require_home"]:
            git = ["/usr/bin/sudo", "-u", owner.pw_name, "/usr/bin/git", "-c", "core.hooksPath=/dev/null", "-c", "core.fsmonitor=false", "-C", str(base)]
            if (subprocess.run([*git, "rev-parse", "--is-inside-work-tree"], capture_output=True, check=False).returncode == 0
                and subprocess.run([*git, "ls-files", "--error-unmatch", "--", data["file"]], capture_output=True, check=False).returncode != 1):
                raise ValueError("Tracked environment file")
        if len(data["content"].encode()) > data["maximum"]:
            raise ValueError("Environment too large")
        write(path, data["content"], owner)
        return
    production = value["name"] == "instance.write-directory-url"
    if production:
        home = Path(data["home"])
        if home != Path(owner.pw_dir):
            raise ValueError("Unexpected production home")
        if not (home / "current").exists():
            return
        release = (home / "current").resolve()
        if release.parent != home / "releases":
            raise ValueError("Unexpected production release")
        application = release / data["directory"]
        base = home / "env" / data["directory"]
    else:
        application = base = Path(data["directory"])
    if (production or data["require_artisan"]) and not (application / "artisan").is_file():
        return
    base.mkdir(mode=0o700, parents=True, exist_ok=True)
    if production:
        for directory in [base, *(parent for parent in base.parents if parent != home and home in parent.parents)]:
            os.chown(directory, owner.pw_uid, owner.pw_gid)
    path = base / ".env"
    created = not path.exists()
    original = path.read_text() if not created else (application / ".env.example").read_text() if (application / ".env.example").is_file() else ""
    if len(lines(original, "APP_URL")) > 1:
        raise ValueError("Duplicate application URL")
    updated = put(original, "APP_URL", "APP_URL=" + data["url"])
    keys = lines(updated, "APP_KEY")
    if len(keys) < 2 and ((created and (not plain(updated, "APP_KEY") or data.get("app_key_stored", False))) or (len(keys) == 1 and not plain(updated, "APP_KEY"))):
        updated = put(updated, "APP_KEY", data["app_key"])
    if created and len(lines(updated, "APP_NAME")) < 2 and (data["app_name_stored"] or plain(updated, "APP_NAME") in ("", "Laravel")):
        updated = put(updated, "APP_NAME", data["app_name"])
    cache = application / "bootstrap/cache/config.php"
    cached = cache.read_text() if not production and cache.is_file() else None
    updated_cache = replace_cached_url(cached, data["url"]) if cached is not None else None
    mode = stat.S_IMODE(path.stat().st_mode) if not created else 0o600
    write(path, updated, owner, mode & 0o770 if production else mode)
    if updated_cache is not None:
        write(cache, updated_cache, owner, stat.S_IMODE(cache.stat().st_mode))



if __name__ == "__main__":
    try:
        value = json.loads(os.environ["ORBIT_ROUTE_INPUT"])
        apply(value, json.load(sys.stdin))
        print(json.dumps({"ready": True, "route_id": value["route_id"], "instance_id": value["instance_id"]}))
    except (KeyError, ValueError, OSError, subprocess.SubprocessError):
        print('{"error_code":"route.environment_write_failed"}')
        raise SystemExit(1) from None
