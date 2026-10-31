import json
import os
import subprocess

v = json.loads(os.environ["ORBIT_EXPORTER_INTENT"])
r = subprocess.run(
    ["/usr/bin/systemctl", "is-active", v["service"]],
    capture_output=True,
    text=True,
    check=False,
)
state = r.stdout.strip()
if (r.returncode == 0 and state == "active") or (
    r.returncode in (1, 3, 4) and state in ("inactive", "failed", "unknown")
):
    print(json.dumps({"active": r.returncode == 0}))
else:
    raise SystemExit("metrics.exporter_service_inspection_failed")
