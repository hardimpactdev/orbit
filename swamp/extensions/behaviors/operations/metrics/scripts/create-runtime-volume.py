import json
import os
import subprocess

v = json.loads(os.environ["ORBIT_CONTAINER_INTENT"])
before = json.loads(os.environ["ORBIT_CONTAINER_BEFORE"])
if before["volume"] is None:
    subprocess.run(
        [
            "/usr/bin/docker",
            "volume",
            "create",
            "--label",
            "com.orbit.managed=metrics",
            "--label",
            "com.orbit.metrics.volume=" + v["service"],
            "--",
            v["volume"],
        ],
        check=True,
    )
