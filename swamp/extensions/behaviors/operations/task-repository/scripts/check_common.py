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


def command(source, root, ssr, *, seed=None, environment=None, secret_values=()):
    if environment is None:
        environment = program_environment(ssr)
    if seed is not None:
        environment.update(
            ORBIT_SEED_PATH=seed["path"], ORBIT_SEED_COMMIT=seed["commit"]
        )
    with tempfile.TemporaryFile() as output:
        result = subprocess.run(
            ["/bin/bash", "-lc", source],
            cwd=root,
            stdin=subprocess.DEVNULL,
            stdout=output,
            stderr=subprocess.STDOUT,
            env=environment,
            check=False,
        )
        size = output.tell()
        output.seek(max(0, size - 102400))
        text = output.read().decode("utf-8", errors="replace")
        secrets = {
            line for value in secret_values for line in value.splitlines() if line
        }
        if len(secrets) > 4096:
            text = "[REDACTED]"
        else:
            for secret in sorted(secrets, key=len, reverse=True):
                text = text.replace(secret, "[REDACTED]")
        text = redact_text(text)
    return {
        "exit_code": result.returncode,
        "output": text.encode()[-16384:].decode("utf-8", errors="ignore"),
    }
