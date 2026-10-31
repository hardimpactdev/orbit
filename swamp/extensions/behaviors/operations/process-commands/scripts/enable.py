"""One systemd boot-policy action; starting/stopping is a separate native step."""

import os

from process_common import SYSTEMCTL, Refused, command, main, owned


def enable(value):
    operation = os.environ.get("ORBIT_PROCESS_ENABLE")
    if value["runtime"] != "systemd" or operation not in ("enable", "disable"):
        raise Refused("process.invalid")
    owned(value)
    if command([SYSTEMCTL, operation, value["name"]]).returncode:
        raise Refused("process.enable_failed")
    return {"ready": True}


if __name__ == "__main__":
    main(enable)
