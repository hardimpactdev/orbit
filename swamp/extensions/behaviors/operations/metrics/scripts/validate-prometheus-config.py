import json
import os
import subprocess

v = json.loads(os.environ["ORBIT_CONTAINER_INTENT"])
subprocess.run(
    [
        "/usr/bin/docker",
        "container",
        "run",
        "--rm",
        "--interactive",
        "--entrypoint",
        "/bin/promtool",
        "--",
        v["image"],
        "check",
        "config",
        "/dev/stdin",
    ],
    input=v["configuration"][0][1],
    text=True,
    check=True,
)
print('{"valid":true}')
