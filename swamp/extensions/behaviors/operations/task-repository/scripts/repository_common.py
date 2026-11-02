"""Public repository identity, snapshots and output formatting for Tasks actions."""

import hashlib
import json
import os
import re
import shutil
import stat
import subprocess
import tempfile
from pathlib import Path


class Refusal(Exception):
    pass


def git(root, *args, env=None):
    return (
        subprocess.check_output(
            [
                "git",
                "-c",
                "core.hooksPath=/dev/null",
                "-c",
                "core.fsmonitor=false",
                "-C",
                root,
                *args,
            ],
            env=env,
            stderr=subprocess.DEVNULL,
        )
        .decode()
        .strip()
    )


def checkout(value):
    root = os.path.abspath(value["checkout_path"])
    if os.path.realpath(root) != root or not os.path.isdir(root):
        raise Refusal("tasks.no_workspace")
    return root


def snapshot(root):
    index = git(root, "rev-parse", "--path-format=absolute", "--git-path", "index")
    with tempfile.TemporaryDirectory(prefix="orbit-task-index-") as scratch:
        candidate = os.path.join(scratch, "index")
        if os.path.exists(index):
            shutil.copy2(index, candidate)
        env = {**os.environ, "GIT_INDEX_FILE": candidate}
        git(root, "add", "--all", env=env)
        return {
            "head": git(root, "rev-parse", "HEAD"),
            "tree": git(root, "write-tree", env=env),
        }


def executable(root, relative):
    if (
        not isinstance(relative, str)
        or not re.fullmatch(r"[A-Za-z0-9_-]+(?:/[A-Za-z0-9_.-]+)*", relative)
        or any(part in (".", "..") for part in relative.split("/"))
    ):
        raise Refusal("tasks.script_unavailable")
    path = os.path.join(root, relative)
    try:
        details = os.lstat(path)
        if (
            not stat.S_ISREG(details.st_mode)
            or not details.st_mode & 0o111
            or os.path.realpath(path) != path
        ):
            raise Refusal("tasks.script_unavailable")
        content = Path(path).read_bytes()
        tracked = subprocess.check_output(
            ["git", "-C", root, "show", "HEAD:" + relative], stderr=subprocess.DEVNULL
        )
        if (
            content != tracked
            or git(root, "ls-tree", "HEAD", "--", relative).split()[0] != "100755"
        ):
            raise Refusal("tasks.script_unavailable")
        return hashlib.sha256(content).hexdigest()
    except (OSError, subprocess.SubprocessError, IndexError):
        raise Refusal("tasks.script_unavailable") from None


def program_environment(ssr):
    env = {
        key: os.environ[key]
        for key in ("HOME", "PATH", "LANG", "LC_ALL", "VP_HOME")
        if key in os.environ
    }
    env.update(COMPOSER_NO_INTERACTION="1", ORBIT_CHECK_PROCESSES="1")
    if "HOME" in env:
        env["COMPOSER_CACHE_DIR"] = env["HOME"] + "/.cache/composer"
    if ssr is not None:
        env.update(
            INERTIA_SSR_PORT=str(ssr), INERTIA_SSR_URL="http://127.0.0.1:" + str(ssr)
        )
    return env


def redact_text(text):
    text = re.sub(r"\x1b\[[0-?]*[ -/]*[@-~]", "", text)
    text = re.sub(
        r"-----BEGIN [A-Z0-9 ]+-----[\s\S]*?(?:-----END [A-Z0-9 ]+-----|$)",
        "[REDACTED]",
        text,
    )
    if "-----END " in text:
        text = text.rsplit("-----END ", 1)[1].split("-----", 1)[-1]
    text = re.sub(r"(?i)(\b[a-z][a-z0-9+.-]*://)[^/@\s]+@", r"\1[REDACTED]@", text)
    text = re.sub(
        r"(?i)(?:Proxy-)?Authorization\s*:[^\r\n]*", "Authorization: [REDACTED]", text
    )
    text = re.sub(r"(?i)\bBearer\s+[A-Za-z0-9._+/=-]+", "Bearer [REDACTED]", text)
    text = re.sub(
        r"(?<![A-Za-z0-9])(?:gh[pousr]_[A-Za-z0-9_]{16,}|github_pat_[A-Za-z0-9_]{20,})",
        "[REDACTED]",
        text,
    )

    def assignment(match):
        return (
            match[0]
            if re.search(
                r"(?i)TOKEN|SECRET|PASSWORD|PASSWD|PASSPHRASE|CREDENTIAL|APIKEY|APPKEY|PRIVATEKEY|KEYS?",
                match[2],
            )
            is None
            else match[1] + match[2] + match[1] + match[3] + "[REDACTED]"
        )

    return re.sub(
        r"([\"\x27]?)([A-Za-z][A-Za-z0-9_-]{0,255})\1(\s*[:=]\s*)(?:\"[^\"\r\n]*\"|\x27[^\x27\r\n]*\x27|[^\s,}&{\[\]]+)",
        assignment,
        text,
    )


def emit(action):
    try:
        result = action()
    except Refusal as error:
        result = {"error_code": str(error)}
    except (OSError, ValueError, TypeError, KeyError, subprocess.SubprocessError):
        result = {"error_code": "tasks.repository_execution_unknown"}
    print(json.dumps(result, ensure_ascii=False), flush=True)
