"""Create one owned container over the fixed Unix socket; no private argv."""

from docker_files import create_body, definition, request_create
from process_common import Refused, artifact_name, main, observe
from unit_files import installation, material


def create(value):
    admitted = installation(value)
    if admitted["runtime"] != "docker" or value.get("slot", "canonical") not in (
        "canonical",
        "candidate",
    ):
        raise Refused("process.invalid")
    private = definition(material(value, admitted))
    current = observe(value)
    if current["present"]:
        if current["spec"] != admitted["spec"]:
            raise Refused("process.spec_changed")
        return {"ready": True}
    request_create(artifact_name(value), create_body(value, admitted, private))
    return {"ready": True}


if __name__ == "__main__":
    main(create)
