"""One Process start, stop or restart. Timer/boot policy is a separate native step."""

import os

from process_common import DOCKER, SOCKET, SYSTEMCTL, Refused, command, main, owned


def control(value):
    operation = os.environ.get("ORBIT_PROCESS_OPERATION")
    if operation not in ("start", "stop", "restart"):
        raise Refused("process.invalid")
    owned(value)
    argv = (
        [SYSTEMCTL, operation, value["name"]]
        if value["runtime"] == "systemd"
        else [DOCKER, SOCKET, "container", operation, value["name"]]
    )
    if command(argv).returncode:
        raise Refused(f"process.{operation}_failed")
    return {"ready": True}


if __name__ == "__main__":
    main(control)
