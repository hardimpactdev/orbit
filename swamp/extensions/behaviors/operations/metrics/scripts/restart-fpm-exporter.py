import json
import os
import subprocess

v = json.loads(os.environ["ORBIT_SERVICE_INTENT"])
if v["active"]:
    subprocess.run(["/usr/bin/systemctl", "restart", "orbit-fpm-exporter"], check=True)
