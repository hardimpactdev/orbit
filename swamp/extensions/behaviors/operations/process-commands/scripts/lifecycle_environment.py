"""Publish an immutable EnvironmentFile from protected stdin; public unit stays in inputs."""

import json
import sys
from pathlib import Path

from process_common import Refused, main
from unit_files import digest, directory, read_file, write_file


def publish(value):
    install = json.loads(Path(sys.argv[1]).read_text())["installation"]
    environment = sys.stdin.read()
    root = Path("/etc/orbit")
    path = (
        root / "processes" / str(value["process_id"]) / (digest(environment) + ".env")
    )
    if (
        install["runtime"] != "systemd"
        or install["name"] != value["name"]
        or install["process_id"] != value["process_id"]
        or install["environment_path"] != str(path)
        or not environment.startswith(f"# Orbit Process {value['process_id']}\n")
        or "\0" in environment
        or f"EnvironmentFile={path}" not in install["unit"].splitlines()
    ):
        raise Refused("process.invalid")
    directory(root, private=False)
    directory(root / "processes")
    directory(path.parent)
    previous = read_file(path, private=True)
    if previous is not None and previous != environment:
        raise Refused("process.name_collision")
    if previous is None:
        write_file(path, environment)
    return {"ready": True}


if __name__ == "__main__":
    main(publish)
