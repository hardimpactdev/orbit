"""Execute one admitted tracked repository script; native SSH owns its lifetime."""

import json
import os
import subprocess
import sys
import tempfile

from repository_common import (
    Refusal,
    checkout,
    emit,
    executable,
    program_environment,
    redact_text,
)


def execute():
    value = json.load(sys.stdin)
    prepared, before = (json.loads(arg) for arg in sys.argv[1:3])
    for observation in (prepared, before):
        if "error_code" in observation:
            return observation
    root = checkout(value)
    if executable(root, value["script"]) != prepared["entrypoint_sha256"]:
        raise Refusal("tasks.script_unavailable")
    with (
        tempfile.TemporaryDirectory(prefix="orbit-task-program-") as scratch,
        tempfile.TemporaryFile() as output,
    ):
        result = subprocess.run(
            [os.path.join(root, value["script"]), *value["args"]],
            cwd=root,
            stdin=subprocess.DEVNULL,
            stdout=output,
            stderr=subprocess.STDOUT,
            env={**program_environment(value.get("ssr_port")), "TMPDIR": scratch},
            check=False,
        )
        size = output.tell()
        output.seek(max(0, size - 102400))
        text = redact_text(output.read().decode("utf-8", errors="replace"))
    return {
        "exit_code": result.returncode,
        "output": text.encode()[-16384:].decode("utf-8", errors="ignore"),
        "output_truncated": size > 16384,
    }


if __name__ == "__main__":
    emit(execute)
