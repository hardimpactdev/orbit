"""Perform one existing-PR update or one PR creation with unchanged caller prose."""

import json
import os

from publication_common import intent, request
from repository_common import Refusal, emit


def write():
    value = intent()
    found = json.loads(os.environ["ORBIT_EXISTING_PULL"])
    if "error_code" in found:
        raise Refusal(found["error_code"])
    number = found["number"]
    body = value["pull_request"]
    path = "/repos/" + value["repository"] + "/pulls"
    if number is not None:
        changed = request(path + "/" + str(number), "PATCH", body)
    else:
        changed = request(
            path, "POST", {**body, "head": value["branch"], "base": value["base"]}
        )
    actual = changed["number"]
    if (
        type(actual) is not int
        or actual <= 0
        or (number is not None and actual != number)
    ):
        raise Refusal("tasks.publish_failed")
    return {"number": actual}


if __name__ == "__main__":
    emit(write)
