"""Remove the drained Orbit CI project; never delete a running runner or user guest."""

import json
import subprocess

REMOVAL_INPUT = None


def remove(_data):
    result = subprocess.run(["/usr/bin/incus", "project", "list", "--format=json"], capture_output=True, text=True, check=True)
    if not any(project["name"] == "orbit-ci" for project in json.loads(result.stdout)):
        return {"removed": True}
    instances = subprocess.run(["/usr/bin/incus", "list", "--project", "orbit-ci", "--format=json"], capture_output=True, text=True, check=True)
    if json.loads(instances.stdout):
        raise ValueError("ci_runner.host_occupied")
    # Canonical non-purge removal retains CI project, image cache, bridge and ACL.
    return {"retained": True}


if __name__ == "__main__":
    print(json.dumps(remove(REMOVAL_INPUT)))
