import os
import subprocess

from support import INPUT

subprocess.run(["/usr/bin/apt-get", "install", "--yes", "--no-install-recommends", "--no-remove", "--", INPUT["package"]], check=True, env={**os.environ, "DEBIAN_FRONTEND": "noninteractive"}, capture_output=True, text=True)
print('{"ready":true}')
