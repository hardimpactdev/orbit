"""Import the copied bundle into a new trusted bare repository on 209."""

import json
import os
import subprocess
from pathlib import Path

from repository_common import Refusal, emit, git


def load():
    value = json.loads(os.environ["ORBIT_PUBLICATION_INPUT"])
    root = Path(os.environ["ORBIT_PUBLICATION_ROOT"])
    repo = str(root / "repository.git")
    env = {
        key: os.environ[key] for key in ("PATH", "HOME", "LANG") if key in os.environ
    }
    env.update(GIT_CONFIG_GLOBAL="/dev/null", GIT_CONFIG_NOSYSTEM="1")
    subprocess.run(
        [
            "git",
            "init",
            "--quiet",
            "--bare",
            "--template=",
            "--object-format=" + ("sha256" if len(value["commit"]) == 64 else "sha1"),
            repo,
        ],
        env=env,
        check=True,
        capture_output=True,
    )
    git(
        repo,
        "fetch",
        "--no-tags",
        str(root / "candidate.bundle"),
        "HEAD:refs/heads/candidate",
        env=env,
    )
    if (
        git(repo, "rev-parse", "refs/heads/candidate", env=env) != value["commit"]
        or git(repo, "rev-parse", value["commit"] + "^{tree}", env=env) != value["tree"]
    ):
        raise Refusal("tasks.head_moved")
    return {"imported": True}


if __name__ == "__main__":
    emit(load)
