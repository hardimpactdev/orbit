import json
import os
import subprocess

v = json.loads(os.environ["ORBIT_EXPORTER_INTENT"])
subprocess.run(["/usr/bin/systemctl", "restart", v["service"]], check=True)
