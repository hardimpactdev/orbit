"""One owned container rename. The workflow chooses replacement or restoration."""

import os

import process_common as common
from process_common import Refused, artifact_name, main, observe


def rename(value):
    destination = os.environ["ORBIT_PROCESS_DESTINATION_SLOT"]
    if (
        value["runtime"] != "docker"
        or destination not in common.SLOTS
        or destination == value.get("slot", "canonical")
        or value.get("expected_spec") is None
    ):
        raise Refused("process.invalid")
    target = {**value, "slot": destination}
    source_state, target_state = observe(value), observe(target)
    if not source_state["present"] and target_state["present"]:
        return {"ready": True}
    if not source_state["present"]:
        raise Refused("process.not_found")
    if target_state["present"]:
        raise Refused("process.recovery_required")
    if common.command(
        [
            common.DOCKER,
            common.SOCKET,
            "container",
            "rename",
            artifact_name(value),
            artifact_name(target),
        ]
    ).returncode:
        raise Refused("process.activation_failed")
    return {"ready": True}


if __name__ == "__main__":
    main(rename)
