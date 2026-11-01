"""Observe the canonical Reverb release and service; private file bytes stay on the host."""

import json
import pathlib
import subprocess
import sys

ROLE_INPUT = None


def own_paths(install, releases):
    raise RuntimeError("Canonical policy was not supplied")


UNIT = pathlib.Path("/etc/systemd/system/orbit-websocket.service")


def refuse(code):
    raise ValueError(code)


def regular(path):
    return path.is_file() and not path.is_symlink()


def observe(data):
    install = pathlib.Path(data["install_path"])
    releases = pathlib.Path(str(install) + ".releases")
    previous = own_paths(install, releases)
    current = releases / ("release." + data["run_id"].replace("-", ""))
    return {
        "previous": None if previous is None else str(previous),
        "release": str(current),
        "active": subprocess.run(
            ["/usr/bin/systemctl", "is-active", "--quiet", "orbit-websocket"],
            capture_output=True,
            check=False,
        ).returncode
        == 0,
        "enabled": subprocess.run(
            ["/usr/bin/systemctl", "is-enabled", "--quiet", "orbit-websocket"],
            capture_output=True,
            check=False,
        ).returncode
        == 0,
    }


if __name__ == "__main__":
    try:
        print(json.dumps(observe(ROLE_INPUT)))
    except (ValueError, OSError):
        sys.exit(1)
