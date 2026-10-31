import json
import os
import subprocess

v = json.loads(os.environ["ORBIT_CONTAINER_INTENT"])
before = json.loads(os.environ["ORBIT_CONTAINER_BEFORE"])
if before["current"] is None or before["current"]["hash"] != before["expected_hash"]:
    args = [
        "/usr/bin/docker",
        "container",
        "create",
        "--pull=never",
        "--name",
        v["name"],
        "--restart",
        "unless-stopped",
        "--network",
        "host",
        "--log-driver",
        "json-file",
        "--log-opt",
        "max-size=10m",
        "--log-opt",
        "max-file=3",
    ]
    for key, value in sorted(v["labels"].items()):
        args.extend(["--label", key + "=" + value])
    args.extend(
        [
            "--label",
            "com.orbit.metrics.spec-hash=" + before["expected_hash"],
            "--mount",
            "type=volume,source=" + v["volume"] + ",target=" + v["mount_target"],
        ]
    )
    for mount in v["mounts"]:
        args.extend(["--volume", mount])
    for value in v["environment"]:
        args.extend(["--env", value])
    args.extend(
        [
            "--health-cmd",
            "wget --no-verbose --tries=1 --spider " + v["health_url"],
            "--health-start-period",
            "30s",
            "--health-interval",
            "5s",
            "--health-retries",
            "12",
            "--",
            v["image"],
            *v["command"],
        ]
    )
    subprocess.run(args, check=True)
