"""Remove only immutable owned environment files after their unit was retired."""

import re

from process_common import Refused, main, observe
from unit_files import ORBIT_ROOT, digest, owned_directory, read_file, sync_directory


def remove(value):
    if value["runtime"] != "systemd" or observe(value)["present"]:
        raise Refused("process.state_mismatch")
    root = ORBIT_ROOT / "processes"
    try:
        root.lstat()
    except FileNotFoundError:
        return {"ready": True}
    owned_directory(ORBIT_ROOT)
    owned_directory(root, private=True)
    owner = root / str(value["process_id"])
    try:
        owner.lstat()
    except FileNotFoundError:
        return {"ready": True}
    owned_directory(owner, private=True)
    paths = list(owner.iterdir())
    # Validate the complete captured set before the first deletion.
    for path in paths:
        content = read_file(path, private=True)
        if (
            not re.fullmatch("[a-f0-9]{64}\\.env", path.name)
            or content is None
            or not content.startswith(f"# Orbit Process {value['process_id']}\n")
            or digest(content) + ".env" != path.name
        ):
            raise Refused("process.name_collision")
    for path in paths:
        path.unlink()
    sync_directory(owner)
    owner.rmdir()
    sync_directory(root)
    return {"ready": True}


if __name__ == "__main__":
    main(remove)
