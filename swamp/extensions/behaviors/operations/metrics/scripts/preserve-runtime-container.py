import json
import os
import subprocess

v = json.loads(os.environ["ORBIT_CONTAINER_INTENT"])
before = json.loads(os.environ["ORBIT_CONTAINER_BEFORE"])
if (
    before["current"] is not None
    and before["current"]["hash"] != before["expected_hash"]
):
    subprocess.run(
        ["/usr/bin/docker", "container", "rename", v["name"], v["backup"]], check=True
    )
