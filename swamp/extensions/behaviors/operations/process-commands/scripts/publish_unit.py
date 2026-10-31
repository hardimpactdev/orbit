"""Publish one validated unit; reload, boot policy and activation are other steps."""

import os

import process_common as common
from process_common import Refused, main
from unit_files import digest, read_file, staged, unit_input, write_file


def publish(value):
    _path, content = staged(value, unit_input(value))
    path = common.UNITS / value["name"]
    previous = read_file(path, value["process_id"])
    if previous == content:
        return {"ready": True}
    expected = os.environ["ORBIT_PROCESS_PREVIOUS_HASH"]
    observed = "null" if previous is None else digest(previous)
    if observed != expected:
        raise Refused("process.spec_changed")
    write_file(path, content, mode=0o644)
    return {"ready": True}


if __name__ == "__main__":
    main(publish)
