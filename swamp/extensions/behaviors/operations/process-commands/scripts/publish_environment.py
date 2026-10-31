"""Publish one immutable root-owned 0600 Process EnvironmentFile."""

import process_common as common
from process_common import Refused, main
from unit_files import (
    ORBIT_ROOT,
    digest,
    directory,
    material,
    read_file,
    unit_input,
    write_file,
)


def publish(value):
    admitted = unit_input(value)
    read_file(common.UNITS / value["name"], value["process_id"])
    data = material(value, admitted)
    content, environment = data["content"], data["environment"]
    root = ORBIT_ROOT / "processes"
    path = root / str(value["process_id"]) / (digest(environment) + ".env")
    if environment == f"# Orbit Process {value['process_id']}\n" and not any(
        line.startswith(f"EnvironmentFile={root}/") for line in content.splitlines()
    ):
        return {"ready": True}
    if (
        not environment.startswith(f"# Orbit Process {value['process_id']}\n")
        or f"EnvironmentFile={path}" not in content.splitlines()
    ):
        raise Refused("process.invalid")
    directory(ORBIT_ROOT, private=False)
    directory(root)
    directory(path.parent)
    previous = read_file(path, private=True)
    if previous is not None and previous != environment:
        raise Refused("process.name_collision")
    if previous is None:
        write_file(path, environment)
    return {"ready": True}


if __name__ == "__main__":
    main(publish)
