"""Bound canonical releases while retaining a failed cutover for native resume."""

import json
import pathlib
import re
import shutil
import sys

ROLE_INPUT = None
UNIT = pathlib.Path("/etc/systemd/system/orbit-websocket.service")


def own_paths(install, releases):
    raise RuntimeError("Canonical policy was not supplied")


def refuse(code):
    raise ValueError(code)


def regular(path):
    return path.is_file() and not path.is_symlink()


def cleanup(data):
    install = pathlib.Path(data["install_path"])
    releases = pathlib.Path(str(install) + ".releases")
    current = own_paths(install, releases)
    candidate = pathlib.Path(data["release"])
    previous = None if data["previous"] is None else pathlib.Path(data["previous"])
    if candidate.parent != releases or not re.fullmatch(
        r"release\.[a-f0-9]{32}", candidate.name
    ):
        refuse("runtime_ownership_drift")
    keep = {current, previous}
    if data["retain_candidate"]:
        keep.add(candidate)
    children = (
        releases.iterdir()
        if data["retain_candidate"] and releases.exists()
        else [candidate]
    )
    changed = False
    for child in children:
        if (
            child not in keep
            and re.fullmatch(r"release\.[a-f0-9]{32}", child.name)
            and child.is_dir()
            and not child.is_symlink()
        ):
            shutil.rmtree(child)
            changed = True
    return {"ready": True, "changed": changed}


if __name__ == "__main__":
    try:
        print(json.dumps(cleanup(ROLE_INPUT)))
    except (ValueError, OSError):
        sys.exit(1)
