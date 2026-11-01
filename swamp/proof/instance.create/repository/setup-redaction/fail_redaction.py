"""Fail with private dotenv values and invalid UTF-8, without exposing argv."""
import json
import sys
from pathlib import Path


def emit(root, stderr, stdout):
    values = json.loads((Path(root) / ".orbit-proof-private-values").read_text())
    stderr.write(b"public-padding\n" * 400)
    for field in ("tab", "hash"):
        value = values[field].encode()
        stderr.write(b"full-value=" + value + b"\n")
        middle = len(value) // 2
        stderr.write(b"invalid-utf8=" + value[:middle] + b"\xff" + value[middle:] + b"\n")
        stderr.write(b"partial-value=" + value[12:36] + b"\n")
    stdout.write(values["stdout"].encode() + b"\n")
    stderr.write(b"public-redaction-tail-marker\n")


if __name__ == "__main__":
    emit(Path.cwd(), sys.stderr.buffer, sys.stdout.buffer)
    raise SystemExit(47)
