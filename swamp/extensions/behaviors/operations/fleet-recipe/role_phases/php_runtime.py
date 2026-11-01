"""Converge all admitted PHP configurations as one legacy footprint phase."""

import json

from phase_common import execute, inputs


def converge(data):
    configurations = (
        data["intent"]["footprint"]["payloads"]
        .get("php-runtime", {})
        .get("configurations", [])
    )
    for configuration in configurations:
        execute("canonical_php_runtime", configuration, "php.configure-runtime")
    return {"ready": True}


if __name__ == "__main__":
    print(json.dumps(converge(inputs())))
