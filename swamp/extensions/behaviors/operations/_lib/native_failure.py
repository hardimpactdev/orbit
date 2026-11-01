"""Run one native step's existing command, keeping success stdout and only redacted stderr on failure."""

import json
import os
import re
import shutil
import subprocess
import sys
import tempfile


def private_values(value):
    if isinstance(value, str):
        yield value
    elif isinstance(value, dict):
        for child in value.values():
            yield from private_values(child)
    elif isinstance(value, list):
        for child in value:
            yield from private_values(child)


def redact(text, values):
    text = re.sub(r"\x1b\[[0-?]*[ -/]*[@-~]|[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]", "", text)
    for value in sorted(set(values), key=len, reverse=True):
        if len(value) >= 3:
            text = text.replace(value, "[REDACTED]")
    text = re.sub(
        r"-----BEGIN [A-Z0-9 ]+-----[\s\S]*?(?:-----END [A-Z0-9 ]+-----|$)",
        "[REDACTED]",
        text,
    )
    text = re.sub(
        r"\b([a-z][a-z0-9+.-]*://)[^\s/@]+@",
        r"\1[REDACTED]@",
        text,
        flags=re.IGNORECASE,
    )
    text = re.sub(
        r"\b((?:Proxy-)?Authorization)\s*:\s*[^\r\n]+",
        r"\1: [REDACTED]",
        text,
        flags=re.IGNORECASE,
    )
    text = re.sub(
        r"\bBearer\s+(?:\"[^\"]*\"|'[^']*'|[A-Za-z0-9._+/=\-]+)",
        "Bearer [REDACTED]",
        text,
        flags=re.IGNORECASE,
    )
    text = re.sub(
        r"\b(?:gh[pousr]_[A-Za-z0-9_]{16,}|github_pat_[A-Za-z0-9_]{20,})\b",
        "[REDACTED]",
        text,
    )
    text = re.sub(
        r"""(["']?(?:[A-Za-z0-9]+[_-])*(?:password|passwd|passphrase|token|secret|credential|api[_-]?key|private[_-]?key)["']?\s*[:=]\s*)(?:"(?:\\.|[^"\\])*"|'[^']*'|[^\s,}]+)""",
        r"\1[REDACTED]",
        text,
        flags=re.IGNORECASE,
    )
    text = re.sub(r"\x1b\[[0-?]*[ -/]*[@-~]|[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]", "", text)
    return text


def stderr_tail(raw, values):
    # Redact the whole stream before cutting a credential's prefix off at the tail boundary.
    text = redact(raw.decode("utf-8", errors="replace"), values)
    tail = text.encode("utf-8")[-4096:].decode("utf-8", errors="ignore")
    return redact(tail, values)


def main():
    options = sys.argv[1:]
    capture_stdin = bool(options and options[0] == "--stdin")
    if capture_stdin:
        options = options[1:]
    if not options or options[0] != "--" or len(options) < 2:
        raise SystemExit("Usage: native_failure.py [--stdin] -- command [arguments...]")
    argv = options[1:]
    private = sys.stdin.buffer.read() if capture_stdin else None
    values = []
    ordinary = {
        "PATH",
        "HOME",
        "USER",
        "LOGNAME",
        "SHELL",
        "PWD",
        "LANG",
        "LC_ALL",
        "TERM",
    }
    for key, value in os.environ.items():
        if key in ordinary or key.startswith("ORBIT_FAILURE_"):
            continue
        if len(value) >= 8:
            values.append(value)
            try:
                values.extend(private_values(json.loads(value)))
            except (ValueError, TypeError):
                pass
    if private is not None:
        values.append(private.decode("utf-8", errors="replace"))
        try:
            values.extend(private_values(json.loads(private)))
        except (ValueError, TypeError):
            pass
    env = os.environ.copy()
    stdin = private
    try:
        with tempfile.TemporaryFile() as output:
            child = subprocess.run(
                argv,
                input=stdin,
                stdout=output,
                stderr=subprocess.PIPE,
                env=env,
                check=False,
            )
            error = child.stderr
            code = child.returncode if child.returncode >= 0 else 128 - child.returncode
            if code == 0:
                output.seek(0)
                shutil.copyfileobj(output, sys.stdout.buffer)
    except OSError as exception:
        error = str(exception).encode()
        code = 127
    sys.stderr.write(stderr_tail(error, values))
    return code


if __name__ == "__main__":
    sys.exit(main())
