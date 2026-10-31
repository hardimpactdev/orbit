"""Remove one inactive owned container. Stop is a separate native step."""

import os

import process_common as common
from process_common import Refused, artifact_name, main, observe


def remove(value):
    volumes = os.environ.get("ORBIT_PROCESS_ANONYMOUS_VOLUMES", "false")
    if value["runtime"] != "docker" or volumes not in ("true", "false"):
        raise Refused("process.invalid")
    state = observe(value)
    if not state["present"]:
        return {"ready": True}
    if state["state"] not in ("created", "exited", "dead"):
        raise Refused("process.state_mismatch")
    argv = [common.DOCKER, common.SOCKET, "container", "rm"]
    if volumes == "true":
        argv.append("--volumes")
    if common.command([*argv, artifact_name(value)]).returncode:
        raise Refused("process.remove_failed")
    return {"ready": True}


if __name__ == "__main__":
    main(remove)
