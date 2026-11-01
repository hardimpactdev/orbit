"""Remove only the selected package; dpkg owns its dependency checks and lock."""
import json  # noqa: F401 -- metadata binds public REMOVE_INPUT with json.loads
import os
import subprocess

REMOVE_INPUT = None
subprocess.run(["/usr/bin/dpkg", "--remove", "--", REMOVE_INPUT["package"]], check=True,
               env={**os.environ, "DEBIAN_FRONTEND": "noninteractive", "LC_ALL": "C"},
               stdin=subprocess.DEVNULL, capture_output=True, text=True)
print('{"ready":true}')
