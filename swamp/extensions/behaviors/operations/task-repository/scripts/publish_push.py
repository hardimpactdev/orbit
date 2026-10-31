"""Push the exact admitted object without force from the trusted bare repository."""

import os
import subprocess

from publication_common import git_environment, intent, token
from repository_common import Refusal, emit


def push():
    value = intent()
    env = git_environment(token())
    try:
        result = subprocess.run(
            [
                "git",
                "-C",
                os.environ["ORBIT_PUBLICATION_ROOT"] + "/repository.git",
                "push",
                "--porcelain",
                "--",
                "https://github.com/" + value["repository"] + ".git",
                value["commit"] + ":refs/heads/" + value["branch"],
            ],
            check=False,
            env=env,
            capture_output=True,
            timeout=60,
        )
    except (OSError, subprocess.SubprocessError):
        raise Refusal("tasks.push_failed") from None
    if result.returncode:
        error = (result.stdout + result.stderr).decode(errors="replace")
        if "refusing to allow a GitHub App" in error and ".github/workflows/" in error:
            raise Refusal("tasks.workflows_permission")
        if "non-fast-forward" in error or "(fetch first)" in error:
            raise Refusal("tasks.not_fast_forward")
        raise Refusal("tasks.push_failed")
    return {"pushed": True}


if __name__ == "__main__":
    emit(push)
