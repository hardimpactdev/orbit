import json
import os
import subprocess

v = json.loads(os.environ["ORBIT_EXPORTER_INTENT"])
source = json.loads(os.environ["ORBIT_FIREWALL_BEFORE"])["source"]
if source is not None and (not v["selected"] or source != v["metrics_address"]):
    subprocess.run(
        [
            "/usr/sbin/ufw",
            "--force",
            "delete",
            "allow",
            "in",
            "on",
            "orbit",
            "proto",
            "tcp",
            "from",
            source,
            "to",
            v["address"],
            "port",
            v["port"],
            "comment",
            v["comment"],
        ],
        check=True,
    )
print('{"removed":true}')
