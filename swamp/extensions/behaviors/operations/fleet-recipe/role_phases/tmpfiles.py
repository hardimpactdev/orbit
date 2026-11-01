"""Publish the admitted scratch rule with the existing footprint provider."""

import json

from phase_common import execute, inputs


def converge(data):
    execute(
        "canonical_footprint",
        data["intent"]["footprint"]["payloads"]["tmpfiles"],
        "node.publish-tmpfiles",
    )
    return {"ready": True}


if __name__ == "__main__":
    print(json.dumps(converge(inputs())))
