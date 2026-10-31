import json
import os
import subprocess

v = json.loads(os.environ["ORBIT_CONTAINER_INTENT"])
subprocess.run(["/usr/bin/docker", "image", "pull", "--", v["image"]], check=True)
