import json
import os
import subprocess

v = json.loads(os.environ["ORBIT_CONTAINER_INTENT"])
subprocess.run(["/usr/bin/docker", "container", "start", "--", v["name"]], check=True)
