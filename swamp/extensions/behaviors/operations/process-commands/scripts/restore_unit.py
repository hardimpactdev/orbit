"""Restore the exact previous unit file. The parent then reloads and restores state."""

import os

import process_common as common
from process_common import Refused, main
from unit_files import (
    checkpoint,
    digest,
    read_file,
    staged,
    sync_directory,
    unit_input,
    write_file,
)


def restore(value):
    admitted = unit_input(value)
    _path, candidate = staged(value, admitted)
    path = common.UNITS / value["name"]
    current = read_file(path, value["process_id"])
    expected = os.environ["ORBIT_PROCESS_PREVIOUS_HASH"]
    if expected == "null":
        if current is not None:
            if digest(current) != digest(candidate):
                raise Refused("process.recovery_required")
            path.unlink()
            sync_directory(path.parent)
        return {"ready": True}
    previous = read_file(
        checkpoint(value, admitted) / "previous.service",
        value["process_id"],
        private=True,
    )
    if previous is None or digest(previous) != expected:
        raise Refused("process.recovery_required")
    if current == previous:
        return {"ready": True}
    if current is not None and current != candidate:
        raise Refused("process.spec_changed")
    # Old units may contain inline credentials. Restore them privately.
    write_file(path, previous)
    return {"ready": True}


if __name__ == "__main__":
    main(restore)
