"""Remove only the selected package; dpkg owns its dependency checks and lock."""
import json
import os
import subprocess
import sys

REMOVE_INPUT = json.loads(sys.argv[1])
subprocess.run(["/usr/bin/dpkg", "--remove", "--", REMOVE_INPUT["package"]], check=True,
               env={**os.environ, "DEBIAN_FRONTEND": "noninteractive", "LC_ALL": "C"},
               stdin=subprocess.DEVNULL, capture_output=True, text=True)
print('{"ready":true}')
