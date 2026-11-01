"""One owned container create request; public definition on disk, environment on stdin."""

import json
import sys
from pathlib import Path

from docker_files import create_body, definition, request_create
from process_common import Refused, main, observe


def create(value):
    intent = json.loads(Path(sys.argv[1]).read_text())
    install = intent["installation"]
    if (
        install["process_id"] != value["process_id"]
        or install["runtime"] != "docker"
        or install["name"] != value["name"]
    ):
        raise Refused("process.invalid")
    public = install["definition"]
    if "environment" in public:
        raise Refused("process.invalid")
    environment = json.loads(sys.stdin.read())
    private = definition({"definition": {**public, "environment": environment}})
    current = observe(value)
    if current["present"]:
        if current["spec"] != install["spec"]:
            raise Refused("process.spec_changed")
        return {"ready": True}
    request_create(value["name"], create_body(value, install, private))
    return {"ready": True}


if __name__ == "__main__":
    main(create)
