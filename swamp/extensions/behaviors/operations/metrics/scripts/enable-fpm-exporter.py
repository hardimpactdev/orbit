import json
import os
import subprocess

v = json.loads(os.environ["ORBIT_SERVICE_INTENT"])
if v["enabled"]:
    subprocess.run(["/usr/bin/systemctl", "enable", "orbit-fpm-exporter"], check=True)
