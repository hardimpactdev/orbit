"""Ensure one admitted Docker image is local. Container creation is a separate step."""

import process_common as common
from docker_files import definition
from process_common import Refused, main
from unit_files import installation, material


def pull(value):
    admitted = installation(value)
    if admitted["runtime"] != "docker":
        raise Refused("process.invalid")
    private = definition(material(value, admitted))
    image = private["image"]
    if common.command(
        [common.DOCKER, common.SOCKET, "image", "inspect", "--format", "{{.Id}}", image]
    ).returncode:
        if common.command(
            [common.DOCKER, common.SOCKET, "image", "pull", "--quiet", image]
        ).returncode:
            raise Refused("process.image_failed")
    return {"ready": True}


if __name__ == "__main__":
    main(pull)
