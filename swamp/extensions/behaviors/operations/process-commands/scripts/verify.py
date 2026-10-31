"""One observed-state assertion. Native workflow resume owns another attempt."""

import os

from process_common import Refused, main, owned


def verify(value):
    desired = os.environ.get("ORBIT_PROCESS_DESIRED")
    if desired not in ("running", "stopped"):
        raise Refused("process.invalid")
    state = owned(value)["state"]
    expected = (
        ("active", "reloading") if value["runtime"] == "systemd" else ("running",)
    )
    if desired == "stopped":
        expected = (
            ("inactive", "failed")
            if value["runtime"] == "systemd"
            else ("created", "exited", "dead")
        )
    if state not in expected:
        raise Refused("process.state_mismatch")
    return {"ready": True, "state": state}


if __name__ == "__main__":
    main(verify)
