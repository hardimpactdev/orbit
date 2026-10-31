import json
import os
import subprocess

v = json.loads(os.environ["ORBIT_EXPORTER_INTENT"])
source = json.loads(os.environ["ORBIT_FIREWALL_BEFORE"])["source"]
if source != v["metrics_address"]:
    subprocess.run(
        [
            "/usr/sbin/ufw",
            "allow",
            "in",
            "on",
            "orbit",
            "proto",
            "tcp",
            "from",
            v["metrics_address"],
            "to",
            v["address"],
            "port",
            v["port"],
            "comment",
            v["comment"],
        ],
        check=True,
    )
print('{"admitted":true}')
