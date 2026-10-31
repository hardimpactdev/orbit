"""Public check path and command evidence helpers; no execution ownership."""

import os
import subprocess
import tempfile

from repository_common import Refusal, program_environment, redact_text


def inside(root, relative):
    target = os.path.realpath(os.path.join(root, relative or "."))
    if target != root and not target.startswith(root + os.sep):
        raise Refusal("tasks.deliverable_invalid")
    return target


def overlay_path(root, relative):
    if (
        not isinstance(relative, str)
        or relative.startswith("/")
        or any(part in ("", ".", "..") for part in relative.split("/"))
    ):
        raise Refusal("tasks.deliverable_invalid")
    return inside(root, relative)


def command(source, root, ssr):
    with tempfile.TemporaryFile() as output:
        result = subprocess.run(
            ["/bin/bash", "-lc", source],
            cwd=root,
            stdin=subprocess.DEVNULL,
            stdout=output,
            stderr=subprocess.STDOUT,
            env=program_environment(ssr),
            check=False,
        )
        size = output.tell()
        output.seek(max(0, size - 102400))
        text = redact_text(output.read().decode("utf-8", errors="replace"))
    return {
        "exit_code": result.returncode,
        "output": text.encode()[-16384:].decode("utf-8", errors="ignore"),
    }
