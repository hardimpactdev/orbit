"""database:server:destroy on the Database server's Node, one chart phase per call.

Arguments: the public intent as JSON and the phase, `remove` or `gone`. Prints one
public JSON object; exit 1 carries an error_code. The data volume is never removed.
"""

import json
import subprocess
import sys

DOCKER = "/usr/bin/docker"


class Refused(Exception):
    def __init__(self, code):
        self.code = code


def docker(*argv):
    return subprocess.run([DOCKER, *argv], text=True, capture_output=True, check=False)


def containers(intent):
    """Every container of the server's Process, including slots left by older creates."""
    reply = docker(
        "ps",
        "--all",
        "--quiet",
        "--no-trunc",
        "--filter",
        "label=orbit.process.id=" + str(intent["process_id"]),
    )
    if reply.returncode:
        raise Refused("database.server_inspect_failed")
    found = reply.stdout.split()
    named = docker(
        "container",
        "inspect",
        "--format",
        '{{.Id}} {{index .Config.Labels "orbit.process.id"}}',
        intent["container"],
    )
    if named.returncode == 0:
        identity, _, owner = named.stdout.strip().partition(" ")
        if owner != str(intent["process_id"]):
            raise Refused("database.server_container_foreign")
        if identity not in found:
            found.append(identity)
    return sorted(found)


def remove(intent):
    for identity in containers(intent):
        if (
            docker("stop", "--time", "60", identity).returncode
            or docker("rm", identity).returncode
        ):
            raise Refused("database.server_remove_failed")
    return {"removed": True}


def gone(intent):
    if containers(intent):
        raise Refused("database.server_remove_incomplete")
    return {"absent": True}


if __name__ == "__main__":
    try:
        value = json.loads(sys.argv[1])
        phase = sys.argv[2]
        result = {"remove": remove, "gone": gone}[phase](value)
    except Refused as error:
        print(json.dumps({"error_code": error.code}))
        raise SystemExit(1) from None
    print(json.dumps(result))
