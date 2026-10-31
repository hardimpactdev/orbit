"""Read the exact destination branch once; never return Git diagnostics."""

import os
import subprocess

from publication_common import git_environment, intent, token
from repository_common import Refusal, emit


def observe():
    value = intent()
    try:
        raw = subprocess.check_output(
            [
                "git",
                "-C",
                os.environ["ORBIT_PUBLICATION_ROOT"] + "/repository.git",
                "ls-remote",
                "--refs",
                "https://github.com/" + value["repository"] + ".git",
                "refs/heads/" + value["branch"],
            ],
            env=git_environment(token()),
            stderr=subprocess.DEVNULL,
            timeout=60,
            text=True,
        ).split()
        return {"commit": raw[0] if raw else None}
    except (OSError, subprocess.SubprocessError):
        raise Refusal("tasks.push_failed") from None


if __name__ == "__main__":
    emit(observe)
