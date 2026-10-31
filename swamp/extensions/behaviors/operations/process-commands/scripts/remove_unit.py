"""Remove one owned, already inactive unit. Stop and disable are parent steps."""

import process_common as common
from process_common import Refused, main, observe
from unit_files import owned_directory, sync_directory


def remove(value):
    if value["runtime"] != "systemd":
        raise Refused("process.invalid")
    owned_directory(common.UNITS)
    state = observe(value)
    if not state["present"]:
        return {"ready": True}
    if state["state"] not in ("inactive", "failed"):
        raise Refused("process.state_mismatch")
    (common.UNITS / value["name"]).unlink()
    sync_directory(common.UNITS)
    return {"ready": True}


if __name__ == "__main__":
    main(remove)
