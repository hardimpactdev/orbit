"""Stage one admitted unit, resolving its account template on the placed Node."""

import process_common as common
from process_common import Refused, main
from unit_files import (
    checkpoint,
    digest,
    material,
    read_file,
    resolve_content,
    unit_input,
    write_file,
)


def stage(value):
    admitted = unit_input(value)
    read_file(common.UNITS / value["name"], value["process_id"])
    content = resolve_content(material(value, admitted), admitted)
    path = checkpoint(value, admitted, create=True) / value["name"]
    previous = read_file(path, value["process_id"], private=True)
    if previous is not None and previous != content:
        raise Refused("process.recovery_required")
    if previous is None:
        write_file(path, content)
    return {"ready": True, "content_hash": digest(content)}


if __name__ == "__main__":
    main(stage)
