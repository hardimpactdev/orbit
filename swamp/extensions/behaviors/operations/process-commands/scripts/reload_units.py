"""One systemd daemon reload, independent of any Process activation."""

import process_common as common
from process_common import Refused, main
from unit_files import owned_directory


def reload(value):
    if value["runtime"] != "systemd":
        raise Refused("process.invalid")
    # Reload is also needed after removal or restoring an originally absent unit.
    owned_directory(common.UNITS)
    if common.command([common.SYSTEMCTL, "daemon-reload"]).returncode:
        raise Refused("process.activation_failed")
    return {"ready": True}


if __name__ == "__main__":
    main(reload)
