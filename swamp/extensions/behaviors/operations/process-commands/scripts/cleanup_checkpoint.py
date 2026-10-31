"""Retire one verified installation checkpoint, only when its parent no longer needs rollback."""

import errno

from process_common import Refused, main
from unit_files import checkpoint, read_file, sync_directory, unit_input


def cleanup(value):
    admitted = unit_input(value)
    try:
        root = checkpoint(value, admitted)
    except FileNotFoundError:
        return {"ready": True}
    paths = list(root.iterdir())
    for path in paths:
        if path.name not in (value["name"], "previous.service", "originally-absent"):
            raise Refused("process.name_collision")
        read_file(path, value["process_id"], private=True)
    for path in paths:
        path.unlink()
    sync_directory(root)
    root.rmdir()
    for parent in [root.parent, root.parent.parent]:
        try:
            parent.rmdir()
        except OSError as error:
            if error.errno != errno.ENOTEMPTY:
                raise
    return {"ready": True}


if __name__ == "__main__":
    main(cleanup)
