"""Capture the previous owned unit in one protected publication checkpoint."""

import process_common as common
from process_common import Refused, main
from unit_files import checkpoint, digest, read_file, unit_input, write_file


def backup(value):
    admitted = unit_input(value)
    root = checkpoint(value, admitted, create=True)
    path = root / "previous.service"
    absent = root / "originally-absent"
    previous = read_file(path, value["process_id"], private=True)
    if read_file(absent, value["process_id"], private=True) is not None:
        if previous is not None:
            raise Refused("process.recovery_required")
        return {"ready": True, "previous_hash": None}
    if previous is None:
        previous = read_file(common.UNITS / value["name"], value["process_id"])
        if previous is not None:
            write_file(path, previous)
        else:
            write_file(absent, f"X-Orbit-Process-ID={value['process_id']}\n")
    return {
        "ready": True,
        "previous_hash": None if previous is None else digest(previous),
    }


if __name__ == "__main__":
    main(backup)
