import json
import os
import pathlib
import subprocess

v = json.loads(os.environ["ORBIT_SERVICE_INTENT"])
root = pathlib.Path("/")
p = root / "etc/systemd/system/orbit-fpm-exporter.service"
if not v["active"] and p.exists():
    subprocess.run(["/usr/bin/systemctl", "stop", "orbit-fpm-exporter"], check=True)
