"""Remove one inactive owned container. Stop is a separate native step."""

import process_common as common
from process_common import Refused, artifact_name, main, observe


def remove(value):
    if value["runtime"] != "docker":
        raise Refused("process.invalid")
    state = observe(value)
    if not state["present"]:
        return {"ready": True}
    if state["state"] not in ("created", "exited", "dead"):
        raise Refused("process.state_mismatch")
    # --volumes removes only anonymous volumes; named Process volumes stay.
    argv = [common.DOCKER, common.SOCKET, "container", "rm", "--volumes"]
    if common.command([*argv, artifact_name(value)]).returncode:
        raise Refused("process.remove_failed")
    return {"ready": True}


if __name__ == "__main__":
    main(remove)
