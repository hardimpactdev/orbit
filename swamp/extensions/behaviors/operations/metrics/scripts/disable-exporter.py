import json
import os
import subprocess

v = json.loads(os.environ["ORBIT_EXPORTER_INTENT"])
r = subprocess.run(
    ["/usr/bin/systemctl", "show", "--property=LoadState", "--value", v["service"]],
    capture_output=True,
    text=True,
    check=True,
)
if (
    json.loads(os.environ["ORBIT_EXPORTER_BEFORE"])["configuration"] is not None
    and r.stdout.strip() != "not-found"
):
    subprocess.run(["/usr/bin/systemctl", "disable", v["service"]], check=True)
